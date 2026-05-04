<?php
/**
 * Expense List API — JWT-authenticated
 *
 * GET /api/expenses/expense-list?page=1[&per_page=25]
 * Authorization: Bearer <jwt>
 *
 * Returns a paginated list of expenses for the authenticated user.
 * Admins and managers see all expenses; regular users see only their own.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'GET required']);
        exit;
    }

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $isAdmin = in_array($jwtUser['role'] ?? '', ['admin', 'manager'], true);

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $db = getDB();

    // Admins/managers see all expenses; crew see only their own
    if ($isAdmin) {
        $whereClause = '1=1';
        $params      = [];
    } else {
        $whereClause = 'e.created_by = ?';
        $params      = [$userId];
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM expenses e WHERE {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT e.id, e.expense_date, e.vendor_name_raw, e.description,
               e.amount, e.gst_amount, e.total,
               e.accounting_category, e.payment_method,
               e.status, e.forwarded_to_accounting,
               e.receipt_media_id, e.job_id, e.anomaly_score, e.created_at,
               v.name AS vendor_name
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        WHERE {$whereClause}
        ORDER BY e.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id']                      = (int)$row['id'];
        $row['amount']                  = $row['amount'] !== null ? (float)$row['amount'] : null;
        $row['gst_amount']              = $row['gst_amount'] !== null ? (float)$row['gst_amount'] : null;
        $row['total']                   = (float)$row['total'];
        $row['receipt_media_id']        = $row['receipt_media_id'] !== null ? (int)$row['receipt_media_id'] : null;
        $row['forwarded_to_accounting'] = (int)($row['forwarded_to_accounting'] ?? 0);
        $row['anomaly_score']           = $row['anomaly_score'] !== null ? (int)$row['anomaly_score'] : null;
        $row['job_id']                  = $row['job_id'] !== null ? (int)$row['job_id'] : null;
        $row['receipt_url']             = null; // iOS fetches images via receipt-image endpoint
    }
    unset($row);

    echo json_encode([
        'success'  => true,
        'expenses' => $rows,
        'total'    => $total,
        'page'     => $page,
        'pages'    => (int)ceil($total / $perPage),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
