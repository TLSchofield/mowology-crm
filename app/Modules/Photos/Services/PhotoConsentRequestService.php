<?php
declare(strict_types=1);

/**
 * PhotoConsentRequestService
 * ──────────────────────────
 * After a visit is completed and photos are uploaded, this service sends
 * ONE email per client asking if they'd like to allow their photos to
 * appear in the public portfolio.
 *
 * Gates:
 *   - Photos exist on this visit
 *   - Client has a valid portal_token
 *   - Client has marketing consent (receive_marketing = 1 OR not yet asked)
 *   - We haven't asked this client in the last 90 days
 *   - Client hasn't already decided on any photo (consent_portfolio IS NOT NULL)
 *
 * Usage:
 *   PhotoConsentRequestService::maybeSend($visitId, $db);
 */
class PhotoConsentRequestService
{
    private const COOLDOWN_DAYS = 90;

    public static function maybeSend(int $visitId, PDO $db): void
    {
        try {
            // 1. Check this visit has photos
            $photoStmt = $db->prepare("
                SELECT COUNT(*) FROM visit_photos
                WHERE visit_id = ? AND deleted_at IS NULL
            ");
            $photoStmt->execute([$visitId]);
            $photoCount = (int)$photoStmt->fetchColumn();
            if ($photoCount === 0) return;

            // 2. Resolve the client (contact) via visit → plan → property
            $ctxStmt = $db->prepare("
                SELECT c.id AS contact_id, c.email, c.first_name, c.last_name,
                       c.portal_token, c.receive_marketing
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                JOIN properties pr ON pr.id = jp.property_id
                JOIN contacts c ON c.id = pr.site_contact_id
                WHERE jv.id = ? LIMIT 1
            ");
            $ctxStmt->execute([$visitId]);
            $ctx = $ctxStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ctx || empty($ctx['email']) || empty($ctx['portal_token'])) return;

            // Marketing opt-out honoured
            if (isset($ctx['receive_marketing']) && (int)$ctx['receive_marketing'] === 0) return;

            $contactId = (int)$ctx['contact_id'];

            // 3. Has the client already decided consent on any photo? If yes, skip.
            try {
                $decidedStmt = $db->prepare("
                    SELECT 1
                    FROM visit_photos vp
                    JOIN job_visits v ON v.id = vp.visit_id
                    JOIN job_plans jp ON jp.id = v.plan_id
                    JOIN properties pr ON pr.id = jp.property_id
                    WHERE pr.site_contact_id = ?
                      AND vp.consent_portfolio IS NOT NULL
                    LIMIT 1
                ");
                $decidedStmt->execute([$contactId]);
                if ($decidedStmt->fetchColumn()) return; // already decided at least one
            } catch (Throwable $e) { /* consent_portfolio column missing (pre-migration) */ return; }

            // 4. Cooldown — track on visit_photos.consent_requested_at
            try {
                $recentStmt = $db->prepare("
                    SELECT MAX(vp.consent_requested_at) AS last_asked
                    FROM visit_photos vp
                    JOIN job_visits v ON v.id = vp.visit_id
                    JOIN job_plans jp ON jp.id = v.plan_id
                    JOIN properties pr ON pr.id = jp.property_id
                    WHERE pr.site_contact_id = ?
                ");
                $recentStmt->execute([$contactId]);
                $lastAsked = $recentStmt->fetchColumn();
                if ($lastAsked && strtotime($lastAsked) > strtotime('-' . self::COOLDOWN_DAYS . ' days')) {
                    return; // asked recently
                }
            } catch (Throwable $e) { /* column missing */ return; }

            // 5. Send the email
            $baseUrl     = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
            $galleryUrl  = $baseUrl . '/customer/gallery.php?token=' . urlencode($ctx['portal_token']);
            $firstName   = $ctx['first_name'] ?: 'there';

            $subject = 'Photos from your visit — one quick favour?';
            $body = '<h2>Hi ' . htmlspecialchars($firstName) . ',</h2>
<p>Our crew took ' . $photoCount . ' photo' . ($photoCount === 1 ? '' : 's') . ' at your property during today\'s visit.
You can view them anytime in your private gallery:</p>
<p style="margin:24px 0;">
  <a href="' . htmlspecialchars($galleryUrl) . '"
     style="background:#2D8659;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;font-size:15px;font-weight:600;display:inline-block;">
    View Your Photo Gallery
  </a>
</p>
<p><strong>Would you be OK with us sharing some in our portfolio?</strong></p>
<p style="font-size:0.9rem;color:#475569;">
  We\'d love to feature before/after shots of your property on our website to help others
  discover what we do. We never share identifying details (no addresses, no names).
</p>
<p style="font-size:0.9rem;color:#475569;">
  In your gallery, click the <strong>&#9734;</strong> star on any photo you\'re happy for us to use.
  Starred photos (<strong>&#9733;</strong>) are approved. Leave photos unstarred to keep them private.
  You can change your mind anytime.
</p>
<p style="color:#94a3b8;font-size:0.8rem;margin-top:24px;">
  Ignore this email if you\'d rather keep all your photos private — they\'ll stay that way.
  Thank you for letting Mowology take care of your landscape.
</p>';

            // Use the CRM messaging layer (PHPMailer SMTP)
            $messagingFile = PUBLIC_ROOT . '/crm/includes/messaging.php';
            if (!function_exists('sendCrmEmail') && is_file($messagingFile)) {
                require_once $messagingFile;
            }
            if (!function_exists('sendCrmEmail')) return; // messaging unavailable

            $displayName = trim(($ctx['first_name'] ?? '') . ' ' . ($ctx['last_name'] ?? ''));
            $sent = sendCrmEmail($ctx['email'], $subject, $body, $displayName);

            // 6. Mark consent_requested_at on ALL photos for this client so we don't
            //    ask again for 90 days (regardless of which photo they see first)
            if ($sent) {
                try {
                    $db->prepare("
                        UPDATE visit_photos vp
                        JOIN job_visits v ON v.id = vp.visit_id
                        JOIN job_plans jp ON jp.id = v.plan_id
                        JOIN properties pr ON pr.id = jp.property_id
                        SET vp.consent_requested_at = NOW()
                        WHERE pr.site_contact_id = ?
                          AND vp.consent_requested_at IS NULL
                    ")->execute([$contactId]);
                    error_log("[PhotoConsent] Sent request to contact #{$contactId} for visit #{$visitId} ({$photoCount} photos)");
                } catch (Throwable $e) { /* non-fatal */ }
            }

        } catch (Throwable $e) {
            // Never let this block visit completion — just log
            error_log('[PhotoConsentRequestService] Error for visit ' . $visitId . ': ' . $e->getMessage());
        }
    }
}
