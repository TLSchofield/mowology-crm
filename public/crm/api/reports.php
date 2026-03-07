<?php
/**
 * Reports Data API
 * Returns JSON data for Chart.js reports.
 *
 * GET ?report=revenue-by-month&start=YYYY-MM-DD&end=YYYY-MM-DD
 * GET ?report=revenue-by-service&start=...&end=...
 * GET ?report=quote-funnel&start=...&end=...
 * GET ?report=crew-profitability&start=...&end=...
 * GET ?report=client-lifetime-value&limit=20
 * GET ?report=overdue-invoices
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once PUBLIC_ROOT . '/crm/includes/functions.php';
requireLogin();
requirePermission('expenses.view');
session_write_close();

header('Content-Type: application/json');

$report = $_GET['report'] ?? '';
$start  = $_GET['start'] ?? date('Y-01-01');
$end    = $_GET['end']   ?? date('Y-12-31');
$limit  = max(1, min(100, (int)($_GET['limit'] ?? 20)));

$db = getDB();

try {
    switch ($report) {

        // ── Revenue by Month ──────────────────────────────────────────
        case 'revenue-by-month':
            $stmt = $db->prepare("
                SELECT DATE_FORMAT(i.issue_date, '%Y-%m') AS month_key,
                       DATE_FORMAT(i.issue_date, '%b %Y') AS month_label,
                       COUNT(*)                            AS invoice_count,
                       COALESCE(SUM(i.total), 0)           AS total_revenue,
                       COALESCE(SUM(i.amount_paid), 0)     AS total_collected,
                       COALESCE(SUM(i.balance_due), 0)     AS total_outstanding
                FROM invoices i
                WHERE i.status NOT IN ('draft', 'cancelled')
                  AND i.issue_date BETWEEN ? AND ?
                GROUP BY month_key, month_label
                ORDER BY month_key ASC
            ");
            $stmt->execute([$start, $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $labels   = array_column($rows, 'month_label');
            $revenue  = array_map('floatval', array_column($rows, 'total_revenue'));
            $collected = array_map('floatval', array_column($rows, 'total_collected'));

            echo json_encode([
                'success' => true,
                'labels'  => $labels,
                'datasets' => [
                    ['label' => 'Invoiced', 'data' => $revenue, 'backgroundColor' => 'rgba(45,134,89,0.7)'],
                    ['label' => 'Collected', 'data' => $collected, 'backgroundColor' => 'rgba(127,216,88,0.7)'],
                ],
                'table' => $rows,
                'summary' => [
                    'total_invoiced'    => array_sum($revenue),
                    'total_collected'   => array_sum($collected),
                    'total_outstanding' => array_sum(array_map('floatval', array_column($rows, 'total_outstanding'))),
                ],
            ]);
            break;

        // ── Revenue by Service ────────────────────────────────────────
        case 'revenue-by-service':
            $stmt = $db->prepare("
                SELECT COALESCE(vms.service_type, 'other') AS service_type,
                       COUNT(*)                             AS visit_count,
                       COALESCE(SUM(vms.quoted_amount), 0)  AS revenue,
                       COALESCE(SUM(vms.gross_margin), 0)   AS margin,
                       AVG(vms.margin_pct)                  AS avg_margin_pct
                FROM visit_margin_snapshots vms
                WHERE vms.visit_date BETWEEN ? AND ?
                GROUP BY vms.service_type
                ORDER BY revenue DESC
            ");
            $stmt->execute([$start, $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $labels  = array_map(function($r) {
                return ucwords(str_replace('_', ' ', $r['service_type']));
            }, $rows);
            $data = array_map('floatval', array_column($rows, 'revenue'));

            $colors = ['#2D8659','#7FD858','#e85d04','#3B82F6','#8B5CF6','#EC4899','#14B8A6','#F59E0B','#6366F1','#EF4444'];

            echo json_encode([
                'success'  => true,
                'labels'   => $labels,
                'datasets' => [
                    ['label' => 'Revenue', 'data' => $data, 'backgroundColor' => array_slice($colors, 0, count($data))],
                ],
                'table' => $rows,
            ]);
            break;

        // ── Quote Funnel ──────────────────────────────────────────────
        case 'quote-funnel':
            // Quote requests count
            $reqStmt = $db->prepare("SELECT COUNT(*) FROM quote_requests WHERE created_at BETWEEN ? AND ?");
            $reqStmt->execute([$start, $end . ' 23:59:59']);
            $requestCount = (int)$reqStmt->fetchColumn();

            // Quote counts by status
            $stmt = $db->prepare("
                SELECT status, COUNT(*) AS cnt,
                       COALESCE(SUM(total_amount), 0) AS total_value
                FROM quotes
                WHERE created_at BETWEEN ? AND ?
                GROUP BY status
            ");
            $stmt->execute([$start, $end . ' 23:59:59']);
            $statusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $statusMap = [];
            foreach ($statusRows as $r) {
                $statusMap[$r['status']] = ['count' => (int)$r['cnt'], 'value' => (float)$r['total_value']];
            }

            $totalQuotes = array_sum(array_column($statusRows, 'cnt'));
            $sent     = ($statusMap['sent']['count'] ?? 0) + ($statusMap['accepted']['count'] ?? 0) + ($statusMap['declined']['count'] ?? 0);
            $accepted = $statusMap['accepted']['count'] ?? 0;

            // Plans created from quotes in period
            $planStmt = $db->prepare("SELECT COUNT(*) FROM job_plans WHERE quote_id IS NOT NULL AND created_at BETWEEN ? AND ?");
            $planStmt->execute([$start, $end . ' 23:59:59']);
            $jobCount = (int)$planStmt->fetchColumn();

            $funnel = [
                ['stage' => 'Requests',  'count' => $requestCount],
                ['stage' => 'Quotes Created', 'count' => $totalQuotes],
                ['stage' => 'Quotes Sent',    'count' => $sent],
                ['stage' => 'Accepted',       'count' => $accepted],
                ['stage' => 'Jobs Created',   'count' => $jobCount],
            ];

            echo json_encode([
                'success' => true,
                'labels'  => array_column($funnel, 'stage'),
                'datasets' => [
                    ['label' => 'Count', 'data' => array_column($funnel, 'count'), 'backgroundColor' => ['#3B82F6','#2D8659','#7FD858','#e85d04','#14B8A6']],
                ],
                'table' => $funnel,
                'conversion' => $totalQuotes > 0 ? round($accepted / $totalQuotes * 100, 1) : 0,
            ]);
            break;

        // ── Crew Profitability ────────────────────────────────────────
        case 'crew-profitability':
            $stmt = $db->prepare("
                SELECT COALESCE(u.full_name, CONCAT(u.first_name,' ',u.last_name), 'Unassigned') AS crew_name,
                       u.id AS crew_id,
                       COUNT(*)                             AS visit_count,
                       COALESCE(SUM(vms.quoted_amount), 0)  AS revenue,
                       COALESCE(SUM(vms.labor_cost), 0)     AS labor_cost,
                       COALESCE(SUM(vms.material_cost), 0)  AS material_cost,
                       COALESCE(SUM(vms.drive_cost), 0)     AS drive_cost,
                       COALESCE(SUM(vms.gross_margin), 0)   AS gross_margin,
                       AVG(vms.margin_pct)                  AS avg_margin_pct
                FROM visit_margin_snapshots vms
                JOIN job_visits jv ON jv.id = vms.visit_id
                LEFT JOIN users u ON u.id = jv.assigned_crew_id
                WHERE vms.visit_date BETWEEN ? AND ?
                GROUP BY u.id, crew_name
                ORDER BY gross_margin DESC
            ");
            $stmt->execute([$start, $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $labels = array_column($rows, 'crew_name');
            $margins = array_map('floatval', array_column($rows, 'gross_margin'));
            $revenues = array_map('floatval', array_column($rows, 'revenue'));

            echo json_encode([
                'success'  => true,
                'labels'   => $labels,
                'datasets' => [
                    ['label' => 'Revenue', 'data' => $revenues, 'backgroundColor' => 'rgba(45,134,89,0.7)'],
                    ['label' => 'Margin', 'data' => $margins, 'backgroundColor' => 'rgba(127,216,88,0.7)'],
                ],
                'table' => $rows,
            ]);
            break;

        // ── Client Lifetime Value ─────────────────────────────────────
        case 'client-lifetime-value':
            $stmt = $db->prepare("
                SELECT c.id, CONCAT(c.first_name, ' ', c.last_name) AS client_name,
                       c.email, c.phone,
                       COUNT(DISTINCT i.id) AS invoice_count,
                       COALESCE(SUM(i.total), 0) AS total_invoiced,
                       COALESCE(SUM(i.amount_paid), 0) AS total_paid,
                       MIN(i.issue_date) AS first_invoice,
                       MAX(i.issue_date) AS last_invoice
                FROM contacts c
                JOIN invoices i ON i.contact_id = c.id AND i.status NOT IN ('draft','cancelled')
                GROUP BY c.id, client_name, c.email, c.phone
                ORDER BY total_paid DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $labels = array_column($rows, 'client_name');
            $values = array_map('floatval', array_column($rows, 'total_paid'));

            echo json_encode([
                'success'  => true,
                'labels'   => $labels,
                'datasets' => [
                    ['label' => 'Lifetime Revenue', 'data' => $values, 'backgroundColor' => 'rgba(45,134,89,0.7)'],
                ],
                'table' => $rows,
            ]);
            break;

        // ── Overdue Invoices ──────────────────────────────────────────
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
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalOverdue = array_sum(array_map('floatval', array_column($rows, 'balance_due')));

            echo json_encode([
                'success' => true,
                'table'   => $rows,
                'summary' => [
                    'count'         => count($rows),
                    'total_overdue' => $totalOverdue,
                    'avg_days'      => count($rows) > 0 ? round(array_sum(array_column($rows, 'days_overdue')) / count($rows)) : 0,
                ],
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown report: ' . $report]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Report query failed']);
}
