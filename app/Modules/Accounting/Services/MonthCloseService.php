<?php
/**
 * MonthCloseService — is a month exact enough to lock? (Tim, 2026-10-14: "the ledger has to be exact".)
 *
 * StatementCloseService proves the statements (they add up, chain, every line is in the books). That is
 * not enough to lock: on 2026-10-14 it listed Jan–Sep 2026 as lockable while January's books were $48.88
 * off the bank and February had 9 open receipts. A month is ready only when ALL of these hold:
 *   1. statements — StatementCloseService::lockable() includes it;
 *   2. books = bank — every tracked account's month-end book balance equals its statement to the cent
 *      (BankBalanceCheckService drift; an account with no statement figure that month is not judged);
 *   3. no open receipts — no draft / pending_approval expense dated in the month;
 *   4. no unsorted bank lines — none waiting on Penny's bank desk (default accounts 4900 / 6900).
 * Penny works the oldest unready month to zero (blockers() says what is left); lockMonth() refuses
 * anything not ready, server side.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/StatementCloseService.php';
require_once __DIR__ . '/BankBalanceCheckService.php';
require_once __DIR__ . '/LedgerService.php';

class MonthCloseService
{
    public const CENT = 0.005;
    public const FIRST = '2026-01';
    /** Days after a month ends before it can be ready: statements, card closings and late crew receipts (Tim, 2026-10-14). */
    public const GRACE_DAYS = 15;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Every month from FIRST to last month, oldest first.
     * @return list<array{month:string, locked:bool, statements_ok:bool, drift:array<string,float>, receipts:int, receipts_amount:float, bank_lines:int, ready:bool, blockers:list<string>}>
     */
    public function months(?string $today = null): array
    {
        $current = substr($today ?? date('Y-m-d'), 0, 7);
        $close = new StatementCloseService($this->db);
        $accounts = $close->status();
        $locked = $close->lockedMonths();
        $statementOk = array_flip(StatementCloseService::lockable($accounts, $locked, $today));

        $drift = [];   // month => [account label => drift]
        try {
            $report = (new BankBalanceCheckService($this->db, new LedgerService($this->db)))->report();
            foreach ($report['accounts'] ?? [] as $a) {
                foreach ($a['months'] ?? [] as $m) {
                    if ($m['statement'] === null || $m['drift'] === null) continue;
                    $drift[$m['ym']][$a['code'] . ' ' . $a['name']] = round((float)$m['drift'], 2);
                }
            }
        } catch (Throwable $e) {
            error_log('[MonthClose] balance report: ' . $e->getMessage());
        }

        $receipts = [];
        foreach ($this->db->query("SELECT DATE_FORMAT(expense_date, '%Y-%m') ym, COUNT(*) n, COALESCE(SUM(total), 0) amt
                                   FROM expenses WHERE status IN ('draft', 'pending_approval') GROUP BY ym")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $receipts[$r['ym']] = [(int)$r['n'], round((float)$r['amt'], 2)];
        }
        $lines = $this->waitingBankLinesByMonth();

        $out = [];
        for ($m = self::FIRST; $m < $current; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            $row = [
                'month' => $m, 'locked' => in_array($m, $locked, true), 'statements_ok' => isset($statementOk[$m]),
                'drift' => $drift[$m] ?? [], 'receipts' => $receipts[$m][0] ?? 0, 'receipts_amount' => $receipts[$m][1] ?? 0.0,
                'bank_lines' => $lines[$m] ?? 0,
            ];
            $row['grace_until'] = self::graceUntil($m);
            $row['blockers'] = $row['locked'] ? [] : self::blockers($row, $today);
            $row['ready'] = !$row['locked'] && !$row['blockers'];
            $out[] = $row;
        }
        return $out;
    }

    /** Months ready to lock now. */
    public function ready(?string $today = null): array
    {
        return array_values(array_map(fn($r) => $r['month'], array_filter($this->months($today), fn($r) => $r['ready'])));
    }

    /** Pure (tested): what still stands between a month and being exact, in Tim's words. */
    public static function blockers(array $m, ?string $today = null): array
    {
        $b = [];
        $until = $m['grace_until'] ?? (isset($m['month']) ? self::graceUntil((string)$m['month']) : null);
        if ($until !== null && ($today ?? date('Y-m-d')) < $until) $b[] = 'waiting until ' . $until . ' for late statements and receipts';
        if (empty($m['statements_ok'])) $b[] = 'a statement is missing or doesn\'t check out';
        foreach ((array)($m['drift'] ?? []) as $acct => $d) {
            if (abs((float)$d) >= self::CENT) {
                $b[] = sprintf('%s: books are $%s %s the bank', $acct, number_format(abs((float)$d), 2), $d > 0 ? 'above' : 'below');
            }
        }
        if (($m['receipts'] ?? 0) > 0) $b[] = $m['receipts'] . ' open receipt' . ($m['receipts'] === 1 ? '' : 's') . ' ($' . number_format((float)($m['receipts_amount'] ?? 0), 2) . ')';
        if (($m['bank_lines'] ?? 0) > 0) $b[] = $m['bank_lines'] . ' bank line' . ($m['bank_lines'] === 1 ? '' : 's') . ' still to sort';
        return $b;
    }

    /** Pure: the first day a month may be ready (GRACE_DAYS after its last day). */
    public static function graceUntil(string $ym): string
    {
        return date('Y-m-d', strtotime(date('Y-m-t', strtotime($ym . '-01')) . ' +' . self::GRACE_DAYS . ' days'));
    }

    /** Bank lines on Penny's desk (same rule as BankDeskService::waiting), by month. */
    private function waitingBankLinesByMonth(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("
                SELECT DATE_FORMAT(t.transaction_date, '%Y-%m') ym, COUNT(*) n
                FROM accounting_transactions t LEFT JOIN chart_of_accounts a ON a.id = t.account_id
                WHERE t.reference_type = 'bank_import'
                  AND ((t.type IN ('expense', 'income') AND (t.account_id IS NULL OR a.code IN ('4900', '6900'))
                        AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_type = 'bank_deposit' AND je.source_id = t.id AND je.reversed_by_entry_id IS NULL))
                       OR (t.type = 'transfer' AND a.code = '2400' AND t.matched_expense_id IS NULL
                           AND t.description NOT REGEXP 'VISA|MASTERCARD|MASTER CARD|AMEX|AMERICAN EXPRESS|CREDIT ?CARD|THANK ?YOU|AUTOMATIC PAYMENT|PAYMENT RECEIVED'))
                  AND NOT EXISTS (SELECT 1 FROM bank_line_reviews r WHERE r.transaction_id = t.id)
                GROUP BY ym")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['ym']] = (int)$r['n'];
            }
        } catch (Throwable $e) {
            error_log('[MonthClose] bank lines: ' . $e->getMessage());
        }
        return $out;
    }
}
