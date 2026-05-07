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

// Production has serialize_precision set high, so (float)"39.90" emits as
// "39.89999999999999857..." (50+ digits). Force the shortest round-trippable
// representation so JSON numbers stay clean: 39.9 instead of 39.8999...
ini_set('serialize_precision', '-1');

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

    // Cast DECIMAL → float so iOS's `Double` can decode (PDO native prepares
    // return DECIMAL as string), build the signed receipt URL (direct
    // /uploads/receipts paths are .htaccess-blocked), and clamp absurd
    // expense_date years (OCR sometimes emits "2090-..." from a "10/90" on a
    // shoulder of a receipt). Use the first 10 chars of created_at as the
    // fallback so the row stays sortable and displayable.
    $thisYear  = (int)date('Y');
    $minYear   = 2020;
    $maxYear   = $thisYear + 1;
    foreach ($rows as &$row) {
        $row['amount']     = $row['amount']     === null ? null : (float)$row['amount'];
        $row['gst_amount'] = $row['gst_amount'] === null ? null : (float)$row['gst_amount'];
        $row['total']      = (float)($row['total'] ?? 0);

        // Clamp impossible expense_date years. We don't mutate the DB here;
        // expense-save validates new entries.
        $d = (string)($row['expense_date'] ?? '');
        if (preg_match('/^(\d{4})-/', $d, $m)) {
            $y = (int)$m[1];
            if ($y < $minYear || $y > $maxYear) {
                $fallback = substr((string)($row['created_at'] ?? ''), 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback)) {
                    $row['expense_date'] = $fallback;
                }
            }
        }

        $row['receipt_url'] = !empty($row['receipt_media_id'])
            ? signReceiptUrl((int)$row['receipt_media_id'], 3600, 'https://mowology.ca')
            : null;
    }
    unset($row);

    // JSON_INVALID_UTF8_SUBSTITUTE prevents json_encode from returning false
    // when a vendor name or description contains stray bytes from OCR — that
    // would emit an empty body and surface as "data couldn't be read" on iOS.
    $json = json_encode([
        'success'  => true,
        'expenses' => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($json === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to encode response: ' . json_last_error_msg()]);
    } else {
        echo $json;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
