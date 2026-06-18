<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * Overhead settings + plan/stop profitability
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 * Global functions — names/signatures unchanged from the original monolith.
 */

// ============================================================================
// PROFITABILITY CALCULATIONS
// ============================================================================

/**
 * Get the configured overhead percentage from overhead_settings.
 * Returns the percentage value (e.g. 20 for 20%), defaults to 20 if not set.
 */
function getOverheadPercentage(): float {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT setting_value FROM overhead_settings WHERE setting_key = 'overhead_percent'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false ? (float)$val : 20.0;
    } catch (\Exception $e) {
        return 20.0;
    }
}

/**
 * Get overhead settings as an associative array.
 * Returns all key-value pairs from overhead_settings with sensible defaults.
 */
function getOverheadSettings(): array {
    $defaults = [
        'overhead_percent' => 20,
        'overhead_apply_mode' => 0,       // 0=percentage, 1=per_hour
        'estimated_billable_hours' => 160,
        'estimated_monthly_revenue' => 18000,
        'estimated_jobs_per_month' => 40,
        'profit_margin' => 35,
    ];

    $db = getDB();
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM overhead_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_merge($defaults, $rows);
    } catch (\Exception $e) {
        return $defaults;
    }
}

/**
 * Calculate the total monthly overhead from overhead_items.
 * Normalizes weekly/quarterly/annual items to monthly amounts.
 */
function getMonthlyOverheadTotal(): float {
    $db = getDB();
    $freqMultipliers = [
        'weekly'    => 52 / 12,   // ~4.33
        'monthly'   => 1,
        'quarterly' => 1 / 3,
        'annual'    => 1 / 12,
    ];

    try {
        $stmt = $db->query("SELECT amount, frequency FROM overhead_items WHERE is_active = 1");
        $total = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $mult = $freqMultipliers[$row['frequency']] ?? 1;
            $total += (float)$row['amount'] * $mult;
        }
        return $total;
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * Full profitability breakdown for a single plan (job view page).
 *
 * Revenue:  Sum of actual_amount from completed visits (falls back to price_per_visit)
 * Labor:    Sum of job_time_entries duration × crew hourly rate, or estimated from plan duration
 * Expenses: Sum of expenses linked to the plan's property
 * Overhead: Applied via overhead_settings (% mode or $/hr mode)
 *
 * @return array {revenue, labor_cost, labor_minutes, labor_estimated, expense_cost,
 *               overhead_cost, overhead_mode, total_cost, profit, margin_pct,
 *               completed_visits, has_data}
 */
function getPlanProfitability(int $planId): array {
    $db = getDB();
    $empty = [
        'revenue' => 0, 'labor_cost' => 0, 'labor_minutes' => 0,
        'labor_estimated' => true, 'expense_cost' => 0, 'overhead_cost' => 0,
        'overhead_mode' => 0, 'total_cost' => 0, 'profit' => 0,
        'margin_pct' => 0, 'completed_visits' => 0, 'has_data' => false,
    ];

    // 1. Revenue + plan info
    $revStmt = $db->prepare("
        SELECT
            jp.price_per_visit,
            jp.property_id,
            jp.estimated_duration_minutes,
            jp.default_crew_id,
            COUNT(*) AS completed_count,
            SUM(COALESCE(jv.actual_amount, jp.price_per_visit)) AS total_revenue
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE jv.plan_id = ? AND jv.status = 'completed'
        GROUP BY jp.id
    ");
    $revStmt->execute([$planId]);
    $rev = $revStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rev || (int)$rev['completed_count'] === 0) {
        return $empty;
    }

    $revenue = (float)$rev['total_revenue'];
    $completedCount = (int)$rev['completed_count'];
    $propertyId = (int)$rev['property_id'];
    $estimatedDuration = (int)$rev['estimated_duration_minutes'];
    $defaultCrewId = $rev['default_crew_id'] ? (int)$rev['default_crew_id'] : null;

    // 2. Labor cost from actual time entries
    $laborStmt = $db->prepare("
        SELECT
            COALESCE(SUM(jte.duration_minutes), 0) AS total_labor_minutes,
            COALESCE(SUM(jte.duration_minutes * (COALESCE(u.hourly_rate, 25) / 60)), 0) AS total_labor_cost
        FROM job_time_entries jte
        JOIN users u ON jte.user_id = u.id
        JOIN job_visits jv ON jte.visit_id = jv.id
        WHERE jv.plan_id = ?
          AND jte.status IN ('completed', 'edited')
    ");
    $laborStmt->execute([$planId]);
    $labor = $laborStmt->fetch(PDO::FETCH_ASSOC);

    $laborMinutes = (float)($labor['total_labor_minutes'] ?? 0);
    $laborCost = (float)($labor['total_labor_cost'] ?? 0);
    $laborEstimated = false;

    // If no actual time entries, estimate from plan duration × crew rate
    if ($laborMinutes <= 0 && $estimatedDuration > 0) {
        $laborEstimated = true;
        $crewRate = 25.0;
        if ($defaultCrewId) {
            $rateStmt = $db->prepare("SELECT COALESCE(hourly_rate, 25) FROM users WHERE id = ?");
            $rateStmt->execute([$defaultCrewId]);
            $crewRate = (float)$rateStmt->fetchColumn() ?: 25.0;
        }
        $laborMinutes = $estimatedDuration * $completedCount;
        $laborCost = ($laborMinutes / 60) * $crewRate;
    }

    // 3. Expenses linked to property
    $expCost = 0;
    if ($propertyId) {
        $expStmt = $db->prepare("
            SELECT COALESCE(SUM(total), 0) AS total_expenses
            FROM expenses
            WHERE property_id = ?
              AND status IN ('draft', 'approved', 'forwarded')
        ");
        $expStmt->execute([$propertyId]);
        $expCost = (float)$expStmt->fetchColumn();
    }

    // 4. Overhead
    $ohSettings = getOverheadSettings();
    $overheadMode = (int)$ohSettings['overhead_apply_mode'];
    $overheadCost = 0;

    if ($overheadMode === 1) {
        // $/hr mode: monthly overhead / billable hours * actual labor hours
        $monthlyOverhead = getMonthlyOverheadTotal();
        $billableHours = max(1, (float)$ohSettings['estimated_billable_hours']);
        $perHourRate = $monthlyOverhead / $billableHours;
        $overheadCost = $perHourRate * ($laborMinutes / 60);
    } else {
        // % mode: overhead as percentage of direct costs (labor + expenses)
        $ohPct = (float)$ohSettings['overhead_percent'];
        $overheadCost = ($laborCost + $expCost) * ($ohPct / 100);
    }

    // 5. Totals
    $totalCost = $laborCost + $expCost + $overheadCost;
    $profit = $revenue - $totalCost;
    $marginPct = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

    return [
        'revenue'          => round($revenue, 2),
        'labor_cost'       => round($laborCost, 2),
        'labor_minutes'    => round($laborMinutes, 0),
        'labor_estimated'  => $laborEstimated,
        'expense_cost'     => round($expCost, 2),
        'overhead_cost'    => round($overheadCost, 2),
        'overhead_mode'    => $overheadMode,
        'total_cost'       => round($totalCost, 2),
        'profit'           => round($profit, 2),
        'margin_pct'       => round($marginPct, 1),
        'completed_visits' => $completedCount,
        'has_data'         => true,
    ];
}

/**
 * Lightweight batch profitability estimates for schedule cards.
 * One query for all plan IDs — returns simplified margin percentages.
 *
 * @param int[] $planIds
 * @return array planId => ['margin_pct' => int|null, 'has_data' => bool]
 */
function getStopProfitabilityBatch(array $planIds): array {
    if (empty($planIds)) return [];
    $db = getDB();

    $placeholders = implode(',', array_fill(0, count($planIds), '?'));

    $stmt = $db->prepare("
        SELECT
            jp.id AS plan_id,
            jp.price_per_visit,
            jp.estimated_duration_minutes,
            COALESCE(u.hourly_rate, 25) AS crew_rate,
            (SELECT COUNT(*) FROM job_visits jv
             WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS completed_count,
            (SELECT COALESCE(SUM(jv2.actual_amount), 0)
             FROM job_visits jv2
             WHERE jv2.plan_id = jp.id AND jv2.status = 'completed') AS actual_revenue,
            (SELECT COALESCE(SUM(jte.duration_minutes), 0)
             FROM job_time_entries jte
             JOIN job_visits jv3 ON jte.visit_id = jv3.id
             WHERE jv3.plan_id = jp.id
               AND jte.status IN ('completed', 'edited')) AS actual_labor_minutes
        FROM job_plans jp
        LEFT JOIN users u ON jp.default_crew_id = u.id
        WHERE jp.id IN ({$placeholders})
    ");
    $stmt->execute($planIds);

    $overheadPct = getOverheadPercentage();
    $results = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['plan_id'];
        $price = (float)$row['price_per_visit'];
        $completedCount = (int)$row['completed_count'];

        if ($completedCount === 0) {
            $results[$pid] = ['margin_pct' => null, 'has_data' => false];
            continue;
        }

        $crewRate = (float)$row['crew_rate'];
        $actualRevenue = (float)$row['actual_revenue'];

        // Revenue: use actual if available, else price × completed
        $revenue = $actualRevenue > 0 ? $actualRevenue : ($price * $completedCount);
        if ($revenue <= 0) {
            $results[$pid] = ['margin_pct' => null, 'has_data' => false];
            continue;
        }

        // Labor: actual time entries, or estimated from plan duration
        $laborMinutes = (float)$row['actual_labor_minutes'];
        if ($laborMinutes <= 0) {
            $laborMinutes = (float)$row['estimated_duration_minutes'] * $completedCount;
        }
        $laborCost = ($laborMinutes / 60) * $crewRate;
        $overhead = $laborCost * ($overheadPct / 100);
        $totalCost = $laborCost + $overhead;
        $margin = (($revenue - $totalCost) / $revenue) * 100;

        $results[$pid] = [
            'margin_pct' => (int)round($margin),
            'has_data' => true,
        ];
    }

    return $results;
}
