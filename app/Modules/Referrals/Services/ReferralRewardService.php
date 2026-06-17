<?php
/**
 * Referral Reward Service
 *
 * Manages the "Refer a Friend" program. Handles:
 *   - Generating unique referral links per contact
 *   - Recording inbound referrals from jobFlow
 *   - Awarding a free product trial when a referred client's first visit completes
 *   - Sending invite and reward notification emails
 *
 * Reward type: free_product_trial — the referring client receives a free service
 * they have never previously received. Admin can pin a specific product in
 * ops_settings.referral_reward_product_id, or leave blank for auto-suggestion.
 *
 * Contact lookup chain for awarding:
 *   job_visits.plan_id → job_plans.property_id → properties.site_contact_id → contacts
 *
 * All public methods silently return on error — never throw — to avoid breaking
 * the end_visit flow when called as a hook.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __FILE__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/Core/paths.php')) {
            require_once $__dir . '/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once APP_ROOT . '/Services/Messaging/MessagingService.php';

class ReferralRewardService
{
    /** Character set for generated referral codes */
    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Length of generated referral code */
    private const CODE_LENGTH = 8;

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called from pow-actions.php after end_visit.
     *
     * Checks whether the completed visit belongs to a referred contact whose
     * referral is still pending, and if so, awards the referrer a free product trial.
     *
     * @param int $visitId  The completed job_visits.id
     * @param PDO $db       Active database connection
     * @param int $userId   The user who completed the visit (for audit log)
     */
    public static function maybeAwardCompletion(int $visitId, PDO $db, int $userId): void
    {
        try {
            // Check program is enabled
            if (!self::isProgramEnabled($db)) {
                return;
            }

            // Resolve the contact whose visit just completed
            $contact = self::resolveContactFromVisit($visitId, $db);
            if (!$contact) {
                return;
            }

            // Find a pending referral where this contact was the referred party.
            // Falls back to email match because referred_contact_id is not set at
            // quote-request time (the person is not yet a contact). When matched
            // by email, referred_contact_id is written so future lookups hit the
            // fast path.
            $referral = self::findPendingReferralForContact(
                (int)$contact['id'],
                trim((string)($contact['email'] ?? '')),
                $db
            );
            if (!$referral) {
                return;
            }

            // Backfill referred_contact_id if this was an email-only match.
            if (empty($referral['referred_contact_id'])) {
                $db->prepare(
                    "UPDATE referrals SET referred_contact_id = ? WHERE id = ?"
                )->execute([(int)$contact['id'], (int)$referral['id']]);
                $referral['referred_contact_id'] = (int)$contact['id'];
            }

            // Suggest or look up the reward product
            $product = self::resolveRewardProduct((int)$referral['referrer_contact_id'], $db);

            // Insert the reward record
            $db->prepare("
                INSERT INTO referral_rewards
                    (referral_id, contact_id, reward_type, reward_product_id, reward_value, status, created_at)
                VALUES (?, ?, 'free_product_trial', ?, ?, 'pending', NOW())
            ")->execute([
                $referral['id'],
                $referral['referrer_contact_id'],
                $product ? (int)$product['id'] : null,
                $product ? (float)$product['base_price'] : 0.00,
            ]);

            // Mark the referral as converted
            $db->prepare("
                UPDATE referrals
                SET status = 'converted', first_visit_id = ?, first_job_completed_at = NOW(), reward_issued_at = NOW()
                WHERE id = ?
            ")->execute([$visitId, $referral['id']]);

            // Notify the referrer
            $referrer = self::getContact((int)$referral['referrer_contact_id'], $db);
            if ($referrer && !empty($referrer['email'])) {
                self::sendRewardEmail($referrer, $product);
            }

            // Audit log
            $db->prepare("
                INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at)
                VALUES (?, 'referral_converted', 'referral', ?, ?, NOW())
            ")->execute([
                $userId,
                $referral['id'],
                json_encode([
                    'referrer_contact_id' => $referral['referrer_contact_id'],
                    'referred_contact_id' => $contact['id'],
                    'visit_id'            => $visitId,
                    'product_id'          => $product['id'] ?? null,
                    'product_name'        => $product['name'] ?? null,
                ]),
            ]);

        } catch (Throwable $e) {
            error_log("ReferralRewardService::maybeAwardCompletion error for visit #{$visitId}: " . $e->getMessage());
        }
    }

    /**
     * Called from jobFlow-confirm.php after a quote request is inserted.
     *
     * Finds the referral_link for the given code, records an inbound referral,
     * and increments the link's visit counter.
     *
     * @param string $code       The referral_code from the URL/session
     * @param array  $quoteData  Must contain: name, email, phone, qr_id (quote_request_id)
     * @param PDO    $db
     */
    public static function recordInboundReferral(string $code, array $quoteData, PDO $db): void
    {
        try {
            $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
            if (!$code) {
                return;
            }

            // Look up the referral link
            $stmt = $db->prepare("SELECT * FROM referral_links WHERE referral_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$link) {
                return; // Unknown or expired code
            }

            // Insert referral record
            $db->prepare("
                INSERT INTO referrals
                    (referral_code, referrer_contact_id, referred_name, referred_email,
                     referred_phone, quote_request_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ")->execute([
                $code,
                $link['contact_id'],
                substr(trim($quoteData['name']  ?? ''), 0, 150),
                substr(trim($quoteData['email'] ?? ''), 0, 200),
                substr(trim($quoteData['phone'] ?? ''), 0, 30),
                $quoteData['qr_id'] ?? null,
            ]);

            // Increment visit counter on link
            $db->prepare("
                UPDATE referral_links SET visits = visits + 1 WHERE referral_code = ?
            ")->execute([$code]);

        } catch (Throwable $e) {
            error_log("ReferralRewardService::recordInboundReferral error (code={$code}): " . $e->getMessage());
        }
    }

    /**
     * Get or create a referral link for a contact.
     *
     * @return array  Row from referral_links including 'referral_code'
     */
    public static function getOrCreateLink(int $contactId, PDO $db): array
    {
        $stmt = $db->prepare("SELECT * FROM referral_links WHERE contact_id = ? LIMIT 1");
        $stmt->execute([$contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $row;
        }

        $code = self::generateUniqueCode($db);
        $db->prepare("
            INSERT INTO referral_links (contact_id, referral_code, created_at)
            VALUES (?, ?, NOW())
        ")->execute([$contactId, $code]);

        $stmt->execute([$contactId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Send a referral invite email to a contact, with their unique referral link.
     *
     * @return bool  True if email was sent successfully
     */
    public static function sendInviteEmail(int $contactId, PDO $db): bool
    {
        try {
            $contact = self::getContact($contactId, $db);
            if (!$contact || empty($contact['email'])) {
                return false;
            }

            $link = self::getOrCreateLink($contactId, $db);
            $code = $link['referral_code'];
            $url  = 'https://mowology.ca/quote?referral_code=' . $code;

            // Resolve the reward product name for the email
            $product = self::resolveRewardProduct($contactId, $db);
            $productName = $product ? htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') : 'a complimentary service';

            $firstName   = htmlspecialchars($contact['first_name'] ?? 'Valued Customer', ENT_QUOTES, 'UTF-8');
            $urlSafe     = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $codeSafe    = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

            $html = '
            <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f7faf8;padding:24px 0;">

              <!-- Header -->
              <div style="background:#1A5F4A;border-radius:10px 10px 0 0;padding:28px 32px;">
                <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;letter-spacing:-0.3px;">
                  Share Mowology — Earn a Free Service
                </h1>
                <p style="color:#b2d8c9;margin:8px 0 0;font-size:14px;">
                  Your personal referral link is inside
                </p>
              </div>

              <!-- Body -->
              <div style="background:#fff;border:1px solid #dce8e2;border-top:none;border-radius:0 0 10px 10px;padding:32px;">

                <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 16px;">
                  Hi ' . $firstName . ',
                </p>
                <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 16px;">
                  You&rsquo;re one of our favourite clients, so we want to offer you something special.
                  Refer a neighbour, friend, or family member to Mowology and when they complete
                  their first service, <strong>you&rsquo;ll receive ' . $productName . ' — on us.</strong>
                </p>
                <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 24px;">
                  All they need to do is book through your personal link below:
                </p>

                <!-- CTA button -->
                <p style="text-align:center;margin:0 0 16px;">
                  <a href="' . $urlSafe . '"
                     style="display:inline-block;padding:16px 36px;background:#2D8659;color:#fff;
                            text-decoration:none;border-radius:8px;font-weight:700;font-size:16px;">
                    Share Your Referral Link
                  </a>
                </p>

                <p style="text-align:center;color:#6B7280;font-size:13px;margin:0 0 28px;">
                  Or share your code: <strong style="color:#2D8659;font-size:16px;letter-spacing:1px;">' . $codeSafe . '</strong>
                </p>

                <div style="background:#f0f9f5;border:1px solid #b7dfc9;border-radius:8px;padding:16px 20px;margin:0 0 24px;">
                  <p style="color:#1A5F4A;font-size:14px;font-weight:700;margin:0 0 8px;">How it works</p>
                  <ol style="color:#374151;font-size:14px;line-height:1.8;margin:0;padding-left:20px;">
                    <li>Share your link or code with anyone you know</li>
                    <li>They book and complete their first Mowology service</li>
                    <li>We contact you to schedule your free ' . $productName . '</li>
                  </ol>
                </div>

                <hr style="border:none;border-top:1px solid #f0f0f0;margin:24px 0;">

                <p style="color:#6B7280;font-size:13px;line-height:1.5;margin:0;">
                  Questions? Reply to this email or call <strong>(778) 846-9273</strong>.
                </p>
              </div>

              <!-- Footer -->
              <p style="text-align:center;color:#9CA3AF;font-size:12px;margin:20px 0 0;">
                &copy; Mowology Landscaping &mdash; Greater Vancouver, BC
              </p>

            </div>';

            $result = sendEmail(
                $contact['email'],
                'Refer a friend, earn a free service — your Mowology link inside',
                $html,
                null,
                'Mowology'
            );

            if ($result['success'] ?? false) {
                // Update invite tracking
                $db->prepare("
                    UPDATE referral_links
                    SET invites_sent = invites_sent + 1, last_invite_sent_at = NOW()
                    WHERE contact_id = ?
                ")->execute([$contactId]);
                return true;
            }

            return false;

        } catch (Throwable $e) {
            error_log("ReferralRewardService::sendInviteEmail error for contact #{$contactId}: " . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private static function isProgramEnabled(PDO $db): bool
    {
        $stmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'referral_program_enabled' LIMIT 1");
        $stmt->execute();
        return (int)($stmt->fetchColumn() ?? 0) === 1;
    }

    private static function resolveContactFromVisit(int $visitId, PDO $db): ?array
    {
        $stmt = $db->prepare("
            SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.mobile, c.is_active
            FROM job_visits jv
            JOIN job_plans  jp ON jp.id = jv.plan_id
            JOIN properties  p ON p.id  = jp.property_id
            JOIN contacts    c ON c.id  = p.site_contact_id
            WHERE jv.id = ?
            LIMIT 1
        ");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function findPendingReferralForContact(int $contactId, string $email, PDO $db): ?array
    {
        // Primary: match on referred_contact_id (set after first match).
        $stmt = $db->prepare("
            SELECT * FROM referrals
            WHERE referred_contact_id = ? AND status = 'pending'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        // Fallback: referred_contact_id was never written (quote-request path
        // does not have a contact_id yet). Match on email instead.
        if ($email === '') return null;
        $stmt = $db->prepare("
            SELECT * FROM referrals
            WHERE referred_contact_id IS NULL
              AND referred_email = ?
              AND status = 'pending'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Find the best reward product for a referrer.
     *
     * Priority:
     *   1. Admin-pinned product (ops_settings.referral_reward_product_id)
     *   2. Highest-value product from catalog that the referrer has never received
     *   3. Highest-value active product (fallback)
     */
    private static function resolveRewardProduct(int $referrerContactId, PDO $db): ?array
    {
        // Check for admin-pinned product
        $pinStmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'referral_reward_product_id' LIMIT 1");
        $pinStmt->execute();
        $pinnedId = (int)trim($pinStmt->fetchColumn() ?? '');

        if ($pinnedId > 0) {
            $p = $db->prepare("SELECT id, name, base_price FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
            $p->execute([$pinnedId]);
            $row = $p->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        // Find products the referrer has already received
        $usedStmt = $db->prepare("
            SELECT DISTINCT pli.product_id
            FROM plan_line_items pli
            JOIN job_plans jp ON jp.id = pli.plan_id
            JOIN properties pr ON pr.id = jp.property_id
            JOIN contacts c ON c.id = pr.site_contact_id
            WHERE c.id = ?
        ");
        $usedStmt->execute([$referrerContactId]);
        $usedIds = $usedStmt->fetchAll(PDO::FETCH_COLUMN);

        // Get all active products, excluding ones they've already received
        $allStmt = $db->query("SELECT id, name, base_price FROM products WHERE is_active = 1 ORDER BY base_price DESC");
        $all = $allStmt->fetchAll(PDO::FETCH_ASSOC);

        // Return first untried product (highest value)
        foreach ($all as $product) {
            if (!in_array($product['id'], $usedIds, true)) {
                return $product;
            }
        }

        // Fallback: return highest-value product regardless
        return $all[0] ?? null;
    }

    private static function getContact(int $contactId, PDO $db): ?array
    {
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, email, phone, mobile
            FROM contacts WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function generateUniqueCode(PDO $db): string
    {
        do {
            $code = '';
            $len  = strlen(self::CODE_CHARS);
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_CHARS[random_int(0, $len - 1)];
            }
            $stmt = $db->prepare("SELECT COUNT(*) FROM referral_links WHERE referral_code = ?");
            $stmt->execute([$code]);
        } while ((int)$stmt->fetchColumn() > 0);

        return $code;
    }

    private static function sendRewardEmail(array $referrer, ?array $product): void
    {
        if (empty($referrer['email'])) {
            return;
        }

        $firstName   = htmlspecialchars($referrer['first_name'] ?? 'Valued Client', ENT_QUOTES, 'UTF-8');
        $productName = $product
            ? htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8')
            : 'a complimentary service';

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f7faf8;padding:24px 0;">

          <!-- Header -->
          <div style="background:#1A5F4A;border-radius:10px 10px 0 0;padding:28px 32px;">
            <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;letter-spacing:-0.3px;">
              Your referral came through! &#127881;
            </h1>
            <p style="color:#b2d8c9;margin:8px 0 0;font-size:14px;">
              You&rsquo;ve earned your reward
            </p>
          </div>

          <!-- Body -->
          <div style="background:#fff;border:1px solid #dce8e2;border-top:none;border-radius:0 0 10px 10px;padding:32px;">

            <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 16px;">
              Hi ' . $firstName . ',
            </p>
            <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 24px;">
              Great news — the person you referred has completed their first Mowology service!
              As a thank you, <strong>we&rsquo;re booking you in for ' . $productName . ' — completely on us.</strong>
            </p>

            <div style="background:#f0f9f5;border:1px solid #b7dfc9;border-radius:8px;padding:20px 24px;margin:0 0 24px;text-align:center;">
              <div style="font-size:36px;margin-bottom:8px;">&#127381;</div>
              <div style="color:#1A5F4A;font-size:18px;font-weight:700;">' . $productName . '</div>
              <div style="color:#6B7280;font-size:13px;margin-top:6px;">Your complimentary service</div>
            </div>

            <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 24px;">
              We&rsquo;ll be in touch shortly to schedule your free service at a time that works for you.
              No action needed on your part — just sit back and enjoy!
            </p>

            <hr style="border:none;border-top:1px solid #f0f0f0;margin:24px 0;">

            <p style="color:#6B7280;font-size:13px;line-height:1.5;margin:0;">
              Questions? Reply to this email or call <strong>(778) 846-9273</strong>.
            </p>
          </div>

          <p style="text-align:center;color:#9CA3AF;font-size:12px;margin:20px 0 0;">
            &copy; Mowology Landscaping &mdash; Greater Vancouver, BC
          </p>

        </div>';

        sendEmail(
            $referrer['email'],
            "You've earned a free {$productName} — thanks for the referral!",
            $html,
            null,
            'Mowology'
        );
    }
}
