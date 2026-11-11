<?php
declare(strict_types=1);

/**
 * app/Modules/Reports/Api/data.php
 *
 * Mobile Reports API — JWT authenticated, admin only
 *
 * GET /api/reports/data?report=<type>&start=YYYY-MM-DD&end=YYYY-MM-DD
 *
 * Supported report types (match ReportType in ReportModels.swift):
 *   revenue-by-month     — bar chart: invoiced vs collected per month
 *   revenue-by-service   — donut chart: revenue by service type
 *   quote-funnel         — horizontal bar: requests → quotes → accepted → jobs
 *   crew-profitability   — bar chart: revenue + margin per crew member
 *   overdue-invoices     — list view: outstanding invoices
 *
 * Responses match the Decodable structs in ReportModels.swift.
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

$jwtUser = requireJwt();

if (($jwtUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$report = $_GET['report'] ?? '';
$start  = $_GET['start']  ?? date('Y-01-01');
$end    = $_GET['end']     ?? date('Y-12-31');

$db = getDB();

try {
    switch ($report) {

        // ── Revenue by Month ──────────────────────────────────────────────────
        case 'revenue-by-month':
            $stmt = $db->prepare("
                SELECT DATE_FORMAT(i.issue_date, '%Y-%m')  AS month_key,
                       DATE_FORMAT(i.issue_date, '%b %Y')  AS month_label,
                       COUNT(*)                             AS invoice_count,
                       COALESCE(SUM(i.total), 0)            AS total_revenue,
                       COALESCE(SUM(i.amount_paid), 0)      AS total_collected,
                       COALESCE(SUM(i.balance_due), 0)      AS total_outstanding
                FROM invoices i
                WHERE i.status NOT IN ('draft', 'cancelled')
                  AND i.issue_date BETWEEN ? AND ?
                GROUP BY month_key, month_label
                ORDER BY month_key ASC
            ");
            $stmt->execute([$start, $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'labels'  => array_column($rows, 'month_label'),
                'series'  => [
                    ['label' => 'Invoiced',  'data' => array_map('floatval', array_column($rows, 'total_revenue'))],
                    ['label' => 'Collected', 'data' => array_map('floatval', array_column($rows, 'total_collected'))],
                ],
                'summary' => [
                    'total_invoiced'    => (float)array_sum(array_column($rows, 'total_revenue')),
                    'total_collected'   => (float)array_sum(array_column($rows, 'total_collected')),
                    'total_outstanding' => (float)array_sum(array_column($rows, 'total_outstanding')),
                ],
            ]);
            break;

        // ── Revenue by Service ────────────────────────────────────────────────
        case 'revenue-by-service':
            $stmt = $db->prepare("
                SELECT COALESCE(vms.service_type, 'other') AS service_type,
                       COUNT(*)                             AS visit_count,
                       COALESCE(SUM(vms.quoted_amount), 0)  AS revenue
                FROM visit_margin_snapshots vms
                WHERE vms.visit_date BETWEEN ? AND ?
                GROUP BY vms.service_type
                ORDER BY revenue DESC
            ");
            $stmt->execute([$start, $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $labels = array_map(function ($r) {
                return ucwords(str_replace('_', ' ', $r['service_type']));
            }, $rows);

            echo json_encode([
                'success' => true,
                'labels'  => $labels,
                'series'  => [
                    ['label' => 'Revenue', 'data' => array_map('floatval', array_column($rows, 'revenue'))],
                ],
                'summary' => [
                    'total_revenue' => (float)array_sum(array_column($rows, 'revenue')),
                    'total_visits'  => (int)array_sum(array_column($rows, 'visit_count')),
                ],
            ]);
            break;

        // ── Quote Funnel ──────────────────────────────────────────────────────
        case 'quote-funnel':
            $reqStmt = $db->prepare("SELECT COUNT(*) FROM quote_requests WHERE created_at BETWEEN ? AND ?");
            $reqStmt->execute([$start, $end . ' 23:59:59']);
            $requestCount = (int)$reqStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT status, COUNT(*) AS cnt
                FROM quotes
                WHERE created_at BETWEEN ? AND ?
                GROUP BY status
            ");
            $stmt->execute([$start, $end . ' 23:59:59']);
            $statusMap = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $statusMap[$r['status']] = (int)$r['cnt'];
            }

            $totalQuotes = array_sum($statusMap);
            $sent        = ($statusMap['sent'] ?? 0) + ($statusMap['accepted'] ?? 0) + ($statusMap['declined'] ?? 0);
            $accepted    = $statusMap['accepted'] ?? 0;

            $planStmt = $db->prepare(
                "SELECT COUNT(*) FROM job_plans WHERE quote_id IS NOT NULL AND created_at BETWEEN ? AND ?"
            );
            $planStmt->execute([$start, $end . ' 23:59:59']);
            $jobCount = (int)$planStmt->fetchColumn();

            $convRate = $totalQuotes > 0 ? round($accepted / $totalQuotes * 100, 1) : 0;

            echo json_encode([
                'success' => true,
                'labels'  => ['Requests', 'Quotes Created', 'Quotes Sent', 'Accepted', 'Jobs Created'],
                'series'  => [
                    ['label' => 'Count', 'data' => [$requestCount, $totalQuotes, $sent, $accepted, $jobCount]],
                ],
                'summary' => [
                    'conversion_rate' => $convRate,
                    'total_quotes'    => $totalQuotes,
                    'accepted'        => $accepted,
                ],
            ]);
            break;

        // ── Crew Profitability ────────────────────────────────────────────────
        case 'crew-profitability':
            $stmt = $db->prepare("
                SELECT COALESCE(u.full_name, CONCAT(u.first_name,' ',u.last_name), 'Unassigned') AS crew_name,
                       COUNT(*)                              AS visit_count,
                       COALESCE(SUM(vms.quoted_amount), 0)   AS revenue,
                       COALESCE(SUM(vms.gross_margin), 0)    AS gross_margin,
                       AVG(vms.margin_pct)                   AS avg_margin_pct
                FROM visit_margin_snapshots vms
                JOIN job_visits jv ON jv.id = vms.visit_id
                LEFT JOIN users u ON u.id = jv.assigned_crew_id
                WHERE vms.visit_date BETWEEN ? AND ?
                GROUP BY u.id, crew_name
                ORDER BY gross_margin DESC
            ");
            $stmt->execute([$start, $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'labels'  => array_column($rows, 'crew_name'),
                'series'  => [
                    ['label' => 'Revenue', 'data' => array_map('floatval', array_column($rows, 'revenue'))],
                    ['label' => 'Margin',  'data' => array_map('floatval', array_column($rows, 'gross_margin'))],
                ],
                'summary' => [
                    'total_revenue' => (float)array_sum(array_column($rows, 'revenue')),
                    'total_margin'  => (float)array_sum(array_column($rows, 'gross_margin')),
                ],
            ]);
            break;

        // ── Overdue Invoices ──────────────────────────────────────────────────
        case 'overdue-invoices':
            $stmt = $db->query("
                SELECT i.id, i.invoice_number, i.issue_date, i.due_date,
                       i.total, i.amount_paid, i.balance_due,
                       DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
                       COALESCE(CONCAT(ct.first_name,' ',ct.last_name), c.company_name, 'N/A') AS client_name,
                       ct.email, ct.phone
                FROM invoices i
                LEFT JOIN contacts ct ON i.contact_id = ct.id
                LEFT JOIN companies c ON i.company_id = c.id
                WHERE i.balance_due > 0
                  AND i.due_date < CURDATE()
                  AND i.status NOT IN ('draft','cancelled','paid')
                ORDER BY i.due_date ASC
            ");
            $rows         = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalOverdue = (float)array_sum(array_column($rows, 'balance_due'));

            echo json_encode([
                'success' => true,
                'labels'  => [],
                'series'  => [],
                'rows'    => $rows,
                'summary' => [
                    'count'         => count($rows),
                    'total_overdue' => $totalOverdue,
                    'avg_days'      => count($rows) > 0
                        ? (int)round(array_sum(array_column($rows, 'days_overdue')) / count($rows))
                        : 0,
                ],
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown report type: ' . $report]);
    }
} catch (Throwable $e) {
    error_log('[reports/data] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Report query failed']);
}
