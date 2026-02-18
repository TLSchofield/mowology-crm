<?php
/**
 * /app/Services/Receipts/MarginTracker.php
 * Expense-to-Quote Margin Tracking
 *
 * Compares actual expense spending linked to a job against the
 * original quote's material estimates for margin analysis.
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/MarginTracker.php';
 *   $margin = getJobMargin($jobPlanId);
 *   // Returns: ['quoted' => 500.00, 'actual' => 420.00, 'margin' => 80.00, 'margin_pct' => 16.0]
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Calculate material margin for a job plan by comparing quoted vs actual expenses.
 *
 * Traces: job_plans.id → job_plans.quote_id → quotes → quote_line_items (materials)
 *         job_plans.id → expenses (linked via job_id)
 *
 * @param int      $planId Job plan ID
 * @param PDO|null $db
 * @return array|null Margin data or null if no quote found
 */
function getJobMargin(int $planId, ?PDO $db = null): ?array
{
    if ($db === null) $db = getDB();

    try {
        // Get the quote associated with this plan
        $stmt = $db->prepare("
            SELECT jp.id, jp.quote_id, jp.property_id, jp.title,
                   q.quote_number, q.total AS quote_total
            FROM job_plans jp
            LEFT JOIN quotes q ON jp.quote_id = q.id
            WHERE jp.id = ?
        ");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) return null;

        // Get quoted material amounts from quote line items
        $quotedMaterials = 0;
        $quotedTotal = 0;

        if ($plan['quote_id']) {
            $stmt = $db->prepare("
                SELECT description, quantity, unit_price, total, service_type
                FROM quote_line_items
                WHERE quote_id = ?
            ");
            $stmt->execute([$plan['quote_id']]);
            $quoteItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($quoteItems as $item) {
                $itemTotal = (float)($item['total'] ?? 0);
                $quotedTotal += $itemTotal;

                // Consider items with material-related service types
                $serviceType = strtolower($item['service_type'] ?? '');
                $desc = strtolower($item['description'] ?? '');

                $isMaterial = (
                    stripos($serviceType, 'material') !== false ||
                    stripos($desc, 'material') !== false ||
                    stripos($desc, 'mulch') !== false ||
                    stripos($desc, 'soil') !== false ||
                    stripos($desc, 'gravel') !== false ||
                    stripos($desc, 'plant') !== false ||
                    stripos($desc, 'seed') !== false ||
                    stripos($desc, 'sod') !== false ||
                    stripos($desc, 'supply') !== false ||
                    stripos($desc, 'supplies') !== false
                );

                if ($isMaterial) {
                    $quotedMaterials += $itemTotal;
                }
            }
        }

        // Get actual expenses linked to this job
        $stmt = $db->prepare("
            SELECT SUM(total) AS actual_total,
                   SUM(CASE WHEN accounting_category = 'Materials' THEN total ELSE 0 END) AS actual_materials,
                   SUM(CASE WHEN accounting_category = 'Fuel' THEN total ELSE 0 END) AS actual_fuel,
                   SUM(CASE WHEN accounting_category = 'Disposal/Dump' THEN total ELSE 0 END) AS actual_disposal,
                   SUM(CASE WHEN accounting_category = 'Tools/Equipment' THEN total ELSE 0 END) AS actual_tools,
                   COUNT(*) AS expense_count
            FROM expenses
            WHERE job_id = ?
              AND status != 'rejected'
        ");
        $stmt->execute([$planId]);
        $actual = $stmt->fetch(PDO::FETCH_ASSOC);

        $actualTotal = (float)($actual['actual_total'] ?? 0);
        $actualMaterials = (float)($actual['actual_materials'] ?? 0);
        $expenseCount = (int)($actual['expense_count'] ?? 0);

        // Calculate margins
        $materialMargin = $quotedMaterials - $actualMaterials;
        $materialMarginPct = $quotedMaterials > 0
            ? round(($materialMargin / $quotedMaterials) * 100, 1)
            : null;

        $totalMargin = (float)($plan['quote_total'] ?? 0) - $actualTotal;
        $totalMarginPct = (float)($plan['quote_total'] ?? 0) > 0
            ? round(($totalMargin / (float)$plan['quote_total']) * 100, 1)
            : null;

        return [
            'plan_id'              => $planId,
            'plan_title'           => $plan['title'] ?? '',
            'quote_number'         => $plan['quote_number'] ?? null,
            'quote_total'          => (float)($plan['quote_total'] ?? 0),
            'quoted_materials'     => $quotedMaterials,
            'actual_total'         => $actualTotal,
            'actual_materials'     => $actualMaterials,
            'actual_fuel'          => (float)($actual['actual_fuel'] ?? 0),
            'actual_disposal'      => (float)($actual['actual_disposal'] ?? 0),
            'actual_tools'         => (float)($actual['actual_tools'] ?? 0),
            'expense_count'        => $expenseCount,
            'material_margin'      => $materialMargin,
            'material_margin_pct'  => $materialMarginPct,
            'total_margin'         => $totalMargin,
            'total_margin_pct'     => $totalMarginPct,
            'status'               => $materialMarginPct !== null && $materialMarginPct < 0 ? 'over_budget' : 'on_track',
        ];
    } catch (\Throwable $e) {
        error_log('getJobMargin error: ' . $e->getMessage());
        return null;
    }
}


/**
 * Get margin summary across all active jobs with expenses.
 *
 * @param PDO|null $db
 * @return array Summary statistics
 */
function getMarginSummary(?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    try {
        // Get all plans that have both a quote and expenses
        $stmt = $db->query("
            SELECT DISTINCT jp.id
            FROM job_plans jp
            JOIN quotes q ON jp.quote_id = q.id
            JOIN expenses e ON e.job_id = jp.id
            WHERE jp.is_active = 1
              AND e.status != 'rejected'
            LIMIT 50
        ");
        $planIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $margins = [];
        $totalQuoted = 0;
        $totalActual = 0;
        $overBudget = 0;

        foreach ($planIds as $planId) {
            $margin = getJobMargin((int)$planId, $db);
            if ($margin) {
                $margins[] = $margin;
                $totalQuoted += $margin['quoted_materials'];
                $totalActual += $margin['actual_materials'];
                if ($margin['status'] === 'over_budget') $overBudget++;
            }
        }

        return [
            'jobs_tracked'     => count($margins),
            'total_quoted'     => $totalQuoted,
            'total_actual'     => $totalActual,
            'total_margin'     => $totalQuoted - $totalActual,
            'total_margin_pct' => $totalQuoted > 0 ? round((($totalQuoted - $totalActual) / $totalQuoted) * 100, 1) : null,
            'over_budget_jobs' => $overBudget,
            'details'          => $margins,
        ];
    } catch (\Throwable $e) {
        error_log('getMarginSummary error: ' . $e->getMessage());
        return ['jobs_tracked' => 0, 'details' => []];
    }
}
