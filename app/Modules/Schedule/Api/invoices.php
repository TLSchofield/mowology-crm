<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/invoices.php
 *
 * Mobile Invoices API — JWT authenticated, admin/manager only.
 *
 * GET /api/schedule/invoices
 *   ?status=draft|sent|paid|overdue|all  (default: all)
 *   ?limit=20  (max 100)
 *   ?offset=0
 *
 * Response 200:
 * {
 *   "success": true,
 *   "total": 8,
 *   "invoices": [
 *     {
 *       "invoice_id": 1,
 *       "invoice_number": "INV-2026-0001",
 *       "client_name": "Bob Jones",
 *       "status": "sent",
 *       "total_amount": 150.00,
 *       "amount_paid": 0.00,
 *       "due_date": "2026-05-15",
 *       "created_at": "2026-05-01"
 *     }
 *   ]
 * }
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
$isAdmin = jwtIsAdmin($jwtUser['role']);

if (!$isAdmin) {
    echo json_encode(['success' => true, 'total' => 0, 'invoices' => []]);
    exit;
}

$validStatuses = ['draft', 'sent', 'paid', 'overdue', 'cancelled', 'all'];
$statusFilter  = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'all';

$limit  = min(100, max(1, (int)($_GET['limit']  ?? 20)));
$offset = max(0,        (int)($_GET['offset'] ?? 0));

try {
    $db = getDB();

    $where  = [];
    $params = [];

    if ($statusFilter !== 'all') {
        $where[]  = 'i.status = ?';
        $params[] = $statusFilter;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM invoices i {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare("
        SELECT
            i.id             AS invoice_id,
            i.invoice_number,
            i.status,
            i.total_amount,
            i.amount_paid,
            i.due_date,
            DATE(i.created_at) AS created_at,
            COALESCE(co.full_name, co.first_name, 'Unknown') AS client_name
        FROM invoices i
        LEFT JOIN contacts co ON i.contact_id = co.id
        {$whereSQL}
        ORDER BY i.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $invoices = array_map(static function (array $r): array {
        $total = (float)($r['total_amount'] ?? 0);
        $paid  = (float)($r['amount_paid']  ?? 0);
        return [
            'invoice_id'     => (int)$r['invoice_id'],
            'invoice_number' => $r['invoice_number'],
            'client_name'    => $r['client_name'],
            'status'         => $r['status'],
            'total_amount'   => $total,
            'amount_paid'    => $paid,
            'balance_owing'  => round($total - $paid, 2),
            'due_date'       => $r['due_date'],
            'created_at'     => $r['created_at'],
        ];
    }, $rows);

    echo json_encode(['success' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'invoices' => $invoices]);

} catch (Throwable $e) {
    error_log('[schedule/invoices] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load invoices.']);
}
