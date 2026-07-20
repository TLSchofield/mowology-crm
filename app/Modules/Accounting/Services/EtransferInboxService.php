<?php
/**
 * EtransferInboxService — parse Interac e-Transfer notification emails, match
 * them to open invoices, and record confirmed payments.
 *
 * The IMAP fetch lives in the cron poller
 * (app/Modules/Accounting/Cron/etransfer_inbox_poll.php); this service holds the
 * parsing + matching + recording logic so it can be unit-tested without a
 * mailbox. parseInteracEmail() is a pure static function — the test exercises it
 * against a real captured email.
 *
 * Design note (why we never auto-record): the e-Transfer amount frequently
 * differs from the invoice total — customers add GST, round up, or combine
 * invoices. So we surface a best-guess match and let staff confirm. The strong
 * signal is the invoice number in the customer's memo; amount is only a fallback.
 */
class EtransferInboxService
{
    /** Invoice statuses that can still receive a payment. */
    private const PAYABLE = ['sent', 'viewed', 'partial', 'overdue'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Parse a raw Interac notification (subject + plain-text body) into fields.
     * Pure + static so it is trivially testable.
     *
     * @return array{sender_name:?string,amount:?float,reference_number:?string,memo:?string,invoice_hint:?string,transfer_type:string}
     */
    public static function parseInteracEmail(string $subject, string $body): array
    {
        $body    = str_replace("\r\n", "\n", $body);
        $subject = trim($subject);

        // Amount — prefer the structured "Amount: $X (CAD)" line, fall back to
        // the first $-amount in the subject ("sent you $66.15", "Claim your $262.50").
        $amount = null;
        if (preg_match('/Amount:\s*\$\s*([0-9,]+\.[0-9]{2})/i', $body, $m)) {
            $amount = (float) str_replace(',', '', $m[1]);
        } elseif (preg_match('/\$\s*([0-9,]+\.[0-9]{2})/', $subject, $m)) {
            $amount = (float) str_replace(',', '', $m[1]);
        }

        // Sender name — structured "Sent From:" line, else patterns in the subject.
        $sender = null;
        if (preg_match('/Sent From:\s*(.+)/i', $body, $m)) {
            $sender = trim($m[1]);
        } elseif (preg_match('/Claim your \$[0-9,.]+ from (.+?) by /i', $subject, $m)) {
            $sender = trim($m[1]);
        } elseif (preg_match('/(?:Interac e-Transfer:\s*)?(.+?) sent you \$/i', $subject, $m)) {
            $sender = trim($m[1]);
        }

        // Interac reference number (unique per transfer → ideal dedup key).
        $reference = null;
        if (preg_match('/Reference Number:\s*([A-Za-z0-9]+)/i', $body, $m)) {
            $reference = trim($m[1]);
        }

        // Customer memo — between "Message:" and the "Transfer Details" block.
        $memo = null;
        if (preg_match('/Message:\s*(.+?)\s*Transfer Details/is', $body, $m)) {
            $memo = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        // Invoice number from the memo (or anywhere in the body). Accept
        // "INV-2026-0096", "INV 2026 0096", "inv2026-96" → normalise.
        $invoiceHint = self::extractInvoiceNumber($memo ?? '') ?? self::extractInvoiceNumber($body);

        // Transfer type — auto-deposited vs needs manual claiming.
        $type = 'unknown';
        if (preg_match('/automatically deposited|has been deposited|deposited into your account/i', $body)) {
            $type = 'autodeposit';
        } elseif (preg_match('/Claim your|Select your financial institution|funds expire/i', $body . ' ' . $subject)) {
            $type = 'claim';
        }

        return [
            'sender_name'      => $sender !== '' ? $sender : null,
            'amount'           => $amount,
            'reference_number' => $reference,
            'memo'             => $memo,
            'invoice_hint'     => $invoiceHint,
            'transfer_type'    => $type,
        ];
    }

    /**
     * Why an invoice can't accept a payment, or null if it can. Pure + testable.
     * Distinguishes "already paid" (duplicate-payment signal) from other states.
     */
    public static function paymentBlockReason(string $status, string $invoiceNumber): ?string
    {
        if (in_array($status, self::PAYABLE, true) || $status === 'draft') {
            return null;
        }
        if ($status === 'paid') {
            return "{$invoiceNumber} is already fully paid — possible duplicate payment. "
                 . 'Dismiss, or attach to a different invoice.';
        }
        return "{$invoiceNumber} is {$status} and can't take a payment.";
    }

    /** Pull a normalised INV-YYYY-NNNN invoice number out of free text. */
    public static function extractInvoiceNumber(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        if (preg_match('/\bINV[-\s]?(\d{4})[-\s]?(\d{2,5})\b/i', $text, $m)) {
            return 'INV-' . $m[1] . '-' . str_pad($m[2], 4, '0', STR_PAD_LEFT);
        }
        return null;
    }

    /**
     * Match parsed fields to an open invoice.
     *
     * @return array{invoice_id:?int,method:string,confidence:string,candidates:array}
     */
    public function matchInvoice(array $parsed): array
    {
        // 1. Exact: invoice number from the memo.
        if (!empty($parsed['invoice_hint'])) {
            $stmt = $this->db->prepare(
                "SELECT id, invoice_number, balance_due, total, status
                   FROM invoices WHERE invoice_number = ? LIMIT 1"
            );
            $stmt->execute([$parsed['invoice_hint']]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($inv) {
                return [
                    'invoice_id' => (int) $inv['id'],
                    'method'     => 'invoice_number',
                    'confidence' => 'high',
                    'candidates' => [$inv],
                ];
            }
        }

        // 2. Fallback: a single open invoice whose balance equals the amount.
        if (!empty($parsed['amount'])) {
            $placeholders = implode(',', array_fill(0, count(self::PAYABLE), '?'));
            $stmt = $this->db->prepare(
                "SELECT id, invoice_number, balance_due, total, status
                   FROM invoices
                  WHERE status IN ($placeholders)
                    AND ABS(balance_due - ?) < 0.01
                  ORDER BY created_at DESC
                  LIMIT 5"
            );
            $stmt->execute(array_merge(self::PAYABLE, [$parsed['amount']]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) {
                return [
                    'invoice_id' => (int) $rows[0]['id'],
                    'method'     => 'amount',
                    'confidence' => 'medium',
                    'candidates' => $rows,
                ];
            }
            if (count($rows) > 1) {
                return ['invoice_id' => null, 'method' => 'none', 'confidence' => 'none', 'candidates' => $rows];
            }
        }

        return ['invoice_id' => null, 'method' => 'none', 'confidence' => 'none', 'candidates' => []];
    }

    /**
     * Insert a parsed notification (deduped) and run matching.
     * Returns ['inserted'=>bool, 'id'=>int|null, 'row'=>array|null].
     */
    public function ingest(array $parsed, string $mailbox, ?string $messageId, ?string $emailSubject, ?string $emailDate): array
    {
        $dedup = $parsed['reference_number'] ?: ($messageId ?: md5(($emailSubject ?? '') . '|' . ($emailDate ?? '')));

        // Already seen?
        $chk = $this->db->prepare("SELECT id FROM etransfer_notifications WHERE dedup_key = ? LIMIT 1");
        $chk->execute([$dedup]);
        if ($existing = $chk->fetchColumn()) {
            return ['inserted' => false, 'id' => (int) $existing, 'row' => null];
        }

        $match = $this->matchInvoice($parsed);
        $dt    = $emailDate ? date('Y-m-d H:i:s', strtotime($emailDate)) : null;

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO etransfer_notifications
                   (mailbox, dedup_key, reference_number, message_id, sender_name, amount, memo,
                    invoice_hint, transfer_type, email_subject, email_date,
                    matched_invoice_id, match_method, match_confidence, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending', NOW())"
            );
            $stmt->execute([
                $mailbox, $dedup, $parsed['reference_number'], $messageId, $parsed['sender_name'],
                $parsed['amount'], $parsed['memo'], $parsed['invoice_hint'], $parsed['transfer_type'],
                $emailSubject, $dt,
                $match['invoice_id'], $match['method'], $match['confidence'],
            ]);
        } catch (PDOException $e) {
            // Lost an insert race against a concurrent poll → already seen.
            if (stripos($e->getMessage(), 'Duplicate') !== false || $e->getCode() === '23000') {
                $chk->execute([$dedup]);
                $existing = (int) $chk->fetchColumn();
                return ['inserted' => false, 'id' => $existing ?: null, 'row' => null];
            }
            throw $e;
        }
        $id = (int) $this->db->lastInsertId();

        return ['inserted' => true, 'id' => $id, 'row' => $this->find($id)];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM etransfer_notifications WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Pending (incl. partially-allocated) notifications, newest first, with the suggested invoice's details. */
    public function listPending(): array
    {
        $sql = "SELECT n.*, i.invoice_number AS matched_invoice_number, i.balance_due AS matched_balance,
                       i.status AS matched_invoice_status
                  FROM etransfer_notifications n
             LEFT JOIN invoices i ON n.matched_invoice_id = i.id
                 WHERE n.status IN ('pending', 'partially_recorded')
              ORDER BY n.email_date DESC, n.id DESC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countPending(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM etransfer_notifications WHERE status IN ('pending', 'partially_recorded')"
        )->fetchColumn();
    }

    /**
     * Record the e-Transfer as a payment against one or more invoices (a single
     * e-Transfer sometimes covers several invoices at once). Delegates the
     * per-invoice apply/cap/ledger work to InvoiceReconciliationService::applyAllocation()
     * so e-Transfers and bank-deposit reconciliation share one allocation ledger
     * (invoice_payment_allocations) instead of two independent "record a payment"
     * code paths — see that method for why.
     *
     * A notification can be recorded across more than one call: each call applies
     * against the amount still unallocated, and status stays 'partially_recorded'
     * until the full transfer amount has been assigned.
     *
     * @param array $allocations [ ['invoice_id'=>int, 'amount'=>?float], ... ] — a
     *   single allocation with a null amount defaults to "whatever's left on the
     *   transfer" (the common single-invoice case, unchanged from before).
     * @return array{ok:bool,message:string,fully_allocated?:bool,remaining?:float}
     */
    public function recordPayment(int $notificationId, array $allocations, int $userId): array
    {
        $note = $this->find($notificationId);
        if (!$note) {
            return ['ok' => false, 'message' => 'Notification not found'];
        }
        if (!in_array($note['status'], ['pending', 'partially_recorded'], true)) {
            return ['ok' => false, 'message' => 'Already ' . $note['status']];
        }

        $clean = [];
        foreach ($allocations as $a) {
            $iid = (int) ($a['invoice_id'] ?? 0);
            $amt = isset($a['amount']) && $a['amount'] !== null && $a['amount'] !== ''
                ? round((float) $a['amount'], 2) : null;
            if ($iid > 0) {
                $clean[] = ['invoice_id' => $iid, 'amount' => $amt];
            }
        }
        if (empty($clean)) {
            return ['ok' => false, 'message' => 'Enter an invoice number to record against'];
        }

        $totalAmount = (float) $note['amount'];
        $remaining   = round($totalAmount - (float) $note['allocated_amount'], 2);

        // A single allocation with no explicit amount defaults to "whatever's left"
        // — the common single-invoice path, unchanged behaviour.
        if (count($clean) === 1 && $clean[0]['amount'] === null) {
            $clean[0]['amount'] = $remaining > 0 ? $remaining : null;
        }
        foreach ($clean as $a) {
            if ($a['amount'] === null || $a['amount'] <= 0) {
                return ['ok' => false, 'message' => 'Enter an amount for each invoice'];
            }
        }

        $sum = round(array_sum(array_column($clean, 'amount')), 2);
        if ($totalAmount > 0 && $sum > $remaining + 0.005) {
            return ['ok' => false, 'message' => sprintf(
                'Allocation total $%.2f exceeds the $%.2f left on this e-Transfer.', $sum, $remaining
            )];
        }

        require_once __DIR__ . '/InvoiceReconciliationService.php';
        $recon   = new InvoiceReconciliationService($this->db);
        $ref     = $note['reference_number'] ?: ('e-Transfer ' . $note['id']);
        $payDate = date('Y-m-d');

        try {
            $this->db->beginTransaction();

            $recorded = [];
            foreach ($clean as $a) {
                // Load unconditionally so we can give a precise reason when it can't
                // take a payment (already paid → likely a duplicate; cancelled → can't apply).
                $stmt = $this->db->prepare("SELECT invoice_number, status FROM invoices WHERE id = ? LIMIT 1");
                $stmt->execute([$a['invoice_id']]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inv) {
                    throw new RuntimeException("Invoice #{$a['invoice_id']} not found.");
                }
                if ($block = self::paymentBlockReason((string) $inv['status'], (string) $inv['invoice_number'])) {
                    throw new RuntimeException($block);
                }

                $result = $recon->applyAllocation(
                    $a['invoice_id'], $a['amount'], 'e_transfer', $ref, $payDate, null, $userId, $notificationId
                );
                if ($result) {
                    $recorded[] = $result;
                }
            }

            if (empty($recorded)) {
                throw new RuntimeException('Nothing to record — the selected invoice(s) are already settled.');
            }

            $appliedSum    = round(array_sum(array_column($recorded, 'applied')), 2);
            $newAllocated  = round((float) $note['allocated_amount'] + $appliedSum, 2);
            $fullyRecorded = $totalAmount <= 0 || $newAllocated >= $totalAmount - 0.005;

            $this->db->prepare(
                "UPDATE etransfer_notifications
                    SET allocated_amount = ?, status = ?, recorded_invoice_id = COALESCE(recorded_invoice_id, ?),
                        recorded_by = ?, processed_at = ?
                  WHERE id = ?"
            )->execute([
                $newAllocated,
                $fullyRecorded ? 'recorded' : 'partially_recorded',
                $recorded[0]['invoice_id'],
                $userId,
                $fullyRecorded ? date('Y-m-d H:i:s') : null,
                $notificationId,
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof RuntimeException) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
            error_log('[EtransferInboxService] recordPayment error: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Database error while recording payment'];
        }

        // Attribution hook (best-effort, mirrors record-payment.php).
        if (defined('APP_ROOT')) {
            $attr = APP_ROOT . '/Modules/Marketing/Services/AttributionService.php';
            if (is_file($attr)) {
                require_once $attr;
                foreach ($recorded as $r) {
                    if ($r['fully_paid']) {
                        try {
                            AttributionService::onInvoicePaid($this->db, $r['invoice_id'], $r['applied']);
                        } catch (\Throwable $e) {
                            error_log('[EtransferInboxService] attribution error: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        $remainingAfter = round($totalAmount - $newAllocated, 2);
        $message = count($recorded) === 1
            ? sprintf('Recorded $%.2f against %s', $recorded[0]['applied'], $recorded[0]['invoice_number'])
            : sprintf('Recorded $%.2f across %d invoices', $appliedSum, count($recorded));
        if (!$fullyRecorded) {
            $message .= sprintf(' — $%.2f left to assign', max(0, $remainingAfter));
        }

        return [
            'ok'              => true,
            'message'         => $message,
            'fully_allocated' => $fullyRecorded,
            'remaining'       => max(0, $remainingAfter),
        ];
    }

    public function dismiss(int $notificationId, int $userId): array
    {
        $upd = $this->db->prepare(
            "UPDATE etransfer_notifications
                SET status = 'dismissed', recorded_by = ?, processed_at = NOW()
              WHERE id = ? AND status IN ('pending', 'partially_recorded')"
        );
        $upd->execute([$userId, $notificationId]);
        return ['ok' => $upd->rowCount() > 0, 'message' => $upd->rowCount() > 0 ? 'Dismissed' : 'Nothing to dismiss'];
    }
}
