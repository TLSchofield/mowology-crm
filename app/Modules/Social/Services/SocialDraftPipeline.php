<?php
/**
 * Social Draft Pipeline
 *
 * Orchestrates auto-generation of a social post draft when a crew member
 * taps the heart (❤️) button on a completed job visit.
 *
 * Entry point:
 *   SocialDraftPipeline::triggerFromVisit(int $visitId, PDO $db): int
 *   Returns social_posts.id (creates or returns existing draft — idempotent).
 *
 * Dependencies (require_once before calling):
 *   app/Services/Social/SocialHashtagEngine.php
 *   app/Services/Social/SocialCardGenerator.php
 *   crm/includes/functions.php  (getDB, sendCrmEmail, etc.)
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class SocialDraftPipeline
{
    // ── Vancouver postal prefix → neighbourhood lookup ────────────────────
    private static $postalNeighbourhoods = [
        'V6J' => 'Kitsilano',
        'V6K' => 'Kitsilano',
        'V6R' => 'Point Grey',
        'V6S' => 'Dunbar',
        'V6M' => 'Kerrisdale',
        'V6G' => 'West End',
        'V6H' => 'Fairview',
        'V5T' => 'Mount Pleasant',
        'V5L' => 'Commercial Drive',
        'V6E' => 'West End',
        'V6B' => 'Downtown',
        'V6C' => 'Downtown',
        'V6Z' => 'Downtown',
        'V5Y' => 'Mount Pleasant',
        'V5V' => 'Kensington',
        'V5W' => 'Marpole',
        'V6N' => 'Marpole',
        'V6P' => 'Oakridge',
        'V6T' => 'UBC',
        'V6L' => 'Dunbar',
    ];

    /**
     * Main entry point. Idempotent — safe to call multiple times for the same visit.
     *
     * @param int $visitId job_visits.id
     * @param PDO $db
     * @return int social_posts.id
     * @throws RuntimeException on unrecoverable errors
     */
    public static function triggerFromVisit(int $visitId, PDO $db): int
    {
        // ── 1. Load visit with all context ─────────────────────────────────
        $stmt = $db->prepare("
            SELECT
                jv.id           AS visit_id,
                jv.visit_number,
                jv.status       AS visit_status,
                jv.social_draft_id,
                jp.service_type,
                jp.property_id,
                p.address,
                p.city,
                p.postal_code,
                CONCAT(u.first_name, ' ', u.last_name) AS crew_name
            FROM job_visits jv
            JOIN job_plans   jp ON jp.id = jv.plan_id
            JOIN properties  p  ON p.id  = jp.property_id
            LEFT JOIN users  u  ON u.id  = jv.assigned_crew_id
            WHERE jv.id = ?
        ");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) {
            throw new \RuntimeException("Visit #$visitId not found.");
        }

        // ── 2. Idempotency guard ────────────────────────────────────────────
        if (!empty($visit['social_draft_id'])) {
            return (int)$visit['social_draft_id'];
        }

        $serviceType = $visit['service_type'] ?? '';
        $address     = $visit['address'] ?? '';
        $city        = $visit['city'] ?? 'Vancouver';
        $postalCode  = strtoupper(trim($visit['postal_code'] ?? ''));
        $crewName    = trim($visit['crew_name'] ?? 'Our Crew');

        // ── 3. Import photos from visit_photos ──────────────────────────────
        $photoStmt = $db->prepare("
            SELECT id, filename, photo_type
            FROM visit_photos
            WHERE visit_id = ?
              AND photo_type IN ('before', 'after', 'during')
              AND deleted_at IS NULL
            ORDER BY FIELD(photo_type, 'before', 'during', 'after'), id ASC
        ");
        $photoStmt->execute([$visitId]);
        $visitPhotos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

        $importedAssets = []; // ['media_id' => int, 'photo_type' => string]
        foreach ($visitPhotos as $vp) {
            $assetId = self::importVisitPhoto($db, $vp);
            $importedAssets[] = [
                'media_id'   => $assetId,
                'photo_type' => $vp['photo_type'],
            ];
        }

        // ── 4. Look up service catalogue for CTA URL + description ─────────
        $ctaUrl          = '/quote?service=' . urlencode($serviceType); // fallback
        $serviceDesc     = '';
        $serviceLabel    = ucwords(str_replace(['_', '-'], ' ', $serviceType));

        $pkgStmt = $db->prepare("
            SELECT slug, description, package_name
            FROM service_packages
            WHERE service_type = ? AND is_active = 1
            LIMIT 1
        ");
        $pkgStmt->execute([$serviceType]);
        $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);

        if ($pkg && !empty($pkg['slug'])) {
            $ctaUrl      = 'https://mowology.ca/services/' . $pkg['slug'];
            $serviceLabel = $pkg['package_name'] ?: $serviceLabel;
            // First sentence of description (strip HTML tags)
            $rawDesc = strip_tags($pkg['description'] ?? '');
            $sentences = preg_split('/(?<=[.!?])\s+/', $rawDesc, 2);
            $serviceDesc = trim($sentences[0] ?? '');
        }

        // Fallback: check service_types for the human-readable label
        if ($serviceType) {
            $stStmt = $db->prepare("SELECT label FROM service_types WHERE slug = ? LIMIT 1");
            $stStmt->execute([$serviceType]);
            $stLabel = $stStmt->fetchColumn();
            if ($stLabel && empty($pkg)) {
                $serviceLabel = (string)$stLabel;
            }
        }

        // ── 5. Select template with variety rotation ────────────────────────
        // Least-used matching template first, with RAND() for extra variety
        $tmplStmt = $db->prepare("
            SELECT *
            FROM social_templates
            WHERE (service_type = ? OR service_type IS NULL)
              AND category = 'proof_of_work'
              AND is_active = 1
            ORDER BY usage_count ASC, RAND()
            LIMIT 1
        ");
        $tmplStmt->execute([$serviceType]);
        $template = $tmplStmt->fetch(PDO::FETCH_ASSOC);

        // Fallback: any active template
        if (!$template) {
            $tmplStmt2 = $db->prepare("
                SELECT * FROM social_templates WHERE is_active = 1 ORDER BY RAND() LIMIT 1
            ");
            $tmplStmt2->execute();
            $template = $tmplStmt2->fetch(PDO::FETCH_ASSOC);
        }

        if (!$template) {
            throw new \RuntimeException('No active social_templates found. Seed templates first.');
        }

        // ── 6. Resolve neighbourhood ────────────────────────────────────────
        $neighbourhood = self::resolveNeighbourhood($postalCode, $city);

        // ── 7. Resolve season ───────────────────────────────────────────────
        $month   = (int)date('n');
        $seasons = [
            1=>'Winter', 2=>'Winter', 3=>'Spring', 4=>'Spring', 5=>'Spring',
            6=>'Summer', 7=>'Summer', 8=>'Summer',
            9=>'Fall', 10=>'Fall', 11=>'Fall', 12=>'Winter',
        ];
        $season = $seasons[$month] ?? 'Summer';

        // ── 8. Build caption ────────────────────────────────────────────────
        $variables = [
            '{service}'             => $serviceLabel,
            '{service_label}'       => $serviceLabel,
            '{service_description}' => $serviceDesc,
            '{service_url}'         => $ctaUrl,
            '{neighborhood}'        => $neighbourhood,
            '{neighbourhood}'       => $neighbourhood,
            '{city}'                => $city,
            '{date}'                => date('F j, Y'),
            '{crew_name}'           => $crewName,
            '{season}'              => $season,
        ];

        $caption = str_replace(
            array_keys($variables),
            array_values($variables),
            $template['caption_template'] ?? ''
        );

        // ── 9. Hashtags + best time ─────────────────────────────────────────
        $hashtagData = SocialHashtagEngine::forServiceAndLocation(
            $serviceType,
            $neighbourhood,
            $city,
            $month
        );

        // Use template hashtag preset if available, else auto-generated
        $hashtags = !empty($template['hashtag_preset'])
            ? (string)$template['hashtag_preset']
            : $hashtagData['hashtags'];

        // Pass $db so bestPostTime can use real engagement data from social_metrics_daily
        $bestTime = SocialHashtagEngine::bestPostTime((int)date('N'), $db);

        // CTA URL: template preset overrides auto-generated (with variable substitution)
        if (!empty($template['cta_url_preset'])) {
            $ctaFromTemplate = str_replace(
                array_keys($variables),
                array_values($variables),
                $template['cta_url_preset']
            );
            if ($ctaFromTemplate) {
                $ctaUrl = $ctaFromTemplate;
            }
        }

        // ── 10. Load admin user for created_by ──────────────────────────────
        $adminStmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $adminStmt->execute();
        $adminId = (int)($adminStmt->fetchColumn() ?: 1);

        // ── 11. INSERT social_posts ─────────────────────────────────────────
        $db->prepare("
            INSERT INTO social_posts
                (title, caption, hashtags, cta_action, cta_url,
                 status, template_id, visit_id, neighborhood, city,
                 service_type, created_by, auto_generated, best_time_suggestion,
                 created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?,
                 'draft', ?, ?, ?, ?,
                 ?, ?, 1, ?,
                 NOW(), NOW())
        ")->execute([
            $serviceLabel . ' — ' . $neighbourhood . ' (' . date('M j') . ')',
            $caption,
            $hashtags,
            $template['cta_preset'] ?? 'BOOK',
            $ctaUrl,
            (int)$template['id'],
            $visitId,
            $neighbourhood,
            $city,
            $serviceType,
            $adminId,
            $bestTime,
        ]);

        $postId = (int)$db->lastInsertId();

        // ── 12. INSERT social_post_media ────────────────────────────────────
        $sortOrder = 0;
        // Before photos first
        foreach ($importedAssets as $asset) {
            if ($asset['photo_type'] === 'before') {
                $db->prepare("
                    INSERT INTO social_post_media (post_id, media_id, sort_order, photo_type)
                    VALUES (?, ?, ?, ?)
                ")->execute([$postId, $asset['media_id'], $sortOrder++, 'before']);
            }
        }
        // After photos
        foreach ($importedAssets as $asset) {
            if ($asset['photo_type'] !== 'before') {
                $db->prepare("
                    INSERT INTO social_post_media (post_id, media_id, sort_order, photo_type)
                    VALUES (?, ?, ?, ?)
                ")->execute([$postId, $asset['media_id'], $sortOrder++, $asset['photo_type']]);
            }
        }

        // ── 13. Determine card type ─────────────────────────────────────────
        $hasBeforePhotos = false;
        $hasAfterPhotos  = false;
        $totalPhotos     = count($importedAssets);

        foreach ($importedAssets as $asset) {
            if ($asset['photo_type'] === 'before') $hasBeforePhotos = true;
            if ($asset['photo_type'] === 'after')  $hasAfterPhotos  = true;
        }

        if ($hasBeforePhotos && $hasAfterPhotos) {
            $cardType = 'before_after';
        } elseif ($totalPhotos > 0) {
            $cardType = 'hero_after';
        } else {
            $cardType = 'hero_after'; // will render with placeholder
        }

        // ── 14. Generate card ───────────────────────────────────────────────
        $cardResult = ['success' => false, 'derivative_id' => null];
        if (!empty($importedAssets)) {
            try {
                $cardResult = SocialCardGenerator::generate($postId, $cardType, $db);
            } catch (\Throwable $e) {
                error_log("SocialCardGenerator failed for post $postId: " . $e->getMessage());
            }
        }

        // ── 15. Update social_posts with card metadata ──────────────────────
        $db->prepare("
            UPDATE social_posts
            SET card_template_type  = ?,
                best_time_suggestion = ?
            WHERE id = ?
        ")->execute([$cardType, $bestTime, $postId]);

        // ── 16. Increment template usage_count ─────────────────────────────
        $db->prepare("
            UPDATE social_templates SET usage_count = usage_count + 1 WHERE id = ?
        ")->execute([$template['id']]);

        // ── 17. Update job_visits ───────────────────────────────────────────
        $db->prepare("
            UPDATE job_visits
            SET social_draft_id = ?, flagged_at = NOW()
            WHERE id = ?
        ")->execute([$postId, $visitId]);

        // ── 18. Notify admin ────────────────────────────────────────────────
        self::notifyAdmin($db, $postId, $serviceLabel, $neighbourhood, $crewName, $adminId);

        return $postId;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Import a visit_photo into media_assets (idempotent).
     * Reuses existing row if file already imported (matched by filename).
     */
    private static function importVisitPhoto(PDO $db, array $vp): int
    {
        $filename = $vp['filename'];
        $filePath = '/uploads/photos/' . $filename;

        // Check if already imported
        $chkStmt = $db->prepare(
            "SELECT id FROM media_assets WHERE context_type = 'visit_photo' AND filename = ? LIMIT 1"
        );
        $chkStmt->execute([$filename]);
        $existing = $chkStmt->fetchColumn();
        if ($existing) {
            return (int)$existing;
        }

        $insStmt = $db->prepare(
            "INSERT INTO media_assets (filename, file_path, context_type, status, created_at)
             VALUES (?, ?, 'visit_photo', 'ready', NOW())"
        );
        $insStmt->execute([$filename, $filePath]);
        return (int)$db->lastInsertId();
    }

    /**
     * Resolve a neighbourhood name from a Canadian postal code prefix.
     * Falls back to $city if no mapping found.
     */
    public static function resolveNeighbourhood(string $postalCode, string $city): string
    {
        if (!$postalCode) {
            return $city;
        }
        // Postal prefix = first 3 characters (e.g. "V6J" from "V6J 2B7")
        $prefix = strtoupper(substr(str_replace(' ', '', $postalCode), 0, 3));
        return isset(self::$postalNeighbourhoods[$prefix])
            ? self::$postalNeighbourhoods[$prefix]
            : $city;
    }

    /**
     * Send admin notification email for the new draft.
     */
    private static function notifyAdmin(
        PDO $db,
        int $postId,
        string $serviceLabel,
        string $neighbourhood,
        string $crewName,
        int $adminId
    ): void {
        try {
            $adminEmailStmt = $db->prepare("SELECT email, full_name FROM users WHERE id = ? LIMIT 1");
            $adminEmailStmt->execute([$adminId]);
            $admin = $adminEmailStmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin || empty($admin['email'])) {
                return;
            }

            $editorUrl = (defined('SITE_URL') ? SITE_URL : 'https://mowology.ca')
                . '/crm/marketing/social-post-editor.php?id=' . $postId;

            $subject = 'New social draft ready — ' . $serviceLabel . ' in ' . $neighbourhood;
            $body    = "Hi " . ($admin['full_name'] ?? 'Admin') . ",\n\n"
                     . $crewName . " completed a " . $serviceLabel . " job in " . $neighbourhood
                     . " and flagged it for social media.\n\n"
                     . "A posting card has been generated automatically. "
                     . "Review and schedule it here:\n"
                     . $editorUrl . "\n\n"
                     . "— Mowology CRM";

            if (function_exists('sendCrmEmail')) {
                sendCrmEmail($admin['email'], $subject, nl2br(htmlspecialchars($body)), $body);
            }
        } catch (\Throwable $e) {
            // Non-fatal — just log
            error_log("SocialDraftPipeline: admin notification failed: " . $e->getMessage());
        }
    }
}
