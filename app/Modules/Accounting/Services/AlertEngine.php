<?php
/**
 * AlertEngine — Financial intelligence alerts for the accounting module.
 *
 * Detects and persists actionable alerts:
 *  1. COST SPIKES     — category spend > 150% of 3-month rolling average
 *  2. LOW-MARGIN JOBS — completed jobs with margin below threshold
 *  3. REVENUE DROP    — this month vs last month > 20% decline
 *  4. UNCATEGORIZED   — transactions sitting on default fallback accounts
 *  5. UNUSUAL EXPENSE — single transaction > 3× category average
 *
 * Alerts are written to accounting_alerts and returned for the dashboard.
 * Users can dismiss individual alerts.
 */
declare(strict_types=1);

class AlertEngine
{
    private PDO $db;

    // Thresholds (can become configurable settings later)
    private const COST_SPIKE_MULTIPLIER   = 1.50;  // 150% of rolling average = spike
    private const LOW_MARGIN_THRESHOLD    = 20.0;  // jobs below 20% margin are flagged
    private const REVENUE_DROP_THRESHOLD  = 0.20;  // 20% month-over-month drop
    private const UNUSUAL_TX_MULTIPLIER   = 3.0;   // tx > 3× category average = unusual
    private const DEFAULT_EXPENSE_CODE    = '6900';
    private const DEFAULT_INCOME_CODE     = '4900';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // RUN ALL CHECKS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Run all alert checks and persist new alerts.
     * Returns array of active (non-dismissed) alerts.
     */
    public function runAll(): array
    {
        $this->detectCostSpikes();
        $this->detectRevenueDrop();
        $this->detectLowMarginJobs();
        $this->detectUnusualExpenses();

        return $this->getActiveAlerts();
    }

    /**
     * Get active (non-dismissed) alerts, plus counts of uncategorized + needs-review.
     */
    public function getDashboardSummary(): array
    {
        $alerts     = $this->getActiveAlerts();
        $uncat      = $this->countUncategorized();
        $review     = $this->countNeedsReview();
        $pending    = $this->countPendingTransactions();

        return [
            'alerts'          => $alerts,
            'alert_count'     => count($alerts),
            'critical_count'  => count(array_filter($alerts, fn($a) => $a['severity'] === 'critical')),
            'warning_count'   => count(array_filter($alerts, fn($a) => $a['severity'] === 'warning')),
            'uncategorized'   => $uncat,
            'needs_review'    => $review,
            'pending_tx'      => $pending,
        ];
    }

    public function getActiveAlerts(): array
    {
        return $this->db->query("
            SELECT * FROM accounting_alerts
            WHERE is_dismissed = 0
            ORDER BY
                CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END ASC,
                created_at DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function dismissAlert(int $id, int $userId): bool
    {
        return $this->db->prepare("
            UPDATE accounting_alerts
            SET is_dismissed = 1, dismissed_by = ?, dismissed_at = NOW()
            WHERE id = ?
        ")->execute([$userId, $id]);
    }

    public function dismissAll(int $userId): int
    {
        $stmt = $this->db->prepare(
            "UPDATE accounting_alerts SET is_dismissed = 1, dismissed_by = ?, dismissed_at = NOW() WHERE is_dismissed = 0"
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ALERT DETECTORS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * COST SPIKE: Compare this month's spend by account vs 3-month rolling average.
     * Alert if current > 150% of average.
     */
    public function detectCostSpikes(): void
    {
        $thisMonth  = date('Y-m-01');
        $today      = date('Y-m-d');
        $threeMonths = date('Y-m-01', strtotime('-3 months'));

        // 3-month avg by account (excluding current month)
        $avgStmt = $this->db->prepare("
            SELECT
                account_id,
                SUM(amount) / 3 AS monthly_avg
            FROM accounting_transactions
            WHERE type = 'expense'
              AND transaction_date >= ?
              AND transaction_date < ?
              AND status IN ('cleared', 'reconciled')
            GROUP BY account_id
            HAVING monthly_avg > 50
        ");
        $avgStmt->execute([$threeMonths, $thisMonth]);
        $averages = [];
        foreach ($avgStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $averages[(int)$row['account_id']] = (float)$row['monthly_avg'];
        }

        if (empty($averages)) return;

        // Current month spend by account
        $curStmt = $this->db->prepare("
            SELECT
                t.account_id,
                coa.name AS account_name,
                SUM(t.amount) AS current_spend
            FROM accounting_transactions t
            JOIN chart_of_accounts coa ON coa.id = t.account_id
            WHERE t.type = 'expense'
              AND t.transaction_date BETWEEN ? AND ?
              AND t.status IN ('cleared', 'reconciled')
            GROUP BY t.account_id, coa.name
        ");
        $curStmt->execute([$thisMonth, $today]);

        foreach ($curStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accountId = (int)$row['account_id'];
            $avg       = $averages[$accountId] ?? null;
            if (!$avg) continue;

            $current    = (float)$row['current_spend'];
            $multiplier = $current / $avg;

            if ($multiplier >= self::COST_SPIKE_MULTIPLIER) {
                $severity = $multiplier >= 2.0 ? 'critical' : 'warning';
                $pct      = round(($multiplier - 1) * 100);
                $this->upsertAlert(
                    'cost_spike',
                    $severity,
                    "Cost spike: {$row['account_name']}",
                    sprintf(
                        '%s spending is $%.2f this month — %d%% above the $%.2f 3-month average.',
                        $row['account_name'], $current, $pct, $avg
                    ),
                    'account',
                    $accountId
                );
            }
        }
    }

    /**
     * REVENUE DROP: Compare this month's revenue vs last month.
     * Alert if decline > 20%.
     */
    public function detectRevenueDrop(): void
    {
        $thisMonthStart = date('Y-m-01');
        $lastMonthStart = date('Y-m-01', strtotime('-1 month'));
        $lastMonthEnd   = date('Y-m-t', strtotime('-1 month'));
        $today          = date('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN transaction_date BETWEEN ? AND ? THEN amount ELSE 0 END) AS this_month,
                SUM(CASE WHEN transaction_date BETWEEN ? AND ? THEN amount ELSE 0 END) AS last_month
            FROM accounting_transactions
            WHERE type = 'income'
              AND status IN ('cleared', 'reconciled')
        ");
        $stmt->execute([$thisMonthStart, $today, $lastMonthStart, $lastMonthEnd]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $thisMonth = (float)$row['this_month'];
        $lastMonth = (float)$row['last_month'];

        if ($lastMonth <= 0) return;

        // Prorate this month (compare at same point in month)
        $dayOfMonth   = (int)date('j');
        $daysInLast   = (int)date('t', strtotime($lastMonthStart));
        $prorated     = $lastMonth * ($dayOfMonth / $daysInLast);

        $dropPct = ($prorated - $thisMonth) / $prorated;

        if ($dropPct >= self::REVENUE_DROP_THRESHOLD) {
            $severity = $dropPct >= 0.40 ? 'critical' : 'warning';
            $this->upsertAlert(
                'revenue_drop',
                $severity,
                'Revenue trending below last month',
                sprintf(
                    'Revenue is $%.2f so far this month vs $%.2f prorated last month (%.0f%% decline).',
                    $thisMonth, $prorated, $dropPct * 100
                ),
                null,
                null
            );
        } else {
            // Clear any previous revenue_drop alert if it's recovered
            $this->db->query("
                UPDATE accounting_alerts SET is_dismissed = 1
                WHERE alert_type = 'revenue_drop' AND is_dismissed = 0
            ");
        }
    }

    /**
     * LOW-MARGIN JOBS: Find recently completed jobs with profit margin below threshold.
     */
    public function detectLowMarginJobs(): void
    {
        $sinceDate = date('Y-m-01', strtotime('-3 months'));

        $stmt = $this->db->prepare("
            SELECT
                jp.id          AS job_id,
                jp.plan_number AS job_number,
                COALESCE(
                    NULLIF(p.property_name, ''),
                    NULLIF(co.company_name, ''),
                    NULLIF(CONCAT(c.first_name, ' ', c.last_name), ' ')
                ) AS client_name,
                SUM(CASE WHEN t.type = 'income'  THEN t.amount ELSE 0 END) AS revenue,
                SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) AS expenses
            FROM job_plans jp
            LEFT JOIN accounting_transactions t ON t.job_id = jp.id
              AND t.status IN ('cleared', 'reconciled')
            LEFT JOIN properties p ON p.id = jp.property_id
            LEFT JOIN companies  co ON co.id = jp.company_id
            LEFT JOIN contacts   c  ON c.id = jp.contact_id
            WHERE jp.status = 'completed'
              AND jp.updated_at >= ?
            GROUP BY jp.id, jp.plan_number, p.property_name, co.company_name, c.first_name, c.last_name
            HAVING revenue > 0
               AND (revenue - expenses) / revenue * 100 < ?
        ");
        $stmt->execute([$sinceDate, self::LOW_MARGIN_THRESHOLD]);
        $lowMarginJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lowMarginJobs as $job) {
            $profit = (float)$job['revenue'] - (float)$job['expenses'];
            $margin = round(($profit / (float)$job['revenue']) * 100, 1);
            $this->upsertAlert(
                'low_margin',
                'warning',
                "Low-profit job: {$job['job_number']}",
                sprintf(
                    'Job %s for %s generated $%.2f revenue with only %.1f%% profit margin.',
                    $job['job_number'],
                    $job['client_name'] ?? 'Unknown',
                    (float)$job['revenue'],
                    $margin
                ),
                'job',
                (int)$job['job_id']
            );
        }
    }

    /**
     * UNUSUAL EXPENSE: Single transaction > 3× the account's average transaction size.
     */
    public function detectUnusualExpenses(): void
    {
        $sinceDate = date('Y-m-01', strtotime('-1 month'));

        // Average transaction size by account (last 6 months)
        $avgStmt = $this->db->query("
            SELECT account_id, AVG(amount) AS avg_amount, COUNT(*) AS tx_count
            FROM accounting_transactions
            WHERE type = 'expense'
              AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              AND status IN ('cleared', 'reconciled')
            GROUP BY account_id
            HAVING tx_count >= 3
        ");
        $averages = [];
        foreach ($avgStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $averages[(int)$row['account_id']] = (float)$row['avg_amount'];
        }

        if (empty($averages)) return;

        // Recent large transactions
        $placeholders = implode(',', array_fill(0, count($averages), '?'));
        $params = array_keys($averages);
        $params[] = $sinceDate;

        $stmt = $this->db->prepare("
            SELECT t.id, t.amount, t.transaction_date, t.description, t.account_id,
                   coa.name AS account_name,
                   v.name   AS vendor_name
            FROM accounting_transactions t
            JOIN chart_of_accounts coa ON coa.id = t.account_id
            LEFT JOIN vendors v ON v.id = t.vendor_id
            WHERE t.type = 'expense'
              AND t.account_id IN ($placeholders)
              AND t.transaction_date >= ?
              AND t.status IN ('cleared', 'reconciled')
        ");
        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
            $avg = $averages[(int)$tx['account_id']] ?? null;
            if (!$avg) continue;

            if ((float)$tx['amount'] > $avg * self::UNUSUAL_TX_MULTIPLIER) {
                $label = $tx['vendor_name'] ?: substr($tx['description'] ?? '', 0, 40);
                $this->upsertAlert(
                    'unusual_expense',
                    'warning',
                    "Large expense: {$tx['account_name']}",
                    sprintf(
                        '$%.2f from "%s" on %s — %.1f× larger than this account\'s average of $%.2f.',
                        (float)$tx['amount'],
                        $label,
                        $tx['transaction_date'],
                        (float)$tx['amount'] / $avg,
                        $avg
                    ),
                    'transaction',
                    (int)$tx['id']
                );
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // BUDGET VS ACTUAL
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Compare actual spending vs budgets from expense_budgets table.
     * Returns rows with status: ok | warning | critical | over_budget.
     */
    public function getBudgetVsActual(string $month): array
    {
        [$year, $monthNum] = explode('-', $month);
        $monthStart = "$year-$monthNum-01";
        $monthEnd   = date('Y-m-t', strtotime($monthStart));

        // Try to use expense_budgets if it exists
        try {
            $stmt = $this->db->prepare("
                SELECT
                    eb.category,
                    eb.monthly_budget,
                    eb.warning_threshold,
                    eb.critical_threshold,
                    coa.id          AS account_id,
                    coa.name        AS account_name,
                    coa.code        AS account_code,
                    COALESCE(SUM(t.amount), 0) AS actual_spend
                FROM expense_budgets eb
                LEFT JOIN chart_of_accounts coa
                    ON coa.expense_category_alias = eb.category AND coa.is_active = 1
                LEFT JOIN accounting_transactions t
                    ON t.account_id = coa.id
                    AND t.type = 'expense'
                    AND t.transaction_date BETWEEN ? AND ?
                    AND t.status IN ('cleared', 'reconciled')
                WHERE eb.monthly_budget > 0
                GROUP BY eb.id, eb.category, eb.monthly_budget, eb.warning_threshold,
                         eb.critical_threshold, coa.id, coa.name, coa.code
                ORDER BY coa.display_order
            ");
            $stmt->execute([$monthStart, $monthEnd]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return []; // expense_budgets table may not exist
        }

        return array_map(function ($r) {
            $budget  = (float)$r['monthly_budget'];
            $actual  = (float)$r['actual_spend'];
            $pct     = $budget > 0 ? round(($actual / $budget) * 100, 1) : 0;
            $warning = (float)($r['warning_threshold']  ?? 80);
            $crit    = (float)($r['critical_threshold'] ?? 100);
            $remain  = max(0, $budget - $actual);

            $status = 'ok';
            if ($pct >= $crit)     $status = 'over_budget';
            elseif ($pct >= $warning) $status = 'critical';
            elseif ($pct >= $warning * 0.9) $status = 'warning';

            return array_merge($r, [
                'budget'    => $budget,
                'actual'    => $actual,
                'remaining' => $remain,
                'pct_used'  => $pct,
                'status'    => $status,
            ]);
        }, $rows);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CREW PROFITABILITY
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Revenue and direct costs attributable to each crew member.
     * Revenue: transactions WHERE assigned_user_id = user
     * Labour cost: from labour_cost_snapshots if available, else estimate hourly_rate * hours
     */
    public function getCrewProfitability(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare("
            SELECT
                u.id,
                u.full_name,
                u.hourly_rate,
                SUM(CASE WHEN t.type = 'income'  THEN t.amount ELSE 0 END) AS revenue,
                SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) AS direct_expenses
            FROM users u
            LEFT JOIN accounting_transactions t
                ON t.assigned_user_id = u.id
                AND t.transaction_date BETWEEN ? AND ?
                AND t.status IN ('cleared', 'reconciled')
            WHERE u.role != 'admin'
              AND u.is_active = 1
            GROUP BY u.id, u.full_name, u.hourly_rate
            HAVING revenue > 0 OR direct_expenses > 0
            ORDER BY revenue DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Try to get labour costs from labour_cost_snapshots
        $labourByUser = $this->getLabourCosts($dateFrom, $dateTo);

        return array_map(function ($r) use ($labourByUser) {
            $userId       = (int)$r['id'];
            $revenue      = (float)$r['revenue'];
            $directCost   = (float)$r['direct_expenses'];
            $labourCost   = $labourByUser[$userId] ?? 0.0;
            $totalCost    = $directCost + $labourCost;
            $profit       = $revenue - $totalCost;
            $margin       = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0;

            return [
                'user_id'       => $userId,
                'name'          => $r['full_name'],
                'revenue'       => $revenue,
                'labour_cost'   => $labourCost,
                'direct_cost'   => $directCost,
                'total_cost'    => $totalCost,
                'profit'        => $profit,
                'margin'        => $margin,
            ];
        }, $rows);
    }

    private function getLabourCosts(string $dateFrom, string $dateTo): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT user_id, SUM(total_cost) AS labour_cost
                FROM labour_cost_snapshots
                WHERE snapshot_date BETWEEN ? AND ?
                GROUP BY user_id
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int)$row['user_id']] = (float)$row['labour_cost'];
            }
            return $map;
        } catch (PDOException $e) {
            return []; // table may not exist
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Insert or update an alert. Uses (alert_type + reference_id) as upsert key.
     * Re-opens a previously dismissed alert if the condition is still active.
     */
    private function upsertAlert(
        string $alertType,
        string $severity,
        string $title,
        string $body,
        ?string $refType,
        ?int $refId
    ): void {
        // Check if an active alert already exists for this condition
        $check = $this->db->prepare("
            SELECT id FROM accounting_alerts
            WHERE alert_type = ? AND reference_type <=> ? AND reference_id <=> ? AND is_dismissed = 0
            LIMIT 1
        ");
        $check->execute([$alertType, $refType, $refId]);
        $existing = $check->fetchColumn();

        if ($existing) {
            // Update the existing alert
            $this->db->prepare("
                UPDATE accounting_alerts SET severity = ?, title = ?, body = ?, created_at = NOW()
                WHERE id = ?
            ")->execute([$severity, $title, $body, $existing]);
        } else {
            $this->db->prepare("
                INSERT INTO accounting_alerts (alert_type, severity, title, body, reference_type, reference_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$alertType, $severity, $title, $body, $refType, $refId]);
        }
    }

    private function countUncategorized(): int
    {
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) FROM accounting_transactions t
                JOIN chart_of_accounts coa ON coa.id = t.account_id
                WHERE coa.code IN ('" . self::DEFAULT_EXPENSE_CODE . "', '" . self::DEFAULT_INCOME_CODE . "')
                  AND t.is_auto_categorized = 0
                  AND t.reference_type != 'manual'
            ");
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    private function countNeedsReview(): int
    {
        try {
            return (int)$this->db->query(
                "SELECT COUNT(*) FROM accounting_transactions WHERE needs_review = 1"
            )->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    private function countPendingTransactions(): int
    {
        try {
            return (int)$this->db->query(
                "SELECT COUNT(*) FROM accounting_transactions WHERE status = 'pending'"
            )->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }
}
