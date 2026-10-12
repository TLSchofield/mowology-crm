<?php
/**
 * JobberLedgerService — the 2026 Jobber money into the journal, without counting anything twice
 * (2026-10-07). Only 2026 is touched: 2017–2025 Jobber invoices are history (FY2025 is filed and
 * locked; its receivable came in with the FY2026 opening, Fy2026OpeningService).
 *
 * The rules
 *   1. Revenue: a Jobber invoice ISSUED in 2026 (not draft / void / bad debt, not recreated in the
 *      CRM) → DR 1100 AR / CR 4900 net + CR 2200 GST, dated its issue date, source 'jobber_invoice'
 *      (jobber_invoices.id). GST is Jobber's own split (Transaction List "Paid - Tax $") where the
 *      export has it, else Total × 5/105 (flagged).
 *   2. Cash: a 2026 bank deposit matched to the Jobber payments it carried → ONE entry, source
 *      'bank_deposit' (accounting_transactions.id — the nightly bank sync then never posts it):
 *      DR bank (the deposit) + DR 6800 Bank charges (Jobber Payments fees) / CR 1100 AR for each
 *      invoice the payments were applied to (2025 invoices: the opening receivable; 2026 invoices:
 *      rule 1, posted first if it isn't yet) / CR 2160 Customer Deposits for a prepayment on a quote
 *      (flagged — booked only when Tim ticks it).
 *   3. Single-entry (accounting_transactions, the P&L / GST line 101 side): the deposit stays the
 *      income record for what paid 2026 invoices; the part that paid 2025 invoices is FY2025
 *      revenue (filed) → the bank line becomes a 'transfer' when that is all of it, else its income
 *      amount drops to the 2026 part (GST scaled). Jobber invoices never write income rows.
 *   4. A deposit the bank balance check already booked as income ('bank_deposit', DR bank / CR
 *      revenue) is re-booked: that entry is reversed first (else rule 1 would double the revenue).
 *
 * Matching is by Jobber PAYMENT, never by date alone (Jobber kept issuing invoices into June):
 *   e-Transfer        the deposit's description carries the payment's confirmation #; else the
 *                     same amount within ±3 days (good)
 *   Jobber Payments   one payout (po_…) = one bank credit of charges − fees, within 10 days after
 *                     the last charge (strong — the amount is exact to the cent)
 *   Cheque            cheques recorded 14 days before to 3 days after the deposit whose amounts add
 *                     up to it exactly (InvoiceReconciliationService::exactSubsetBounded)
 *   anything else     the same amount within ±3 days (weak — never pre-ticked)
 * Every proposal is approved by Tim (signature-checked), logged in jobber_ledger_log, and undone
 * by batch (reversal entries, bank lines restored — append-only).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
declare(strict_types=1);

require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/JobberCsv.php';
require_once __DIR__ . '/JobberImportService.php';
require_once __DIR__ . '/InvoiceReconciliationService.php';
require_once __DIR__ . '/BankBalanceCheckService.php';

class JobberLedgerService
{
    public const FY_START = '2026-01-01';
    public const REVENUE_CODE = '4900';
    public const FEE_CODE = '6800';
    public const DEPOSITS_CODE = '2160';
    public const ETRANSFER_DAYS = 3;
    public const CHEQUE_BEFORE = 14;
    public const CHEQUE_AFTER = 3;
    public const PAYOUT_DAYS = 10;
    public const CRM_DUP_DAYS = 21;
    public const CENT = 0.005;

    private PDO $db;
    private LedgerService $ledger;
    private ?array $chart = null;

    public function __construct(PDO $db, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->ledger = $ledger ?? new LedgerService($db);
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM jobber_ledger_log LIMIT 0");
            return (new JobberImportService($this->db))->ready();
        } catch (Throwable $e) {
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PURE — units and matching
    // ══════════════════════════════════════════════════════════════════════════

    public static function methodOf(array $p): string
    {
        $m = strtolower((string)($p['method'] ?? ''));
        if (!empty($p['payout_id']) || strpos($m, 'jobber') !== false || strpos($m, 'card') !== false) return 'card';
        if (strpos($m, 'transfer') !== false) return 'etransfer';
        if (strpos($m, 'cheque') !== false || strpos($m, 'check') !== false) return 'cheque';
        return 'other';
    }

    /**
     * Group payments the way they reach the bank. Pure.
     * @param array $payments id, payment_date, amount, fee, method, cheque_no, confirmation_no, payout_id, client_name, kind
     * @return list<array{key:string, method:string, ids:list<int>, date:string, first:string, gross:float, fee:float, net:float, confirmation:?string, payout:?string, deposit_kind:bool}>
     */
    public static function units(array $payments): array
    {
        $g = [];
        foreach ($payments as $p) {
            $m = self::methodOf($p);
            $id = (int)$p['id'];
            if ($m === 'card') $k = !empty($p['payout_id']) ? 'po:' . $p['payout_id'] : 'card:' . $id;
            elseif ($m === 'etransfer') $k = !empty($p['confirmation_no']) ? 'et:' . $p['confirmation_no'] : 'et#' . $id;
            elseif ($m === 'cheque') $k = 'cq:' . $p['payment_date'] . '|' . (!empty($p['cheque_no']) ? $p['cheque_no'] : '') . '|' . strtolower((string)$p['client_name']);
            else $k = 'x:' . $id;
            if (!isset($g[$k])) {
                $g[$k] = ['key' => $k, 'method' => $m, 'ids' => [], 'date' => (string)$p['payment_date'], 'first' => (string)$p['payment_date'],
                          'gross' => 0.0, 'fee' => 0.0, 'net' => 0.0, 'confirmation' => $p['confirmation_no'] ?? null,
                          'payout' => $p['payout_id'] ?? null, 'deposit_kind' => false, 'clients' => []];
            }
            $u = &$g[$k];
            $u['ids'][] = $id;
            $u['gross'] = round($u['gross'] + (float)$p['amount'], 2);
            $u['fee'] = round($u['fee'] + (float)($p['fee'] ?? 0), 2);
            $u['net'] = round($u['gross'] - $u['fee'], 2);
            if ((string)$p['payment_date'] > $u['date']) $u['date'] = (string)$p['payment_date'];
            if ((string)$p['payment_date'] < $u['first']) $u['first'] = (string)$p['payment_date'];
            if (($p['kind'] ?? 'payment') === 'deposit') $u['deposit_kind'] = true;
            if (!empty($p['client_name']) && !in_array($p['client_name'], $u['clients'], true)) $u['clients'][] = (string)$p['client_name'];
            unset($u);
        }
        return array_values($g);
    }

    private static function days(string $a, string $b): int
    {
        return (int)round((strtotime($a) - strtotime($b)) / 86400);
    }

    /**
     * Match units to bank deposits. Pure.
     * @param array $units    from units()
     * @param array $deposits id, date, amount, description
     * @return list<array{deposit_id:int, units:list<string>, how:string, confidence:string}>
     */
    public static function match(array $units, array $deposits): array
    {
        $out = [];
        $usedU = []; $usedD = [];
        $byKey = [];
        foreach ($units as $u) $byKey[$u['key']] = $u;
        usort($deposits, fn($a, $b) => [$a['date'], (int)$a['id']] <=> [$b['date'], (int)$b['id']]);
        $eq = fn($a, $b) => abs((float)$a - (float)$b) < self::CENT;
        $desc = fn($d) => strtoupper((string)$d['description']);
        $add = function (array $d, array $keys, string $how, string $conf) use (&$out, &$usedU, &$usedD) {
            $out[] = ['deposit_id' => (int)$d['id'], 'units' => $keys, 'how' => $how, 'confidence' => $conf];
            $usedD[(int)$d['id']] = true;
            foreach ($keys as $k) $usedU[$k] = true;
        };

        // 1. e-Transfer by confirmation #.
        foreach ($deposits as $d) {
            if (isset($usedD[(int)$d['id']])) continue;
            preg_match_all('/\d{6,}/', (string)$d['description'], $m);
            if (!$m[0]) continue;
            foreach ($units as $u) {
                if (isset($usedU[$u['key']]) || $u['method'] !== 'etransfer' || empty($u['confirmation'])) continue;
                $conf = preg_replace('/\D/', '', (string)$u['confirmation']);
                if ($conf === '' || strlen($conf) < 6) continue;
                foreach ($m[0] as $digits) {
                    if (strpos($digits, $conf) !== false || strpos($conf, $digits) !== false && strlen($digits) >= 10) {
                        if ($eq($u['net'], $d['amount'])) { $add($d, [$u['key']], 'e-Transfer ref ' . $u['confirmation'], 'strong'); continue 3; }
                    }
                }
            }
        }
        // 2. Jobber Payments payouts: charges − fees to the cent.
        foreach ($deposits as $d) {
            if (isset($usedD[(int)$d['id']])) continue;
            $best = null;
            foreach ($units as $u) {
                if (isset($usedU[$u['key']]) || $u['method'] !== 'card' || empty($u['payout'])) continue;
                $lag = self::days($d['date'], $u['date']);
                if ($lag < 0 || $lag > self::PAYOUT_DAYS || !$eq($u['net'], $d['amount'])) continue;
                $named = preg_match('/JOBBER|STRIPE|WEPAY/', $desc($d)) ? 0 : 1;
                $score = [$named, $lag];
                if ($best === null || $score < $best[0]) $best = [$score, $u];
            }
            if ($best) $add($d, [$best[1]['key']], 'Jobber Payments payout ' . $best[1]['payout'], 'strong');
        }
        // 3. e-Transfer without a ref: same amount, ±3 days, nearest.
        foreach ($deposits as $d) {
            if (isset($usedD[(int)$d['id']])) continue;
            $best = null;
            foreach ($units as $u) {
                if (isset($usedU[$u['key']]) || $u['method'] !== 'etransfer' || !$eq($u['net'], $d['amount'])) continue;
                $lag = abs(self::days($d['date'], $u['date']));
                if ($lag > self::ETRANSFER_DAYS) continue;
                $named = preg_match('/TRANSFER|INTERAC|E-TFR|ETFR/', $desc($d)) ? 0 : 1;
                if ($best === null || [$named, $lag] < $best[0]) $best = [[$named, $lag], $u];
            }
            if ($best) $add($d, [$best[1]['key']], 'e-Transfer, same amount within ' . self::ETRANSFER_DAYS . ' days', 'good');
        }
        // 4. Cheques: an exact sum of cheques recorded around the deposit.
        foreach ($deposits as $d) {
            if (isset($usedD[(int)$d['id']])) continue;
            $pool = [];
            foreach ($units as $u) {
                if (isset($usedU[$u['key']]) || $u['method'] !== 'cheque') continue;
                $lag = self::days($d['date'], $u['date']);
                if ($lag < -self::CHEQUE_AFTER || $lag > self::CHEQUE_BEFORE) continue;
                $pool[] = [abs($lag), $u];
            }
            if (!$pool) continue;
            usort($pool, fn($a, $b) => [$a[0], $a[1]['key']] <=> [$b[0], $b[1]['key']]);
            $pool = array_slice(array_map(fn($x) => $x[1], $pool), 0, 12);
            $pick = InvoiceReconciliationService::exactSubsetBounded(array_map(fn($u) => $u['net'], $pool), (float)$d['amount'], 6, 12);
            if ($pick === null) continue;
            $keys = array_map(fn($i) => $pool[$i]['key'], $pick);
            $add($d, $keys, count($keys) === 1 ? 'Cheque, same amount' : count($keys) . ' cheques adding up to the deposit', count($keys) === 1 ? 'good' : 'good');
        }
        // 5. Anything else: same amount, ±3 days (weak).
        foreach ($deposits as $d) {
            if (isset($usedD[(int)$d['id']])) continue;
            $best = null;
            foreach ($units as $u) {
                if (isset($usedU[$u['key']]) || !$eq($u['net'], $d['amount'])) continue;
                $lag = abs(self::days($d['date'], $u['date']));
                if ($lag > self::ETRANSFER_DAYS) continue;
                if ($best === null || $lag < $best[0]) $best = [$lag, $u];
            }
            if ($best) $add($d, [$best[1]['key']], 'Same amount within ' . self::ETRANSFER_DAYS . ' days (' . $best[1]['method'] . ')', 'weak');
        }
        return $out;
    }

    /**
     * The journal + bank-line effect of one deposit paying these payments. Pure.
     * @param array $deposit  id, date, amount, gst_amount, bank_account_id (chart id)
     * @param array $payments id, amount, fee, kind, invoice_numbers, quote_number, contact_id
     * @param array $invoices jobber_number => [id, issued_date, total, subtotal, tax, contact_id, status, crm_invoice_id]
     * @param array $acct     ar, fee, deposits (chart ids)
     * @return array{lines:array, allocations:list<array>, fee:float, pre2026:float, income_after:float, gst_after:float, missing:list<string>, prepayment:bool, revenue_needed:list<int>}
     */
    public static function cashPlan(array $deposit, array $payments, array $invoices, array $acct): array
    {
        $lines = [['account_id' => (int)$deposit['bank_account_id'], 'debit' => round((float)$deposit['amount'], 2), 'credit' => 0]];
        $fee = round(array_sum(array_map(fn($p) => (float)($p['fee'] ?? 0), $payments)), 2);
        if ($fee > self::CENT) $lines[] = ['account_id' => $acct['fee'], 'debit' => $fee, 'credit' => 0, 'description' => 'Jobber Payments processing fees'];
        $alloc = []; $missing = []; $pre = 0.0; $prepay = false; $need = [];
        foreach ($payments as $p) {
            if (($p['kind'] ?? 'payment') === 'deposit') {
                $prepay = true;
                $lines[] = ['account_id' => $acct['deposits'], 'debit' => 0, 'credit' => round((float)$p['amount'], 2), 'contact_id' => $p['contact_id'] ?? null,
                            'description' => 'Prepayment on Jobber quote #' . ($p['quote_number'] ?? '?')];
                $alloc[] = ['payment_id' => (int)$p['id'], 'invoice' => null, 'jobber_invoice_id' => null, 'amount' => round((float)$p['amount'], 2), 'year' => null];
                continue;
            }
            $nums = JobberCsv::invoiceNumbers($p['invoice_numbers'] ?? '');
            if (!$nums) { $missing[] = 'a payment of $' . number_format((float)$p['amount'], 2) . ' with no invoice #'; continue; }
            $list = [];
            foreach ($nums as $n) {
                if (!isset($invoices[$n])) { $missing[] = '#' . $n; continue; }
                $list[] = [$n, (float)$invoices[$n]['total']];
            }
            if (!$list) continue;
            foreach (JobberImportService::allocate((float)$p['amount'], $list) as $n => $amt) {
                $inv = $invoices[$n];
                $is2026 = (string)$inv['issued_date'] >= self::FY_START;
                if (!$is2026) $pre += $amt;
                elseif (!in_array((int)$inv['id'], $need, true)) $need[] = (int)$inv['id'];
                $lines[] = ['account_id' => $acct['ar'], 'debit' => 0, 'credit' => round($amt, 2), 'contact_id' => $inv['contact_id'] ?? null,
                            'description' => 'Jobber invoice #' . $n . ($is2026 ? '' : ' (FY2025 receivable)')];
                $alloc[] = ['payment_id' => (int)$p['id'], 'invoice' => (string)$n, 'jobber_invoice_id' => (int)$inv['id'], 'amount' => round($amt, 2),
                            'year' => substr((string)$inv['issued_date'], 0, 4)];
            }
        }
        $amount = round((float)$deposit['amount'], 2);
        $incomeAfter = round(max(0.0, $amount - $pre), 2);
        $gst = round((float)($deposit['gst_amount'] ?? 0), 2);
        $gstAfter = $amount > 0 ? round($gst * $incomeAfter / $amount, 2) : 0.0;
        return ['lines' => $lines, 'allocations' => $alloc, 'fee' => $fee, 'pre2026' => round($pre, 2), 'income_after' => $incomeAfter,
                'gst_after' => $gstAfter, 'missing' => array_values(array_unique($missing)), 'prepayment' => $prepay, 'revenue_needed' => $need];
    }

    /** DR AR / CR revenue + GST for one 2026 Jobber invoice. Pure. */
    public static function revenueEntry(array $inv, array $acct): array
    {
        $total = round((float)$inv['total'], 2);
        $tax = round((float)$inv['tax'], 2);
        $net = round($total - $tax, 2);
        $lines = [['account_id' => $acct['ar'], 'debit' => $total, 'credit' => 0, 'contact_id' => $inv['contact_id'] ?? null,
                   'description' => 'Jobber invoice #' . $inv['jobber_number']]];
        if ($net > self::CENT) $lines[] = ['account_id' => $acct['revenue'], 'debit' => 0, 'credit' => $net, 'contact_id' => $inv['contact_id'] ?? null];
        if ($tax > self::CENT) $lines[] = ['account_id' => $acct['gst'], 'debit' => 0, 'credit' => $tax, 'gst_amount' => $tax];
        return [
            'entry_date' => (string)$inv['issued_date'],
            'memo' => 'Jobber invoice #' . $inv['jobber_number'] . ' — ' . mb_substr((string)($inv['client_name'] ?? ''), 0, 120) .
                      (($inv['tax_source'] ?? '') === 'computed' ? ' (GST computed as 5/105 of the total)' : ''),
            'source_type' => 'jobber_invoice', 'source_id' => (int)$inv['id'], 'lines' => $lines,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DB READS
    // ══════════════════════════════════════════════════════════════════════════

    /** jobber_number => invoice row */
    public function invoices(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT id, jobber_number, client_name, contact_id, issued_date, status, subtotal, tax, tax_source, total, balance, crm_invoice_id
                                   FROM jobber_invoices")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['jobber_number']] = $r;
        }
        return $out;
    }

    /** jobber_invoice_id => live revenue entry id */
    private function liveRevenue(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT id, source_id FROM journal_entries WHERE source_type = 'jobber_invoice' AND reversed_by_entry_id IS NULL")
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['source_id']] = (int)$r['id'];
        return $out;
    }

    /** jobber_payment_id => true for payments already booked (live log). */
    private function claimedPayments(): array
    {
        return array_fill_keys(array_map('intval', $this->db->query("SELECT DISTINCT jobber_payment_id FROM jobber_ledger_log
                               WHERE op = 'payment' AND undone_at IS NULL AND jobber_payment_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** Unbooked 2026 Jobber payments (refunds left out). */
    public function openPayments(): array
    {
        $claimed = $this->claimedPayments();
        $s = $this->db->prepare("SELECT id, kind, client_name, contact_id, payment_date, amount, fee, method, cheque_no, confirmation_no, payout_id,
                                        invoice_numbers, quote_number FROM jobber_payments WHERE payment_date >= ? AND refunded_on IS NULL ORDER BY payment_date, id");
        $s->execute([self::FY_START]);
        return array_values(array_filter($s->fetchAll(PDO::FETCH_ASSOC), fn($p) => !isset($claimed[(int)$p['id']])));
    }

    /**
     * 2026 bank deposits that may carry Jobber money: income lines on any account not tied to a
     * CRM invoice, not booked by the income clean-up, not in the journal — or in it only as the
     * bank balance check's revenue posting ('bank_deposit', not ours: rebook = reverse first).
     */
    public function deposits(): array
    {
        $s = $this->db->prepare("
            SELECT t.id, t.transaction_date, t.amount, t.gst_amount, t.description, t.bank_account_id, t.type, t.status, t.account_id
            FROM accounting_transactions t
            WHERE t.reference_type = 'bank_import' AND t.type = 'income' AND t.amount > 0.005 AND t.transaction_date >= ?
              AND (t.status IS NULL OR t.status <> 'void')
              AND t.matched_invoice_id IS NULL AND t.matched_expense_id IS NULL
            ORDER BY t.transaction_date, t.id
        ");
        $s->execute([self::FY_START]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $allocated = $this->idSet("SELECT DISTINCT transaction_id FROM invoice_payment_allocations WHERE transaction_id IS NOT NULL");
        $cleaned = $this->idSet("SELECT DISTINCT transaction_id FROM income_cleanup_log WHERE status = 'booked'");
        $imported = $this->idSet("SELECT DISTINCT source_id FROM journal_entries WHERE source_type = 'bank_import' AND reversed_by_entry_id IS NULL AND source_id IS NOT NULL");
        $ours = $this->idSet("SELECT DISTINCT transaction_id FROM jobber_ledger_log WHERE undone_at IS NULL AND transaction_id IS NOT NULL");
        $bbc = [];
        foreach ($this->db->query("SELECT id, source_id FROM journal_entries WHERE source_type = 'bank_deposit' AND reversed_by_entry_id IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bbc[(int)$r['source_id']] = (int)$r['id'];
        }
        $bank1010 = $this->chartId('1010');
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (isset($allocated[$id]) || isset($cleaned[$id]) || isset($imported[$id]) || isset($ours[$id])) continue;
            $out[] = ['id' => $id, 'date' => substr((string)$r['transaction_date'], 0, 10), 'amount' => round((float)$r['amount'], 2),
                      'gst_amount' => round((float)($r['gst_amount'] ?? 0), 2), 'description' => (string)$r['description'],
                      'bank_account_id' => !empty($r['bank_account_id']) ? (int)$r['bank_account_id'] : $bank1010,
                      'account_id' => (int)$r['account_id'], 'bbc_entry_id' => $bbc[$id] ?? null];
        }
        return $out;
    }

    private function crmInvoices(): array
    {
        try {
            $s = $this->db->prepare("SELECT id, invoice_number, total, contact_id, issue_date FROM invoices WHERE COALESCE(total, 0) > 0 AND issue_date >= ?");
            $s->execute([date('Y-m-d', strtotime(self::FY_START . ' -60 days'))]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** A CRM invoice that looks like the same bill (same total, ±21 days, same contact when both known). Pure. */
    public static function crmTwin(array $inv, array $crm): ?array
    {
        foreach ($crm as $c) {
            if (abs((float)$c['total'] - (float)$inv['total']) >= self::CENT) continue;
            if (abs(self::days(substr((string)$c['issue_date'], 0, 10), (string)$inv['issued_date'])) > self::CRM_DUP_DAYS) continue;
            if (!empty($c['contact_id']) && !empty($inv['contact_id']) && (int)$c['contact_id'] !== (int)$inv['contact_id']) continue;
            return $c;
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REPORT
    // ══════════════════════════════════════════════════════════════════════════

    public function report(): array
    {
        if (!$this->ready()) return ['ready' => false];
        $invoices = $this->invoices();
        $acct = $this->accounts();
        $revenue = $this->revenueItems($invoices);
        $proposals = $this->proposals($invoices, $acct);
        $opening = null;
        try {
            require_once __DIR__ . '/Fy2026OpeningService.php';
            $opening = (new Fy2026OpeningService($this->db, $this->ledger))->existing();
        } catch (Throwable $e) { /* none */ }
        $warnings = [];
        if (!$opening) $warnings[] = 'The FY2026 opening is not booked yet — payments of 2025 invoices credit a receivable that only the opening puts on the books. Book the opening first.';
        if (!$invoices) $warnings[] = 'No Jobber invoices imported yet — import the Invoices export first, then the Transaction List and the Jobber Payments export.';
        foreach (['ar' => '1100', 'revenue' => self::REVENUE_CODE, 'gst' => '2200', 'fee' => self::FEE_CODE, 'deposits' => self::DEPOSITS_CODE] as $k => $code) {
            if ($acct[$k] === null) $warnings[] = 'Account ' . $code . ' is missing from the chart' . ($k === 'deposits' ? ' (run migration 1239)' : '') . '.';
        }
        return [
            'ready' => true, 'fy_start' => self::FY_START, 'opening_booked' => $opening !== null, 'warnings' => $warnings,
            'revenue' => $revenue,
            'proposals' => array_map([self::class, 'forPage'], $proposals['matched']),
            'unmatched_deposits' => $proposals['deposits'],
            'unmatched_units' => $proposals['units'],
            'log' => $this->batches(),
        ];
    }

    private static function forPage(array $p): array
    {
        unset($p['entry'], $p['revenue_entries'], $p['payments']);
        return $p;
    }

    /** 2026 Jobber invoices whose revenue isn't in the journal yet. */
    public function revenueItems(?array $invoices = null): array
    {
        $invoices = $invoices ?? $this->invoices();
        $live = $this->liveRevenue();
        $crm = $this->crmInvoices();
        $items = []; $held = []; $total = 0.0; $sig = [];
        foreach ($invoices as $inv) {
            if ((string)$inv['issued_date'] < self::FY_START || isset($live[(int)$inv['id']])) continue;
            $why = null;
            if (in_array($inv['status'], ['draft', 'void'], true)) continue;
            if ($inv['status'] === 'bad_debt') $why = 'Bad debt in Jobber — not booked as revenue (ask the accountant whether to book and write off)';
            elseif (!empty($inv['crm_invoice_id'])) continue;   // recreated in the CRM: the CRM invoice carries it
            elseif ($twin = self::crmTwin($inv, $crm)) $why = 'Looks like CRM invoice ' . $twin['invoice_number'] . ' (same total, ' . substr((string)$twin['issue_date'], 0, 10) . ') — book only if both were really billed';
            $row = ['id' => (int)$inv['id'], 'number' => (string)$inv['jobber_number'], 'client' => (string)$inv['client_name'], 'issued' => $inv['issued_date'],
                    'total' => round((float)$inv['total'], 2), 'tax' => round((float)$inv['tax'], 2), 'tax_source' => (string)$inv['tax_source'],
                    'status' => (string)$inv['status'], 'locked' => $this->isLocked((string)$inv['issued_date'])];
            if ($why) { $row['reason'] = $why; $held[] = $row; continue; }
            $items[] = $row;
            $total += $row['total'];
            $sig[] = [$row['id'], $row['total'], $row['tax']];
        }
        return ['items' => $items, 'held' => $held, 'count' => count($items), 'total' => round($total, 2),
                'computed_tax' => count(array_filter($items, fn($r) => $r['tax_source'] === 'computed')),
                'signature' => sha1(json_encode($sig))];
    }

    /** Matched deposits (proposals), plus what is left on either side. */
    public function proposals(?array $invoices = null, ?array $acct = null): array
    {
        $invoices = $invoices ?? $this->invoices();
        $acct = $acct ?? $this->accounts();
        $payments = $this->openPayments();
        $byId = [];
        foreach ($payments as $p) $byId[(int)$p['id']] = $p;
        $units = self::units($payments);
        $unitsByKey = [];
        foreach ($units as $u) $unitsByKey[$u['key']] = $u;
        $deposits = $this->deposits();
        $depById = [];
        foreach ($deposits as $d) $depById[$d['id']] = $d;
        $crm = $this->crmInvoices();
        $live = $this->liveRevenue();
        $matched = [];
        $usedUnits = [];
        foreach (self::match($units, $deposits) as $m) {
            $d = $depById[$m['deposit_id']];
            $pays = [];
            foreach ($m['units'] as $k) { $usedUnits[$k] = true; foreach ($unitsByKey[$k]['ids'] as $pid) $pays[] = $byId[$pid]; }
            $matched[] = $this->proposal($d, $pays, $m['how'], $m['confidence'], $invoices, $acct, $live, $crm);
        }
        usort($matched, fn($a, $b) => [$a['date'], $a['transaction_id']] <=> [$b['date'], $b['transaction_id']]);
        $usedDeps = array_fill_keys(array_map(fn($p) => $p['transaction_id'], $matched), true);
        $leftDeps = array_values(array_filter($deposits, fn($d) => !isset($usedDeps[$d['id']])));
        foreach ($leftDeps as &$d) {
            $twin = BankBalanceCheckService::crmInvoiceFor((float)$d['amount'], $d['date'], $crm);
            $d['crm_invoice'] = $twin ? (string)$twin['invoice_number'] : null;
            $d['bbc_booked'] = $d['bbc_entry_id'] !== null;
        }
        unset($d);
        $leftUnits = array_values(array_map(function ($u) {
            return ['key' => $u['key'], 'method' => $u['method'], 'date' => $u['date'], 'gross' => $u['gross'], 'fee' => $u['fee'], 'net' => $u['net'],
                    'payout' => $u['payout'], 'clients' => $u['clients'], 'count' => count($u['ids'])];
        }, array_filter($units, fn($u) => !isset($usedUnits[$u['key']]))));
        return ['matched' => $matched, 'deposits' => $leftDeps, 'units' => $leftUnits];
    }

    /** One deposit's proposal: what gets reversed, posted and changed. */
    private function proposal(array $d, array $pays, string $how, string $confidence, array $invoices, array $acct, array $live, array $crm): array
    {
        $plan = self::cashPlan($d, $pays, $invoices, $acct);
        $byIdInv = [];
        foreach ($invoices as $inv) $byIdInv[(int)$inv['id']] = $inv;
        $revenue = [];
        $blocked = [];
        if ($plan['missing']) $blocked[] = 'Jobber invoice(s) not imported: ' . implode(', ', array_slice($plan['missing'], 0, 6));
        foreach ($plan['revenue_needed'] as $iid) {
            $inv = $byIdInv[$iid];
            if (isset($live[$iid])) continue;
            if (!empty($inv['crm_invoice_id'])) { $blocked[] = 'Jobber #' . $inv['jobber_number'] . ' was recreated in the CRM — record this payment on the CRM invoice instead'; continue; }
            if (in_array($inv['status'], ['draft', 'void'], true)) { $blocked[] = 'Jobber #' . $inv['jobber_number'] . ' is a ' . $inv['status']; continue; }
            $revenue[] = $iid;
        }
        foreach (['ar', 'fee', 'deposits', 'revenue', 'gst'] as $k) if ($acct[$k] === null && ($k !== 'deposits' || $plan['prepayment']) && ($k !== 'fee' || $plan['fee'] > 0)) $blocked[] = 'Chart account missing (' . $k . ')';
        $dr = 0.0; $cr = 0.0;
        foreach ($plan['lines'] as $l) { $dr += $l['debit']; $cr += $l['credit']; }
        if (abs($dr - $cr) >= 0.01) $blocked[] = sprintf('Payments ($%s) don\'t add up to the deposit', number_format($cr - ($dr - (float)$d['amount']), 2));
        $flags = [];
        if ($plan['prepayment']) $flags[] = 'Includes a prepayment on a quote — booked to 2160 Customer Deposits until the job is invoiced';
        if ($d['bbc_entry_id']) $flags[] = 'Already booked as income by the bank balance check (entry #' . $d['bbc_entry_id'] . ') — that entry is reversed first';
        $twin = BankBalanceCheckService::crmInvoiceFor((float)$d['amount'], $d['date'], $crm);
        if ($twin) $flags[] = 'Same amount as CRM invoice ' . $twin['invoice_number'] . ' — make sure it is the Jobber payment';
        $years = array_values(array_unique(array_filter(array_column($plan['allocations'], 'year'))));
        $paymentIds = array_map(fn($p) => (int)$p['id'], $pays);
        sort($paymentIds);
        // Revenue still to post isn't in the signature: posting it is idempotent and the same either way.
        $sig = sha1(json_encode([(int)$d['id'], round((float)$d['amount'], 2), $paymentIds, $d['bbc_entry_id'], $plan['income_after']]));
        return [
            'key' => 'tx:' . $d['id'], 'transaction_id' => (int)$d['id'], 'date' => $d['date'], 'amount' => $d['amount'],
            'description' => $d['description'], 'how' => $how, 'confidence' => $confidence,
            'payments' => $pays,
            'payment_list' => array_map(fn($p) => ['id' => (int)$p['id'], 'date' => $p['payment_date'], 'client' => $p['client_name'], 'amount' => round((float)$p['amount'], 2),
                                                   'fee' => round((float)($p['fee'] ?? 0), 2), 'method' => $p['method'], 'invoices' => $p['invoice_numbers'],
                                                   'kind' => $p['kind']], $pays),
            'fee' => $plan['fee'], 'fy2025_part' => $plan['pre2026'], 'income_after' => $plan['income_after'], 'gst_after' => $plan['gst_after'],
            'years' => $years, 'revenue_ids' => $revenue,
            'revenue_numbers' => array_map(fn($i) => (string)$byIdInv[$i]['jobber_number'], $revenue),
            'bbc_entry_id' => $d['bbc_entry_id'], 'flags' => $flags, 'blocked' => $blocked,
            'preselect' => !$blocked && !$flags && $confidence !== 'weak',
            'locked' => $this->isLocked($d['date']),
            'signature' => $sig,
            'entry' => ['lines' => $plan['lines'], 'allocations' => $plan['allocations'], 'gst_before' => $d['gst_amount']],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // BOOK / UNDO
    // ══════════════════════════════════════════════════════════════════════════

    /** Book the revenue of the 2026 Jobber invoices listed (ids), refused if the list changed. */
    public function bookRevenue(string $signature, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1239 first.'];
        $acct = $this->accounts();
        if ($acct['ar'] === null || $acct['revenue'] === null || $acct['gst'] === null) return ['ok' => false, 'message' => 'Chart accounts 1100 / 4900 / 2200 are needed.'];
        $grp = $this->revenueItems();
        if (!hash_equals($grp['signature'], $signature)) return ['ok' => false, 'message' => 'The list changed since you looked — reload and check it again.'];
        $invoices = [];
        foreach ($this->invoices() as $inv) $invoices[(int)$inv['id']] = $inv;
        $batch = self::batchId();
        $booked = 0; $skipped = 0; $failed = [];
        foreach ($grp['items'] as $it) {
            if ($it['locked']) { $skipped++; continue; }
            try {
                $this->postRevenue($invoices[$it['id']], $acct, $batch, $userId);
                $booked++;
            } catch (Throwable $e) {
                $failed[] = '#' . $it['number'] . ': ' . $e->getMessage();
            }
        }
        return ['ok' => $booked > 0 || !$failed, 'batch_id' => $batch, 'booked' => $booked,
                'message' => $booked . ' Jobber invoice(s) booked as 2026 revenue' . ($skipped ? ', ' . $skipped . ' in locked months skipped' : '') .
                             ($failed ? ', ' . count($failed) . ' failed: ' . implode('; ', array_slice($failed, 0, 3)) : '') . '.'];
    }

    private function postRevenue(array $inv, array $acct, string $batch, int $userId): int
    {
        $existing = $this->ledger->findEntryIdBySource('jobber_invoice', (int)$inv['id']);
        if ($existing !== null) return $existing;
        $entry = self::revenueEntry($inv, $acct);
        $entry['created_by'] = $userId;
        $entry['proposed_by'] = 'penny';
        $id = $this->ledger->postManual($entry);
        $this->log($batch, 'revenue', (int)$inv['id'], null, null, $id, null, (float)$inv['total'], (string)$inv['issued_date'], $userId);
        return $id;
    }

    /**
     * Book the deposits Tim ticked. $picks = list of [key, signature]; each proposal is rebuilt
     * and refused alone when it changed.
     */
    public function bookDeposits(array $picks, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1239 first.'];
        $want = [];
        foreach ($picks as $p) if (isset($p['key'], $p['signature'])) $want[(string)$p['key']] = (string)$p['signature'];
        if (!$want) return ['ok' => false, 'message' => 'Tick the deposits to book.'];
        $invoices = $this->invoices();
        $acct = $this->accounts();
        $props = [];
        foreach ($this->proposals($invoices, $acct)['matched'] as $p) $props[$p['key']] = $p;
        $byId = [];
        foreach ($invoices as $inv) $byId[(int)$inv['id']] = $inv;
        $batch = self::batchId();
        $booked = 0; $failed = [];
        foreach ($want as $key => $sig) {
            $p = $props[$key] ?? null;
            if (!$p || !hash_equals($p['signature'], $sig)) { $failed[] = $key . ': changed since you looked — reload'; continue; }
            if ($p['blocked']) { $failed[] = $key . ': ' . implode('; ', $p['blocked']); continue; }
            if ($p['locked']) { $failed[] = $key . ': its month is locked'; continue; }
            try {
                $this->bookProposal($p, $byId, $acct, $batch, $userId);
                $booked++;
            } catch (Throwable $e) {
                $failed[] = $key . ': ' . $e->getMessage();
            }
        }
        return ['ok' => $booked > 0 && !$failed, 'batch_id' => $batch, 'booked' => $booked, 'failed' => $failed,
                'message' => $booked . ' deposit(s) booked against Jobber invoices' . ($failed ? '; ' . count($failed) . ' not: ' . implode(' | ', array_slice($failed, 0, 3)) : '') . '.'];
    }

    private function bookProposal(array $p, array $invoicesById, array $acct, string $batch, int $userId): void
    {
        $tx = (int)$p['transaction_id'];
        if ($p['bbc_entry_id']) {
            $rev = $this->ledger->reverseEntry((int)$p['bbc_entry_id'], $userId, 'Re-booked against Jobber invoices', 'penny');
            $this->log($batch, 'reverse', null, null, $tx, $rev, (int)$p['bbc_entry_id'], -(float)$p['amount'], $p['date'], $userId);
        }
        foreach ($p['revenue_ids'] as $iid) $this->postRevenue($invoicesById[$iid], $acct, $batch, $userId);
        $entryId = $this->ledger->postManual([
            'entry_date' => $p['date'],
            'memo' => mb_substr('Jobber payments deposited — ' . $p['how'] . ' — ' . $p['description'], 0, 255),
            'source_type' => 'bank_deposit', 'source_id' => $tx, 'created_by' => $userId, 'proposed_by' => 'penny',
            'lines' => $p['entry']['lines'],
        ]);
        foreach ($p['entry']['allocations'] as $a) {
            // payment_id 0 = a deposit paying an open Jobber invoice with no Jobber payment recorded (bookMatched)
            $this->log($batch, 'payment', $a['jobber_invoice_id'], $a['payment_id'] ?: null, $tx, $entryId, null, $a['amount'], $p['date'], $userId);
        }
        if ($p['fee'] > self::CENT) $this->log($batch, 'fee', null, null, $tx, $entryId, null, $p['fee'], $p['date'], $userId);
        $this->changeBankLine($batch, $tx, (float)$p['amount'], (float)$p['income_after'], (float)$p['gst_after'], (float)$p['entry']['gst_before'], $p, $userId);
    }

    /** Rule 3: the bank line keeps only the 2026 part as income. Logged with what it was. */
    private function changeBankLine(string $batch, int $tx, float $amount, float $incomeAfter, float $gstAfter, float $gstBefore, array $p, int $userId): void
    {
        $s = $this->db->prepare("SELECT type, amount, gst_amount, status FROM accounting_transactions WHERE id = ?");
        $s->execute([$tx]);
        $before = $s->fetch(PDO::FETCH_ASSOC);
        if (!$before) return;
        $note = 'Jobber import ' . date('Y-m-d') . ': paid Jobber invoice(s) ' . implode(', ', array_unique(array_filter(array_column($p['entry']['allocations'], 'invoice'))));
        $now = date('Y-m-d H:i:s');
        if ($incomeAfter <= self::CENT) {
            $this->db->prepare("UPDATE accounting_transactions SET type = 'transfer', status = 'reconciled', notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?)) WHERE id = ?")
               ->execute([$note . ' — FY2025 receivable, not 2026 income', $tx]);
        } elseif (abs($incomeAfter - $amount) >= self::CENT) {
            $this->db->prepare("UPDATE accounting_transactions SET amount = ?, gst_amount = ?, notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?)) WHERE id = ?")
               ->execute([$incomeAfter, $gstAfter, $note . ' — $' . number_format($amount - $incomeAfter, 2) . ' of it was FY2025 receivable', $tx]);
        } else {
            $this->db->prepare("UPDATE accounting_transactions SET notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?)) WHERE id = ?")->execute([$note, $tx]);
        }
        try { $this->db->prepare("UPDATE bank_import_rows SET match_status = 'manually_matched' WHERE transaction_id = ?")->execute([$tx]); } catch (Throwable $e) { /* no column */ }
        $this->db->prepare("INSERT INTO jobber_ledger_log (batch_id, op, transaction_id, amount, entry_date, tx_before_type, tx_before_amount, tx_before_gst,
                                                           tx_before_status, created_by, created_at) VALUES (?, 'bank_line', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$batch, $tx, round($incomeAfter, 2), $p['date'], $before['type'], $before['amount'], $before['gst_amount'], $before['status'], $userId, $now]);
    }

    /**
     * Book a deposit by hand against Jobber invoice numbers (the payments recorded on them in 2026
     * that aren't booked yet). The payments must add up to the deposit (after fees).
     */
    public function linkManual(int $txId, array $numbers, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1239 first.'];
        $d = null;
        foreach ($this->deposits() as $x) if ($x['id'] === $txId) $d = $x;
        if (!$d) return ['ok' => false, 'message' => 'That deposit is not open (already booked, linked to a CRM invoice, or not 2026).'];
        $numbers = array_values(array_unique(array_map(fn($n) => ltrim(trim((string)$n), '#'), $numbers)));
        $pays = [];
        foreach ($this->openPayments() as $p) {
            if (array_intersect(JobberCsv::invoiceNumbers($p['invoice_numbers']), $numbers)) $pays[] = $p;
        }
        if (!$pays) return ['ok' => false, 'message' => 'No unbooked 2026 Jobber payments on invoice(s) ' . implode(', ', $numbers) . '.'];
        $net = round(array_sum(array_map(fn($p) => (float)$p['amount'] - (float)($p['fee'] ?? 0), $pays)), 2);
        if (abs($net - $d['amount']) >= self::CENT) {
            return ['ok' => false, 'message' => sprintf('Those payments come to $%s (after fees); the deposit is $%s.', number_format($net, 2), number_format($d['amount'], 2))];
        }
        $invoices = $this->invoices();
        $acct = $this->accounts();
        $p = $this->proposal($d, $pays, 'Linked by hand: Jobber #' . implode(', #', $numbers), 'manual', $invoices, $acct, $this->liveRevenue(), $this->crmInvoices());
        if ($p['blocked']) return ['ok' => false, 'message' => implode('; ', $p['blocked'])];
        $byId = [];
        foreach ($invoices as $inv) $byId[(int)$inv['id']] = $inv;
        $batch = self::batchId();
        $this->bookProposal($p, $byId, $acct, $batch, $userId);
        return ['ok' => true, 'batch_id' => $batch, 'message' => 'Deposit booked against Jobber #' . implode(', #', $numbers) . '.'];
    }

    /**
     * Book a deposit against chosen Jobber payments and/or open Jobber invoice balances
     * (PaymentMatchService, 2026-10-07). An invoice balance with no Jobber payment behind it — paid
     * after the cutover, never recorded in Jobber — becomes a payment of $amount on that invoice
     * (logged with jobber_payment_id NULL; remainingWithoutPayment() takes it off the balance).
     * Same entry, same rules as every other Jobber deposit: the FY2025 part settles the opening
     * receivable and leaves 2026 income. Refused when it doesn't add up to the cent.
     *
     * @param int[] $paymentIds     jobber_payments.id (unbooked, 2026)
     * @param array $invoiceAllocs  list of ['jobber_invoice_id' => int, 'amount' => float]
     */
    public function bookMatched(int $txId, array $paymentIds, array $invoiceAllocs, string $how, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1239 first.'];
        $d = null;
        foreach ($this->deposits() as $x) if ($x['id'] === $txId) $d = $x;
        if (!$d) return ['ok' => false, 'message' => 'That deposit is not open (already booked, linked to a CRM invoice, in the journal, or not 2026).'];
        $open = [];
        foreach ($this->openPayments() as $p) $open[(int)$p['id']] = $p;
        $pays = [];
        foreach (array_unique(array_map('intval', $paymentIds)) as $pid) {
            if (!isset($open[$pid])) return ['ok' => false, 'message' => 'Jobber payment #' . $pid . ' is already booked or not a 2026 payment.'];
            $pays[] = $open[$pid];
        }
        $invoices = $this->invoices();
        $byId = [];
        foreach ($invoices as $inv) $byId[(int)$inv['id']] = $inv;
        $left = $this->remainingWithoutPayment();
        foreach ($invoiceAllocs as $a) {
            $iid = (int)($a['jobber_invoice_id'] ?? 0);
            $amt = round((float)($a['amount'] ?? 0), 2);
            $inv = $byId[$iid] ?? null;
            if (!$inv || $amt <= self::CENT) return ['ok' => false, 'message' => 'Jobber invoice #' . $iid . ' not found.'];
            $rem = round((float)$inv['balance'] - ($left[$iid] ?? 0.0), 2);
            if ($amt > $rem + self::CENT) return ['ok' => false, 'message' => sprintf('Jobber #%s has only $%s left owing.', $inv['jobber_number'], number_format(max(0, $rem), 2))];
            $pays[] = ['id' => 0, 'kind' => 'payment', 'client_name' => $inv['client_name'], 'contact_id' => $inv['contact_id'], 'payment_date' => $d['date'],
                       'amount' => $amt, 'fee' => 0, 'method' => 'Bank deposit (not recorded in Jobber)', 'invoice_numbers' => (string)$inv['jobber_number'], 'quote_number' => null];
        }
        if (!$pays) return ['ok' => false, 'message' => 'Pick the Jobber payments or invoices this deposit paid.'];
        $net = round(array_sum(array_map(fn($p) => (float)$p['amount'] - (float)($p['fee'] ?? 0), $pays)), 2);
        if (abs($net - $d['amount']) >= self::CENT) {
            return ['ok' => false, 'message' => sprintf('Those come to $%s (after fees); the deposit is $%s.', number_format($net, 2), number_format($d['amount'], 2))];
        }
        $acct = $this->accounts();
        $p = $this->proposal($d, $pays, mb_substr($how, 0, 120), 'manual', $invoices, $acct, $this->liveRevenue(), $this->crmInvoices());
        if ($p['blocked']) return ['ok' => false, 'message' => implode('; ', $p['blocked'])];
        if ($p['locked']) return ['ok' => false, 'message' => substr($d['date'], 0, 7) . ' is locked.'];
        $batch = self::batchId();
        $this->bookProposal($p, $byId, $acct, $batch, $userId);
        return ['ok' => true, 'batch_id' => $batch, 'fy2025_part' => $p['fy2025_part'], 'income_after' => $p['income_after'],
                'message' => 'Deposit booked against Jobber ' . implode(', ', array_unique(array_filter(array_column($p['entry']['allocations'], 'invoice')))) . '.'];
    }

    /** jobber_invoice_id => amount paid by deposits with no Jobber payment behind them (bookMatched, live). */
    public function remainingWithoutPayment(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT jobber_invoice_id, SUM(amount) AS paid FROM jobber_ledger_log
                                       WHERE op = 'payment' AND undone_at IS NULL AND jobber_payment_id IS NULL AND jobber_invoice_id IS NOT NULL
                                       GROUP BY jobber_invoice_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['jobber_invoice_id']] = round((float)$r['paid'], 2);
            }
        } catch (Throwable $e) { /* before migration 1239 */ }
        return $out;
    }

    /** A 2026 deposit with no Jobber invoice behind it: DR bank / CR revenue (+ GST) — it stays income. */
    public function bookAsIncome(int $txId, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1239 first.'];
        $d = null;
        foreach ($this->deposits() as $x) if ($x['id'] === $txId) $d = $x;
        if (!$d) return ['ok' => false, 'message' => 'That deposit is not open.'];
        if ($d['bbc_entry_id']) return ['ok' => false, 'message' => 'It is already booked as income (bank balance check).'];
        $acct = $this->accounts();
        $gst = round(min(max(0.0, $d['gst_amount']), $d['amount']), 2);
        $revAcct = $this->accountType($d['account_id']) === 'revenue' ? $d['account_id'] : $acct['revenue'];
        $lines = [['account_id' => $d['bank_account_id'], 'debit' => $d['amount'], 'credit' => 0]];
        if ($d['amount'] - $gst > self::CENT) $lines[] = ['account_id' => $revAcct, 'debit' => 0, 'credit' => round($d['amount'] - $gst, 2), 'description' => mb_substr($d['description'], 0, 255)];
        if ($gst > self::CENT) $lines[] = ['account_id' => $acct['gst'], 'debit' => 0, 'credit' => $gst, 'gst_amount' => $gst];
        $batch = self::batchId();
        $id = $this->ledger->postManual(['entry_date' => $d['date'], 'memo' => mb_substr('Deposit with no Jobber invoice — ' . $d['description'], 0, 255),
                                         'source_type' => 'bank_deposit', 'source_id' => $txId, 'created_by' => $userId, 'proposed_by' => 'owner', 'lines' => $lines]);
        $this->log($batch, 'income', null, null, $txId, $id, null, $d['amount'], $d['date'], $userId);
        return ['ok' => true, 'batch_id' => $batch, 'message' => 'Booked as income (entry #' . $id . ').'];
    }

    /**
     * An open Jobber invoice recreated in the CRM: link it. Issued in 2025 → its receivable is in the
     * opening, and the CRM invoice will post revenue + receivable again, so a carry-over entry takes
     * that back (DR 4900 + DR 2200 / CR 1100); AccountingService::syncFromInvoices also leaves the
     * CRM invoice's payment out of 2026 income. Issued in 2026 → its Jobber revenue entry (if any) is
     * reversed; the CRM invoice carries it.
     */
    public function linkCrmInvoice(int $jobberId, string $invoiceNumber, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1239 first.'];
        $s = $this->db->prepare("SELECT * FROM jobber_invoices WHERE id = ?");
        $s->execute([$jobberId]);
        $j = $s->fetch(PDO::FETCH_ASSOC);
        if (!$j) return ['ok' => false, 'message' => 'Jobber invoice not found.'];
        if (!empty($j['crm_invoice_id'])) return ['ok' => false, 'message' => 'Already linked — undo that first.'];
        $c = $this->db->prepare("SELECT id, invoice_number, subtotal, tax_amount, total, issue_date, contact_id FROM invoices WHERE invoice_number = ? LIMIT 1");
        $c->execute([trim($invoiceNumber)]);
        $crm = $c->fetch(PDO::FETCH_ASSOC);
        if (!$crm) return ['ok' => false, 'message' => 'No CRM invoice ' . $invoiceNumber . '.'];
        $taken = $this->db->prepare("SELECT jobber_number FROM jobber_invoices WHERE crm_invoice_id = ? LIMIT 1");
        $taken->execute([(int)$crm['id']]);
        if ($other = $taken->fetchColumn()) return ['ok' => false, 'message' => 'That CRM invoice is already linked to Jobber #' . $other . '.'];
        $batch = self::batchId();
        $this->db->prepare("UPDATE jobber_invoices SET crm_invoice_id = ?, updated_at = ? WHERE id = ?")->execute([(int)$crm['id'], date('Y-m-d H:i:s'), $jobberId]);
        $this->log($batch, 'link', $jobberId, null, null, null, null, 0.0, date('Y-m-d'), $userId);
        $msg = 'Jobber #' . $j['jobber_number'] . ' linked to ' . $crm['invoice_number'] . '.';
        if ((string)$j['issued_date'] < self::FY_START) {
            $acct = $this->accounts();
            $amount = round(min((float)$j['balance'], (float)$crm['total']), 2);
            if ($amount > self::CENT) {
                $ratio = (float)$crm['total'] > 0 ? (float)($crm['tax_amount'] ?? 0) / (float)$crm['total'] : 0.0;
                $gst = round($amount * $ratio, 2);
                $date = substr((string)($crm['issue_date'] ?: date('Y-m-d')), 0, 10);
                if ($date < self::FY_START || $this->isLocked($date)) $date = date('Y-m-d');
                $lines = [['account_id' => $acct['revenue'], 'debit' => round($amount - $gst, 2), 'credit' => 0, 'contact_id' => $crm['contact_id'] ?? null]];
                if ($gst > self::CENT) $lines[] = ['account_id' => $acct['gst'], 'debit' => $gst, 'credit' => 0, 'gst_amount' => -$gst];
                $lines[] = ['account_id' => $acct['ar'], 'debit' => 0, 'credit' => $amount, 'contact_id' => $crm['contact_id'] ?? null];
                $id = $this->ledger->postManual(['entry_date' => $date, 'source_type' => 'jobber_carryover', 'source_id' => $jobberId, 'created_by' => $userId,
                    'proposed_by' => 'owner', 'is_adjusting' => 1, 'lines' => $lines,
                    'memo' => 'Jobber #' . $j['jobber_number'] . ' (2025, in the opening receivable) recreated as ' . $crm['invoice_number'] . ' — its revenue is FY2025']);
                $this->log($batch, 'carryover', $jobberId, null, null, $id, null, $amount, $date, $userId);
                $msg .= ' Carry-over entry #' . $id . ' takes the CRM invoice\'s revenue back out (it is FY2025 revenue).';
            }
        } else {
            $live = $this->liveRevenue();
            if (isset($live[$jobberId])) {
                $rev = $this->ledger->reverseEntry($live[$jobberId], $userId, 'Jobber #' . $j['jobber_number'] . ' recreated as ' . $crm['invoice_number'], 'owner');
                $this->log($batch, 'reverse', $jobberId, null, null, $rev, $live[$jobberId], -(float)$j['total'], date('Y-m-d'), $userId);
                $msg .= ' Its Jobber revenue entry was reversed — the CRM invoice carries it.';
            }
        }
        return ['ok' => true, 'batch_id' => $batch, 'message' => $msg];
    }

    /** CRM invoice ids that carry a 2025 Jobber invoice (their payments are not 2026 income). */
    public static function carryoverInvoiceIds(PDO $db): array
    {
        try {
            $s = $db->prepare("SELECT crm_invoice_id FROM jobber_invoices WHERE crm_invoice_id IS NOT NULL AND issued_date < ?");
            $s->execute([self::FY_START]);
            return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Undo one approval: entries reversed, bank lines restored, reversed entries posted again, links removed. */
    public function undo(string $batch, int $userId): array
    {
        $s = $this->db->prepare("SELECT * FROM jobber_ledger_log WHERE batch_id = ? AND undone_at IS NULL ORDER BY id");
        $s->execute([$batch]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['ok' => false, 'message' => 'Nothing to undo in that batch.'];
        // Our postings out first, then bank lines back, then what we reversed posted again (a re-post
        // of the same source is idempotent, so it must come after our own entry for it is reversed).
        $rank = fn($r) => $r['op'] === 'reverse' ? 2 : ($r['op'] === 'bank_line' || $r['op'] === 'link' ? 1 : 0);
        usort($rows, fn($a, $b) => [$rank($a), (int)$a['id']] <=> [$rank($b), (int)$b['id']]);
        $mark = $this->db->prepare("UPDATE jobber_ledger_log SET undone_at = ?, undone_by = ?, undo_entry_id = ? WHERE id = ?");
        $now = date('Y-m-d H:i:s');
        $reversed = [];
        $done = 0; $failed = [];
        foreach ($rows as $r) {
            try {
                $undoId = null;
                if (in_array($r['op'], ['revenue', 'payment', 'fee', 'income', 'carryover'], true) && $r['entry_id']) {
                    $eid = (int)$r['entry_id'];
                    if (!isset($reversed[$eid])) $reversed[$eid] = $this->ledger->reverseEntry($eid, $userId, 'Jobber import undone (' . $batch . ')', 'owner');
                    $undoId = $reversed[$eid];
                } elseif ($r['op'] === 'bank_line') {
                    $this->db->prepare("UPDATE accounting_transactions SET type = ?, amount = ?, gst_amount = ?, status = ? WHERE id = ?")
                       ->execute([$r['tx_before_type'], $r['tx_before_amount'], $r['tx_before_gst'], $r['tx_before_status'], (int)$r['transaction_id']]);
                } elseif ($r['op'] === 'reverse' && $r['target_entry_id']) {
                    $undoId = $this->repostCopy((int)$r['target_entry_id'], $userId, $batch);
                } elseif ($r['op'] === 'link') {
                    $this->db->prepare("UPDATE jobber_invoices SET crm_invoice_id = NULL WHERE id = ?")->execute([(int)$r['jobber_invoice_id']]);
                }
                $mark->execute([$now, $userId, $undoId, (int)$r['id']]);
                $done++;
            } catch (Throwable $e) {
                $failed[] = $r['op'] . ' #' . $r['id'] . ': ' . $e->getMessage();
            }
        }
        return ['ok' => !$failed, 'message' => $done . ' step(s) undone' . ($failed ? ', ' . count($failed) . ' failed: ' . implode('; ', array_slice($failed, 0, 3)) : '') . '.'];
    }

    private function repostCopy(int $entryId, int $userId, string $batch): int
    {
        $h = $this->db->prepare("SELECT entry_date, memo, source_type, source_id, is_adjusting FROM journal_entries WHERE id = ?");
        $h->execute([$entryId]);
        $hdr = $h->fetch(PDO::FETCH_ASSOC);
        if (!$hdr) throw new RuntimeException("Journal entry $entryId not found");
        $l = $this->db->prepare("SELECT account_id, debit, credit, gst_amount, pst_amount, description, job_id, contact_id, vendor_id, crew_user_id, cost_type_id, service_type
                                 FROM journal_lines WHERE entry_id = ?");
        $l->execute([$entryId]);
        $lines = array_map(fn($ln) => ['account_id' => (int)$ln['account_id'], 'debit' => (float)$ln['debit'], 'credit' => (float)$ln['credit'],
            'gst_amount' => (float)$ln['gst_amount'], 'pst_amount' => (float)$ln['pst_amount'], 'description' => $ln['description'], 'job_id' => $ln['job_id'],
            'contact_id' => $ln['contact_id'], 'vendor_id' => $ln['vendor_id'], 'crew_user_id' => $ln['crew_user_id'], 'cost_type_id' => $ln['cost_type_id'],
            'service_type' => $ln['service_type']], $l->fetchAll(PDO::FETCH_ASSOC));
        return $this->ledger->postManual(['entry_date' => substr((string)$hdr['entry_date'], 0, 10),
            'memo' => mb_substr('Re-posted (Jobber import ' . $batch . ' undone) — ' . (string)$hdr['memo'], 0, 255),
            'source_type' => (string)$hdr['source_type'], 'source_id' => $hdr['source_id'] !== null ? (int)$hdr['source_id'] : null,
            'is_adjusting' => (int)$hdr['is_adjusting'], 'created_by' => $userId, 'proposed_by' => 'owner', 'lines' => $lines]);
    }

    public function batches(int $limit = 30): array
    {
        try {
            $s = $this->db->query("SELECT batch_id, COUNT(*) AS steps, MIN(created_at) AS created_at,
                                          SUM(CASE WHEN op IN ('revenue') THEN 1 ELSE 0 END) AS revenue,
                                          COUNT(DISTINCT CASE WHEN op = 'payment' THEN transaction_id END) AS deposits,
                                          SUM(CASE WHEN op IN ('link', 'carryover') THEN 1 ELSE 0 END) AS links,
                                          SUM(CASE WHEN op = 'income' THEN 1 ELSE 0 END) AS income,
                                          SUM(CASE WHEN undone_at IS NULL THEN 0 ELSE 1 END) AS undone
                                   FROM jobber_ledger_log GROUP BY batch_id ORDER BY MIN(id) DESC LIMIT " . max(1, min(200, $limit)));
            return array_map(fn($r) => ['batch_id' => $r['batch_id'], 'steps' => (int)$r['steps'], 'created_at' => $r['created_at'],
                                        'revenue' => (int)$r['revenue'], 'deposits' => (int)$r['deposits'], 'links' => (int)$r['links'], 'income' => (int)$r['income'],
                                        'undone' => (int)$r['undone'] >= (int)$r['steps']], $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private static function batchId(): string
    {
        return 'jbl-' . date('YmdHis') . '-' . substr(sha1(uniqid('', true)), 0, 8);
    }

    private function log(string $batch, string $op, ?int $jobberId, ?int $paymentId, ?int $tx, ?int $entryId, ?int $target, float $amount, string $date, int $userId): void
    {
        $this->db->prepare("INSERT INTO jobber_ledger_log (batch_id, op, jobber_invoice_id, jobber_payment_id, transaction_id, entry_id, target_entry_id, amount, entry_date, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$batch, $op, $jobberId, $paymentId, $tx, $entryId, $target, round($amount, 2), $date, $userId, date('Y-m-d H:i:s')]);
    }

    /** ar, revenue, gst, fee, deposits => chart id (null when missing). */
    public function accounts(): array
    {
        $deposits = $this->chartIdOrNull(self::DEPOSITS_CODE);
        if ($deposits === null) {
            try {
                $id = $this->db->query("SELECT id FROM chart_of_accounts WHERE LOWER(name) LIKE '%customer deposit%' ORDER BY id LIMIT 1")->fetchColumn();
                $deposits = $id === false ? null : (int)$id;
            } catch (Throwable $e) { /* none */ }
        }
        return ['ar' => $this->chartIdOrNull('1100'), 'revenue' => $this->chartIdOrNull(self::REVENUE_CODE), 'gst' => $this->chartIdOrNull('2200'),
                'fee' => $this->chartIdOrNull(self::FEE_CODE), 'deposits' => $deposits];
    }

    private function accountType(int $id): ?string
    {
        $s = $this->db->prepare("SELECT type FROM chart_of_accounts WHERE id = ?");
        $s->execute([$id]);
        $t = $s->fetchColumn();
        return $t === false ? null : (string)$t;
    }

    private function chartIdOrNull(string $code): ?int
    {
        if ($this->chart === null) {
            $this->chart = [];
            foreach ($this->db->query("SELECT id, code FROM chart_of_accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!isset($this->chart[(string)$r['code']])) $this->chart[(string)$r['code']] = (int)$r['id'];
            }
        }
        return $this->chart[$code] ?? null;
    }

    private function chartId(string $code): int
    {
        $id = $this->chartIdOrNull($code);
        if ($id === null) throw new RuntimeException("Chart of accounts code not found: $code");
        return $id;
    }

    private function isLocked(string $date): bool
    {
        if ($date === '' || $date < self::FY_START) return true;
        try { return $this->ledger->isLocked($date); } catch (Throwable $e) { return false; }
    }

    private function idSet(string $sql): array
    {
        try {
            return array_fill_keys(array_map('intval', $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (Throwable $e) {
            return [];
        }
    }
}
