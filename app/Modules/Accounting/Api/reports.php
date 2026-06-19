<?php
/**
 * Accounting Reports API
 *
 * GET ?action=pl&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
 * GET ?action=cashflow&date_from=&date_to=
 * GET ?action=tax&year=2026&quarter=1
 * GET ?action=tax_year&year=2026
 * GET ?action=job_profitability&date_from=&date_to=&limit=
 * GET ?action=monthly_trend&months=12
 * GET ?action=expense_breakdown&date_from=&date_to=
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    requirePermission('expenses.view');

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Accounting/Services/AccountingService.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/TaxEngine.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/RulesEngine.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/ReportingService.php';

    $svc       = new AccountingService($db);
    $tax       = new TaxEngine($db);
    $reporting = new ReportingService($db);

    $action    = $_GET['action'] ?? '';
    $thisMonth = date('Y-m-01');
    $today     = date('Y-m-d');
    $dateFrom  = $_GET['date_from'] ?? $thisMonth;
    $dateTo    = $_GET['date_to']   ?? $today;

    switch ($action) {

        // ── Profit & Loss ─────────────────────────────────────────────────────
        case 'pl':
            $data = $svc->getProfitLoss($dateFrom, $dateTo);
            echo json_encode(['ok' => true, 'report' => $data]);
            break;

        // ── Double-entry statements (Phase 1) ─────────────────────────────────
        case 'trial_balance':
            echo json_encode(['ok' => true, 'report' => $reporting->getTrialBalance($dateTo)]);
            break;

        case 'balance_sheet':
            echo json_encode(['ok' => true, 'report' => $reporting->getBalanceSheet($dateTo)]);
            break;

        case 'income_statement':
            echo json_encode(['ok' => true, 'report' => $reporting->getIncomeStatement($dateFrom, $dateTo)]);
            break;

        // ── GGOB cost drill-down (group_by: cost_type | service_type | job) ────
        case 'cost_drilldown':
            $groupBy = in_array($_GET['group_by'] ?? '', ['cost_type', 'service_type', 'job'], true)
                ? $_GET['group_by'] : 'cost_type';
            echo json_encode(['ok' => true, 'group_by' => $groupBy,
                'report' => $reporting->getCostDrillDown($dateFrom, $dateTo, $groupBy)]);
            break;

        // ── Cash Flow (simplified) ────────────────────────────────────────────
        case 'cashflow':
            $data = $svc->getCashFlow($dateFrom, $dateTo);
            echo json_encode(['ok' => true, 'report' => $data]);
            break;

        // ── GST/HST Tax Filing Report ─────────────────────────────────────────
        case 'tax':
            $year    = (int)($_GET['year']    ?? date('Y'));
            $quarter = (int)($_GET['quarter'] ?? $tax->currentQuarter());
            $data    = $tax->getGstFilingReport($year, $quarter);
            echo json_encode(['ok' => true, 'report' => $data]);
            break;

        // ── Full-year GST summary (all 4 quarters) ────────────────────────────
        case 'tax_year':
            $year = (int)($_GET['year'] ?? date('Y'));
            $data = $tax->getYearlyGstSummary($year);
            echo json_encode(['ok' => true, 'quarters' => $data]);
            break;

        // ── Job Profitability ─────────────────────────────────────────────────
        case 'job_profitability':
            $limit = min(100, max(5, (int)($_GET['limit'] ?? 25)));
            $data  = $svc->getJobProfitability($dateFrom, $dateTo, $limit);
            echo json_encode(['ok' => true, 'jobs' => $data]);
            break;

        // ── Monthly Trend (bar chart data) ────────────────────────────────────
        case 'monthly_trend':
            $months = min(36, max(3, (int)($_GET['months'] ?? 12)));
            $data   = $svc->getMonthlyTrend($months);
            echo json_encode(['ok' => true, 'trend' => $data]);
            break;

        // ── Expense Breakdown by category ─────────────────────────────────────
        case 'expense_breakdown':
            $stmt = $db->prepare("
                SELECT
                    coa.code,
                    coa.name                 AS category,
                    COUNT(t.id)              AS count,
                    SUM(t.amount)            AS total,
                    SUM(t.gst_amount)        AS gst_total,
                    AVG(t.amount)            AS avg_amount
                FROM accounting_transactions t
                JOIN chart_of_accounts coa ON coa.id = t.account_id
                WHERE t.type = 'expense'
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.status IN ('cleared', 'reconciled')
                GROUP BY coa.id, coa.code, coa.name
                ORDER BY total DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalExp = array_sum(array_column($data, 'total'));
            $data = array_map(function ($r) use ($totalExp) {
                $r['pct'] = $totalExp > 0 ? round(((float)$r['total'] / $totalExp) * 100, 1) : 0;
                return $r;
            }, $data);

            echo json_encode(['ok' => true, 'breakdown' => $data, 'total' => $totalExp]);
            break;

        // ── Accountant Export (clean CSV-ready data) ──────────────────────────
        case 'accountant_export':
            $stmt = $db->prepare("
                SELECT
                    t.transaction_date,
                    t.type,
                    coa.code   AS account_code,
                    coa.name   AS account_name,
                    t.amount,
                    t.gst_amount,
                    t.pst_amount,
                    t.description,
                    t.reference_type,
                    t.reference_id,
                    t.status,
                    t.needs_review,
                    v.name     AS vendor_name,
                    jp.plan_number AS job_number,
                    CONCAT(c.first_name, ' ', c.last_name) AS client_name
                FROM accounting_transactions t
                JOIN chart_of_accounts coa ON coa.id = t.account_id
                LEFT JOIN vendors   v  ON v.id  = t.vendor_id
                LEFT JOIN job_plans jp ON jp.id = t.job_id
                LEFT JOIN contacts  c  ON c.id  = t.contact_id
                WHERE t.transaction_date BETWEEN ? AND ?
                ORDER BY t.transaction_date ASC, t.type ASC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Summary totals
            $revenue  = array_sum(array_map(fn($r) => $r['type'] === 'income'  ? (float)$r['amount'] : 0, $rows));
            $expenses = array_sum(array_map(fn($r) => $r['type'] === 'expense' ? (float)$r['amount'] : 0, $rows));

            echo json_encode([
                'ok'           => true,
                'date_from'    => $dateFrom,
                'date_to'      => $dateTo,
                'transactions' => $rows,
                'totals'       => [
                    'revenue'    => $revenue,
                    'expenses'   => $expenses,
                    'net_profit' => $revenue - $expenses,
                    'count'      => count($rows),
                ],
            ]);
            break;

        default:
            throw new Exception("Unknown report action: $action", 400);
    }

} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
