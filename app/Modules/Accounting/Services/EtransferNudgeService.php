<?php
/**
 * EtransferNudgeService — Penny's one-time "please use info@" thank-you.
 *
 * A client who e-Transfers to an address WITHOUT Auto-deposit makes Tim claim the
 * money with a security answer (EtransferInboxService::parseInteracEmail() calls these
 * transfer_type 'claim'). Once the payer is known, Penny emails them a thank-you that
 * asks them to update the saved recipient to info@mowology.ca (Auto-deposit on).
 *
 * Rules (owner, 2026-10-08):
 *   - automatic, no review step; email only (SMS can't carry an address — CLAUDE.md rule 11)
 *   - once per payer, ever: never again for the same email address, contact or sender
 *   - never for Auto-deposit transfers, Yardi EFTs, or 'unknown'-type notifications
 *     (we only nudge when the email itself proves the money had to be claimed)
 *   - never without an email on file, never to the business itself
 *   - payer not identifiable at ingest → deferred: fires when the owner records/links
 *     the transfer to an invoice (EtransferInboxService::recordPayment / mergeAlreadyRecorded)
 *   - kill switch: ops_settings.penny_etransfer_nudge_enabled ('1' default, '0' = off)
 *
 * Log: etransfer_address_nudges (migration 1301). The row is claimed BEFORE the send
 * (unique email_key) so two pollers can't both email; a failed send deletes its claim
 * so the next transfer can try again. No table → nothing is sent.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class EtransferNudgeService
{
    public const SETTING = 'penny_etransfer_nudge_enabled';
    public const AUTODEPOSIT_ADDRESS = 'info@mowology.ca';
    /** Don't thank someone for a transfer that's older than this (backlog clean-ups). */
    public const MAX_AGE_DAYS = 30;

    private PDO $db;
    /** @var callable(string $to, string $subject, string $html): bool */
    private $mailer;
    /** @var callable(int $invoiceId): array */
    private $recipientResolver;

    public function __construct(PDO $db, ?callable $mailer = null, ?callable $recipientResolver = null)
    {
        $this->db = $db;
        $this->mailer = $mailer ?? [$this, 'defaultMailer'];
        $this->recipientResolver = $recipientResolver ?? [$this, 'defaultRecipients'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Entry points
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called after a notification is ingested, recorded or merged. Never throws.
     * @return array{sent:bool,reason:string}
     */
    public function onNotification(int $notificationId, string $trigger = 'ingest'): array
    {
        try {
            $note = $this->loadNote($notificationId);
            if (!$note) return ['sent' => false, 'reason' => 'not_found'];
            $plan = $this->plan($note);
            if (!$plan['send']) return ['sent' => false, 'reason' => $plan['reason']];
            return $this->send($note, $plan, $trigger);
        } catch (Throwable $e) {
            error_log('[etransfer-nudge] ' . $e->getMessage());
            return ['sent' => false, 'reason' => 'error'];
        }
    }

    /**
     * Read-only: what would happen for each recent claim-type transfer.
     * @return array<int,array>
     */
    public function dryRun(int $days = 60): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM etransfer_notifications
              WHERE transfer_type = 'claim' AND email_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
              ORDER BY email_date DESC, id DESC LIMIT 100"
        );
        $stmt->execute([max(1, min(365, $days))]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $note) {
            $plan = $this->plan($note);
            $out[] = [
                'notification_id' => (int)$note['id'],
                'date'            => $note['email_date'],
                'sender'          => $note['sender_name'],
                'amount'          => $note['amount'] !== null ? (float)$note['amount'] : null,
                'memo'            => $note['memo'],
                'status'          => $note['status'],
                'would_send'      => $plan['send'],
                'reason'          => $plan['reason'],
                'to'              => $plan['to'] ?? null,
                'contact_id'      => $plan['contact_id'] ?? null,
                'subject'         => $plan['subject'] ?? null,
                'body'            => $plan['text'] ?? null,
            ];
        }
        return $out;
    }

    /** How many clients Penny has asked (Penny card fact). 0 before migration 1301. */
    public function countSent(): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM etransfer_address_nudges WHERE status = 'sent'")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function enabled(): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([self::SETTING]);
            $v = $stmt->fetchColumn();
            return $v === false || $v === null || trim((string)$v) !== '0';
        } catch (Throwable $e) {
            return true; // no ops_settings: default on (the log table still gates sending)
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Decision (reads only)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Should this notification trigger the email, and with what text?
     * @return array{send:bool,reason:string,to?:string,contact_id?:int,first_name?:string,invoice_id?:?int,invoice_number?:?string,subject?:string,text?:string}
     */
    public function plan(array $note): array
    {
        if (!$this->enabled()) return ['send' => false, 'reason' => 'switched_off'];
        if (!self::isManualInterac($note)) return ['send' => false, 'reason' => 'not_manual'];

        $amount = (float)($note['amount'] ?? 0);
        if ($amount <= 0) return ['send' => false, 'reason' => 'no_amount'];
        if (self::isBusinessName((string)($note['sender_name'] ?? ''))) return ['send' => false, 'reason' => 'business_itself'];

        $when = !empty($note['email_date']) ? strtotime((string)$note['email_date']) : false;
        if ($when && $when < time() - self::MAX_AGE_DAYS * 86400) return ['send' => false, 'reason' => 'too_old'];

        if ($this->senderSwitchedAfter($note)) return ['send' => false, 'reason' => 'already_uses_autodeposit'];

        // Who paid: the invoice this transfer was recorded against / hard-matched to;
        // else the invoice this same sender's earlier transfer was recorded against.
        [$invoiceId, $invoiceKnown] = $this->identifyInvoice($note);
        if (!$invoiceId) return ['send' => false, 'reason' => 'payer_unknown'];

        $recipient = $this->pickRecipient(($this->recipientResolver)($invoiceId));
        if (!$recipient) return ['send' => false, 'reason' => 'no_email'];
        if (self::isBusinessEmail($recipient['email'])) return ['send' => false, 'reason' => 'business_itself'];

        if ($this->alreadyNudged($recipient['email'], $recipient['contact_id'], (string)($note['sender_name'] ?? ''))) {
            return ['send' => false, 'reason' => 'already_asked'];
        }

        $invoiceNumber = $invoiceKnown ? $this->invoiceNumber($invoiceId) : null;
        $memoHadInvoice = !empty($note['invoice_hint'])
            || EtransferInboxService::extractInvoiceNumber((string)($note['memo'] ?? '')) !== null;
        $firstName = $this->firstName($recipient['contact_id']);
        $msg = self::render($firstName, $amount, $invoiceNumber, $memoHadInvoice);

        return [
            'send'           => true,
            'reason'         => 'ok',
            'to'             => $recipient['email'],
            'contact_id'     => $recipient['contact_id'],
            'first_name'     => $firstName,
            'invoice_id'     => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'subject'        => $msg['subject'],
            'text'           => $msg['text'],
        ];
    }

    /** Claim-type Interac notification (the money had to be accepted by hand). Pure. */
    public static function isManualInterac(array $note): bool
    {
        $source = (string)($note['source'] ?? 'interac');
        return ($note['transfer_type'] ?? '') === 'claim' && ($source === '' || $source === 'interac');
    }

    public static function isBusinessEmail(string $email): bool
    {
        return (bool)preg_match('/@(?:[a-z0-9-]+\.)*mowology\.ca$/i', trim($email));
    }

    public static function isBusinessName(string $name): bool
    {
        return stripos($name, 'mowology') !== false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Copy (pure, unit tested) — Tim's wording, 2026-10-08. Do not paraphrase.
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{subject:string,text:string} */
    public static function render(string $firstName, float $amount, ?string $invoiceNumber, bool $memoHadInvoice): array
    {
        $first = trim($firstName) !== '' ? trim($firstName) : 'there';
        $money = '$' . number_format($amount, 2);
        $sign  = "Thank you,\nPenny\nMowology Lawns & Landscapes\n(778) 846-9273";
        $addr  = self::AUTODEPOSIT_ADDRESS;
        $askInvoice = "If you can add the invoice number in the message, we'll match it to the right invoice faster.";

        if ($invoiceNumber !== null && $invoiceNumber !== '') {
            $keep = $memoHadInvoice
                ? 'Please keep the invoice number in the message, as you did this time. It\'s the quickest way for us to match it.'
                : $askInvoice;
            return [
                'subject' => "Thank you for your payment — {$invoiceNumber}",
                'text'    => "Hi {$first},\n\n"
                           . "Thank you for your e-Transfer of {$money} for {$invoiceNumber}. It's received.\n\n"
                           . "One small favour for next time. Could you update the recipient in your banking to {$addr}? "
                           . "That address has Auto-deposit turned on, so there's no security question or password to set. "
                           . "Your payment lands straight away and we can match it to your invoice faster.\n\n"
                           . "{$keep}\n\n{$sign}",
            ];
        }
        return [
            'subject' => 'Thank you for your payment',
            'text'    => "Hi {$first},\n\n"
                       . "Thank you for your e-Transfer of {$money}. It's received.\n\n"
                       . "One small favour for next time. Could you update the recipient in your banking to {$addr}? "
                       . "That address has Auto-deposit turned on, so there's no security question or password to set, "
                       . "and your payment lands straight away.\n\n"
                       . "{$askInvoice}\n\n{$sign}",
        ];
    }

    /** Plain paragraphs → minimal HTML (sendCrmEmail takes HTML). */
    public static function toHtml(string $text): string
    {
        $paras = preg_split("/\n{2,}/", trim($text));
        $html = '';
        foreach ($paras as $p) {
            $html .= '<p style="margin:0 0 14px;">' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        return '<div style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1a1a1a;">' . $html . '</div>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sending + logging
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{sent:bool,reason:string} */
    private function send(array $note, array $plan, string $trigger): array
    {
        $emailKey = mb_substr(strtolower(trim($plan['to'])), 0, 190);
        try {
            $this->db->prepare(
                "INSERT INTO etransfer_address_nudges
                   (notification_id, contact_id, email, email_key, sender_key, sender_name, invoice_id, invoice_number,
                    amount, trigger_source, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?, 'sending', NOW())"
            )->execute([
                (int)$note['id'], $plan['contact_id'] ?: null, $plan['to'], $emailKey,
                self::senderKey((string)($note['sender_name'] ?? '')) ?: null,
                $note['sender_name'] !== null ? mb_substr((string)$note['sender_name'], 0, 255) : null,
                $plan['invoice_id'] ?: null, $plan['invoice_number'],
                round((float)$note['amount'], 2), mb_substr($trigger, 0, 20),
            ]);
        } catch (Throwable $e) {
            // Duplicate key (someone else claimed it) or no table yet: never send unlogged.
            return ['sent' => false, 'reason' => 'claim_failed'];
        }
        $logId = (int)$this->db->lastInsertId();

        $ok = false;
        try {
            $ok = (bool)($this->mailer)($plan['to'], $plan['subject'], self::toHtml($plan['text']));
        } catch (Throwable $e) {
            error_log('[etransfer-nudge] send failed: ' . $e->getMessage());
        }

        if (!$ok) {
            $this->db->prepare("DELETE FROM etransfer_address_nudges WHERE id = ?")->execute([$logId]);
            return ['sent' => false, 'reason' => 'send_failed'];
        }

        $this->db->prepare("UPDATE etransfer_address_nudges SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$logId]);

        // Contact timeline.
        if (!empty($plan['contact_id'])) {
            try {
                $this->db->prepare(
                    "INSERT INTO communication_log (contact_id, type, direction, subject, message, to_email, status, created_by, created_at)
                     VALUES (?, 'email', 'outbound', ?, ?, ?, 'sent', NULL, NOW())"
                )->execute([(int)$plan['contact_id'], $plan['subject'], "Penny (automatic): asked to e-Transfer to info@ next time\n\n" . $plan['text'], $plan['to']]);
            } catch (Throwable $e) {
                error_log('[etransfer-nudge] timeline log failed: ' . $e->getMessage());
            }
        }
        return ['sent' => true, 'reason' => 'sent'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lookups
    // ─────────────────────────────────────────────────────────────────────────

    private function loadNote(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM etransfer_notifications WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array{0:?int,1:bool} [invoice id, is it THIS transfer's invoice] */
    private function identifyInvoice(array $note): array
    {
        if (!empty($note['recorded_invoice_id'])) return [(int)$note['recorded_invoice_id'], true];
        if (!empty($note['matched_invoice_id']) && ($note['match_confidence'] ?? '') === 'high') {
            return [(int)$note['matched_invoice_id'], true];
        }
        // Learned: this sender's earlier transfer was recorded against an invoice → same payer.
        $sender = trim((string)($note['sender_name'] ?? ''));
        if ($sender === '') return [null, false];
        $stmt = $this->db->prepare(
            "SELECT recorded_invoice_id FROM etransfer_notifications
              WHERE sender_name = ? AND id <> ? AND recorded_invoice_id IS NOT NULL
                AND status IN ('recorded', 'partially_recorded')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$sender, (int)$note['id']]);
        $prev = $stmt->fetchColumn();
        return $prev ? [(int)$prev, false] : [null, false];
    }

    /** A later Auto-deposit transfer from the same sender: they've already switched. */
    private function senderSwitchedAfter(array $note): bool
    {
        $sender = trim((string)($note['sender_name'] ?? ''));
        if ($sender === '') return false;
        $stmt = $this->db->prepare(
            "SELECT 1 FROM etransfer_notifications
              WHERE sender_name = ? AND transfer_type = 'autodeposit' AND id <> ?
                AND (email_date >= ? OR ? IS NULL) LIMIT 1"
        );
        $date = $note['email_date'] ?? null;
        $stmt->execute([$sender, (int)$note['id'], $date, $date]);
        return (bool)$stmt->fetchColumn();
    }

    /** @param array<int,array> $recipients from resolveReminderRecipients() */
    private function pickRecipient(array $recipients): ?array
    {
        foreach ($recipients as $r) {
            $email = trim((string)($r['email_address'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['email' => $email, 'contact_id' => (int)($r['contact_id'] ?? 0)];
            }
        }
        return null;
    }

    private function alreadyNudged(string $email, int $contactId, string $sender): bool
    {
        $senderKey = self::senderKey($sender);
        $stmt = $this->db->prepare(
            "SELECT 1 FROM etransfer_address_nudges
              WHERE email_key = ? OR (? > 0 AND contact_id = ?) OR (? <> '' AND sender_key = ?) LIMIT 1"
        );
        $stmt->execute([strtolower(trim($email)), $contactId, $contactId, $senderKey, $senderKey]);
        return (bool)$stmt->fetchColumn();
    }

    private function invoiceNumber(int $invoiceId): ?string
    {
        $stmt = $this->db->prepare("SELECT invoice_number FROM invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $n = $stmt->fetchColumn();
        return $n ? (string)$n : null;
    }

    private function firstName(int $contactId): string
    {
        if ($contactId <= 0) return '';
        $stmt = $this->db->prepare("SELECT first_name FROM contacts WHERE id = ? LIMIT 1");
        $stmt->execute([$contactId]);
        return trim((string)($stmt->fetchColumn() ?: ''));
    }

    private static function senderKey(string $name): string
    {
        return mb_substr(strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $name)), 0, 190);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Production defaults (tests inject their own)
    // ─────────────────────────────────────────────────────────────────────────

    public function defaultMailer(string $to, string $subject, string $html): bool
    {
        if (!function_exists('sendCrmEmail') && defined('APP_ROOT')) {
            require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
        }
        return function_exists('sendCrmEmail') && sendCrmEmail($to, $subject, $html);
    }

    /** Where this invoice's reminders go: its own send snapshot, PM routing, then its contact. */
    public function defaultRecipients(int $invoiceId): array
    {
        if (!function_exists('resolveReminderRecipients') && defined('APP_ROOT')) {
            require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceRouting.php';
        }
        return function_exists('resolveReminderRecipients') ? resolveReminderRecipients($invoiceId, $this->db) : [];
    }
}
