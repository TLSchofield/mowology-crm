<?php
/**
 * LedgerRepostService — move already-posted invoices and receipts to the right accounts.
 *
 * Posting is once-only per (source_type, source_id), so fixing LedgerAccountMap changes
 * new postings but not the ~years already in the books (every invoice on 4900, every
 * receipt on 6900). This compares each automatic invoice / expense entry's income or
 * cost account(s) with what the maps say now, and re-posts only those that differ:
 * delete the entry (its lines cascade) and post it again through the same recipe the
 * nightly sync uses. If a re-post fails after the delete, the nightly sync posts the
 * missing entry again — with the new accounts — so nothing is left out.
 * Payments, bank lines and manual / adjusting entries are never touched.
 *
 * Run from public/crm/api/run-repost-ledger.php (dry-run by default, typed confirm).
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';
require_once __DIR__ . '/LedgerAccountMap.php';

class LedgerRepostService
{
    /** Lines that aren't the income / cost side of an entry. */
    private const NOT_CATEGORY = ['1100', '2200', '2210', '2300', '2400', '1010', '1300', '2100'];

    private PDO $db;
    private LedgerAccountMap $map;
    private LedgerSyncService $sync;
    private LedgerService $ledger;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->map = new LedgerAccountMap($db);
        $this->ledger = new LedgerService($db);
        $this->sync = new LedgerSyncService($db, $this->ledger);
    }

    /**
     * What would move.
     * @return array{invoices: array, expenses: array, by_account: array, unmapped: array}
     */
    public function plan(): array
    {
        $posted = $this->postedCategoryLines();
        $invoices = [];
        $s = $this->db->query("SELECT id, subtotal, tax_amount, total, amount_paid, status, contact_id, plan_id, contract_id,
                                      issue_date, paid_at, created_at FROM invoices WHERE COALESCE(total, 0) > 0");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            $have = $posted['invoice'][(int)$inv['id']] ?? null;
            if (!$have) continue;                                  // not posted yet — the sync will post it right
            $args = $this->sync->mapInvoiceToInvoiceArgs($inv);
            $want = self::asMap($this->map->invoiceSplits($inv, $args['net']));
            if (!self::same($have['lines'], $want)) {
                $invoices[] = ['id' => (int)$inv['id'], 'entry_id' => $have['entry_id'], 'from' => $have['lines'], 'to' => $want];
            }
        }
        $expenses = [];
        $s = $this->db->query("SELECT id, expense_date, total, gst_amount, pst_amount, accounting_category, payment_method,
                                      vendor_id, job_id, contact_id FROM expenses WHERE total > 0 AND status IN ('approved', 'forwarded')");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $have = $posted['expense'][(int)$e['id']] ?? null;
            if (!$have) continue;
            $amount = array_sum($have['lines']);
            $want = [$this->map->expenseCode($e['accounting_category']) => round($amount, 2)];
            if (!self::same($have['lines'], $want)) {
                $expenses[] = ['id' => (int)$e['id'], 'entry_id' => $have['entry_id'], 'from' => $have['lines'], 'to' => $want,
                               'category' => $e['accounting_category']];
            }
        }
        return ['invoices' => $invoices, 'expenses' => $expenses,
                'by_account' => self::byAccount(array_merge($invoices, $expenses), $this->names()),
                'unmapped' => $this->map->unmappedServiceTypes()];
    }

    /** Re-post what plan() found. @return array{reposted: int, failed: int, errors: array} */
    public function apply(): array
    {
        $p = $this->plan();
        $done = 0; $failed = 0; $errors = [];
        $invRows = $this->rowsById("SELECT id, subtotal, tax_amount, total, amount_paid, status, contact_id, plan_id, contract_id,
                                           issue_date, paid_at, created_at FROM invoices", array_column($p['invoices'], 'id'));
        $expRows = $this->rowsById("SELECT id, expense_date, total, gst_amount, pst_amount, accounting_category, payment_method,
                                           vendor_id, job_id, contact_id FROM expenses", array_column($p['expenses'], 'id'));
        foreach ($p['invoices'] as $item) {
            try {
                $row = $invRows[$item['id']];
                $args = $this->sync->mapInvoiceToInvoiceArgs($row);
                $args['revenue_splits'] = $this->map->invoiceSplits($row, $args['net']);
                $this->db->prepare("DELETE FROM journal_entries WHERE id = ? AND source_type = 'invoice'")->execute([$item['entry_id']]);
                $this->ledger->postInvoice($args);
                $done++;
            } catch (Throwable $e) {
                $failed++; $errors[] = 'Invoice ' . $item['id'] . ': ' . $e->getMessage();
            }
        }
        foreach ($p['expenses'] as $item) {
            try {
                $args = $this->sync->expenseArgs($expRows[$item['id']]);
                $this->db->prepare("DELETE FROM journal_entries WHERE id = ? AND source_type = 'expense'")->execute([$item['entry_id']]);
                $this->ledger->postExpense($args);
                $done++;
            } catch (Throwable $e) {
                $failed++; $errors[] = 'Receipt ' . $item['id'] . ': ' . $e->getMessage();
            }
        }
        return ['reposted' => $done, 'failed' => $failed, 'errors' => array_slice($errors, 0, 20)];
    }

    /** source_type => source_id => [entry_id, lines: code => amount] for the income / cost side. */
    private function postedCategoryLines(): array
    {
        $rows = $this->db->query("
            SELECT je.id AS entry_id, je.source_type, je.source_id, c.code, jl.debit, jl.credit
            FROM journal_entries je
            JOIN journal_lines jl ON jl.entry_id = je.id
            JOIN chart_of_accounts c ON c.id = jl.account_id
            WHERE je.source_type IN ('invoice', 'expense') AND je.status = 'posted' AND je.source_id IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);
        $out = ['invoice' => [], 'expense' => []];
        foreach ($rows as $r) {
            if (in_array($r['code'], self::NOT_CATEGORY, true)) continue;
            $t = $r['source_type']; $id = (int)$r['source_id'];
            $out[$t][$id]['entry_id'] = (int)$r['entry_id'];
            $amt = $t === 'invoice' ? (float)$r['credit'] : (float)$r['debit'];
            $out[$t][$id]['lines'][$r['code']] = round(($out[$t][$id]['lines'][$r['code']] ?? 0) + $amt, 2);
        }
        return $out;
    }

    private function rowsById(string $sql, array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($this->db->query($sql . " WHERE id IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
        return $out;
    }

    private function names(): array
    {
        return array_column($this->db->query("SELECT code, name FROM chart_of_accounts")->fetchAll(PDO::FETCH_ASSOC), 'name', 'code');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public static function asMap(array $splits): array
    {
        $m = [];
        foreach ($splits as [$code, $amt]) $m[$code] = round(($m[$code] ?? 0) + (float)$amt, 2);
        return $m;
    }

    /** Same accounts with the same amounts (to the cent)? */
    public static function same(array $a, array $b): bool
    {
        $a = array_filter($a, fn($v) => abs($v) >= 0.005);
        $b = array_filter($b, fn($v) => abs($v) >= 0.005);
        if (array_diff_key($a, $b) || array_diff_key($b, $a)) return false;
        foreach ($a as $k => $v) if (abs($v - $b[$k]) > 0.009) return false;
        return true;
    }

    /** Net change per account across all moves: code => [name, before, after]. */
    public static function byAccount(array $moves, array $names): array
    {
        $out = [];
        foreach ($moves as $m) {
            foreach ($m['from'] as $code => $amt) {
                $out[$code] ??= ['name' => $names[$code] ?? $code, 'out' => 0.0, 'in' => 0.0];
                $out[$code]['out'] += $amt;
            }
            foreach ($m['to'] as $code => $amt) {
                $out[$code] ??= ['name' => $names[$code] ?? $code, 'out' => 0.0, 'in' => 0.0];
                $out[$code]['in'] += $amt;
            }
        }
        foreach ($out as &$o) { $o['out'] = round($o['out'], 2); $o['in'] = round($o['in'], 2); $o['net'] = round($o['in'] - $o['out'], 2); }
        unset($o);
        ksort($out);
        return $out;
    }
}
