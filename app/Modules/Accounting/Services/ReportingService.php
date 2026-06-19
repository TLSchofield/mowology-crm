<?php
/**
 * ReportingService — financial statements from the double-entry journal (Phase 1).
 *
 * Reads journal_lines (joined to chart_of_accounts + journal_entries) and
 * produces tie-out statements:
 *   - getTrialBalance(asOf)        : every account's debit/credit; ΣDR == ΣCR
 *   - getBalanceSheet(asOf)        : assets == liabilities + equity + net income
 *   - getIncomeStatement(from,to)  : revenue − expenses
 *   - getCostDrillDown(from,to,by) : GGOB pivot of expenses by cost_type / service_type / job
 *
 * The pure build*() shapers contain the accounting logic and are unit-tested
 * with canned rows; the get*() methods are the thin SQL wrappers.
 */
declare(strict_types=1);

class ReportingService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PURE SHAPERS (no DB) — the accounting logic
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Signed balance for an account: positive on its normal side.
     * debit-normal  → debit − credit ; credit-normal → credit − debit.
     */
    public static function signedBalance(string $normalBalance, float $debit, float $credit): float
    {
        return $normalBalance === 'credit'
            ? round($credit - $debit, 2)
            : round($debit - $credit, 2);
    }

    /**
     * @param array $rows each ['code','name','type','normal_balance','debit','credit']
     */
    public function buildTrialBalance(array $rows): array
    {
        $accounts = [];
        $totalDebit = 0.0; $totalCredit = 0.0;
        foreach ($rows as $r) {
            $debit  = round((float)$r['debit'], 2);
            $credit = round((float)$r['credit'], 2);
            $net = round($debit - $credit, 2);
            $accounts[] = [
                'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'],
                'debit'  => $net > 0 ? $net : 0.0,
                'credit' => $net < 0 ? -$net : 0.0,
            ];
            $totalDebit  += $net > 0 ? $net : 0.0;
            $totalCredit += $net < 0 ? -$net : 0.0;
        }
        return [
            'accounts'     => $accounts,
            'total_debit'  => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'in_balance'   => abs($totalDebit - $totalCredit) < 0.005,
        ];
    }

    /**
     * @param array $rows each ['code','name','type','normal_balance','debit','credit']
     */
    public function buildBalanceSheet(array $rows): array
    {
        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $totals   = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
        $netIncome = 0.0;

        foreach ($rows as $r) {
            $bal = self::signedBalance($r['normal_balance'], (float)$r['debit'], (float)$r['credit']);
            $type = $r['type'];
            if (isset($sections[$type])) {
                $sections[$type][] = ['code' => $r['code'], 'name' => $r['name'], 'balance' => $bal];
                $totals[$type] += $bal;
            } elseif ($type === 'revenue') {
                $netIncome += $bal;            // revenue normal-credit → positive
            } elseif ($type === 'expense') {
                $netIncome -= $bal;            // expense normal-debit → reduces income
            }
        }
        $netIncome = round($netIncome, 2);

        // Current-year earnings flow into equity.
        $equityWithEarnings = round($totals['equity'] + $netIncome, 2);
        $liabPlusEquity = round($totals['liability'] + $equityWithEarnings, 2);

        return [
            'assets'              => $sections['asset'],
            'liabilities'         => $sections['liability'],
            'equity'              => $sections['equity'],
            'total_assets'        => round($totals['asset'], 2),
            'total_liabilities'   => round($totals['liability'], 2),
            'total_equity'        => round($totals['equity'], 2),
            'net_income'          => $netIncome,
            'equity_with_earnings'=> $equityWithEarnings,
            'liabilities_plus_equity' => $liabPlusEquity,
            'balances'            => abs($totals['asset'] - $liabPlusEquity) < 0.005,
        ];
    }

    /**
     * @param array $rows each ['code','name','type','normal_balance','debit','credit']
     */
    public function buildIncomeStatement(array $rows): array
    {
        $revenue = []; $expenses = [];
        $totalRev = 0.0; $totalExp = 0.0;
        foreach ($rows as $r) {
            $bal = self::signedBalance($r['normal_balance'], (float)$r['debit'], (float)$r['credit']);
            if ($r['type'] === 'revenue') {
                $revenue[] = ['code' => $r['code'], 'name' => $r['name'], 'amount' => $bal];
                $totalRev += $bal;
            } elseif ($r['type'] === 'expense') {
                $expenses[] = ['code' => $r['code'], 'name' => $r['name'], 'amount' => $bal];
                $totalExp += $bal;
            }
        }
        return [
            'revenue'        => $revenue,
            'expenses'       => $expenses,
            'total_revenue'  => round($totalRev, 2),
            'total_expenses' => round($totalExp, 2),
            'net_income'     => round($totalRev - $totalExp, 2),
        ];
    }

    /**
     * @param array $rows each ['key','amount'] already grouped by the chosen dimension
     */
    public function buildCostDrillDown(array $rows): array
    {
        $groups = []; $total = 0.0;
        foreach ($rows as $r) {
            $amt = round((float)$r['amount'], 2);
            $groups[] = ['key' => $r['key'] ?? '(unassigned)', 'amount' => $amt];
            $total += $amt;
        }
        return ['groups' => $groups, 'total' => round($total, 2)];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DB WRAPPERS
    // ══════════════════════════════════════════════════════════════════════════

    /** Per-account debit/credit totals up to $asOf (inclusive). */
    private function accountTotals(?string $asOf = null, ?string $from = null): array
    {
        $where = ["je.status = 'posted'"];
        $params = [];
        if ($from !== null) { $where[] = 'je.entry_date >= ?'; $params[] = $from; }
        if ($asOf !== null) { $where[] = 'je.entry_date <= ?'; $params[] = $asOf; }
        $sql = "
            SELECT coa.code, coa.name, coa.type, coa.normal_balance,
                   COALESCE(SUM(jl.debit), 0)  AS debit,
                   COALESCE(SUM(jl.credit), 0) AS credit
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.entry_id
            JOIN chart_of_accounts coa ON coa.id = jl.account_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY coa.id, coa.code, coa.name, coa.type, coa.normal_balance, coa.display_order
            ORDER BY coa.display_order, coa.code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTrialBalance(?string $asOf = null): array
    {
        return $this->buildTrialBalance($this->accountTotals($asOf));
    }

    public function getBalanceSheet(?string $asOf = null): array
    {
        return $this->buildBalanceSheet($this->accountTotals($asOf));
    }

    public function getIncomeStatement(string $from, string $to): array
    {
        return $this->buildIncomeStatement($this->accountTotals($to, $from));
    }

    /**
     * GGOB cost drill-down: expense lines grouped by a dimension.
     * @param string $groupBy 'cost_type' | 'service_type' | 'job'
     */
    public function getCostDrillDown(string $from, string $to, string $groupBy = 'cost_type'): array
    {
        switch ($groupBy) {
            case 'service_type':
                $keyExpr = 'jl.service_type'; $join = '';
                break;
            case 'job':
                $keyExpr = 'jl.job_id'; $join = '';
                break;
            case 'cost_type':
            default:
                $keyExpr = 'ct.name'; $join = 'LEFT JOIN cost_types ct ON ct.id = jl.cost_type_id';
                break;
        }
        $sql = "
            SELECT $keyExpr AS `key`,
                   COALESCE(SUM(jl.debit - jl.credit), 0) AS amount
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.entry_id
            JOIN chart_of_accounts coa ON coa.id = jl.account_id
            $join
            WHERE je.status = 'posted'
              AND coa.type = 'expense'
              AND je.entry_date BETWEEN ? AND ?
            GROUP BY `key`
            ORDER BY amount DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$from, $to]);
        return $this->buildCostDrillDown($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
