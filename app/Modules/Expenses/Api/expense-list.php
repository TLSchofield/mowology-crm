<?php
/**
 * iOS Expense List API — JWT-authenticated
 *
 * GET ?page=1&per_page=25&status=draft&date_from=YYYY-MM-DD
 *
 * Returns the authenticated user's expenses (field crew see their own;
 * admin/manager see all).
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
    require_once APP_ROOT . '/Services/Receipts/ReceiptUrlSigner.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $isAdmin = jwtIsAdmin($jwtUser['role']);

    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $where  = ['1=1'];
    $params = [];

    // Crew members only see their own expenses
    if (!$isAdmin) {
        $where[]  = 'e.created_by = ?';
        $params[] = $userId;
    }

    if (!empty($_GET['status'])) {
        $where[]  = 'e.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['date_from'])) {
        $where[]  = 'e.expense_date >= ?';
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where[]  = 'e.expense_date <= ?';
        $params[] = $_GET['date_to'];
    }
    if (!empty($_GET['job_id'])) {
        $where[]  = 'e.job_id = ?';
        $params[] = (int)$_GET['job_id'];
    }

    $whereClause = implode(' AND ', $where);

    $db = getDB();

    $countStmt = $db->prepare("SELECT COUNT(*) FROM expenses e WHERE {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listStmt = $db->prepare("
        SELECT e.id, e.expense_date, e.vendor_name_raw, e.description,
               e.amount, e.gst_amount, e.total, e.accounting_category,
               e.payment_method, e.status, e.forwarded_to_accounting,
               e.receipt_media_id, e.job_id, e.anomaly_score, e.created_at,
               COALESCE(v.name, e.vendor_name_raw) AS vendor_name
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        WHERE {$whereClause}
        ORDER BY e.expense_date DESC, e.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build signed receipt image URLs — direct uploads/ paths are blocked
    // by .htaccess. The signed URL lets AsyncImage fetch the image without
    // needing to attach a JWT header (signature in the URL is enough).
    foreach ($rows as &$row) {
        $row['receipt_url'] = !empty($row['receipt_media_id'])
            ? signReceiptUrl((int)$row['receipt_media_id'], 3600, 'https://mowology.ca')
            : null;
    }
    unset($row);

    echo json_encode([
        'success'  => true,
        'expenses' => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
