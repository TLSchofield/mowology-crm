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
               COALESCE(v.name, e.vendor_name_raw) AS vendor_name,
               ma.stored_filename AS receipt_filename,
               ma.archived_at, ma.thumb_path, ma.original_removed
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN media_assets ma ON ma.id = e.receipt_media_id
        WHERE {$whereClause}
        ORDER BY e.expense_date DESC, e.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build direct receipt image URLs for iOS display. Once a receipt is archived
    // (ReceiptArchiveService), the full-res original is deleted from web disk — serve
    // the retained thumbnail instead so the URL doesn't 404.
    foreach ($rows as &$row) {
        if (!empty($row['original_removed']) && !empty($row['thumb_path'])) {
            $row['receipt_url'] = 'https://mowology.ca' . $row['thumb_path'];
        } else {
            $row['receipt_url'] = !empty($row['receipt_filename'])
                ? 'https://mowology.ca/uploads/receipts/' . $row['receipt_filename']
                : null;
        }
        unset($row['receipt_filename'], $row['thumb_path'], $row['original_removed']);

        // PDO returns DECIMAL columns as PHP strings regardless of prepare mode, so
        // these would otherwise serialize as JSON strings ("45.67"). iOS decodes
        // `total` as a non-optional Double — a string value fails the whole array
        // decode, silently landing the app on its empty state with no error shown.
        $row['amount'] = $row['amount'] !== null ? (float)$row['amount'] : null;
        $row['gst_amount'] = $row['gst_amount'] !== null ? (float)$row['gst_amount'] : null;
        $row['total'] = (float)$row['total'];
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
