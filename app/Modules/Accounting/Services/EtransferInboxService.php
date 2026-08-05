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
 * Design note (why we mostly don't auto-record): the e-Transfer amount
 * frequently differs from the invoice total — customers add GST, round up, or
 * combine invoices. So by default we surface a best-guess match and let staff
 * confirm. The strong signal is the invoice number in the customer's memo;
 * amount is only a fallback.
 *
 * The one exception is autoRecordFullyCertain(): when a hard identity match
 * (invoice # in the memo, or the transfer's own reference # already on an
 * invoice) is corroborated by a high-confidence bank deposit AND the amount
 * exactly equals the invoice balance, staff shouldn't have to click through
 * something this unambiguous — see that method for the exact bar.
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
        // Accept both the "INV" abbreviation and the full word "invoice" — customers
        // typing memos write "invoice 2026-0308" at least as often as "INV-2026-0308".
        if (preg_match('/\b(?:INV|invoice)[-\s]?(\d{4})[-\s]?(\d{2,5})\b/i', $text, $m)) {
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
        // 0. Exact: this transfer's own Interac reference # already sitting in an
        // invoice's payment_reference — i.e. staff already recorded this exact
        // transfer manually before the poller ever saw it. Outranks the memo-based
        // invoice number below because it's a hard identity match, not a hint.
        if (!empty($parsed['reference_number'])) {
            $stmt = $this->db->prepare(
                "SELECT id, invoice_number, balance_due, total, status
                   FROM invoices WHERE payment_reference = ? LIMIT 1"
            );
            $stmt->execute([$parsed['reference_number']]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($inv) {
                return [
                    'invoice_id' => (int) $inv['id'],
                    'method'     => 'reference_number',
                    'confidence' => 'high',
                    'candidates' => [$inv],
                ];
            }
        }

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
     * Find an already-imported bank deposit that likely corresponds to this
     * e-Transfer notification — the bank↔email leg of three-way reconciliation
     * (bank record + invoice + email all need to agree before staff can treat
     * a transfer as officially closed). Scoring mirrors
     * BankImportService::findInvoiceMatchForETransfer(): amount match is
     * required, date proximity and sender-name overlap against the bank
     * description raise confidence.
     *
     * @param array $note Fields: amount, sender_name, email_date, and
     *   optionally matched_invoice_id (either a freshly-parsed transfer or an
     *   existing etransfer_notifications row).
     * @return array{tx_id:int,confidence:int,description:string}|null
     */
    public function matchBankTransaction(array $note): ?array
    {
        $amount = $note['amount'] ?? null;
        if ($amount === null || (float) $amount <= 0) {
            return null;
        }

        // Stage 0 — deterministic: BankImportService's own import-time matcher
        // (findInvoiceMatchForETransfer) may have already reconciled this exact
        // invoice's bank deposit and booked it as a cash-clearing 'transfer' row
        // (see BankImportService.php's "Book the bank deposit as a cash-clearing
        // TRANSFER" branch) — bypassing invoice_payment_allocations entirely, so
        // our own fuzzy scoring below (which only looks at still-unmatched
        // type='income' rows) can never see it. A transfer already tied to this
        // invoice is a stronger signal than anything our own scoring produces.
        if (!empty($note['matched_invoice_id'])) {
            $stmt = $this->db->prepare(
                "SELECT id FROM accounting_transactions
                  WHERE type = 'transfer' AND reference_type = 'bank_import'
                    AND matched_invoice_id = ? LIMIT 1"
            );
            $stmt->execute([(int) $note['matched_invoice_id']]);
            $txId = $stmt->fetchColumn();
            if ($txId) {
                return [
                    'tx_id'       => (int) $txId,
                    'confidence'  => 100,
                    'description' => 'Already reconciled to this invoice via bank import',
                ];
            }
        }

        $stmt = $this->db->prepare(
            "SELECT at.id AS tx_id, at.transaction_date, bir.description
               FROM accounting_transactions at
               JOIN bank_import_rows bir ON bir.transaction_id = at.id
              WHERE at.type = 'income'
                AND at.reference_type = 'bank_import'
                AND ABS(at.amount - ?) < 0.01
              ORDER BY at.transaction_date DESC
              LIMIT 25"
        );
        $stmt->execute([$amount]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($candidates)) {
            return null;
        }

        $emailDate   = $note['email_date'] ?? null;
        $senderWords = [];
        foreach (preg_split('/\s+/', strtolower((string) ($note['sender_name'] ?? ''))) as $w) {
            if (strlen($w) >= 3) {
                $senderWords[] = $w;
            }
        }

        $best = null;
        $bestScore = 0;
        foreach ($candidates as $c) {
            // Date proximity is a HARD gate, not just a scoring bonus — an
            // e-Transfer notification email arrives essentially the same day
            // the money moves, so a deposit weeks away can't be this transfer
            // no matter how well amount + sender name line up. Without this,
            // amount(50) + name(25) alone clears the 60-point bar and silently
            // links to an unrelated deposit from a different month.
            if ($emailDate) {
                $dayDiff = abs((strtotime($c['transaction_date']) - strtotime($emailDate)) / 86400);
                if ($dayDiff > 10) {
                    continue;
                }
                $score = 50 + ($dayDiff <= 1 ? 20 : ($dayDiff <= 3 ? 12 : ($dayDiff <= 7 ? 6 : 0)));
            } else {
                // No email date to compare — amount alone can't clear the bar,
                // sender-name overlap is required to even be considered.
                $score = 50;
            }
            $descLower = strtolower((string) $c['description']);
            foreach ($senderWords as $w) {
                if (strpos($descLower, $w) !== false) {
                    $score += 25;
                    break;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }

        if (!$best || $bestScore < 60) {
            return null;
        }

        return [
            'tx_id'       => (int) $best['tx_id'],
            'confidence'  => min($bestScore, 100),
            'description' => (string) $best['description'],
        ];
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

        $match     = $this->matchInvoice($parsed);
        $dt        = $emailDate ? date('Y-m-d H:i:s', strtotime($emailDate)) : null;
        $bankMatch = $this->matchBankTransaction([
            'amount'             => $parsed['amount'],
            'sender_name'        => $parsed['sender_name'],
            'email_date'         => $dt,
            'matched_invoice_id' => $match['invoice_id'],
        ]);

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO etransfer_notifications
                   (mailbox, dedup_key, reference_number, message_id, sender_name, amount, memo,
                    invoice_hint, transfer_type, email_subject, email_date,
                    matched_invoice_id, match_method, match_confidence,
                    bank_transaction_id, bank_match_confidence, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending', NOW())"
            );
            $stmt->execute([
                $mailbox, $dedup, $parsed['reference_number'], $messageId, $parsed['sender_name'],
                $parsed['amount'], $parsed['memo'], $parsed['invoice_hint'], $parsed['transfer_type'],
                $emailSubject, $dt,
                $match['invoice_id'], $match['method'], $match['confidence'],
                $bankMatch['tx_id'] ?? null, $bankMatch['confidence'] ?? null,
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

    /** Pending (incl. partially-allocated) notifications, newest first, with the suggested invoice's + bank deposit's details. */
    public function listPending(): array
    {
        $sql = "SELECT n.*, i.invoice_number AS matched_invoice_number, i.balance_due AS matched_balance,
                       i.status AS matched_invoice_status,
                       bir.description AS bank_description, at.transaction_date AS bank_transaction_date
                  FROM etransfer_notifications n
             LEFT JOIN invoices i ON n.matched_invoice_id = i.id
             LEFT JOIN accounting_transactions at ON n.bank_transaction_id = at.id
             LEFT JOIN bank_import_rows bir ON bir.transaction_id = at.id
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

        // Single-invoice attempt against an invoice that can't take a real payment
        // (already closed) — surface a "merge" option instead of a hard error, so
        // staff can link this notification to the invoice that already reflects
        // the payment, rather than being stuck choosing between Dismiss (loses the
        // record) or a confusing duplicate-payment error. Multi-invoice splits keep
        // the strict all-or-nothing behaviour below.
        if (count($clean) === 1) {
            $stmt = $this->db->prepare("SELECT invoice_number, status, payment_reference FROM invoices WHERE id = ? LIMIT 1");
            $stmt->execute([$clean[0]['invoice_id']]);
            $invRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invRow && ($block = self::paymentBlockReason((string) $invRow['status'], (string) $invRow['invoice_number']))) {
                // Cross-reference the Interac reference # on this transfer against
                // whatever staff typed into the invoice's payment reference when it
                // was manually recorded — an exact match is a near-certain signal
                // this is the same transfer, not a coincidental duplicate.
                $invRef  = trim((string) ($invRow['payment_reference'] ?? ''));
                $noteRef = trim((string) ($note['reference_number'] ?? ''));
                $refMatch = ($invRef !== '' && $noteRef !== '' && $invRef === $noteRef);

                return [
                    'ok'                   => false,
                    'message'              => $block,
                    'can_merge'            => true,
                    'merge_invoice_id'     => (int) $clean[0]['invoice_id'],
                    'merge_invoice_number' => $invRow['invoice_number'],
                    'reference_match'      => $refMatch,
                    'invoice_payment_reference' => $invRef !== '' ? $invRef : null,
                ];
            }
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

    /**
     * Re-run invoice_hint extraction + matching against already-ingested pending
     * notifications. One-time backfill for parser improvements (e.g. recognising
     * "invoice 2026-0308" as well as "INV-2026-0308") that don't retroactively
     * apply — matching only ran once, at ingest time. Only touches rows still
     * awaiting action; never re-matches something already recorded/dismissed.
     *
     * @return array{checked:int,updated:int}
     */
    public function rematchPending(): array
    {
        $rows = $this->db->query(
            "SELECT id, memo, email_subject, invoice_hint, matched_invoice_id, match_method, match_confidence
               FROM etransfer_notifications
              WHERE status IN ('pending', 'partially_recorded')"
        )->fetchAll(PDO::FETCH_ASSOC);

        $checked = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $checked++;
            $hint = self::extractInvoiceNumber($row['memo'] ?? '')
                 ?? self::extractInvoiceNumber($row['email_subject'] ?? '');
            if ($hint === null || $hint === $row['invoice_hint']) {
                continue;
            }

            $match = $this->matchInvoice(['invoice_hint' => $hint, 'amount' => null]);
            if ((int) $match['invoice_id'] === (int) $row['matched_invoice_id']
                && $hint === $row['invoice_hint']) {
                continue;
            }

            $this->db->prepare(
                "UPDATE etransfer_notifications
                    SET invoice_hint = ?, matched_invoice_id = ?, match_method = ?, match_confidence = ?
                  WHERE id = ?"
            )->execute([
                $hint, $match['invoice_id'], $match['method'], $match['confidence'], $row['id'],
            ]);
            $updated++;
        }

        return ['checked' => $checked, 'updated' => $updated];
    }

    /** Bank-match confidence floor for auto-recording — same-day + sender-name overlap, not just amount. */
    private const AUTO_RECORD_BANK_CONFIDENCE_FLOOR = 90;

    /**
     * Auto-record e-Transfers that clear a deliberately narrow "100% certain"
     * bar so staff never have to click through something this unambiguous.
     * All three must hold:
     *   1. Hard identity match on the invoice — invoice # literally in the
     *      customer's memo, or the transfer's own reference # already sitting
     *      in that invoice's payment_reference. Not the amount-fallback guess.
     *   2. The bank deposit is linked with high confidence (same-day +
     *      sender-name overlap) — not just "an amount happened to match."
     *   3. The transfer amount exactly equals the invoice's outstanding
     *      balance (to the cent) — no GST/rounding ambiguity to second-guess.
     *
     * Anything short of all three still waits for a human, exactly as before.
     * Safe to call repeatedly (only touches pending/partially_recorded rows).
     *
     * @return array{checked:int,recorded:int,skipped:array}
     */
    public function autoRecordFullyCertain(int $systemUserId): array
    {
        $rows = $this->db->prepare(
            "SELECT n.id, n.amount, n.matched_invoice_id, i.balance_due AS invoice_balance
               FROM etransfer_notifications n
               JOIN invoices i ON i.id = n.matched_invoice_id
              WHERE n.status IN ('pending', 'partially_recorded')
                AND n.match_method IN ('invoice_number', 'reference_number')
                AND n.match_confidence = 'high'
                AND n.bank_transaction_id IS NOT NULL
                AND n.bank_match_confidence >= ?"
        );
        $rows->execute([self::AUTO_RECORD_BANK_CONFIDENCE_FLOOR]);
        $candidates = $rows->fetchAll(PDO::FETCH_ASSOC);

        $result = ['checked' => count($candidates), 'recorded' => 0, 'recorded_ids' => [], 'skipped' => []];

        foreach ($candidates as $c) {
            $amount  = round((float) $c['amount'], 2);
            $balance = round((float) $c['invoice_balance'], 2);
            if (abs($amount - $balance) > 0.01) {
                $result['skipped'][] = ['id' => (int) $c['id'], 'reason' => 'amount does not exactly match the invoice balance'];
                continue;
            }

            $res = $this->recordPayment(
                (int) $c['id'],
                [['invoice_id' => (int) $c['matched_invoice_id'], 'amount' => null]],
                $systemUserId
            );
            if ($res['ok'] ?? false) {
                $result['recorded']++;
                $result['recorded_ids'][] = (int) $c['id'];
            } else {
                $result['skipped'][] = ['id' => (int) $c['id'], 'reason' => $res['message'] ?? 'unknown'];
            }
        }

        return $result;
    }

    /**
     * Link a pending notification to an invoice that's already closed (paid
     * elsewhere, e.g. manually) WITHOUT applying a payment allocation — this is
     * "yes, this e-Transfer is the thing that already closed that invoice, just
     * stop asking me to record it." Never touches invoices, accounting_transactions,
     * or invoice_payment_allocations, so it's always safely reversible via unmerge().
     */
    public function mergeAlreadyRecorded(int $notificationId, int $invoiceId, int $userId): array
    {
        $note = $this->find($notificationId);
        if (!$note) {
            return ['ok' => false, 'message' => 'Notification not found'];
        }
        if (!in_array($note['status'], ['pending', 'partially_recorded'], true)) {
            return ['ok' => false, 'message' => 'Already ' . $note['status']];
        }

        $stmt = $this->db->prepare("SELECT invoice_number, status FROM invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return ['ok' => false, 'message' => 'Invoice not found'];
        }
        if (self::paymentBlockReason((string) $inv['status'], (string) $inv['invoice_number']) === null) {
            return ['ok' => false, 'message' => "{$inv['invoice_number']} can still take a real payment — use Record instead."];
        }

        $this->db->prepare(
            "UPDATE etransfer_notifications
                SET status = 'recorded', matched_invoice_id = ?, recorded_invoice_id = ?,
                    allocated_amount = amount, recorded_by = ?, processed_at = NOW()
              WHERE id = ?"
        )->execute([$invoiceId, $invoiceId, $userId, $notificationId]);

        return [
            'ok'      => true,
            'merged'  => true,
            'message' => "Linked to {$inv['invoice_number']} (already closed) — no new payment recorded.",
        ];
    }

    /**
     * Undo mergeAlreadyRecorded() — puts the notification back in the pending
     * queue. Refuses if a real payment was ever applied against this notification
     * (invoice_payment_allocations row exists), since reversing an actual ledger
     * entry needs the invoice's own payment-reversal path, not this shortcut.
     */
    public function unmerge(int $notificationId, int $userId): array
    {
        $note = $this->find($notificationId);
        if (!$note) {
            return ['ok' => false, 'message' => 'Notification not found'];
        }
        if ($note['status'] !== 'recorded') {
            return ['ok' => false, 'message' => 'Nothing to undo'];
        }

        $chk = $this->db->prepare("SELECT COUNT(*) FROM invoice_payment_allocations WHERE etransfer_notification_id = ?");
        $chk->execute([$notificationId]);
        if ((int) $chk->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'This e-Transfer was recorded as a real payment — reverse it from the invoice\'s payment history instead.'];
        }

        $this->db->prepare(
            "UPDATE etransfer_notifications
                SET status = 'pending', recorded_invoice_id = NULL, allocated_amount = 0,
                    recorded_by = NULL, processed_at = NULL
              WHERE id = ?"
        )->execute([$notificationId]);

        return ['ok' => true, 'message' => 'Back in the pending queue.'];
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
