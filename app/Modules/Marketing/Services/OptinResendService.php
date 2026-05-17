<?php
declare(strict_types=1);

/**
 * OptinResendService
 *
 * Drives the one-off "Oops" opt-in confirmation resend. The original
 * marketing opt-in email shipped with a broken confirmation link
 * (/crm/api/optin-confirm.php — a manual shim that 404s after a cPanel
 * resync). Every recipient must get a fresh email, with a freshly
 * minted token, pointing at the canonical /optin-confirm.php handler.
 *
 * This rides the existing campaign infrastructure (marketing_campaigns
 * + campaign_sends) for recipient tracking and progress, but does NOT
 * use the generic campaign_sender template merge — opt-in needs a
 * unique token per recipient, which the generic sender cannot produce.
 *
 * No namespace — loaded via require_once (consistent with the rest of
 * app/Modules/[Module]/Services/). PHP 7.4 safe.
 */
class OptinResendService
{
    /** Marks the resend campaign so the generic campaign_sender skips it. */
    public const TRIGGER_TYPE = 'optin_resend';

    /** Stable campaign name — used to find/avoid duplicate campaigns. */
    public const CAMPAIGN_NAME = 'Opt-In Resend — Oops Fix (May 2026)';

    public const SUBJECT = 'Oops — please confirm your email';

    /** Send window: 10:00 inclusive .. 14:00 exclusive (server time). */
    public const WINDOW_START_HOUR = 10;
    public const WINDOW_END_HOUR   = 14;

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Recipients
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Everyone who was sent the original (broken) opt-in email and still
     * needs to confirm.
     *
     * Source of truth: marketing_optin_tokens. The original send went out
     * via optin.php sendOptInEmail(), which writes one row per contact
     * with sent_at set. We resend to anyone whose latest token was sent
     * but is still 'pending' or 'expired' (the broken link meant almost
     * nobody could confirm). CASL: exclude unsubscribes and anyone who
     * somehow already confirmed.
     *
     * @return array<int,array{contact_id:int,email:string,first_name:string}>
     */
    public function findRecipients(): array
    {
        $sql = "
            SELECT c.id AS contact_id, c.email, c.first_name
            FROM contacts c
            JOIN marketing_optin_tokens mot
                  ON mot.id = (
                      SELECT m2.id FROM marketing_optin_tokens m2
                      WHERE m2.contact_id = c.id
                      ORDER BY m2.created_at DESC LIMIT 1
                  )
            WHERE c.is_active = 1
              AND c.email IS NOT NULL AND c.email <> ''
              AND mot.sent_at IS NOT NULL
              AND mot.status IN ('pending','expired')
              AND NOT EXISTS (
                  SELECT 1 FROM marketing_optin_tokens mc
                  WHERE mc.contact_id = c.id AND mc.status = 'confirmed'
              )
              AND NOT EXISTS (
                  SELECT 1 FROM marketing_unsubscribes mu
                  WHERE mu.email = c.email
              )
            ORDER BY c.id ASC
        ";
        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $email = strtolower(trim((string)$r['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $out[] = [
                'contact_id' => (int)$r['contact_id'],
                'email'      => $email,
                'first_name' => (string)($r['first_name'] ?? ''),
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Campaign lifecycle
    // ─────────────────────────────────────────────────────────────────────

    /** @return array|null The optin_resend campaign row, or null. */
    public function findCampaign(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM marketing_campaigns
             WHERE trigger_type = ? AND name = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([self::TRIGGER_TYPE, self::CAMPAIGN_NAME]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create the resend campaign in 'scheduled' status and queue every
     * recipient as a pending campaign_send. Nothing is sent here.
     * 'scheduled' is deliberately NOT 'sending' — the sender cron treats
     * 'scheduled' as preview/dry-run. Going live is an explicit, separate
     * status flip to 'sending'.
     *
     * Idempotent: if the campaign already exists it is returned as-is.
     *
     * @return array{campaign_id:int,queued:int,created:bool}
     */
    public function buildCampaign(): array
    {
        $existing = $this->findCampaign();
        if ($existing) {
            $queued = (int)$this->db->query(
                "SELECT COUNT(*) FROM campaign_sends
                 WHERE campaign_id = " . (int)$existing['id'] . " AND status = 'pending'"
            )->fetchColumn();
            return [
                'campaign_id' => (int)$existing['id'],
                'queued'      => $queued,
                'created'     => false,
            ];
        }

        $recipients = $this->findRecipients();

        $ins = $this->db->prepare(
            "INSERT INTO marketing_campaigns
                (name, segment_type, trigger_type, auto_send, schedule_date,
                 subject_override, body_override, status, recipient_count, created_at)
             VALUES (?, 'custom_list', ?, 0, ?, ?, ?, 'scheduled', ?, NOW())"
        );
        $ins->execute([
            self::CAMPAIGN_NAME,
            self::TRIGGER_TYPE,
            $this->nextWindowOpen()->format('Y-m-d H:i:s'),
            self::SUBJECT,
            '(opt-in resend — body generated per recipient with a fresh token)',
            count($recipients),
        ]);
        $campaignId = (int)$this->db->lastInsertId();

        $sendIns = $this->db->prepare(
            "INSERT INTO campaign_sends (campaign_id, contact_id, email, status, created_at)
             VALUES (?, ?, ?, 'pending', NOW())"
        );
        $queued = 0;
        foreach ($recipients as $r) {
            $sendIns->execute([$campaignId, $r['contact_id'], $r['email']]);
            $queued++;
        }

        return ['campaign_id' => $campaignId, 'queued' => $queued, 'created' => true];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Send window
    // ─────────────────────────────────────────────────────────────────────

    public function isWithinSendWindow(\DateTimeInterface $now): bool
    {
        $h = (int)$now->format('G');
        return $h >= self::WINDOW_START_HOUR && $h < self::WINDOW_END_HOUR;
    }

    /** Next datetime the window opens (today 10:00 if still ahead, else tomorrow). */
    public function nextWindowOpen(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $now = $now ?: new \DateTimeImmutable('now');
        $today10 = $now->setTime(self::WINDOW_START_HOUR, 0, 0);
        if ($now < $today10) {
            return $today10;
        }
        return $today10->modify('+1 day');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Token + email
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Mint a fresh opt-in token for a contact and upsert it into
     * marketing_optin_tokens. Mirrors optin.php sendOptInEmail() exactly:
     * raw token stored (the confirm handler accepts the raw token), 30-day
     * expiry, resent_count incremented on an existing pending row.
     *
     * @return string The raw token to embed in the confirm URL.
     */
    public function issueFreshToken(int $contactId, string $email): string
    {
        $rawToken  = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $existing = $this->db->prepare(
            "SELECT id FROM marketing_optin_tokens
             WHERE contact_id = ? AND status = 'pending'
             ORDER BY created_at DESC LIMIT 1"
        );
        $existing->execute([$contactId]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $this->db->prepare(
                "UPDATE marketing_optin_tokens
                 SET token = ?, email = ?, status = 'pending', expires_at = ?,
                     sent_at = NOW(), resent_count = resent_count + 1, last_resent_at = NOW()
                 WHERE id = ?"
            )->execute([$rawToken, $email, $expiresAt, (int)$existingId]);
        } else {
            $this->db->prepare(
                "INSERT INTO marketing_optin_tokens
                    (contact_id, email, token, status, sent_at, expires_at, created_at)
                 VALUES (?, ?, ?, 'pending', NOW(), ?, NOW())"
            )->execute([$contactId, $email, $rawToken, $expiresAt]);
        }

        return $rawToken;
    }

    public function confirmUrl(string $rawToken): string
    {
        $base = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
        return rtrim($base, '/') . '/optin-confirm.php?token=' . urlencode($rawToken);
    }

    /**
     * Branded "Oops" confirmation email. Acknowledges the prior broken
     * link and gives a fresh working button. Wrapped in the standard
     * branded shell (requires TemplateRenderer wrapInBrandedEmail()).
     */
    public function buildOopsEmail(string $firstName, string $confirmUrl, string $unsubUrl = ''): string
    {
        $name = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');

        $body = '<h2 style="margin:0 0 12px;color:#1A5F4A;font-size:20px;">Oops — that last link didn\'t work</h2>
<p>Hi ' . $name . ',</p>
<p>We recently emailed you to confirm your marketing email preferences, but the confirmation
button in that message was broken — our apologies.</p>
<p>Here is a fresh link that works. If you\'d like to keep receiving occasional updates about
seasonal services, special offers, and landscaping tips, just confirm below:</p>
<p style="margin:24px 0;">
  <a href="' . $href . '"
     style="background:#2D8659;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:600;display:inline-block;">
    &#10003; Yes, keep me subscribed
  </a>
</p>
<p style="color:#666;font-size:13px;">If you do not wish to receive marketing emails, simply ignore this message — you will not be added.</p>
<p style="color:#666;font-size:13px;">This link expires in 30 days. Questions? Call us at (604) 358-1818.</p>';

        if (function_exists('wrapInBrandedEmail')) {
            return wrapInBrandedEmail($body, $unsubUrl);
        }
        return $body;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Batch processing
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Process up to $batchSize pending sends for the given campaign.
     * For each: mint a fresh token, build the Oops email, send it via
     * $sender, record outcome, log activity. When no pending sends
     * remain, the campaign is marked 'completed'.
     *
     * @param callable $sender fn(string $to, string $subject, string $html): array{success:bool,error?:string}
     * @return array{sent:int,failed:int,remaining:int,completed:bool}
     */
    public function processBatch(int $campaignId, int $batchSize, callable $sender): array
    {
        $stmt = $this->db->prepare(
            "SELECT cs.id, cs.contact_id, cs.email, c.first_name
             FROM campaign_sends cs
             LEFT JOIN contacts c ON c.id = cs.contact_id
             WHERE cs.campaign_id = ? AND cs.status = 'pending'
             ORDER BY cs.id ASC
             LIMIT " . (int)$batchSize
        );
        $stmt->execute([$campaignId]);
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pending)) {
            $this->markCompleted($campaignId);
            return ['sent' => 0, 'failed' => 0, 'remaining' => 0, 'completed' => true];
        }

        $sent = 0;
        $failed = 0;

        foreach ($pending as $row) {
            $sendId    = (int)$row['id'];
            $contactId = (int)$row['contact_id'];
            $email     = strtolower(trim((string)$row['email']));
            $firstName = (string)($row['first_name'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->db->prepare(
                    "UPDATE campaign_sends SET status='failed', error_message='Invalid email' WHERE id=?"
                )->execute([$sendId]);
                $failed++;
                continue;
            }

            $rawToken   = $this->issueFreshToken($contactId, $email);
            $confirmUrl = $this->confirmUrl($rawToken);
            $unsubUrl   = function_exists('generateUnsubscribeUrl')
                ? generateUnsubscribeUrl($email, $sendId)
                : '';
            $html = $this->buildOopsEmail($firstName, $confirmUrl, $unsubUrl);

            $result = $sender($email, self::SUBJECT, $html);

            if (!empty($result['success'])) {
                $this->db->prepare(
                    "UPDATE campaign_sends SET status='sent', sent_at=NOW() WHERE id=?"
                )->execute([$sendId]);
                $sent++;

                try {
                    $this->db->prepare(
                        "INSERT INTO communication_log
                            (contact_id, type, direction, subject, message, to_email, status, created_by, created_at)
                         VALUES (?, 'email', 'outbound', ?, ?, ?, 'sent', NULL, NOW())"
                    )->execute([
                        $contactId,
                        self::SUBJECT,
                        'Campaign: ' . self::CAMPAIGN_NAME,
                        $email,
                    ]);
                } catch (\Exception $e) { /* non-fatal */ }

                try {
                    $this->db->prepare(
                        "INSERT INTO activity_log (contact_id, action, details, created_at)
                         VALUES (?, 'campaign_email', ?, NOW())"
                    )->execute([
                        $contactId,
                        'Opt-in resend ("Oops") email sent',
                    ]);
                } catch (\Exception $e) { /* non-fatal */ }
            } else {
                $err = isset($result['error']) ? (string)$result['error'] : 'Unknown send failure';
                $this->db->prepare(
                    "UPDATE campaign_sends SET status='failed', error_message=? WHERE id=?"
                )->execute([substr($err, 0, 500), $sendId]);
                $failed++;
            }

            usleep(100000); // 100ms — gentle on shared-hosting SMTP
        }

        $this->db->prepare(
            "UPDATE marketing_campaigns
             SET sent_count = (SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = ? AND status = 'sent')
             WHERE id = ?"
        )->execute([$campaignId, $campaignId]);

        $remaining = (int)$this->db->query(
            "SELECT COUNT(*) FROM campaign_sends
             WHERE campaign_id = " . (int)$campaignId . " AND status = 'pending'"
        )->fetchColumn();

        $completed = false;
        if ($remaining === 0) {
            $this->markCompleted($campaignId);
            $completed = true;
        }

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining, 'completed' => $completed];
    }

    private function markCompleted(int $campaignId): void
    {
        $this->db->prepare(
            "UPDATE marketing_campaigns
             SET status='completed',
                 sent_count  = (SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=? AND status='sent'),
                 open_count  = (SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=? AND opened_at IS NOT NULL),
                 click_count = (SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=? AND clicked_at IS NOT NULL)
             WHERE id=? AND status <> 'completed'"
        )->execute([$campaignId, $campaignId, $campaignId, $campaignId]);
    }
}
