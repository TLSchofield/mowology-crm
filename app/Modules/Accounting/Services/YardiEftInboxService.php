<?php
/**
 * YardiEftInboxService — parse Yardi/Tribe property-management "EFT Payment"
 * remittance emails, match each invoice line to an invoice, and record
 * payments. Rows share the etransfer_notifications table + the "Pending
 * e-Transfers" panel on /crm/invoices with EtransferInboxService, tagged
 * source='yardi_eft', instead of a parallel table + UI.
 *
 * The IMAP fetch lives in the cron poller
 * (app/Modules/Accounting/Cron/yardi_eft_inbox_poll.php); this service holds
 * the parsing + matching + recording logic so it can be unit-tested without a
 * mailbox. parseRemittanceEmail() is a pure static function.
 *
 * Design note (why this DOES auto-record, unlike Interac e-Transfers): a
 * Yardi remittance already names the exact invoice number per line — there's
 * no customer memo to interpret, no "did they add GST" ambiguity. So when a
 * line's invoice number resolves to a payable invoice and the amount exactly
 * matches that invoice's balance due, we record it immediately. Anything
 * short of that (invoice not found, already settled, amount mismatch) still
 * drops a pending row for manual review, the same posture as e-Transfers.
 */
class YardiEftInboxService
{
    private const PAYABLE = ['sent', 'viewed', 'partial', 'overdue'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Parse a Yardi remittance email (subject + plain-text body) into the
     * transaction reference/date and one or more invoice line items.
     *
     * @return array{transaction_reference:?string,transaction_date:?string,total:?float,lines:array<int,array{property:?string,invoice_number:?string,invoice_date:?string,amount:float}>}
     */
    public static function parseRemittanceEmail(string $subject, string $body): array
    {
        $body = str_replace("\r\n", "\n", $body);

        $reference = null;
        if (preg_match('/Transaction Reference No\s*\n?\s*([A-Za-z0-9\-]+)/i', $body, $m)) {
            $reference = trim($m[1]);
        }

        $txnDate = null;
        if (preg_match('/Transaction Date\s*\n?\s*(\d{4}-\d{2}-\d{2})/i', $body, $m)) {
            $txnDate = trim($m[1]);
        }

        $total = null;
        if (preg_match('/\bTotal\s*\n?\s*\$\s*([0-9,]+\.[0-9]{2})/i', $body, $m)) {
            $total = (float) str_replace(',', '', $m[1]);
        }

        // Invoice Details table — plain-text export lines each cell on its own
        // row: Property Name / Invoice # / Invoice Date / Payment Amount,
        // repeated per invoice, terminated by the "Total" line.
        $lines = [];
        if (preg_match('/Invoice Details\s*\n(.*?)\n\s*Total\s*\n/is', $body, $m)) {
            $rows = preg_split('/\n+/', trim($m[1]));
            $rows = array_values(array_filter($rows, static fn($l) => trim($l) !== ''));
            // First 4 non-empty lines are the header labels — skip them.
            $rows = array_slice($rows, 4);
            for ($i = 0; $i + 3 < count($rows); $i += 4) {
                $property = trim($rows[$i]);
                $invNo    = trim($rows[$i + 1]);
                $invDate  = trim($rows[$i + 2]);
                $amtRaw   = trim($rows[$i + 3]);
                if (!preg_match('/^\$?\s*([0-9,]+\.[0-9]{2})$/', $amtRaw, $am)) {
                    continue;
                }
                $lines[] = [
                    'property'       => $property !== '' ? $property : null,
                    'invoice_number' => $invNo !== '' ? $invNo : null,
                    'invoice_date'   => $invDate !== '' ? $invDate : null,
                    'amount'         => (float) str_replace(',', '', $am[1]),
                ];
            }
        }

        return [
            'transaction_reference' => $reference,
            'transaction_date'      => $txnDate,
            'total'                 => $total,
            'lines'                 => $lines,
        ];
    }

    /**
     * Ingest a parsed remittance: for each invoice line, either auto-record
     * (exact invoice-number match + exact amount) or drop a pending row for
     * manual review, reusing etransfer_notifications/InvoiceReconciliationService.
     *
     * @return array{processed:int,auto_recorded:int,pending:int,skipped_duplicate:int}
     */
    public function ingest(array $parsed, string $mailbox, ?string $messageId, ?string $emailSubject, ?string $emailDate, int $systemUserId): array
    {
        $ref     = $parsed['transaction_reference'] ?: ($messageId ?: md5(($emailSubject ?? '') . '|' . ($emailDate ?? '')));
        $dt      = $emailDate ? date('Y-m-d H:i:s', strtotime($emailDate)) : null;
        $result  = ['processed' => 0, 'auto_recorded' => 0, 'pending' => 0, 'skipped_duplicate' => 0];

        foreach ($parsed['lines'] as $line) {
            $result['processed']++;
            $invNo = $line['invoice_number'];
            $dedup = 'yardi:' . $ref . ':' . ($invNo ?: md5(json_encode($line)));

            $chk = $this->db->prepare("SELECT id FROM etransfer_notifications WHERE dedup_key = ? LIMIT 1");
            $chk->execute([$dedup]);
            if ($chk->fetchColumn()) {
                $result['skipped_duplicate']++;
                continue;
            }

            $invoice = null;
            if ($invNo) {
                $stmt = $this->db->prepare(
                    "SELECT id, invoice_number, balance_due, total, status FROM invoices WHERE invoice_number = ? LIMIT 1"
                );
                $stmt->execute([$invNo]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $canAutoRecord = $invoice
                && in_array($invoice['status'], self::PAYABLE, true)
                && abs((float) $invoice['balance_due'] - $line['amount']) < 0.01;

            if ($canAutoRecord) {
                if ($this->autoRecord((int) $invoice['id'], $line['amount'], $ref, $dedup, $systemUserId)) {
                    $result['auto_recorded']++;
                    continue;
                }
                // Fell through (e.g. race with another payment) — drop a pending row instead.
            }

            $method     = $invoice ? 'invoice_number' : 'none';
            $confidence = $invoice ? 'high' : 'none';

            $this->db->prepare(
                "INSERT INTO etransfer_notifications
                   (mailbox, source, dedup_key, reference_number, message_id, sender_name, amount,
                    memo, invoice_hint, transfer_type, email_subject, email_date,
                    matched_invoice_id, match_method, match_confidence, status, created_at)
                 VALUES (?, 'yardi_eft', ?, ?, ?, ?, ?, ?, ?, 'yardi_eft', ?, ?, ?, ?, ?, 'pending', NOW())"
            )->execute([
                $mailbox, $dedup, $ref, $messageId, $line['property'], $line['amount'],
                $invoice ? null : 'Yardi remittance — invoice not found or already settled',
                $invNo, $emailSubject, $dt,
                $invoice ? (int) $invoice['id'] : null, $method, $confidence,
            ]);
            $result['pending']++;
        }

        return $result;
    }

    /** Record one auto-certain line via the shared allocation ledger. Returns success. */
    private function autoRecord(int $invoiceId, float $amount, string $ref, string $dedupKey, int $userId): bool
    {
        require_once __DIR__ . '/InvoiceReconciliationService.php';

        try {
            $this->db->beginTransaction();

            // Log the notification as already-recorded so the dedup key still
            // guards against reprocessing the same line on a later poll.
            $this->db->prepare(
                "INSERT INTO etransfer_notifications
                   (mailbox, source, dedup_key, reference_number, amount, allocated_amount,
                    matched_invoice_id, match_method, match_confidence, transfer_type,
                    status, recorded_invoice_id, recorded_by, created_at, processed_at)
                 VALUES ('yardi_eft', 'yardi_eft', ?, ?, ?, ?, ?, 'invoice_number', 'high', 'yardi_eft',
                         'recorded', ?, ?, NOW(), NOW())"
            )->execute([$dedupKey, $ref, $amount, $amount, $invoiceId, $invoiceId, $userId]);
            $notificationId = (int) $this->db->lastInsertId();

            $recon  = new InvoiceReconciliationService($this->db);
            $result = $recon->applyAllocation($invoiceId, $amount, 'yardi_eft', $ref, date('Y-m-d'), null, $userId, $notificationId);
            if (!$result) {
                throw new RuntimeException('Invoice already settled');
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[YardiEftInboxService] autoRecord failed: ' . $e->getMessage());
            return false;
        }
    }
}
