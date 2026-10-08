<?php
/**
 * IncomeCleanupService — "Penny's income clean-up" (2026-10-07).
 *
 * The problem (measured 2026-10-06 by /crm/api/income-overlap-report.php): every bank
 * deposit that was never tied to its invoice is still booked type='income', next to the
 * invoice's own income row. AccountingService's P&L / monthly trend / dashboard sum every
 * type='income' row, so 2026 income read ~$210K too high. (The journal skips revenue
 * deposits and GstReportService line 101 now reads invoices, so neither of those carries
 * the double; the old ledger-based GST figure did — see gstEffect().)
 *
 * This service PROPOSES; Tim approves; then it BOOKS through the existing paths:
 *   stripe     — Stripe payouts after the cutover → StripePayoutService::apply()
 *                (re-reads Stripe, books only when the payout adds up to the cent).
 *   exact      — one invoice: a payment already recorded by hand (link it), an invoice
 *                paid the old way with no allocation rows (mark "already recorded"), or an
 *                open invoice owing exactly this (record the payment — only when the payer
 *                is named in the memo or the amount is unique, since that writes to AR).
 *   exact_sum  — several invoices of ONE payer adding up to the cent
 *                (exactSubsetBounded: Dorset $804.04 = 2 × $402.02).
 *   needs_you  — partials, unknowns, conflicts (same amount as a card-paid invoice, or an
 *                invoice already tied to another deposit), with the closest candidates.
 *   jobber     — before the CRM cutover (2026-02-25): Jobber-era deposits are the ONLY
 *                income record and stay income. Listed so obvious non-income (funds
 *                transfers between our own accounts, loan proceeds, refunds) is flagged.
 *                Nothing here is booked by this service.
 *
 * Safety:
 *   - The browser never decides: book() rebuilds the proposal and refuses a line whose
 *     action / invoices / payments changed since Tim looked (signature).
 *   - Never pays an invoice twice: record_payment re-reads balance_due; already_recorded
 *     re-checks the invoice is still paid, unlinked and unclaimed.
 *   - Locked months are refused (LedgerService::isLocked) — booking and reversing.
 *   - Every booking is logged in income_cleanup_log (migration 1228) with the bank row
 *     as it was, and reverse() puts it back.
 *   - A deposit flipped to 'transfer' always keeps matched_invoice_id set: the bank
 *     journal sync (LedgerSyncService) skips matched rows; an unmatched 'transfer' would
 *     post as money OUT (see journalCheck()).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/InvoiceReconciliationService.php';
require_once __DIR__ . '/BankInvoiceMatchService.php';
require_once __DIR__ . '/StripePayoutService.php';

class IncomeCleanupService
{
    /** First CRM invoice paid + first CRM Stripe charge. Before it: Jobber. */
    public const CUTOVER = '2026-02-25';
    /** Recorded payments / paid invoices considered for a deposit: this many days before … after it. */
    public const BEFORE_DAYS = 21;
    public const AFTER_DAYS  = 60;
    /** An open invoice can be paid at most this long after it was issued (InvoiceReconciliationService::MAX_DAYS_AFTER_DUE). */
    public const OPEN_MAX_DAYS = 150;

    public const BUCKETS  = ['stripe', 'exact', 'exact_sum', 'needs_you', 'jobber'];
    public const BOOKABLE = ['stripe', 'exact', 'exact_sum'];
    public const CARD_METHODS = ['stripe', 'credit_card', 'card', 'credit'];
    public const PAYABLE_STATUSES = ['sent', 'viewed', 'partial', 'overdue'];

    protected PDO $db;
    private ?LedgerService $ledger;

    public function __construct(PDO $db, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->ledger = $ledger;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PROPOSAL (read only)
    // ══════════════════════════════════════════════════════════════════════════

    /** Everything the clean-up page shows. Never writes. */
    public function proposal(int $year = 2026): array
    {
        $data  = $this->load($year);
        $lines = self::classify($data['deposits'], $data['payments'], $data['invoices'], [
            'cutover' => self::CUTOVER,
            'locked'  => $data['locked'],
            'decided' => $data['decided'],
        ]);
        $income  = $this->incomeByMonth($year);
        $effect  = self::incomeEffect($income, $lines);
        return [
            'year'      => $year,
            'cutover'   => self::CUTOVER,
            'groups'    => self::summarise($lines),
            'lines'     => $lines,
            'income'    => $effect,
            'gst'       => self::gstEffect($year, $income, $lines, $this->filings($year)),
            'locked'    => $data['locked'],
            'journal'   => $this->journalCheck(),
            'log'       => $this->recentLog(40),
        ];
    }

    /** The data classify() needs. Portable SQL (MySQL 5.7 + SQLite in tests); windows are applied in PHP. */
    protected function load(int $year): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to   = sprintf('%04d-12-31', $year);
        $poolFrom = date('Y-m-d', strtotime($from) - 200 * 86400);

        $s = $this->db->prepare("
            SELECT t.id, t.transaction_date, t.amount, t.gst_amount, t.description, t.account_id, t.matched_invoice_id,
                   (SELECT COALESCE(SUM(a.amount), 0) FROM invoice_payment_allocations a WHERE a.transaction_id = t.id) AS allocated
            FROM accounting_transactions t
            WHERE t.reference_type = 'bank_import' AND t.type = 'income' AND t.amount > 0.005
              AND t.status IN ('cleared', 'reconciled')
              AND t.transaction_date BETWEEN ? AND ?
            ORDER BY t.transaction_date, t.id
        ");
        $s->execute([$from, $to . ' 23:59:59']);
        $deposits = $s->fetchAll(PDO::FETCH_ASSOC);

        // Payments recorded on invoices with no bank deposit linked (Record Payment, e-Transfer panel).
        $payments = [];
        $base = "SELECT a.id, a.invoice_id, a.amount, a.payment_date, a.method, a.reference, a.etransfer_notification_id,
                        i.invoice_number, i.bill_to_name, co.company_name, ct.first_name, ct.last_name%s
                 FROM invoice_payment_allocations a
                 JOIN invoices i ON i.id = a.invoice_id
                 LEFT JOIN companies co ON co.id = i.company_id
                 LEFT JOIN contacts  ct ON ct.id = i.contact_id%s
                 WHERE a.transaction_id IS NULL AND a.amount > 0 AND a.payment_date >= ?
                 ORDER BY a.payment_date, a.id";
        try {
            $q = $this->db->prepare(sprintf($base, ', n.amount AS email_amount, n.email_date, n.sender_name',
                                             ' LEFT JOIN etransfer_notifications n ON n.id = a.etransfer_notification_id'));
            $q->execute([$poolFrom]);
            $payments = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $q = $this->db->prepare(sprintf($base, ', NULL AS email_amount, NULL AS email_date, NULL AS sender_name', ''));
            $q->execute([$poolFrom]);
            $payments = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($payments as &$p) $p['payer'] = self::payerName($p);
        unset($p);

        // Invoices: paid near the year, or still open.
        $stripeCol = $this->has("SELECT 1 FROM stripe_payments LIMIT 0")
            ? "(SELECT COUNT(*) FROM stripe_payments sp WHERE sp.invoice_id = i.id AND sp.status = 'succeeded')" : "0";
        $q = $this->db->prepare("
            SELECT i.id, i.invoice_number, i.status, i.total, i.amount_paid, i.balance_due, i.payment_method,
                   i.paid_at, i.updated_at, i.invoice_date, i.stripe_charge_id,
                   i.bill_to_name, co.company_name, ct.first_name, ct.last_name,
                   (SELECT COUNT(*) FROM invoice_payment_allocations a WHERE a.invoice_id = i.id) AS alloc_count,
                   (SELECT MIN(a.transaction_id) FROM invoice_payment_allocations a WHERE a.invoice_id = i.id AND a.transaction_id IS NOT NULL) AS alloc_deposit_id,
                   (SELECT MIN(t.id) FROM accounting_transactions t WHERE t.reference_type = 'bank_import' AND t.matched_invoice_id = i.id) AS claimed_by,
                   {$stripeCol} AS stripe_paid
            FROM invoices i
            LEFT JOIN companies co ON co.id = i.company_id
            LEFT JOIN contacts  ct ON ct.id = i.contact_id
            WHERE (i.status IN ('paid', 'partial') AND COALESCE(i.paid_at, i.updated_at) >= ?)
               OR (i.balance_due > 0.005 AND i.status IN ('sent', 'viewed', 'partial', 'overdue'))
            ORDER BY i.id
        ");
        $q->execute([$poolFrom]);
        $invoices = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($invoices as &$i) $i['payer'] = self::payerName($i);
        unset($i);

        // Invoices this clean-up already claimed (booked, not reversed) are never offered again.
        $decided = [];
        $claimedInvoices = [];
        $stillIncome = array_flip(array_map(fn($d) => (int)$d['id'], $deposits));
        if ($this->has("SELECT 1 FROM income_cleanup_log LIMIT 0")) {
            foreach ($this->db->query("SELECT transaction_id, status, action, invoice_ids, note FROM income_cleanup_log
                                       WHERE status IN ('booked', 'skipped') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $decided[(int)$r['transaction_id']] = ['status' => $r['status'], 'action' => $r['action'], 'note' => $r['note']];
                if ($r['status'] === 'booked' && !isset($stillIncome[(int)$r['transaction_id']])) {   // undone elsewhere = no claim
                    foreach (self::ids((string)$r['invoice_ids']) as $iid) $claimedInvoices[$iid] = (int)$r['transaction_id'];
                }
            }
        }
        foreach ($invoices as &$i) {
            if (empty($i['claimed_by']) && isset($claimedInvoices[(int)$i['id']])) $i['claimed_by'] = $claimedInvoices[(int)$i['id']];
        }
        unset($i);

        return ['deposits' => $deposits, 'payments' => $payments, 'invoices' => $invoices,
                'locked' => $this->lockedMonths(), 'decided' => $decided];
    }

    /** type='income' rows (invoices + deposits) per month, as the P&L sums them; plus the old ledger GST line 101 basis. */
    protected function incomeByMonth(int $year): array
    {
        $s = $this->db->prepare("
            SELECT SUBSTR(transaction_date, 1, 7) AS month,
                   SUM(amount) AS income,
                   SUM(CASE WHEN reference_type = 'invoice' THEN amount ELSE 0 END) AS invoices,
                   SUM(CASE WHEN reference_type = 'bank_import' THEN amount ELSE 0 END) AS deposits,
                   SUM(amount + COALESCE(gst_amount, 0) + COALESCE(pst_amount, 0)) AS ledger_101
            FROM accounting_transactions
            WHERE type = 'income' AND status IN ('cleared', 'reconciled') AND transaction_date BETWEEN ? AND ?
            GROUP BY SUBSTR(transaction_date, 1, 7)
            ORDER BY month
        ");
        $s->execute([sprintf('%04d-01-01', $year), sprintf('%04d-12-31 23:59:59', $year)]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['month']] = ['income' => round((float)$r['income'], 2), 'invoices' => round((float)$r['invoices'], 2),
                                 'deposits' => round((float)$r['deposits'], 2), 'ledger_101' => round((float)$r['ledger_101'], 2)];
        }
        return $out;
    }

    /** GST returns Tim has told us he filed (migration 1228). [] before it. */
    protected function filings(int $year): array
    {
        if (!$this->has("SELECT 1 FROM gst_filings LIMIT 0")) return [];
        $s = $this->db->prepare("SELECT id, period_from, period_to, filed_on, basis, line_101, notes FROM gst_filings
                                 WHERE period_to >= ? AND period_from <= ? ORDER BY period_from");
        $s->execute([sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Deposits already flipped to 'transfer' by reconciliation (allocations, or "already recorded")
     * WITHOUT matched_invoice_id, that have a live bank journal entry. LedgerSyncService posts a
     * 'transfer' bank line as money OUT (Dr category / Cr bank), so each of these is in the
     * journal backwards: cash and revenue both understated by 2 × amount... report only.
     */
    public function journalCheck(): array
    {
        try {
            $rows = $this->db->query("
                SELECT t.id, t.transaction_date, t.amount, t.description
                FROM accounting_transactions t
                WHERE t.reference_type = 'bank_import' AND t.type = 'transfer'
                  AND t.matched_invoice_id IS NULL AND t.matched_expense_id IS NULL
                  AND (EXISTS (SELECT 1 FROM invoice_payment_allocations a WHERE a.transaction_id = t.id)
                       OR t.notes LIKE '%Marked as already recorded%')
                  AND EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_type = 'bank_import' AND je.source_id = t.id
                                AND je.reversed_by_entry_id IS NULL)
                ORDER BY t.transaction_date
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['ok' => null, 'error' => $e->getMessage(), 'count' => 0, 'total' => 0.0, 'lines' => []];
        }
        return ['ok' => !$rows, 'count' => count($rows), 'total' => round(array_sum(array_map(fn($r) => (float)$r['amount'], $rows)), 2),
                'lines' => array_slice($rows, 0, 50)];
    }

    public function recentLog(int $limit = 40): array
    {
        if (!$this->has("SELECT 1 FROM income_cleanup_log LIMIT 0")) return [];
        $s = $this->db->prepare("SELECT id, transaction_id, bucket, action, amount, invoice_numbers, status, note, booked_at, reversed_at
                                 FROM income_cleanup_log ORDER BY id DESC LIMIT " . max(1, min(500, $limit)));
        $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // BOOK / SKIP / REVERSE
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Book approved lines. $approved: txId => signature the page showed. The proposal is
     * rebuilt here; a line is booked only if it is still bookable with the same signature.
     * @return array{booked: array, failed: array, income_removed: float}
     */
    public function book(array $approved, int $userId, int $year = 2026): array
    {
        if (!$this->has("SELECT 1 FROM income_cleanup_log LIMIT 0")) {
            return ['booked' => [], 'failed' => [['id' => 0, 'message' => 'Run migration 1228 first — every booking is logged so it can be undone.']], 'income_removed' => 0.0];
        }
        $prop = [];
        foreach ($this->proposal($year)['lines'] as $l) $prop[$l['id']] = $l;
        $booked = []; $failed = []; $removed = 0.0;
        foreach ($approved as $txId => $sig) {
            $txId = (int)$txId;
            $line = $prop[$txId] ?? null;
            $why = self::refusal($line, (string)$sig, $this->isLocked($line['date'] ?? ''));
            if ($why !== null) { $failed[] = ['id' => $txId, 'message' => $why]; continue; }
            try {
                $r = $this->bookLine($line, $userId);
                $booked[] = ['id' => $txId] + $r;
                $removed += (float)$line['income_delta'];
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                $failed[] = ['id' => $txId, 'message' => $e->getMessage()];
            }
        }
        return ['booked' => $booked, 'failed' => $failed, 'income_removed' => round(-$removed, 2)];
    }

    /** Why a line may not be booked now; null = go. Pure. */
    public static function refusal(?array $line, string $signature, bool $locked): ?string
    {
        if (!$line) return 'That deposit is no longer waiting (booked or changed elsewhere) — reload.';
        if (!in_array($line['bucket'], self::BOOKABLE, true) || ($line['action'] ?? 'none') === 'none') return 'Nothing to book on this line — it needs you.';
        if (!empty($line['skipped'])) return 'You skipped this line — un-skip it first.';
        if ($locked || !empty($line['locked'])) return substr((string)$line['date'], 0, 7) . ' is locked — nothing in a closed month changes.';
        if (!hash_equals((string)$line['signature'], $signature)) return 'This line changed since you looked (' . $line['say'] . ') — reload and check it again.';
        return null;
    }

    /** One line through the existing reconciliation paths. Logged. */
    protected function bookLine(array $line, int $userId): array
    {
        $txId = (int)$line['id'];
        $before = $this->snapshot($txId);
        if (!$before) throw new RuntimeException('Bank line not found.');
        $recon = new InvoiceReconciliationService($this->db);
        $detail = [];

        switch ($line['action']) {
            case 'link_payments':
                $this->db->beginTransaction();
                $recon->linkAllocationsToDeposit($txId, $line['allocation_ids'], $userId);
                $this->claim($txId, $line['invoice_ids'][0]);
                $logId = $this->log($line, 'booked', $before, $userId, $detail);
                $this->db->commit();
                break;

            case 'already_recorded':
                foreach ($line['invoice_ids'] as $iid) $this->assertStillPaidUnlinked($iid, $txId);
                $this->db->beginTransaction();
                $recon->markDepositAlreadyRecorded($txId, $userId, 'income clean-up: payment already on ' . implode(', ', $line['invoice_numbers']));
                $this->claim($txId, $line['invoice_ids'][0]);
                $logId = $this->log($line, 'booked', $before, $userId, $detail);
                $this->db->commit();
                break;

            case 'record_payment':
                foreach ($line['allocations'] as $a) $this->assertOwes((int)$a['invoice_id'], (float)$a['amount']);
                $res = $recon->attach($txId, $line['allocations'], $userId);   // its own DB transaction
                if (empty($res['fully_allocated'])) {
                    $recon->detach($txId, null, $userId);
                    throw new RuntimeException('Recording left part of the deposit unexplained — undone, nothing changed.');
                }
                $this->claim($txId, $line['invoice_ids'][0]);
                $logId = $this->log($line, 'booked', $before, $userId, $detail);
                break;

            case 'stripe_payout':
                $invRows = $this->invoiceRowStatuses($line['invoice_ids']);
                $r = $this->stripe()->apply($txId, $userId);
                if (empty($r['ok'])) throw new RuntimeException($r['message'] ?? 'Stripe payout not booked.');
                $ids = array_map(fn($i) => (int)$i['invoice_id'], $r['invoices'] ?? []);
                $line['invoice_ids'] = $ids;
                $line['invoice_numbers'] = array_column($r['invoices'] ?? [], 'number');
                $detail = ['invoice_rows' => $invRows + $this->invoiceRowStatuses($ids), 'fee_tx_id' => $this->stripeFeeLine($txId), 'fees' => $r['fees'] ?? 0];
                $logId = $this->log($line, 'booked', $before, $userId, $detail);
                break;

            default:
                throw new RuntimeException('Unknown action ' . $line['action']);
        }
        return ['log_id' => $logId, 'action' => $line['action'], 'amount' => $line['amount'], 'invoices' => $line['invoice_numbers']];
    }

    /** Skip a line (it stays as it is; Tim can un-skip). $note e.g. "Jobber invoice paid late — real income". */
    public function skip(int $txId, int $userId, string $note = '', int $year = 2026): array
    {
        $line = null;
        foreach ($this->proposal($year)['lines'] as $l) if ($l['id'] === $txId) $line = $l;
        if (!$line) return ['ok' => false, 'message' => 'That deposit is no longer waiting.'];
        if (!empty($line['skipped'])) return ['ok' => true, 'message' => 'Already skipped.'];
        $line['note'] = trim($note);
        $this->log($line, 'skipped', $this->snapshot($txId) ?: [], $userId, []);
        return ['ok' => true, 'message' => 'Skipped — left exactly as it is.'];
    }

    public function unskip(int $txId, int $userId): array
    {
        $this->db->prepare("UPDATE income_cleanup_log SET status = 'unskipped', reversed_by = ?, reversed_at = ? WHERE transaction_id = ? AND status = 'skipped'")
           ->execute([$userId, date('Y-m-d H:i:s'), $txId]);
        return ['ok' => true, 'message' => 'Back on the list.'];
    }

    /** Put a booked line back exactly as it was. */
    public function reverse(int $logId, int $userId): array
    {
        $s = $this->db->prepare("SELECT * FROM income_cleanup_log WHERE id = ?");
        $s->execute([$logId]);
        $log = $s->fetch(PDO::FETCH_ASSOC);
        if (!$log || $log['status'] !== 'booked') return ['ok' => false, 'message' => 'Nothing to undo there.'];
        $txId = (int)$log['transaction_id'];
        $before = json_decode((string)$log['before_row'], true) ?: [];
        $detail = json_decode((string)$log['detail'], true) ?: [];
        if ($this->isLocked((string)($before['transaction_date'] ?? ''))) {
            return ['ok' => false, 'message' => substr((string)$before['transaction_date'], 0, 7) . ' is locked — it can\'t be undone in a closed month.'];
        }
        try {
            switch ($log['action']) {
                case 'link_payments':
                    $this->db->beginTransaction();
                    $ids = self::ids((string)$log['allocation_ids']);
                    if ($ids) {
                        $in = implode(',', array_fill(0, count($ids), '?'));
                        $this->db->prepare("UPDATE invoice_payment_allocations SET transaction_id = NULL WHERE transaction_id = ? AND id IN ({$in})")
                           ->execute(array_merge([$txId], $ids));
                    }
                    $this->restore($txId, $before);
                    break;
                case 'already_recorded':
                    $this->db->beginTransaction();
                    $this->restore($txId, $before);
                    break;
                case 'record_payment':
                    (new InvoiceReconciliationService($this->db))->detach($txId, null, $userId);   // own transaction
                    $this->db->beginTransaction();
                    $this->restore($txId, $before);
                    break;
                case 'stripe_payout':
                    $this->db->beginTransaction();
                    $this->restore($txId, $before);
                    foreach (($detail['invoice_rows'] ?? []) as $rowId => $st) {
                        $this->db->prepare("UPDATE accounting_transactions SET status = ? WHERE id = ?")->execute([$st, (int)$rowId]);
                    }
                    if (!empty($detail['fee_tx_id'])) {
                        $this->db->prepare("UPDATE accounting_transactions SET status = 'void' WHERE id = ?")->execute([(int)$detail['fee_tx_id']]);
                    }
                    try {   // StripePayoutService marked it reviewed; back on Penny's list too
                        $this->db->prepare("DELETE FROM bank_line_reviews WHERE transaction_id = ?")->execute([$txId]);
                    } catch (Throwable $e) { /* before migration 1129 */ }
                    break;
                default:
                    return ['ok' => false, 'message' => 'Unknown action'];
            }
            $this->setStaging($txId, 'unmatched');
            $this->db->prepare("UPDATE income_cleanup_log SET status = 'reversed', reversed_by = ?, reversed_at = ? WHERE id = ?")
               ->execute([$userId, date('Y-m-d H:i:s'), $logId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'message' => 'Not undone: ' . $e->getMessage()];
        }
        if (!empty($detail['fee_tx_id'])) {   // the fee's journal entry, the append-only way
            try {
                $l = $this->ledger();
                $e = $l->findEntryIdBySource('bank_import', (int)$detail['fee_tx_id']);
                if ($e) $l->reverseEntry($e, $userId, 'income clean-up undone — Stripe payout back to unbooked', 'owner');
            } catch (Throwable $e) {
                error_log('income clean-up: fee journal reversal failed for tx ' . $detail['fee_tx_id'] . ': ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'message' => 'Undone — the deposit is back as it was.'];
    }

    /** Record a GST return Tim filed (so changes to it show as "adjust on the next return"). */
    public function recordFiling(string $from, string $to, ?string $filedOn, string $basis, ?float $line101, string $notes, int $userId): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            return ['ok' => false, 'message' => 'Give the period as two dates, from ≤ to.'];
        }
        if (!in_array($basis, ['ledger', 'invoices', 'accountant', 'unknown'], true)) $basis = 'unknown';
        $this->db->prepare("DELETE FROM gst_filings WHERE period_from = ? AND period_to = ?")->execute([$from, $to]);
        $this->db->prepare("INSERT INTO gst_filings (period_from, period_to, filed_on, basis, line_101, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$from, $to, $filedOn ?: null, $basis, $line101, $notes, $userId]);
        return ['ok' => true, 'message' => 'Noted: ' . $from . ' → ' . $to . ' filed.'];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Pure (unit tested)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Sort every deposit into a bucket with one proposed action. Deposits are taken
     * oldest first; a recorded payment or invoice is claimed by at most ONE deposit.
     *
     * @param array $deposits id, transaction_date, amount, description, allocated?, gst_amount?
     * @param array $payments unlinked allocations: id, invoice_id, invoice_number, amount, payment_date, method,
     *                        reference, payer, etransfer_notification_id, email_amount, email_date, sender_name
     * @param array $invoices id, invoice_number, status, total, amount_paid, balance_due, payment_method, paid_at,
     *                        updated_at, invoice_date, stripe_charge_id, payer, alloc_count, alloc_deposit_id, claimed_by, stripe_paid
     * @param array $opts     cutover, locked ['YYYY-MM'], decided [txId => {status, note}]
     */
    public static function classify(array $deposits, array $payments, array $invoices, array $opts = []): array
    {
        $cutover = (string)($opts['cutover'] ?? self::CUTOVER);
        $locked  = array_flip($opts['locked'] ?? []);
        $decided = $opts['decided'] ?? [];
        usort($deposits, fn($a, $b) => [substr((string)$a['transaction_date'], 0, 10), (int)$a['id']] <=> [substr((string)$b['transaction_date'], 0, 10), (int)$b['id']]);

        $legacy = []; $open = []; $card = []; $linked = [];
        foreach ($invoices as $i) {
            $i['paid_on'] = substr((string)($i['paid_at'] ?: $i['updated_at']), 0, 10);
            $isCard = self::isCard($i);
            if (($i['status'] ?? '') === 'paid' && !$isCard && (int)($i['alloc_count'] ?? 0) === 0 && empty($i['claimed_by'])) $legacy[(int)$i['id']] = $i;
            if ((float)$i['balance_due'] > 0.005 && in_array($i['status'] ?? '', self::PAYABLE_STATUSES, true) && empty($i['claimed_by'])) $open[(int)$i['id']] = $i;
            if (in_array($i['status'] ?? '', ['paid', 'partial'], true) && $isCard) $card[(int)$i['id']] = $i;
            if (!empty($i['alloc_deposit_id']) || !empty($i['claimed_by'])) $linked[(int)$i['id']] = $i;
        }
        $pay = [];
        foreach ($payments as $p) {
            if (in_array(strtolower((string)$p['method']), self::CARD_METHODS, true)) continue;   // card money arrives in a Stripe payout
            $pay[(int)$p['id']] = $p;
        }
        $usedPay = []; $usedInv = [];

        $out = [];
        foreach ($deposits as $d) {
            $id = (int)$d['id'];
            $date = substr((string)$d['transaction_date'], 0, 10);
            $amount = round((float)$d['amount'], 2);
            $desc = (string)$d['description'];
            $line = ['id' => $id, 'date' => $date, 'month' => substr($date, 0, 7), 'amount' => $amount,
                     'gst' => round((float)($d['gst_amount'] ?? 0), 2), 'description' => $desc,
                     'bucket' => 'needs_you', 'action' => 'none', 'invoice_ids' => [], 'invoice_numbers' => [],
                     'allocation_ids' => [], 'allocations' => [], 'payer' => '', 'say' => '', 'flag' => null, 'note' => null,
                     'candidates' => [], 'income_delta' => 0.0, 'locked' => isset($locked[substr($date, 0, 7)]),
                     'skipped' => ($decided[$id]['status'] ?? '') === 'skipped', 'skip_note' => $decided[$id]['note'] ?? null];
            $m = '$' . number_format($amount, 2);

            if ($date < $cutover) {
                $line['bucket'] = 'jobber';
                $line['flag'] = self::nonIncomeReason($desc);
                $line['say'] = $line['flag'] ? "Jobber era, but {$line['flag']} — probably not income." : 'Jobber era — the deposit is the only record of this income. Stays income.';
                $out[] = self::sign($line);
                continue;
            }
            if (preg_match('/\bSTRIPE\b/i', $desc)) {
                $line['bucket'] = 'stripe';
                $line['action'] = 'stripe_payout';
                $line['income_delta'] = -$amount;
                $line['say'] = "Stripe payout {$m}: its card payments are already income on their invoices. I check Stripe when you approve and book only if it adds up to the cent (fees go to 6850).";
                $out[] = self::sign($line);
                continue;
            }
            $allocated = round((float)($d['allocated'] ?? 0), 2);
            $words = InvoiceReconciliationService::bankMemoWords($desc);
            $inWindow = fn(string $on) => $on !== '' && $on >= self::shift($date, -self::BEFORE_DAYS) && $on <= self::shift($date, self::AFTER_DAYS);

            // 1. Payments already recorded by hand that add up to this deposit.
            $pool = array_values(array_filter($pay, fn($p) => !isset($usedPay[(int)$p['id']]) && $inWindow(substr((string)$p['payment_date'], 0, 10))));
            $rec = BankInvoiceMatchService::pickRecorded($pool, $amount, $date, null);
            if (count($rec) === 1) {
                $hit = $rec[0];
                $rows = array_values(array_filter($pool, fn($p) => in_array((int)$p['id'], $hit['allocation_ids'], true)));
                $line['action'] = 'link_payments';
                $line['allocation_ids'] = $hit['allocation_ids'];
                $line['invoice_ids'] = array_values(array_unique(array_map(fn($p) => (int)$p['invoice_id'], $rows)));
                $line['invoice_numbers'] = $hit['invoice_numbers'];
                $line['payer'] = $hit['payer'];
                $line['bucket'] = count($hit['allocation_ids']) > 1 ? 'exact_sum' : 'exact';
                $line['income_delta'] = -$amount;
                $line['say'] = "{$m} is already recorded on " . implode(', ', $hit['invoice_numbers']) . " ({$hit['how']}). Link it — counts once.";
                foreach ($hit['allocation_ids'] as $a) $usedPay[$a] = $id;
                foreach ($line['invoice_ids'] as $iid) $usedInv[$iid] = $id;
                $out[] = self::sign($line);
                continue;
            }
            if (count($rec) > 1) {
                $line['say'] = "{$m} matches payments already recorded for more than one client — which one?";
                $line['candidates'] = array_map(fn($r) => ['kind' => 'recorded', 'invoices' => $r['invoice_numbers'], 'payer' => $r['payer'], 'how' => $r['how']], $rec);
                $out[] = self::sign($line);
                continue;
            }
            if ($allocated > 0.005) {
                $line['say'] = "Part of this deposit is already on an invoice; {$m} of it is still counted as income. Look at what the rest was.";
                $line['candidates'] = self::closest($amount, $date, $pay, $legacy, $open, $usedPay, $usedInv);
                $out[] = self::sign($line);
                continue;
            }

            // 2. An invoice paid the old way (no allocation rows) for exactly this amount.
            $leg = array_values(array_filter($legacy, fn($i) => !isset($usedInv[(int)$i['id']]) && $inWindow($i['paid_on'])
                                              && abs(round((float)$i['amount_paid'], 2) - $amount) < 0.005));
            if ($leg) {
                $pick = self::best($leg, $words, $date, 'paid_on');
                $line['action'] = 'already_recorded';
                $line['bucket'] = 'exact';
                $line['invoice_ids'] = [(int)$pick['id']];
                $line['invoice_numbers'] = [(string)$pick['invoice_number']];
                $line['payer'] = (string)$pick['payer'];
                $line['income_delta'] = -$amount;
                $line['say'] = "{$m} = {$pick['invoice_number']}" . ($pick['payer'] !== '' ? " ({$pick['payer']})" : '') . ", marked paid on {$pick['paid_on']}. Link it — counts once.";
                if (count($leg) > 1) $line['note'] = count($leg) . ' invoices of this amount were paid around then — I picked ' . ($pick['_why'] ?? 'the closest date') . '. The income is the same whichever it is.';
                $usedInv[(int)$pick['id']] = $id;
                $out[] = self::sign($line);
                continue;
            }

            // 3. Several old-way paid invoices of ONE payer adding up exactly.
            $set = self::payerSubset(array_filter($legacy, fn($i) => !isset($usedInv[(int)$i['id']]) && $inWindow($i['paid_on'])), $amount, 'amount_paid', 'paid_on');
            if ($set === false) {
                $line['say'] = "{$m} adds up to paid invoices of more than one client — which one?";
                $line['candidates'] = self::closest($amount, $date, $pay, $legacy, $open, $usedPay, $usedInv);
                $out[] = self::sign($line);
                continue;
            }
            if ($set) {
                $line['action'] = 'already_recorded';
                $line['bucket'] = 'exact_sum';
                $line['invoice_ids'] = array_map(fn($i) => (int)$i['id'], $set);
                $line['invoice_numbers'] = array_map(fn($i) => (string)$i['invoice_number'], $set);
                $line['payer'] = (string)$set[0]['payer'];
                $line['income_delta'] = -$amount;
                $line['say'] = "{$m} = " . self::listing($line['invoice_numbers']) . ($line['payer'] !== '' ? " ({$line['payer']})" : '') . ', all already marked paid. Link them — counts once.';
                foreach ($line['invoice_ids'] as $iid) $usedInv[$iid] = $id;
                $out[] = self::sign($line);
                continue;
            }

            // 4. An open invoice owing exactly this — only when the payer is named or the amount is unique.
            $openFit = array_values(array_filter($open, fn($i) => !isset($usedInv[(int)$i['id']]) && self::openInWindow($i, $date)
                                                  && abs(round((float)$i['balance_due'], 2) - $amount) < 0.005));
            $named = array_values(array_filter($openFit, fn($i) => $words && InvoiceReconciliationService::payerMatchesWords((string)$i['payer'], $words)));
            if (count($named) >= 1 || count($openFit) === 1) {
                $pick = self::best($named ?: $openFit, $words, $date, 'invoice_date');
                $line['action'] = 'record_payment';
                $line['bucket'] = 'exact';
                $line['invoice_ids'] = [(int)$pick['id']];
                $line['invoice_numbers'] = [(string)$pick['invoice_number']];
                $line['allocations'] = [['invoice_id' => (int)$pick['id'], 'amount' => $amount]];
                $line['payer'] = (string)$pick['payer'];
                $line['say'] = "{$pick['invoice_number']}" . ($pick['payer'] !== '' ? " ({$pick['payer']})" : '') . " still shows {$m} owing. Record this deposit as its payment — the income moves from the bank line to the invoice (no change in total).";
                $line['note'] = $named ? 'The bank memo names this client.' : 'The only open invoice of this amount.';
                $usedInv[(int)$pick['id']] = $id;
                $out[] = self::sign($line);
                continue;
            }

            // 5. Open invoices of ONE payer adding up exactly (named in the memo, so a set never mixes clients).
            $openSet = self::payerSubset(array_filter($open, fn($i) => !isset($usedInv[(int)$i['id']]) && self::openInWindow($i, $date)
                                                     && $words && InvoiceReconciliationService::payerMatchesWords((string)$i['payer'], $words)),
                                         $amount, 'balance_due', 'invoice_date');
            if ($openSet) {
                $line['action'] = 'record_payment';
                $line['bucket'] = 'exact_sum';
                $line['invoice_ids'] = array_map(fn($i) => (int)$i['id'], $openSet);
                $line['invoice_numbers'] = array_map(fn($i) => (string)$i['invoice_number'], $openSet);
                $line['allocations'] = array_map(fn($i) => ['invoice_id' => (int)$i['id'], 'amount' => round((float)$i['balance_due'], 2)], $openSet);
                $line['payer'] = (string)$openSet[0]['payer'];
                $line['say'] = "{$m} pays " . self::listing($line['invoice_numbers']) . " ({$line['payer']}) exactly. Record it on them — no change in total income.";
                foreach ($line['invoice_ids'] as $iid) $usedInv[$iid] = $id;
                $out[] = self::sign($line);
                continue;
            }

            // 6. Needs you — with the reason when it is a conflict.
            $conflict = null;
            foreach ($card as $i) {
                if (abs(round((float)$i['amount_paid'], 2) - $amount) < 0.005 && $inWindow($i['paid_on'])) {
                    $conflict = "same amount as {$i['invoice_number']}, which was paid by card (its money comes in a Stripe payout) — a second payment, or a coincidence?";
                    break;
                }
            }
            if (!$conflict) {
                foreach ($linked as $i) {
                    if (abs(round((float)$i['amount_paid'], 2) - $amount) < 0.005 && $inWindow(substr((string)($i['paid_at'] ?: $i['updated_at']), 0, 10))) {
                        $conflict = "same amount as {$i['invoice_number']}, but that invoice is already tied to bank line #" . ($i['alloc_deposit_id'] ?: $i['claimed_by']) . ' — paid twice, or another invoice?';
                        break;
                    }
                }
            }
            if ($openFit) $line['note'] = count($openFit) . ' open invoices owe exactly ' . $m . ' and the memo doesn\'t say who paid.';
            $line['say'] = $conflict ? "{$m}: {$conflict}" : "I can't make {$m} add up to any invoice or recorded payment — a partial, a Jobber invoice paid late (then it IS income), or not a client payment.";
            $line['flag'] = self::nonIncomeReason($desc);
            $line['candidates'] = self::closest($amount, $date, $pay, $legacy, $open, $usedPay, $usedInv);
            $out[] = self::sign($line);
        }
        return $out;
    }

    /** Totals per bucket. Pure. */
    public static function summarise(array $lines): array
    {
        $g = [];
        foreach (self::BUCKETS as $b) $g[$b] = ['bucket' => $b, 'count' => 0, 'total' => 0.0, 'bookable' => 0, 'bookable_total' => 0.0,
                                                'income_delta' => 0.0, 'skipped' => 0, 'flagged' => 0, 'flagged_total' => 0.0, 'locked' => 0];
        foreach ($lines as $l) {
            $x = &$g[$l['bucket']];
            $x['count']++;
            $x['total'] += $l['amount'];
            if (!empty($l['skipped'])) $x['skipped']++;
            if (!empty($l['locked'])) $x['locked']++;
            if ($l['flag']) { $x['flagged']++; $x['flagged_total'] += $l['amount']; }
            if (self::isBookable($l)) {
                $x['bookable']++;
                $x['bookable_total'] += $l['amount'];
                $x['income_delta'] += $l['income_delta'];
            }
            unset($x);
        }
        foreach ($g as &$x) foreach (['total', 'bookable_total', 'income_delta', 'flagged_total'] as $k) $x[$k] = round($x[$k], 2);
        unset($x);
        return $g;
    }

    public static function isBookable(array $l): bool
    {
        return in_array($l['bucket'], self::BOOKABLE, true) && $l['action'] !== 'none' && empty($l['skipped']) && empty($l['locked']);
    }

    /**
     * Income before → after per month if every bookable line is approved. Pure.
     * @param array $income month => {income, invoices, deposits, ledger_101}
     */
    public static function incomeEffect(array $income, array $lines): array
    {
        $months = [];
        foreach ($income as $mo => $r) $months[$mo] = ['month' => $mo, 'before' => (float)$r['income'], 'delta' => 0.0, 'needs_you' => 0.0, 'jobber_flagged' => 0.0];
        foreach ($lines as $l) {
            $mo = $l['month'];
            if (!isset($months[$mo])) $months[$mo] = ['month' => $mo, 'before' => 0.0, 'delta' => 0.0, 'needs_you' => 0.0, 'jobber_flagged' => 0.0];
            if (self::isBookable($l)) $months[$mo]['delta'] += (float)$l['income_delta'];
            elseif ($l['bucket'] === 'needs_you' && empty($l['skipped'])) $months[$mo]['needs_you'] += $l['amount'];
            if ($l['bucket'] === 'jobber' && $l['flag']) $months[$mo]['jobber_flagged'] += $l['amount'];
        }
        ksort($months);
        $tot = ['before' => 0.0, 'delta' => 0.0, 'after' => 0.0, 'needs_you' => 0.0, 'jobber_flagged' => 0.0];
        foreach ($months as &$m) {
            foreach (['before', 'delta', 'needs_you', 'jobber_flagged'] as $k) $m[$k] = round($m[$k], 2);
            $m['after'] = round($m['before'] + $m['delta'], 2);
            foreach ($tot as $k => $_) $tot[$k] += $m[$k];
        }
        unset($m);
        foreach ($tot as $k => $v) $tot[$k] = round($v, 2);
        // If every needs-you line also turns out to be money already on an invoice:
        $tot['after_if_needs_you_double'] = round($tot['after'] - $tot['needs_you'], 2);
        return ['months' => array_values($months), 'total' => $tot];
    }

    /**
     * GST line 101 per quarter. GstReportService (2026-10-07) reads line 101 from INVOICES,
     * so booking changes nothing there. The old ledger figure (SUM of type='income' rows —
     * what TaxEngine showed before) drops by each booked deposit. A filed return whose
     * figure came from the ledger gets "adjust on your next return". Pure.
     */
    public static function gstEffect(int $year, array $income, array $lines, array $filings): array
    {
        $delta = [];
        foreach ($lines as $l) {
            if (!self::isBookable($l)) continue;
            $sign = $l['income_delta'] < 0 ? -1 : 0;
            $delta[$l['month']] = ($delta[$l['month']] ?? 0) + $sign * ($l['amount'] + $l['gst']);
        }
        $periods = [];
        for ($q = 1; $q <= 4; $q++) {
            $from = sprintf('%04d-%02d-01', $year, $q * 3 - 2);
            $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $q * 3)));
            $periods[] = ['label' => "Q{$q} {$year}", 'from' => $from, 'to' => $to];
        }
        $sum = function (string $from, string $to, array $byMonth) {
            $t = 0.0;
            foreach ($byMonth as $mo => $v) if ($mo >= substr($from, 0, 7) && $mo <= substr($to, 0, 7)) $t += $v;
            return round($t, 2);
        };
        $ledger = array_map(fn($r) => (float)$r['ledger_101'], $income);
        $out = [];
        foreach ($periods as $p) {
            $before = $sum($p['from'], $p['to'], $ledger);
            $d = $sum($p['from'], $p['to'], $delta);
            $out[] = $p + ['ledger_before' => $before, 'ledger_after' => round($before + $d, 2), 'ledger_delta' => $d, 'invoice_basis_delta' => 0.0];
        }
        $filed = [];
        foreach ($filings as $f) {
            $d = $sum((string)$f['period_from'], (string)$f['period_to'], $delta);
            $basis = (string)($f['basis'] ?? 'unknown');
            $filed[] = ['from' => $f['period_from'], 'to' => $f['period_to'], 'filed_on' => $f['filed_on'] ?? null, 'basis' => $basis,
                        'line_101_filed' => $f['line_101'] !== null ? (float)$f['line_101'] : null, 'ledger_delta' => $d,
                        'adjust' => abs($d) > 0.005 && $basis !== 'invoices',
                        'say' => abs($d) < 0.005 ? 'No change.'
                               : ($basis === 'invoices' ? 'Filed from invoices — booking does not change it.'
                               : 'Line 101 was ' . ($d < 0 ? 'over' : 'under') . 'stated by $' . number_format(abs($d), 2) . ' if it came from the ledger — adjustment needed on your NEXT return (the filed one is not changed).')];
        }
        return ['periods' => $out, 'filed' => $filed, 'filings_known' => (bool)$filings];
    }

    /** "a funds transfer" etc. when a credit is obviously not a sale; null otherwise. Pure. */
    public static function nonIncomeReason(string $description): ?string
    {
        $d = strtoupper($description);
        $rules = [
            '/\bTFR-FR\b|FUNDS TRANSFER|TRANSFER FROM|\bTFR FROM\b|\bFROM SAVINGS\b|\bBR TO BR\b|ONLINE TRANSFER|INTERNET TRANSFER|\bACCT TRANSFER\b|\bXFER\b/' => 'a transfer between accounts',
            '/\bLOAN\b|\bLOC\b|LINE OF CREDIT|\bADVANCE\b|\bFINANCING\b/' => 'loan or credit-line money',
            '/\bREFUND\b|\bREVERSAL\b|\bRETURN(ED)? ITEM\b|\bCHARGEBACK\b|\bREBATE\b/' => 'a refund or reversal',
            '/\bGST\b|\bCRA\b|RECEIVER GENERAL|CANADA REVENUE|\bTAX REFUND\b/' => 'a government / tax payment',
            '/\bSHAREHOLDER\b|\bOWNER\b|\bCAPITAL CONTRIBUTION\b/' => 'owner money',
            '/\bINSURANCE\b|\bICBC\b|\bCLAIM\b/' => 'an insurance payment',
            '/\bINTEREST\b/' => 'bank interest (other income, not sales)',
        ];
        foreach ($rules as $re => $why) if (preg_match($re, $d)) return $why;
        return null;
    }

    /** One payer's invoices adding up exactly: the set, [] when none, false when 2+ payers fit. Pure. */
    public static function payerSubset(array $invoices, float $amount, string $amountKey, string $dateKey)
    {
        $byPayer = [];
        foreach ($invoices as $i) {
            $v = round((float)$i[$amountKey], 2);
            if ($v <= 0.005 || $v >= $amount - 0.005) continue;
            $key = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$i['payer']));
            if ($key === '') continue;
            $byPayer[$key][] = $i;
        }
        $hits = [];
        foreach ($byPayer as $rows) {
            usort($rows, fn($a, $b) => [(string)$a[$dateKey], (int)$a['id']] <=> [(string)$b[$dateKey], (int)$b['id']]);
            $rows = array_slice($rows, 0, 12);
            $pick = InvoiceReconciliationService::exactSubsetBounded(array_map(fn($r) => (float)$r[$amountKey], $rows), $amount, 4, 12);
            if ($pick === null || count($pick) < 2) continue;
            $hits[] = array_map(fn($k) => $rows[$k], $pick);
        }
        if (count($hits) > 1) return false;
        return $hits[0] ?? [];
    }

    /** Stable fingerprint of what approving a line would do. Pure. */
    public static function sign(array $line): array
    {
        $ids = $line['invoice_ids']; sort($ids);
        $al = $line['allocation_ids']; sort($al);
        $line['signature'] = substr(sha1(implode('|', [$line['id'], $line['bucket'], $line['action'], number_format($line['amount'], 2, '.', ''),
                                                    implode(',', $ids), implode(',', $al)])), 0, 16);
        return $line;
    }

    // ── pure helpers ─────────────────────────────────────────────────────────

    private static function isCard(array $i): bool
    {
        return in_array(strtolower((string)($i['payment_method'] ?? '')), self::CARD_METHODS, true)
            || !empty($i['stripe_charge_id']) || (int)($i['stripe_paid'] ?? 0) > 0;
    }

    private static function openInWindow(array $i, string $date): bool
    {
        $issued = substr((string)($i['invoice_date'] ?? ''), 0, 10);
        if ($issued === '') return true;
        return $issued <= self::shift($date, 7) && $issued >= self::shift($date, -self::OPEN_MAX_DAYS);
    }

    /** Payer named in the memo first, then the closest date, then the lowest id. */
    private static function best(array $rows, array $words, string $date, string $dateKey): array
    {
        $named = $words ? array_values(array_filter($rows, fn($r) => InvoiceReconciliationService::payerMatchesWords((string)$r['payer'], $words))) : [];
        $pool = $named ?: $rows;
        usort($pool, fn($a, $b) => [abs(self::days((string)$a[$dateKey], $date)), (int)$a['id']] <=> [abs(self::days((string)$b[$dateKey], $date)), (int)$b['id']]);
        $pool[0]['_why'] = $named ? 'the one whose client the memo names' : 'the closest date';
        return $pool[0];
    }

    /** Up to 3 nearest amounts in the window, for the needs-you card. */
    private static function closest(float $amount, string $date, array $pay, array $legacy, array $open, array $usedPay, array $usedInv): array
    {
        $c = [];
        $lo = self::shift($date, -self::BEFORE_DAYS); $hi = self::shift($date, self::AFTER_DAYS);
        foreach ($pay as $p) {
            $on = substr((string)$p['payment_date'], 0, 10);
            if (isset($usedPay[(int)$p['id']]) || $on < $lo || $on > $hi) continue;
            $c[] = ['kind' => 'recorded', 'invoice' => $p['invoice_number'], 'payer' => $p['payer'], 'amount' => round((float)$p['amount'], 2), 'date' => $on];
        }
        foreach ($legacy as $i) {
            if (isset($usedInv[(int)$i['id']]) || $i['paid_on'] < $lo || $i['paid_on'] > $hi) continue;
            $c[] = ['kind' => 'paid', 'invoice' => $i['invoice_number'], 'payer' => $i['payer'], 'amount' => round((float)$i['amount_paid'], 2), 'date' => $i['paid_on']];
        }
        foreach ($open as $i) {
            if (isset($usedInv[(int)$i['id']]) || !self::openInWindow($i, $date)) continue;
            $c[] = ['kind' => 'open', 'invoice' => $i['invoice_number'], 'payer' => $i['payer'], 'amount' => round((float)$i['balance_due'], 2), 'date' => substr((string)$i['invoice_date'], 0, 10)];
        }
        usort($c, fn($a, $b) => [abs($a['amount'] - $amount), $a['invoice']] <=> [abs($b['amount'] - $amount), $b['invoice']]);
        return array_map(fn($x) => $x + ['diff' => round($x['amount'] - $amount, 2)], array_slice($c, 0, 3));
    }

    private static function listing(array $nums): string
    {
        if (count($nums) <= 2) return implode(' and ', $nums);
        return implode(', ', array_slice($nums, 0, -1)) . ' and ' . end($nums);
    }

    private static function shift(string $date, int $days): string
    {
        return date('Y-m-d', strtotime($date . ' 12:00:00') + $days * 86400);
    }

    private static function days(string $a, string $b): int
    {
        if ($a === '' || $b === '') return 9999;
        return (int)round((strtotime(substr($a, 0, 10)) - strtotime(substr($b, 0, 10))) / 86400);
    }

    public static function payerName(array $r): string
    {
        foreach ([$r['bill_to_name'] ?? '', $r['company_name'] ?? '', trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))] as $n) {
            if (trim((string)$n) !== '') return trim((string)$n);
        }
        return '';
    }

    /** "12,15" → [12, 15]. */
    public static function ids(string $csv): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DB helpers
    // ══════════════════════════════════════════════════════════════════════════

    protected function stripe(): StripePayoutService
    {
        return new StripePayoutService($this->db);
    }

    protected function ledger(): LedgerService
    {
        return $this->ledger ??= new LedgerService($this->db);
    }

    protected function isLocked(string $date): bool
    {
        if ($date === '') return false;
        return in_array(substr($date, 0, 7), $this->lockedMonths(), true);
    }

    protected function lockedMonths(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT year, month FROM accounting_periods WHERE status = 'locked'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[] = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']);
            }
        } catch (Throwable $e) { /* no periods table → nothing locked */ }
        return $out;
    }

    private function snapshot(int $txId): ?array
    {
        $s = $this->db->prepare("SELECT * FROM accounting_transactions WHERE id = ?");
        $s->execute([$txId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The bank row's fields back as they were (only the ones reconciliation changes). */
    private function restore(int $txId, array $before): void
    {
        $cols = ['type', 'amount', 'status', 'matched_invoice_id', 'match_confidence', 'matched_at', 'matched_by', 'notes', 'payment_reference'];
        $set = []; $vals = [];
        foreach ($cols as $c) {
            if (!array_key_exists($c, $before)) continue;
            $set[] = "{$c} = ?";
            $vals[] = $before[$c];
        }
        if (!$set) throw new RuntimeException('No saved state to restore.');
        $vals[] = $txId;
        $this->db->prepare("UPDATE accounting_transactions SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
    }

    /** Keep matched_invoice_id on a flipped deposit: the bank journal sync skips matched rows (a bare 'transfer' would post as money out). */
    private function claim(int $txId, int $invoiceId): void
    {
        $this->db->prepare("UPDATE accounting_transactions SET matched_invoice_id = COALESCE(matched_invoice_id, ?) WHERE id = ?")
           ->execute([$invoiceId, $txId]);
    }

    private function assertStillPaidUnlinked(int $invoiceId, int $txId): void
    {
        $s = $this->db->prepare("SELECT invoice_number, status,
                                        (SELECT COUNT(*) FROM invoice_payment_allocations a WHERE a.invoice_id = i.id) AS allocs,
                                        (SELECT MIN(t.id) FROM accounting_transactions t WHERE t.reference_type = 'bank_import' AND t.matched_invoice_id = i.id AND t.id <> ?) AS other
                                 FROM invoices i WHERE i.id = ?");
        $s->execute([$txId, $invoiceId]);
        $i = $s->fetch(PDO::FETCH_ASSOC);
        if (!$i) throw new RuntimeException("Invoice #{$invoiceId} not found.");
        if ($i['status'] !== 'paid') throw new RuntimeException("{$i['invoice_number']} is no longer marked paid — reload.");
        if ((int)$i['allocs'] > 0) throw new RuntimeException("{$i['invoice_number']} has a recorded payment now — reload.");
        if ($i['other']) throw new RuntimeException("{$i['invoice_number']} is already tied to bank line #{$i['other']} — not linking it twice.");
    }

    private function assertOwes(int $invoiceId, float $amount): void
    {
        $s = $this->db->prepare("SELECT invoice_number, balance_due, status FROM invoices WHERE id = ?");
        $s->execute([$invoiceId]);
        $i = $s->fetch(PDO::FETCH_ASSOC);
        if (!$i) throw new RuntimeException("Invoice #{$invoiceId} not found.");
        if (!in_array($i['status'], self::PAYABLE_STATUSES, true) || (float)$i['balance_due'] < $amount - 0.005) {
            throw new RuntimeException("{$i['invoice_number']} is already paid (owes \$" . number_format((float)$i['balance_due'], 2) . ") — not paying it twice.");
        }
    }

    /** invoice income row id => status, so a Stripe booking can be undone exactly. */
    private function invoiceRowStatuses(array $invoiceIds): array
    {
        $invoiceIds = array_values(array_filter(array_map('intval', $invoiceIds)));
        if (!$invoiceIds) return [];
        $in = implode(',', array_fill(0, count($invoiceIds), '?'));
        $s = $this->db->prepare("SELECT id, status FROM accounting_transactions WHERE reference_type = 'invoice' AND type = 'income' AND reference_id IN ({$in})");
        $s->execute($invoiceIds);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = (string)$r['status'];
        return $out;
    }

    private function stripeFeeLine(int $txId): ?int
    {
        $s = $this->db->prepare("SELECT f.id FROM accounting_transactions f JOIN accounting_transactions t ON t.id = ?
                                 WHERE f.type = 'expense' AND f.reference_type = 'bank_import' AND f.payment_reference = t.payment_reference
                                   AND f.id <> t.id ORDER BY f.id DESC LIMIT 1");
        $s->execute([$txId]);
        $id = $s->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function setStaging(int $txId, string $status): void
    {
        try {
            $this->db->prepare("UPDATE bank_import_rows SET match_status = ? WHERE transaction_id = ?")->execute([$status, $txId]);
        } catch (Throwable $e) { /* no staging row */ }
    }

    private function log(array $line, string $status, array $before, int $userId, array $detail): int
    {
        $this->db->prepare("
            INSERT INTO income_cleanup_log (transaction_id, bucket, action, amount, invoice_ids, invoice_numbers, allocation_ids,
                                            before_row, detail, status, note, booked_by, booked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            (int)$line['id'], $line['bucket'], $status === 'skipped' ? 'skip' : $line['action'], $line['amount'],
            implode(',', $line['invoice_ids']), mb_substr(implode(', ', $line['invoice_numbers']), 0, 500), implode(',', $line['allocation_ids']),
            json_encode($before), json_encode($detail + ['say' => $line['say'], 'signature' => $line['signature'] ?? null]),
            $status, $line['note'] ?? null, $userId, date('Y-m-d H:i:s'),
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function has(string $sql): bool
    {
        try {
            $this->db->query($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
