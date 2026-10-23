<?php
require_once dirname(__DIR__, 3) . '/Services/Messaging/IdentityMail.php';

/**
 * ContractInvoiceSender — emails one monthly contract invoice (contract_billing cron).
 *
 * Extracted from Contracts/Cron/contract_billing.php (2026-10-06) so the "every invoice email
 * carries the PDF" rule is testable and a held invoice can be retried:
 *
 *   send($invoiceId)  → 'sent'   PDF attached, status flipped to 'sent', autopay/SMS/log hooks run
 *                       'held'   no PDF (InvoicePdfGate) — NOT sent, stays 'draft', recorded as
 *                                activity_log 'invoice_pdf_blocked' {context: contract_billing}
 *                       'failed' email delivery failed — stays 'draft' (resend manually, as before)
 *
 *   heldInvoiceIds()  → contract invoices still 'draft' and never sent that were held for a
 *                       missing PDF. The cron retries these at the start of every run.
 *
 * The email (invoice_sent template + bill summary) is unchanged from the inline cron version.
 * Everything it needs is read from the invoice it already wrote: invoice_contacts
 * primary_recipient (the resolved billing email), the contract's contact (greeting + SMS).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 2) . '/Invoices/Services/InvoicePdfGate.php';

class ContractInvoiceSender
{
    public const CONTEXT = 'contract_billing';
    /** Don't resurrect ancient drafts. */
    public const RETRY_WINDOW_DAYS = 120;

    private PDO $db;
    private InvoicePdfGate $gate;
    /** @var callable(string $to, string $subject, string $html, string $attachPath): bool */
    private $mailer;
    /** @var callable(int $invoiceId, array $row): void  autopay + SMS + activity log */
    private $afterSent;

    public function __construct(PDO $db, InvoicePdfGate $gate, ?callable $mailer = null, ?callable $afterSent = null)
    {
        $this->db = $db;
        $this->gate = $gate;
        $this->mailer = $mailer ?? static function (string $to, string $subject, string $html, string $attach): bool {
            return (bool)sendCrmEmail($to, $subject, $html, $attach);
        };
        $this->afterSent = $afterSent ?? [self::class, 'defaultAfterSent'];
    }

    /** @return array{status: string, invoice_number: string, email: ?string, message: string} */
    public function send(int $invoiceId): array
    {
        try {
            $row = $this->load($invoiceId);
        } catch (\Throwable $e) {
            // Couldn't even read it: held, so the next run retries (Codex re-review 2026-10-16).
            try { $this->gate->recordBlocked($invoiceId, self::CONTEXT, 'error loading: ' . mb_substr($e->getMessage(), 0, 200)); } catch (\Throwable $e2) {}
            return ['status' => 'held', 'invoice_number' => '', 'email' => null, 'message' => "invoice {$invoiceId}: not sent yet (could not load) — held, will retry next run"];
        }
        if (!$row) {
            return ['status' => 'failed', 'invoice_number' => '', 'email' => null, 'message' => "invoice {$invoiceId} not found or not a draft"];
        }
        $num   = (string)$row['invoice_number'];
        $email = trim((string)($row['billing_email'] ?? ''));
        if ($email === '') {
            return ['status' => 'failed', 'invoice_number' => $num, 'email' => null, 'message' => "{$num}: no recipient email"];
        }

        // The rule: no PDF, no email, and the invoice stays a draft.
        // Prepaid credit first, so the PDF, the email and autopay all see what is left (Tim 2026-10-14).
        // Anything that throws BEFORE the email goes is held like a missing PDF, so the next run retries it
        // (it used to be committed, never sent and never retried — Codex contract review 2026-10-16).
        try {
            require_once dirname(__DIR__, 2) . '/Accounting/Services/ClientCreditService.php';
            \ClientCreditService::applyOnSend($this->db, $invoiceId);
            $pdf = $this->gate->ensurePdf($invoiceId);
            $mail = $pdf === null ? null : self::buildEmail($row);
        } catch (\Throwable $e) {
            error_log("[contract_billing] {$num} not sent (before the email): " . $e->getMessage());
            $this->gate->recordBlocked($invoiceId, self::CONTEXT, 'error before send: ' . mb_substr($e->getMessage(), 0, 200));
            return ['status' => 'held', 'invoice_number' => $num, 'email' => $email,
                    'message' => "{$num}: not sent yet (" . mb_substr($e->getMessage(), 0, 120) . ') — held, will retry next run'];
        }
        if ($pdf === null) {
            $this->gate->recordBlocked($invoiceId, self::CONTEXT);
            return ['status' => 'held', 'invoice_number' => $num, 'email' => $email,
                    'message' => "{$num}: " . InvoicePdfGate::NOT_SENT_MESSAGE . ' — held, will retry next run'];
        }

        // The file must still be there at the moment of sending (sendEmail drops a missing one silently).
        if (!$this->gate->isUsable($pdf)) {
            $this->gate->recordBlocked($invoiceId, self::CONTEXT, 'PDF vanished before send: ' . $pdf);
            return ['status' => 'held', 'invoice_number' => $num, 'email' => $email,
                    'message' => "{$num}: " . InvoicePdfGate::NOT_SENT_MESSAGE . ' — held, will retry next run'];
        }

        if (!($this->mailer)($email, $mail['subject'], $mail['html'], $pdf)) {
            error_log("[contract_billing] Email failed for invoice {$num} (contract {$row['contract_number']}, email {$email})");
            // Held like a missing PDF: retried next run and visible to Charlie — a failed send used
            // to be lost for the month (next run found the draft and created nothing; review 2026-10-12).
            $this->gate->recordBlocked($invoiceId, self::CONTEXT, 'email failed to ' . $email);
            return ['status' => 'held', 'invoice_number' => $num, 'email' => $email,
                    'message' => "{$row['contract_number']}: invoice {$num} — email failed, held, will retry next run"];
        }

        // sent_at always; status only from draft (an invoice prepaid by credit stays 'paid'). The email has GONE: saving
        // that is retried, and a failure never reports it unsent — a retry next run would email it twice (Codex re-review).
        $marked = false;
        for ($try = 0; $try < 3 && !$marked; $try++) {
            try {
                $this->db->prepare("UPDATE invoices SET sent_at = COALESCE(sent_at, NOW()), status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END WHERE id = ?")
                         ->execute([$invoiceId]);
                $marked = true;
            } catch (\Throwable $e) {
                error_log("[contract_billing] {$num} emailed but not yet marked sent (try " . ($try + 1) . '): ' . $e->getMessage());
                usleep(300000);
            }
        }
        if (!$marked) error_log("[contract_billing] URGENT {$num} was EMAILED but could not be marked sent — mark it sent by hand before the next run");
        try {
            $this->db->prepare("UPDATE invoice_contacts SET invoice_sent_at = NOW() WHERE invoice_id = ?")->execute([$invoiceId]);
        } catch (\Throwable $e) { error_log("[contract_billing] {$num} recipients not stamped: " . $e->getMessage()); }

        try {
            ($this->afterSent)($invoiceId, $row + ['sent_to' => $email]);
        } catch (Throwable $e) {
            // The email went; never report this as unsent (a resend would duplicate it).
            error_log("[contract_billing] post-send hooks failed for {$num}: " . $e->getMessage());
        }

        return ['status' => 'sent', 'invoice_number' => $num, 'email' => $email,
                'message' => "{$row['contract_number']} → {$num} ({$email})"];
    }

    /** Contract invoices held for a missing PDF, still draft and never sent. @return int[] */
    public function heldInvoiceIds(?string $now = null): array
    {
        $since = date('Y-m-d', strtotime(($now ?? date('Y-m-d')) . ' -' . self::RETRY_WINDOW_DAYS . ' days'));
        $s = $this->db->prepare("
            SELECT DISTINCT i.id
            FROM invoices i
            JOIN activity_log al ON al.invoice_id = i.id AND al.action = ?
            JOIN contracts ctr ON ctr.id = i.contract_id AND ctr.status = 'active'   -- never retry a cancelled contract
                 AND (ctr.end_date IS NULL OR ctr.end_date >= DATE_FORMAT(i.invoice_date, '%Y-%m-01'))   -- nor one that has ended (Codex 2026-10-16)
            WHERE i.contract_id IS NOT NULL
              AND i.status IN ('draft', 'paid')   -- a draft, or one prepaid in full by credit that never went (Codex re-review)
              AND i.sent_at IS NULL
              AND i.invoice_date >= ?
              AND al.details LIKE ?
            ORDER BY i.id ASC
        ");
        $s->execute([InvoicePdfGate::BLOCKED_ACTION, $since, '%"context":"' . self::CONTEXT . '"%']);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    private function load(int $invoiceId): ?array
    {
        $s = $this->db->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.access_token,
                   i.contract_id, i.contact_id,
                   ctr.contract_number,
                   -- Greeting and SMS go to whoever the invoice is ADDRESSED to (a property manager's
                   -- billing contact or inbox), not the contract's on-site contact (review 2026-10-12).
                   -- A contactless inbox (email routing) gets no name and no SMS.
                   rc.first_name, rc.last_name, rc.mobile AS contact_mobile, rc.receive_sms,
                   (SELECT ic.email_address FROM invoice_contacts ic
                     WHERE ic.invoice_id = i.id AND ic.contact_role = 'primary_recipient'
                       AND ic.email_address IS NOT NULL AND ic.email_address <> ''
                     ORDER BY ic.id ASC LIMIT 1) AS billing_email
            FROM invoices i
            LEFT JOIN contracts ctr ON ctr.id = i.contract_id
            LEFT JOIN contacts  rc  ON rc.id = (SELECT ic2.contact_id FROM invoice_contacts ic2
                                                 WHERE ic2.invoice_id = i.id AND ic2.contact_role = 'primary_recipient'
                                                   AND ic2.email_address IS NOT NULL AND ic2.email_address <> ''
                                                 ORDER BY ic2.id ASC LIMIT 1)
            WHERE i.id = ? AND i.sent_at IS NULL AND i.status IN ('draft', 'paid')   -- never sent: a draft, or prepaid in full by credit
        ");
        $s->execute([$invoiceId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** The invoice_sent email exactly as the cron built it inline. @return array{subject: string, html: string} */
    public static function buildEmail(array $row): array
    {
        require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
        $num           = (string)$row['invoice_number'];
        $total         = (float)$row['total_amount'];
        $dueDate       = (string)$row['due_date'];
        $monthLabel    = date('F Y', strtotime((string)$row['invoice_date']));
        $recipientName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Valued Customer';
        $firstName     = ($row['first_name'] ?? '') ?: 'there';
        $companyInfo   = EmailWrapper::getCompanyInfo();
        $viewUrl       = _idSiteUrl() . '/customer/invoice.php?token=' . urlencode((string)$row['access_token']);

        $tpl = loadEmailTemplate('invoice_sent', [
            '{{customer_first_name}}' => $firstName,
            '{{customer_name}}'       => $recipientName,
            '{{invoice_number}}'      => $num,
            '{{amount_due}}'          => formatCurrency($total),
            '{{due_date}}'            => formatDate($dueDate),
            '{{company_name}}'        => $companyInfo['company_name'],
            '{{company_phone}}'       => $companyInfo['company_phone'],
        ]);

        $b  = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:460px;margin:0 0 20px;font-size:14px;font-family:\'Helvetica Neue\',Arial,sans-serif;">';
        $b .= '<tr><td style="padding:6px 0;color:#4a6b5d;width:120px;">Invoice #</td><td style="padding:6px 0;color:#0D3B2E;font-weight:700;">' . htmlspecialchars($num) . '</td></tr>';
        $b .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Period</td><td style="padding:6px 0;color:#0D3B2E;">' . htmlspecialchars($monthLabel) . '</td></tr>';
        $b .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Amount Due</td><td style="padding:6px 0;color:#0D3B2E;font-size:18px;font-weight:700;">' . formatCurrency($total) . ' CAD</td></tr>';
        $b .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Due Date</td><td style="padding:6px 0;color:#0D3B2E;">' . formatDate($dueDate) . '</td></tr>';
        $b .= '<tr><td style="padding:6px 0;color:#4a6b5d;vertical-align:top;">Bill To</td><td style="padding:6px 0;color:#0D3B2E;">' . htmlspecialchars($recipientName) . '</td></tr>';
        $b .= '</table>';

        return [
            'subject' => $tpl['subject'],
            'html'    => EmailWrapper::wrap($b . $tpl['body_html'], 'Pay the invoice', $viewUrl, $companyInfo),
        ];
    }

    /** Production hooks after a successful send: autopay, SMS, activity log (unchanged from the cron). */
    public static function defaultAfterSent(int $invoiceId, array $row): void
    {
        $db = getDB();
        $autopayServicePath = APP_ROOT . '/Services/Payments/AutopayService.php';
        if (is_file($autopayServicePath)) {
            require_once $autopayServicePath;
            AutopayService::triggerOnSend($db, $invoiceId, 'contract_billing.php');
        }

        if (!empty($row['receive_sms']) && !empty($row['contact_mobile'])) {
            sendInvoiceNotificationSms($row['contact_mobile'], $row['invoice_number'], (float)$row['total_amount']);
        }

        logActivityExtended(
            0,
            'contract_invoice_generated',
            json_encode([
                'invoice_id'      => $invoiceId,
                'invoice_number'  => $row['invoice_number'],
                'contract_id'     => (int)$row['contract_id'],
                'contract_number' => $row['contract_number'],
                'amount'          => (float)$row['total_amount'],
                'period'          => date('F Y', strtotime((string)$row['invoice_date'])),
                'sent_to'         => $row['sent_to'] ?? null,
                'pdf_attached'    => true,
            ]),
            null,
            null, null,
            $invoiceId
        );
    }
}
