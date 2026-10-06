<?php
/**
 * Review Request Service
 *
 * Triggered after a job visit is marked complete. Sends a Google Review
 * request to the customer associated with the visit.
 *
 * EVERY completed visit is eligible — the same request, whatever the crew
 * thought of the job. Google's policy forbids selective solicitation (asking only
 * the customers likely to be happy), so the crew's heart (job_visits.is_flagged)
 * is NOT a gate here any more (changed 2026-10-05). The heart only decides whether
 * the job's photos go to the public portfolio and whether Mia features it in posts.
 * A private "tell us if something wasn't right" line sits alongside the review
 * link, never instead of it. No incentive is ever offered for a review.
 *
 * Called from both completion paths: pow-actions.php end_visit (web crew) and
 * VisitLifecycleService::updateVisitStatus() (timer clock-out / iOS / JWT).
 *
 * Delivery:
 *   - Email (always, if contact has an email address)
 *   - SMS   (only if contact has SMS consent + a phone number)
 *
 * Rate limits:
 *   - 30-day cooldown per contact (review_request_sent_at)
 *   - Max 3 total requests per contact (review_request_sent_count)
 *   - Respect review_request_opted_out flag
 *   - Never to an address on marketing_unsubscribes
 *
 * Contact lookup chain:
 *   job_visits.plan_id → job_plans.property_id → properties.site_contact_id → contacts
 *
 * SMS rules (CRITICAL — carrier gateway compliance):
 *   - Plain text only, ≤ 160 chars
 *   - NO URLs of any kind (carriers block silently)
 *   - Direct customer to check email for the actual link
 *   - Always include (778) 846-9273 as fallback contact
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

class ReviewRequestService
{
    /** Days between review requests to the same contact */
    private const COOLDOWN_DAYS = 30;

    /** Maximum lifetime review requests per contact */
    private const MAX_REQUESTS = 3;

    /**
     * Maybe send a review request after a visit completes.
     *
     * Silently returns if the contact is ineligible or any lookup fails.
     * Errors are logged but never thrown — this must not break the end_visit flow.
     *
     * @param int  $visitId        The completed job_visits.id
     * @param PDO  $db             Active database connection
     */
    public static function maybeSend(int $visitId, PDO $db): void
    {
        try {
            // ── 1. Only completed visits — and every one of them (no quality gate) ─
            if (!self::isVisitCompleted($visitId, $db)) {
                return;
            }

            // ── 2. Resolve contact from visit ────────────────────────────────
            $contact = self::resolveContact($visitId, $db);
            if (!$contact) {
                return; // No contact attached to this visit
            }

            // ── 3. Eligibility checks ─────────────────────────────────────────
            if (!self::isEligible($contact)) {
                return;
            }
            if (self::isUnsubscribed((string)$contact['email'], $db)) {
                return;
            }

            // ── 4. Get Google Review URL ──────────────────────────────────────
            $reviewUrl = self::getReviewUrl($db);
            if (!$reviewUrl) {
                error_log("ReviewRequestService: google_review_url not set in ops_settings — skipping");
                return;
            }

            // ── 5. Send email (required) ──────────────────────────────────────
            $emailSent = false;
            if (!empty($contact['email'])) {
                $emailResult = self::sendReviewEmail($contact, $reviewUrl);
                $emailSent = $emailResult['success'] ?? false;
                if (!$emailSent) {
                    error_log("ReviewRequestService: email failed for contact #{$contact['id']}: " . ($emailResult['error'] ?? 'unknown'));
                }
            }

            // ── 6. Send SMS (optional — consent required) ─────────────────────
            $smsSent = false;
            $smsPhone = self::getSmsPhone($contact);
            if ($smsPhone && hasSmConsent((int)$contact['id'])) {
                $smsResult = self::sendReviewSms($contact, $smsPhone);
                $smsSent = $smsResult['success'] ?? false;
                if (!$smsSent) {
                    error_log("ReviewRequestService: SMS failed for contact #{$contact['id']}: " . json_encode($smsResult['errors'] ?? []));
                }
            }

            // ── 7. Update records ─────────────────────────────────────────────
            if ($emailSent || $smsSent) {
                self::recordSent($visitId, (int)$contact['id'], $db);
            }

        } catch (Throwable $e) {
            // Never break the end_visit flow
            error_log("ReviewRequestService: unexpected error for visit #{$visitId}: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Look up the contact associated with a visit via:
     *   job_visits → job_plans → properties → contacts
     *
     * @return array|null Contact row or null if not found
     */
    private static function resolveContact(int $visitId, PDO $db): ?array
    {
        $stmt = $db->prepare("
            SELECT
                c.id,
                c.first_name,
                c.last_name,
                c.email,
                c.phone,
                c.mobile,
                c.is_active,
                c.receive_sms,
                c.consent_sms,
                c.review_request_sent_at,
                c.review_request_sent_count,
                c.review_request_opted_out,
                c.has_reviewed
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

    /**
     * Check whether this contact should receive a review request.
     */
    public static function isEligible(array $contact, ?DateTimeImmutable $now = null): bool
    {
        // Permanent stop: client has already left a Google review
        if (!empty($contact['has_reviewed'])) {
            return false;
        }

        // Must be active
        if (empty($contact['is_active'])) {
            return false;
        }

        // Must have at least an email address
        if (empty($contact['email'])) {
            return false;
        }

        // Opted out
        if (!empty($contact['review_request_opted_out'])) {
            return false;
        }

        // Rate limit
        if ((int)($contact['review_request_sent_count'] ?? 0) >= self::MAX_REQUESTS) {
            return false;
        }

        // Cooldown
        if (!empty($contact['review_request_sent_at'])) {
            $lastSent = new DateTimeImmutable($contact['review_request_sent_at']);
            $cooldown = new DateInterval('P' . self::COOLDOWN_DAYS . 'D');
            $nextEligible = $lastSent->add($cooldown);
            if (($now ?? new DateTimeImmutable()) < $nextEligible) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fetch the Google Review URL from ops_settings.
     */
    private static function getReviewUrl(PDO $db): ?string
    {
        $stmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'google_review_url'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $url = trim($row['setting_value'] ?? '');
        return $url ?: null;
    }

    /**
     * Get the best phone number for SMS delivery.
     * Prefers mobile over phone.
     */
    private static function getSmsPhone(array $contact): ?string
    {
        $mobile = trim($contact['mobile'] ?? '');
        $phone  = trim($contact['phone']  ?? '');
        return $mobile ?: ($phone ?: null);
    }

    /**
     * Send the review request email with branded HTML.
     */
    private static function sendReviewEmail(array $contact, string $reviewUrl): array
    {
        $firstName = htmlspecialchars($contact['first_name'] ?? 'Valued Customer', ENT_QUOTES, 'UTF-8');
        $reviewUrlSafe = htmlspecialchars($reviewUrl, ENT_QUOTES, 'UTF-8');

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f7faf8;padding:24px 0;">

          <!-- Header -->
          <div style="background:#1A5F4A;border-radius:10px 10px 0 0;padding:28px 32px;">
            <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;letter-spacing:-0.3px;">
              How did we do, ' . $firstName . '?
            </h1>
            <p style="color:#b2d8c9;margin:8px 0 0;font-size:14px;">
              One minute, and it helps the next person deciding whether to call us.
            </p>
          </div>

          <!-- Body -->
          <div style="background:#fff;border:1px solid #dce8e2;border-top:none;border-radius:0 0 10px 10px;padding:32px;">

            <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 18px;">
              Thanks for having us at the property today.
            </p>
            <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 28px;">
              A short Google review, whatever you thought of the work, helps the next
              council or homeowner deciding whether to call us. It takes about a minute.
            </p>

            <!-- CTA button -->
            <p style="text-align:center;margin:0 0 28px;">
              <a href="' . $reviewUrlSafe . '"
                 style="display:inline-block;padding:16px 36px;background:#2D8659;color:#fff;
                        text-decoration:none;border-radius:8px;font-weight:700;font-size:16px;
                        letter-spacing:0.2px;">
                Write a Google review
              </a>
            </p>

            <p style="color:#9CA3AF;font-size:13px;text-align:center;margin:0 0 4px;">
              Or copy this link: <span style="color:#2D8659;">' . $reviewUrlSafe . '</span>
            </p>

            <hr style="border:none;border-top:1px solid #f0f0f0;margin:28px 0;">

            <p style="color:#6B7280;font-size:13px;line-height:1.5;margin:0;">
              If something wasn&rsquo;t right, you can also reply to this email or call
              <strong>(778) 846-9273</strong> and we&rsquo;ll put it right.
            </p>
          </div>

          <!-- Footer -->
          <p style="text-align:center;color:#9CA3AF;font-size:12px;margin:20px 0 0;">
            &copy; Mowology Landscaping &mdash; Greater Vancouver, BC
          </p>

        </div>';

        return sendEmail(
            $contact['email'],
            'How did the visit go, ' . ($contact['first_name'] ?: 'there') . '?',
            $html,
            null,
            'Mowology'
        );
    }

    /**
     * Send the review request SMS.
     *
     * STRICT rules:
     *  - Plain text, ≤ 160 chars
     *  - NO URLs (carrier email-to-SMS gateways drop messages with links)
     *  - Direct customer to check email for the link
     */
    private static function sendReviewSms(array $contact, string $phone): array
    {
        $firstName = $contact['first_name'] ?? '';

        return sendSms($phone, self::smsText($firstName), 'Mowology');
    }

    /** The review text: plain ASCII, no URL, no exclamation marks, ≤160 characters. */
    public static function smsText(string $firstName): string
    {
        $firstName = trim(preg_replace('/[^\x20-\x7E]/', '', $firstName));
        $greeting = $firstName ? "Hi {$firstName}," : "Hi,";
        $body = "{$greeting} thanks for having Mowology out. "
              . "Check your email for a link to leave a Google review. "
              . "Questions? Call (778) 846-9273";

        if (mb_strlen($body) > 160) { // a very long first name: drop it rather than cut the phone number
            $body = "Hi, thanks for having Mowology out. Check your email for a link to leave a Google review. Questions? Call (778) 846-9273";
        }
        return $body;
    }

    /** Only completed visits get a request (both completion paths call this after the status write). */
    private static function isVisitCompleted(int $visitId, PDO $db): bool
    {
        $stmt = $db->prepare('SELECT status FROM job_visits WHERE id = ? LIMIT 1');
        $stmt->execute([$visitId]);
        return $stmt->fetchColumn() === 'completed';
    }

    /** Unsubscribes always block, even for a review request. */
    private static function isUnsubscribed(string $email, PDO $db): bool
    {
        try {
            $s = $db->prepare('SELECT 1 FROM marketing_unsubscribes WHERE email = ? LIMIT 1');
            $s->execute([strtolower(trim($email))]);
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) {
            return false; // no table → nobody unsubscribed
        }
    }

    /**
     * Mark the request as sent on both the contact and the visit.
     */
    private static function recordSent(int $visitId, int $contactId, PDO $db): void
    {
        $db->prepare("
            UPDATE contacts
            SET review_request_sent_at    = NOW(),
                review_request_sent_count = review_request_sent_count + 1
            WHERE id = ?
        ")->execute([$contactId]);

        $db->prepare("
            UPDATE job_visits
            SET review_request_sent_at = NOW()
            WHERE id = ?
        ")->execute([$visitId]);
    }
}
