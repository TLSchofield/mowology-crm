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
 *   3. no open receipts — no draft / pending_approval expense dated in the month (those on no bank or
 *      card line are named: paid personally → 'owner_paid' posts to the shareholder loan, needs no line);
 *      An item IN TRANSIT is not drift: a receipt dated in the month that the bank posted in the first days of the next
 *      (a debit purchase at 9:50 pm on the 28th, posted on the 1st) is the normal reconciling item of a bank reconciliation —
 *      the receipt keeps its true date, the check lists it as 'cleared <date>' and leaves it out of the gap (Tim, 2026-10-26).
 *   4. no unsorted bank lines — none waiting on Penny's bank desk (default accounts 4900 / 6900).
 * Penny works the oldest unready month to zero (blockers() says what is left); lockMonth() refuses
 * anything not ready, server side.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/StatementCloseService.php';
require_once __DIR__ . '/BankLineReceipts.php';
require_once __DIR__ . '/BankBalanceCheckService.php';
require_once __DIR__ . '/LedgerService.php';

class MonthCloseService
{
    public const CENT = 0.005;
    public const FIRST = '2026-01';
    /** Days after a month ends before it can be ready: statements, card closings and late crew receipts (Tim, 2026-10-14). */
    public const GRACE_DAYS = 15;
    /** A bank posting up to this many days into the next month is a reconciling item in transit, not drift. */
    public const IN_TRANSIT_DAYS = 3;

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
        $kinds = [];   // account label => 'bank' | 'card'
        $transit = []; // month => in-transit items left out of the drift
        $reportFailed = false;
        try {
            $report = (new BankBalanceCheckService($this->db, new LedgerService($this->db)))->report();
            foreach ($report['accounts'] ?? [] as $a) {
                foreach ($a['months'] ?? [] as $m) {
                    if ($m['statement'] === null || $m['drift'] === null) continue;
                    $drift[$m['ym']][$a['code'] . ' ' . $a['name']] = round((float)$m['drift'], 2);
                    $kinds[$a['code'] . ' ' . $a['name']] = (string)($a['kind'] ?? '');
                }
            }
            foreach ($this->inTransit() as $it) {
                $label = $it['code'] . ' ' . $it['name'];
                if (!isset($drift[$it['ym']][$label]) || in_array($it['ym'], $locked, true)) continue;   // a locked month is not re-judged
                if (!isset($kinds[$label]) || !in_array($kinds[$label], ['bank', 'card'], true)) continue;   // unknown kind: leave the difference showing
                $drift[$it['ym']][$label] = self::withoutInTransit((float)$drift[$it['ym']][$label], (float)$it['amount'], $kinds[$label]);
                $transit[$it['ym']][] = $it;
            }
        } catch (Throwable $e) {
            error_log('[MonthClose] balance report: ' . $e->getMessage());
            $reportFailed = true;   // books vs bank unproven: no month is ready (Codex review 2026-10-14)
        }

        $receipts = [];
        // unmatched = no bank or card line carries it, nor one of the same amount within 5 days: paid personally,
        // cash, or the statement isn't imported yet.
        foreach ($this->db->query("SELECT DATE_FORMAT(e.expense_date, '%Y-%m') ym, COUNT(*) n, COALESCE(SUM(e.total), 0) amt,
                                          SUM(CASE WHEN NOT " . BankLineReceipts::linkedSql($this->db, 'e.id') . "
                                                AND NOT EXISTS (SELECT 1 FROM accounting_transactions t WHERE t.reference_type = 'bank_import'
                                                                AND ABS(ABS(t.amount) - e.total) < 0.005
                                                                AND t.transaction_date BETWEEN e.expense_date - INTERVAL 5 DAY AND e.expense_date + INTERVAL 5 DAY)
                                           THEN 1 ELSE 0 END) unmatched
                                   FROM expenses e WHERE e.status IN ('draft', 'pending_approval') GROUP BY ym")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $receipts[$r['ym']] = [(int)$r['n'], round((float)$r['amt'], 2), (int)$r['unmatched']];
        }
        $lines = $this->waitingBankLinesByMonth();

        $out = [];
        for ($m = self::FIRST; $m < $current; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            $row = [
                'month' => $m, 'locked' => in_array($m, $locked, true), 'statements_ok' => isset($statementOk[$m]),
                'drift' => $drift[$m] ?? [], 'receipts' => $receipts[$m][0] ?? 0, 'receipts_amount' => $receipts[$m][1] ?? 0.0,
                'receipts_unmatched' => $receipts[$m][2] ?? 0,
                'bank_lines' => $lines[$m] ?? 0,
                'in_transit' => $transit[$m] ?? [],
            ];
            $row['grace_until'] = self::graceUntil($m);
            $row['blockers'] = $row['locked'] ? [] : self::blockers($row, $today);
            if (!$row['locked'] && $reportFailed) $row['blockers'][] = 'the bank balance check could not run — try again';
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

    /**
     * Pure (tested): a drift (books minus bank) with one in-transit item taken out. A bank account's books already hold the
     * payment that the bank has not posted yet, so books sit LOWER by the amount (add it back); a card's books already hold
     * the charge, so the owed balance sits HIGHER (take it off).
     */
    public static function withoutInTransit(float $drift, float $amount, string $kind): float
    {
        $amount = abs($amount);
        return round($kind === 'card' ? $drift - $amount : $drift + $amount, 2);
    }

    /**
     * Receipts dated in one month that the bank posted in the first IN_TRANSIT_DAYS days of the next: matched receipt ↔ bank
     * line, expense dated in month M, line dated in M+1 and within the window. One row each; empty before the tables exist.
     * @return list<array{ym:string, code:string, name:string, amount:float, expense_id:int, vendor:string, expense_date:string, bank_date:string}>
     */
    public function inTransit(): array
    {
        try {
            // Strict on purpose (Codex review 2026-10-26): a purchase only (positive line), the SAME amount as the receipt, the
            // receipt's own live entry in its month credited to the SAME account the bank line is on, and the bank line's own
            // entry already retired — otherwise it is not "in transit", it is a difference that must still block the month.
            $rows = $this->db->query("
                SELECT e.id, e.expense_date, COALESCE(v.name, e.vendor_name_raw) AS vendor, t.transaction_date AS bank_date, t.amount,
                       a.code, a.name
                FROM expenses e
                JOIN accounting_transactions t ON t.matched_expense_id = e.id AND t.reference_type = 'bank_import' AND t.type = 'expense'
                JOIN chart_of_accounts a ON a.id = t.bank_account_id
                JOIN journal_entries je ON je.source_type = 'expense' AND je.source_id = e.id AND je.status = 'posted'
                     AND je.reversed_by_entry_id IS NULL AND DATE_FORMAT(je.entry_date, '%Y-%m') = DATE_FORMAT(e.expense_date, '%Y-%m')
                JOIN (SELECT entry_id, account_id, SUM(credit) - SUM(debit) AS net_credit FROM journal_lines GROUP BY entry_id, account_id) jl
                     ON jl.entry_id = je.id AND jl.account_id = t.bank_account_id
                LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE e.status IN ('approved', 'forwarded') AND e.expense_date >= '" . self::FIRST . "-01'
                  AND t.amount > 0 AND ABS(t.amount - e.total) < 0.005 AND ABS(jl.net_credit - t.amount) < 0.005
                  AND DATE(t.transaction_date) > LAST_DAY(e.expense_date)
                  AND DATE(t.transaction_date) <= DATE_ADD(LAST_DAY(e.expense_date), INTERVAL " . self::IN_TRANSIT_DAYS . " DAY)
                  AND NOT EXISTS (SELECT 1 FROM journal_entries bj WHERE bj.source_type LIKE 'bank%'
                                  AND bj.source_id = t.id AND bj.status = 'posted' AND bj.reversed_by_entry_id IS NULL)
                ORDER BY e.expense_date, e.id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $seen = [];
        foreach ($rows as $r) $seen[(int)$r['id']] = ($seen[(int)$r['id']] ?? 0) + 1;
        $out = [];
        foreach ($rows as $r) {
            if ($seen[(int)$r['id']] > 1) continue;   // one receipt on two lines is ambiguous: leave the difference showing
            $out[] = ['ym' => substr((string)$r['expense_date'], 0, 7), 'code' => (string)$r['code'], 'name' => (string)$r['name'],
                      'amount' => round(abs((float)$r['amount']), 2), 'expense_id' => (int)$r['id'], 'vendor' => (string)$r['vendor'],
                      'expense_date' => substr((string)$r['expense_date'], 0, 10), 'bank_date' => substr((string)$r['bank_date'], 0, 10)];
        }
        return $out;
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
        if (($m['receipts'] ?? 0) > 0) {
            $u = (int)($m['receipts_unmatched'] ?? 0);
            $b[] = $m['receipts'] . ' open receipt' . ($m['receipts'] === 1 ? '' : 's') . ' ($' . number_format((float)($m['receipts_amount'] ?? 0), 2) . ')'
                 . ($u > 0 ? ' — ' . $u . ' on no bank or card line: if you paid personally, choose "Paid personally (owner)" and approve' : '');
        }
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
