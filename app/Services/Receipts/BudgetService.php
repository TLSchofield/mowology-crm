<?php
/**
 * /app/Services/Receipts/BudgetService.php
 * Expense Budget Variance Tracking
 *
 * Compares actual spending against monthly budget targets per category.
 * Fires warnings at configurable thresholds (default 80% warning, 100% critical).
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/BudgetService.php';
 *   $status = checkBudgetVariance('Materials', '2026-03');
 *   // Returns: ['budget' => 500.00, 'spent' => 420.00, 'pct' => 84, 'status' => 'warning', 'remaining' => 80.00]
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Check budget variance for a single category in a specific month.
 *
 * @param string $category Accounting category (e.g., 'Materials', 'Fuel')
 * @param string $month    Month string 'YYYY-MM' (e.g., '2026-03')
 * @param PDO|null $db     Database connection (auto-fetches if null)
 * @return array Budget status or null if no budget set
 */
function checkBudgetVariance(string $category, string $month, ?PDO $db = null): ?array
{
    if ($db === null) $db = getDB();

    $budgetMonth = $month . '-01';

    try {
        // Get budget for this category + month
        $stmt = $db->prepare("
            SELECT budget_amount, warning_threshold, critical_threshold
            FROM expense_budgets
            WHERE accounting_category = ? AND budget_month = ?
        ");
        $stmt->execute([$category, $budgetMonth]);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$budget) return null;

        $budgetAmt = (float)$budget['budget_amount'];
        if ($budgetAmt <= 0) return null;

        // Calculate actual spending for this category in this month
        $monthStart = $budgetMonth;
        $monthEnd = date('Y-m-t', strtotime($budgetMonth));

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total), 0) as spent, COUNT(*) as count
            FROM expenses
            WHERE accounting_category = ?
              AND expense_date BETWEEN ? AND ?
              AND status != 'rejected'
        ");
        $stmt->execute([$category, $monthStart, $monthEnd]);
        $actual = $stmt->fetch(PDO::FETCH_ASSOC);

        $spent = (float)$actual['spent'];
        $count = (int)$actual['count'];
        $pct = ($budgetAmt > 0) ? round(($spent / $budgetAmt) * 100, 1) : 0;
        $remaining = max(0, $budgetAmt - $spent);

        $warnThreshold = (int)$budget['warning_threshold'];
        $critThreshold = (int)$budget['critical_threshold'];

        $status = 'ok';
        if ($pct >= $critThreshold) {
            $status = 'critical';
        } elseif ($pct >= $warnThreshold) {
            $status = 'warning';
        }

        return [
            'category'           => $category,
            'month'              => $month,
            'budget'             => $budgetAmt,
            'spent'              => $spent,
            'expense_count'      => $count,
            'pct'                => $pct,
            'remaining'          => $remaining,
            'status'             => $status,
            'warning_threshold'  => $warnThreshold,
            'critical_threshold' => $critThreshold,
        ];
    } catch (\Throwable $e) {
        error_log('checkBudgetVariance error: ' . $e->getMessage());
        return null;
    }
}


/**
 * Get budget status for ALL categories in a month.
 *
 * @param string   $month Month string 'YYYY-MM'
 * @param PDO|null $db
 * @return array Array of budget status objects
 */
function getAllBudgetVariances(string $month, ?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    $budgetMonth = $month . '-01';

    try {
        $stmt = $db->prepare("
            SELECT accounting_category FROM expense_budgets
            WHERE budget_month = ? AND budget_amount > 0
            ORDER BY accounting_category
        ");
        $stmt->execute([$budgetMonth]);
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        foreach ($categories as $cat) {
            $result = checkBudgetVariance($cat, $month, $db);
            if ($result) {
                $results[] = $result;
            }
        }

        return $results;
    } catch (\Throwable $e) {
        error_log('getAllBudgetVariances error: ' . $e->getMessage());
        return [];
    }
}


/**
 * Check if saving this expense would push any category over threshold.
 *
 * @param array    $expense The expense being saved
 * @param PDO|null $db
 * @return array|null Warning if over threshold, null if ok
 */
function checkBudgetOnSave(array $expense, ?PDO $db = null): ?array
{
    $category = $expense['accounting_category'] ?? '';
    $total = (float)($expense['total'] ?? 0);
    $date = $expense['expense_date'] ?? date('Y-m-d');

    if (empty($category) || $total <= 0) return null;

    $month = substr($date, 0, 7); // YYYY-MM
    $current = checkBudgetVariance($category, $month, $db);

    if (!$current) return null;

    // Calculate what the new percentage would be
    $newSpent = $current['spent'] + $total;
    $newPct = ($current['budget'] > 0) ? round(($newSpent / $current['budget']) * 100, 1) : 0;

    // Only warn if this expense pushes it over a threshold
    if ($current['status'] === 'ok' && $newPct >= $current['warning_threshold']) {
        return [
            'level'    => 'warning',
            'message'  => sprintf(
                'This expense will push %s spending to %.0f%% of budget ($%.2f / $%.2f)',
                $category, $newPct, $newSpent, $current['budget']
            ),
            'category' => $category,
            'new_pct'  => $newPct,
        ];
    }

    if ($current['status'] !== 'critical' && $newPct >= $current['critical_threshold']) {
        return [
            'level'    => 'critical',
            'message'  => sprintf(
                'This expense will exceed the %s monthly budget ($%.2f / $%.2f = %.0f%%)',
                $category, $newSpent, $current['budget'], $newPct
            ),
            'category' => $category,
            'new_pct'  => $newPct,
        ];
    }

    return null;
}
