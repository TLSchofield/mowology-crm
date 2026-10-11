<?php
/**
 * DepositBackfillService — link orphan bank deposits to what they paid (owner, 2026-10-11).
 * Plan: docs/plans/2026-10-11-penny-payment-certainty.md, step 2.
 *
 * Orphans = income bank deposits since the Jobber cutover with no invoice linked. Candidates:
 *   - CRM invoices already marked PAID with no deposit behind them (the "hand-recorded payment"
 *     gap): the invoice's ledger row already recognises the income, so the deposit is the SAME
 *     money counted again → link it (allocation + deposit becomes a cash-clearing transfer, via
 *     InvoiceReconciliationService::linkAllocationsToDeposit — the same path as "Already recorded").
 *   - Jobber payments (jobber_payments): Jobber invoices are not in the books, the deposit is the
 *     only income record for that era → the deposit STAYS income; bank_deposit_sources records
 *     which payment it was (payer, method, Jobber invoice numbers) for Penny's payment habits.
 *
 * Matching (pure, unit-tested): method from the bank memo must fit the payment's method; payment
 * dated 30 days before to 5 days after the deposit; exact amount (one candidate, or several where
 * the memo names one payer); otherwise an exact group of up to 5 (cheques banked together).
 * Deposits that mix CRM and Jobber payments, ambiguous ones and unmatched ones are only listed.
 * Nothing dated before 2026-02-25 (FY2025 is filed and locked) is touched.
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

require_once __DIR__ . '/InvoiceReconciliationService.php';

class DepositBackfillService
{
    public const SINCE = '2026-02-25';
    public const DAYS_BEFORE = 30;
    public const DAYS_AFTER = 5;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** What the bank memo says this deposit is: cheque | e_transfer | stripe | eft | other. */
    public static function kindOf(string $memo): string
    {
        $u = strtoupper((string)preg_replace('/[^A-Za-z]/', '', $memo));
        if (str_contains($u, 'CHEQUE') || str_contains($u, 'MOBILEDEPOSIT') || str_contains($u, 'ATM')) return 'cheque';
        if (str_contains($u, 'ETRANSFER')) return 'e_transfer';
        if (str_contains($u, 'STRIPE')) return 'stripe';
        if (str_contains($u, 'PREAUTHORIZED')) return 'eft';
        return 'other';
    }

    /** The allocation method for a deposit of this kind (when the invoice recorded none). */
    public static function methodForKind(string $kind): string
    {
        return ['cheque' => 'cheque', 'e_transfer' => 'e_transfer', 'stripe' => 'credit_card'][$kind] ?? 'other';
    }

    /** Can a payment recorded with $method arrive as a deposit of this kind? */
    public static function methodFits(string $kind, string $method): bool
    {
        $m = str_replace([' ', '-'], '_', strtolower($method));
        return match ($kind) {
            'cheque'     => str_contains($m, 'cheque') || str_contains($m, 'check'),
            'e_transfer' => str_contains($m, 'transfer') || str_contains($m, 'interac'),
            'stripe'     => str_contains($m, 'stripe') || str_contains($m, 'card') || str_contains($m, 'jobber'),
            default      => true,
        };
    }

    /**
     * Match one deposit against candidate payments (not yet used).
     * $dep: transaction_date, amount, description. $cands: [key, amount, method, date, name, src].
     * @return array{class: string, pick: array}  class: single | subset | ambiguous | none
     */
    public static function match(array $dep, array $cands): array
    {
        $kind = self::kindOf((string)$dep['description']);
        $dt = strtotime((string)$dep['transaction_date']);
        $amt = round((float)$dep['amount'], 2);
        $pool = array_values(array_filter($cands, function ($c) use ($kind, $dt) {
            if (empty($c['date']) || !self::methodFits($kind, (string)$c['method'])) return false;
            $p = strtotime((string)$c['date']);
            return $p >= $dt - self::DAYS_BEFORE * 86400 && $p <= $dt + self::DAYS_AFTER * 86400;
        }));
        usort($pool, fn($a, $b) => [abs(strtotime($a['date']) - $dt), $a['key']] <=> [abs(strtotime($b['date']) - $dt), $b['key']]);
        $exact = array_values(array_filter($pool, fn($c) => abs(round((float)$c['amount'], 2) - $amt) <= 0.01));
        if (count($exact) === 1) return ['class' => 'single', 'pick' => $exact];
        if (count($exact) > 1) {
            $words = InvoiceReconciliationService::bankMemoWords((string)$dep['description']);
            $named = $words ? array_values(array_filter($exact, fn($c) => !empty($c['name']) && InvoiceReconciliationService::payerMatchesWords((string)$c['name'], $words))) : [];
            return count($named) === 1 ? ['class' => 'single', 'pick' => $named] : ['class' => 'ambiguous', 'pick' => $exact];
        }
        if ($kind !== 'e_transfer' && $pool) {   // e-Transfers arrive one by one
            $sub = InvoiceReconciliationService::exactSubsetBounded(array_map(fn($c) => round((float)$c['amount'], 2), $pool), $amt, 5, 14);
            if ($sub) return ['class' => 'subset', 'pick' => array_map(fn($i) => $pool[$i], $sub)];
        }
        return ['class' => 'none', 'pick' => []];
    }

    /**
     * Run the deposits in date order; a candidate is used once. Each row gets an action:
     * link_crm (all picks CRM), source_jobber (all picks Jobber), review (mixed / ambiguous), none.
     */
    public static function plan(array $deposits, array $cands): array
    {
        $used = [];
        $out = [];
        foreach ($deposits as $d) {
            $free = array_values(array_filter($cands, fn($c) => !isset($used[$c['key']])));
            $m = self::match($d, $free);
            $srcs = array_values(array_unique(array_column($m['pick'], 'src')));
            $action = 'none';
            if (in_array($m['class'], ['single', 'subset'], true)) {
                $action = $srcs === ['crm'] ? 'link_crm' : ($srcs === ['jobber'] ? 'source_jobber' : 'review');
                if ($action !== 'review') foreach ($m['pick'] as $p) $used[$p['key']] = true;
            } elseif ($m['class'] === 'ambiguous') {
                $action = 'review';
            }
            $out[] = ['deposit' => $d, 'class' => $m['class'], 'action' => $action, 'pick' => $m['pick'], 'kind' => self::kindOf((string)$d['description'])];
        }
        return $out;
    }

    /** Totals per action, and how much 2026 income drops (CRM links remove a double count). */
    public static function summary(array $plan): array
    {
        $s = [];
        foreach ($plan as $r) {
            $a = $r['action'];
            $s[$a]['n'] = ($s[$a]['n'] ?? 0) + 1;
            $s[$a]['amount'] = round(($s[$a]['amount'] ?? 0) + (float)$r['deposit']['amount'], 2);
        }
        return ['by_action' => $s, 'income_drop' => round($s['link_crm']['amount'] ?? 0, 2)];
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM bank_deposit_sources LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function deposits(): array
    {
        $s = $this->db->prepare("
            SELECT id, transaction_date, amount, description FROM accounting_transactions
            WHERE type = 'income' AND reference_type = 'bank_import' AND matched_invoice_id IS NULL
              AND transaction_date >= ?
              AND NOT EXISTS (SELECT 1 FROM bank_deposit_sources b WHERE b.transaction_id = accounting_transactions.id)
            ORDER BY transaction_date, id
        ");
        $s->execute([self::SINCE]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function candidates(): array
    {
        $out = [];
        $s = $this->db->prepare("
            SELECT i.id, i.invoice_number, COALESCE(NULLIF(i.amount_paid, 0), i.total) amt, COALESCE(i.payment_method, '') m,
                   COALESCE(i.payment_date, DATE(i.paid_at)) d, i.bill_to_name name
            FROM invoices i
            WHERE i.status = 'paid' AND i.invoice_date >= ?
              AND NOT EXISTS (SELECT 1 FROM invoice_payment_allocations a WHERE a.invoice_id = i.id AND a.transaction_id IS NOT NULL)
        ");
        $s->execute([self::SINCE]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['key' => 'crm' . $r['id'], 'src' => 'crm', 'id' => (int)$r['id'], 'ref' => $r['invoice_number'],
                      'amount' => (float)$r['amt'], 'method' => $r['m'], 'date' => $r['d'], 'name' => $r['name']];
        }
        try {
            $rows = $this->db->query("
                SELECT j.id, j.invoice_numbers, j.amount, j.method, j.payment_date, j.client_name, j.contact_id
                FROM jobber_payments j
                WHERE j.kind = 'payment'
                  AND NOT EXISTS (SELECT 1 FROM bank_deposit_sources b WHERE b.source = 'jobber_payment' AND b.source_id = j.id)
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $out[] = ['key' => 'job' . $r['id'], 'src' => 'jobber', 'id' => (int)$r['id'], 'ref' => 'Jobber #' . $r['invoice_numbers'],
                          'amount' => (float)$r['amount'], 'method' => (string)$r['method'], 'date' => $r['payment_date'],
                          'name' => $r['client_name'], 'contact_id' => $r['contact_id'] !== null ? (int)$r['contact_id'] : null];
            }
        } catch (Throwable $e) { /* jobber_payments not imported here */ }
        return $out;
    }

    public function buildPlan(): array
    {
        return self::plan($this->deposits(), $this->candidates());
    }

    /**
     * Write the plan's link_crm and source_jobber rows. Each deposit is its own transaction.
     * @return array{linked: int, sourced: int, skipped: array}
     */
    public function apply(array $plan, int $userId): array
    {
        $rec = new InvoiceReconciliationService($this->db);
        $out = ['linked' => 0, 'sourced' => 0, 'income_removed' => 0.0, 'skipped' => []];
        foreach ($plan as $r) {
            $tx = (int)$r['deposit']['id'];
            if ($r['action'] === 'link_crm') {
                try {
                    $this->db->beginTransaction();
                    $ids = [];
                    foreach ($r['pick'] as $p) $ids = array_merge($ids, $this->allocationsFor($p, $r['deposit'], $userId));
                    $rec->linkAllocationsToDeposit($tx, $ids, $userId);
                    $this->db->prepare("UPDATE accounting_transactions SET notes = CONCAT_WS('\n', NULLIF(TRIM(notes), ''), ?) WHERE id = ?")
                        ->execute(['Linked by the deposit backfill ' . date('Y-m-d') . ' — paid ' . implode(', ', array_column($r['pick'], 'ref')), $tx]);
                    $this->db->commit();
                    $out['linked']++;
                    $out['income_removed'] = round($out['income_removed'] + (float)$r['deposit']['amount'], 2);
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                    $out['skipped'][] = ['deposit' => $tx, 'why' => $e->getMessage()];
                }
            } elseif ($r['action'] === 'source_jobber') {
                try {
                    $ins = $this->db->prepare("
                        INSERT IGNORE INTO bank_deposit_sources (transaction_id, source, source_id, contact_id, payer_name, method, amount, created_by)
                        VALUES (?, 'jobber_payment', ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($r['pick'] as $p) {
                        $ins->execute([$tx, $p['id'], $p['contact_id'] ?? null, mb_substr((string)$p['name'], 0, 255), mb_substr((string)$p['method'], 0, 30), round((float)$p['amount'], 2), $userId]);
                    }
                    $out['sourced']++;
                } catch (Throwable $e) {
                    $out['skipped'][] = ['deposit' => $tx, 'why' => $e->getMessage()];
                }
            }
        }
        return $out;
    }

    /**
     * The paid invoice's payment as allocation rows not yet tied to a deposit: its existing
     * unlinked ones when they cover it, else one new row (the invoice itself is not changed —
     * it is already paid). Caller holds the transaction.
     * @return int[]
     */
    private function allocationsFor(array $cand, array $dep, int $userId): array
    {
        $s = $this->db->prepare("SELECT id, amount FROM invoice_payment_allocations WHERE invoice_id = ? AND transaction_id IS NULL");
        $s->execute([$cand['id']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($rows && abs(round(array_sum(array_column($rows, 'amount')), 2) - round((float)$cand['amount'], 2)) <= 0.01) {
            return array_map(fn($r) => (int)$r['id'], $rows);
        }
        if ($rows) throw new RuntimeException('Invoice ' . $cand['ref'] . ' has part-recorded payments — review by hand.');
        $method = strtolower((string)$cand['method']) ?: self::methodForKind(self::kindOf((string)$dep['description']));
        $this->db->prepare("
            INSERT INTO invoice_payment_allocations (invoice_id, transaction_id, amount, payment_date, method, reference, notes, created_by)
            VALUES (?, NULL, ?, ?, ?, NULL, ?, ?)
        ")->execute([$cand['id'], round((float)$cand['amount'], 2), substr((string)($cand['date'] ?: $dep['transaction_date']), 0, 10),
                     mb_substr($method, 0, 20), 'Deposit backfill ' . date('Y-m-d'), $userId]);
        return [(int)$this->db->lastInsertId()];
    }
}
