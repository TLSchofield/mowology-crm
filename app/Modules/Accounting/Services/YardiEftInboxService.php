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
     * Parse a Yardi remittance email (subject + body — HTML or plain text)
     * into the transaction reference/date and one or more invoice line items.
     * The real email (captured 2026-08-05) is HTML-only, built from nested
     * MS-Office-style tables; a plain-text body is also accepted (e.g. a
     * manually forwarded/exported copy) as a fallback.
     *
     * @return array{transaction_reference:?string,transaction_date:?string,total:?float,lines:array<int,array{property:?string,invoice_number:?string,invoice_date:?string,amount:float}>}
     */
    public static function parseRemittanceEmail(string $subject, string $body): array
    {
        if (preg_match('/<[a-z][^>]*>/i', $body)) {
            return self::parseHtmlBody($body);
        }
        return self::parseTextBody($body);
    }

    /** @return array{transaction_reference:?string,transaction_date:?string,total:?float,lines:array} */
    private static function parseHtmlBody(string $html): array
    {
        $empty = ['transaction_reference' => null, 'transaction_date' => null, 'total' => null, 'lines' => []];

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        if (!$ok) {
            return $empty;
        }
        $xpath = new DOMXPath($doc);

        // The label text sits inside a <span> child, not as a direct text node
        // of the <td> — normalize-space(.) matches the td's full text content
        // (descendants included), text() alone would match nothing.
        $labelValue = static function (string $label) use ($xpath): ?string {
            $nodes = $xpath->query(
                "//td[normalize-space(.)='" . $label . "']/following-sibling::td[1]"
            );
            if ($nodes && $nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
            return null;
        };

        $reference = $labelValue('Transaction Reference No');
        $txnDate   = $labelValue('Transaction Date');
        $totalRaw  = $labelValue('Total');
        $total     = ($totalRaw && preg_match('/([0-9,]+\.[0-9]{2})/', $totalRaw, $m))
            ? (float) str_replace(',', '', $m[1]) : null;

        // The "Invoice Details" table: identified by its header row containing
        // "Invoice #" rather than position, since surrounding layout tables vary.
        $lines = [];
        $headerCells = $xpath->query("//tr[th[normalize-space(.)='Invoice #']]");
        if ($headerCells && $headerCells->length > 0) {
            $table = $headerCells->item(0)->parentNode->parentNode; // tr -> thead -> table
            $rows  = $xpath->query(".//tbody/tr", $table);
            foreach ($rows as $row) {
                $cells = $xpath->query('./td', $row);
                if ($cells->length < 4) {
                    continue;
                }
                $amtRaw = trim($cells->item(3)->textContent);
                if (!preg_match('/([0-9,]+\.[0-9]{2})/', $amtRaw, $am)) {
                    continue;
                }
                $property = trim($cells->item(0)->textContent);
                $invNo    = trim($cells->item(1)->textContent);
                $invDate  = trim($cells->item(2)->textContent);
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

    /** @return array{transaction_reference:?string,transaction_date:?string,total:?float,lines:array} */
    private static function parseTextBody(string $body): array
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
    public function ingest(array $parsed, string $mailbox, ?string $messageId, ?string $emailSubject, ?string $emailDate, int $systemUserId, bool $senderVerified = false): array
    {
        $ref     = $parsed['transaction_reference'] ?: ($messageId ?: md5(($emailSubject ?? '') . '|' . ($emailDate ?? '')));
        $dt      = $emailDate ? date('Y-m-d H:i:s', strtotime($emailDate)) : null;
        $result  = ['processed' => 0, 'auto_recorded' => 0, 'pending' => 0, 'skipped_duplicate' => 0];

        // When the money moved: the remittance's Transaction Date, else the email's date — never
        // the day the poller ran (review 2026-10-12: wrong month/GST quarter, missed bank matches).
        $paidDate = self::paidDate($parsed['transaction_date'] ?? null, $emailDate);
        // An email alone never marks an invoice paid (a From header is forgeable and the host adds no
        // verification stamp — checked 2026-10-12): the remittance's deposit must be in the bank.
        $lineSum  = round(array_sum(array_map(fn($l) => (float)$l['amount'], $parsed['lines'])), 2);
        $totalOk  = ($parsed['total'] ?? null) === null || abs((float)$parsed['total'] - $lineSum) < 0.01;
        $banked   = $totalOk ? $this->bankDeposit($lineSum, $paidDate) : null;

        $seen = [];
        foreach ($parsed['lines'] as $line) {
            $result['processed']++;
            $invNo = $line['invoice_number'];
            $base  = 'yardi:' . $ref . ':' . ($invNo ?: md5(json_encode($line)));
            // Two lines on one invoice in one remittance are two payments (the second used to be
            // dropped as "already seen"); the first keeps the original key so old mail isn't re-read.
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $dedup = $seen[$base] === 1 ? $base : $base . ':' . $seen[$base];

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

            // Security review #4 (2026-10-12): only a remittance whose sender is PROVEN (exact
            // DoNotReply@yardi.com + a verified yardi.com DKIM signature — MailAuth) can ever be
            // recorded without a person; anything else waits in the panel as 'medium', which
            // recordWhenBanked() never touches.
            $canAutoRecord = $senderVerified
                && $invoice
                && $banked !== null
                && in_array($invoice['status'], self::PAYABLE, true)
                && abs((float) $invoice['balance_due'] - $line['amount']) < 0.01;

            if ($canAutoRecord) {
                if ($this->autoRecord((int) $invoice['id'], $line['amount'], $ref, $dedup, $systemUserId, $paidDate, null, $banked)) {
                    $result['auto_recorded']++;
                    continue;
                }
                // Fell through (e.g. race with another payment) — drop a pending row instead.
            }

            $method     = $invoice ? 'invoice_number' : 'none';
            $confidence = $invoice ? ($senderVerified ? 'high' : 'medium') : 'none';
            $memo = !$senderVerified ? 'Sender not verified (no valid Yardi signature) — check this remittance by hand'
                : (!$invoice ? 'Yardi remittance — invoice not found or already settled'
                : (!$totalOk ? 'Yardi remittance lines do not add up to its total — check it'
                : ($banked === null ? 'Waiting for the bank deposit before recording' : null)));
            $params = [
                $mailbox, $dedup, $ref, $messageId, $line['property'], $line['amount'], $memo,
                $invNo, $emailSubject, $dt,
                $invoice ? (int) $invoice['id'] : null, $method, $confidence,
            ];
            $cols = $vals = '';
            if ($this->hasSenderVerified()) { $cols = ', sender_verified'; $vals = ', ?'; $params[] = $senderVerified ? 1 : 0; }

            $this->db->prepare(
                "INSERT INTO etransfer_notifications
                   (mailbox, source, dedup_key, reference_number, message_id, sender_name, amount,
                    memo, invoice_hint, transfer_type, email_subject, email_date,
                    matched_invoice_id, match_method, match_confidence, status, created_at{$cols})
                 VALUES (?, 'yardi_eft', ?, ?, ?, ?, ?, ?, ?, 'yardi_eft', ?, ?, ?, ?, ?, 'pending', NOW(){$vals})"
            )->execute($params);
            $result['pending']++;
        }

        return $result;
    }

    /** Record one auto-certain line via the shared allocation ledger. Returns success. */
    private function autoRecord(int $invoiceId, float $amount, string $ref, string $dedupKey, int $userId, ?string $paidDate = null, ?int $replacesPendingId = null, ?int $depositTx = null): bool
    {
        require_once __DIR__ . '/InvoiceReconciliationService.php';

        try {
            $this->db->beginTransaction();
            // A pending line being recorded now that its deposit is in: replaced inside this
            // transaction, so a failure leaves it pending instead of losing it.
            if ($replacesPendingId !== null) {
                $this->db->prepare("DELETE FROM etransfer_notifications WHERE id = ? AND status = 'pending'")->execute([$replacesPendingId]);
            }

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
            $result = $recon->applyAllocation($invoiceId, $amount, 'yardi_eft', $ref, $paidDate ?: date('Y-m-d'), $depositTx, $userId, $notificationId);
            if (!$result) {
                throw new RuntimeException('Invoice already settled');
            }
            // The deposit that proved the money arrived is this payment: settle it, or it stays
            // income beside the invoice (review 2026-10-12).
            if ($depositTx) $recon->settleDeposit($depositTx, $userId);

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

    private ?bool $senderVerifiedCol = null;

    /** etransfer_notifications.sender_verified exists (migration 1340). */
    private function hasSenderVerified(): bool
    {
        if ($this->senderVerifiedCol === null) {
            try {
                $this->db->query("SELECT sender_verified FROM etransfer_notifications LIMIT 0");
                $this->senderVerifiedCol = true;
            } catch (Throwable $e) {
                $this->senderVerifiedCol = false;
            }
        }
        return $this->senderVerifiedCol;
    }

    /** Y-m-d the money moved: the remittance date, else the email date, else today. Pure. */
    public static function paidDate(?string $transactionDate, ?string $emailDate): string
    {
        foreach ([$transactionDate, $emailDate] as $d) {
            // strtotime() reads words ("not a date") as relative times — a date has digits.
            if ($d !== null && preg_match('/\d{1,4}\D\d{1,2}|\d{1,2} [A-Za-z]{3} \d{4}/', $d)
                && ($t = strtotime(trim($d))) !== false && $t <= time() + 86400) {
                return date('Y-m-d', $t);
            }
        }
        return date('Y-m-d');
    }

    /** The one bank deposit of exactly this amount from 2 days before to 7 days after, or null. */
    public function bankDeposit(float $amount, string $date): ?int
    {
        if ($amount <= 0) return null;
        $s = $this->db->prepare("SELECT id FROM accounting_transactions
                                 WHERE reference_type = 'bank_import' AND type = 'income'   -- not one already consumed by another payment
                                   AND ABS(amount - ?) < 0.005
                                   AND transaction_date BETWEEN DATE_SUB(?, INTERVAL 2 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
                                 LIMIT 2");
        $s->execute([$amount, $date, $date]);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    /**
     * Pending Yardi lines whose deposit has since reached the bank: record them now (the poll calls
     * this each run). Same rules as ingest — exact invoice + exact balance + the remittance's deposit.
     */
    public function recordWhenBanked(int $systemUserId): int
    {
        // Only lines whose email was proven to come from Yardi (sender_verified = 1, migration 1340).
        // Before 1340 runs nothing is recorded here — every line waits for a person (fail safe).
        if (!$this->hasSenderVerified()) return 0;
        $rows = $this->db->query("SELECT n.id, n.reference_number, n.amount, n.dedup_key, n.email_date, n.matched_invoice_id,
                                         i.balance_due, i.status
                                    FROM etransfer_notifications n JOIN invoices i ON i.id = n.matched_invoice_id
                                   WHERE n.source = 'yardi_eft' AND n.status = 'pending' AND n.match_confidence = 'high'
                                     AND n.sender_verified = 1")
                         ->fetchAll(PDO::FETCH_ASSOC);
        $byRef = [];
        foreach ($rows as $r) $byRef[(string)$r['reference_number']][] = $r;
        $done = 0;
        foreach ($byRef as $ref => $group) {
            $all = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM etransfer_notifications WHERE source = 'yardi_eft' AND reference_number = ?");
            $all->execute([$ref]);
            $date = self::paidDate(null, $group[0]['email_date']);
            $dep = $this->bankDeposit(round((float)$all->fetchColumn(), 2), $date);
            if ($dep === null) continue;
            foreach ($group as $r) {
                if (!in_array($r['status'], self::PAYABLE, true) || abs((float)$r['balance_due'] - (float)$r['amount']) >= 0.01) continue;
                if ($this->autoRecord((int)$r['matched_invoice_id'], (float)$r['amount'], $ref, (string)$r['dedup_key'], $systemUserId, $date, (int)$r['id'], $dep)) $done++;
            }
        }
        return $done;
    }
}
