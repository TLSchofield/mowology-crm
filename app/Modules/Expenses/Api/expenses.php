<?php
/**
 * Expenses API — CRUD for expense records
 *
 * GET:  ?action=list[&page=1&per_page=25&category=...&vendor_id=...&date_from=...&date_to=...&status=...]
 * GET:  ?action=get&id=X
 * POST: {action: 'create', ...fields}
 * POST: {action: 'update', id, ...fields}
 * POST: {action: 'delete', id}
 * POST: {action: 'suggest', ocr_text, lat, lng, job_id}  — smart categorization
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('expenses.view');

    $canEdit = userHasPermission('expenses.edit');
    $canSend = userHasPermission('expenses.send');

    $db = getDB();

    // Determine action
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }

    switch ($action) {
        case 'list':
            handleList($db);
            break;

        case 'get':
            handleGet($db);
            break;

        case 'create':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleCreate($db, $input, $user);
            break;

        case 'update':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleUpdate($db, $input, $user);
            break;

        case 'delete':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleDelete($db, $input);
            break;

        case 'suggest':
            handleSuggest($input);
            break;

        case 'stats':
            handleStats($db);
            break;

        case 'check_duplicates':
            handleCheckDuplicates($db);
            break;

        default:
            throw new Exception('Invalid action: ' . htmlspecialchars($action));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


function handleList(PDO $db): void
{
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['category'])) {
        $where[] = 'e.accounting_category = ?';
        $params[] = $_GET['category'];
    }
    if (!empty($_GET['vendor_id'])) {
        $where[] = 'e.vendor_id = ?';
        $params[] = (int)$_GET['vendor_id'];
    }
    if (!empty($_GET['status'])) {
        $where[] = 'e.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['date_from'])) {
        $where[] = 'e.expense_date >= ?';
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where[] = 'e.expense_date <= ?';
        $params[] = $_GET['date_to'];
    }
    if (!empty($_GET['job_id'])) {
        $where[] = 'e.job_id = ?';
        $params[] = (int)$_GET['job_id'];
    }
    if (!empty($_GET['search'])) {
        $where[] = '(e.vendor_name_raw LIKE ? OR e.description LIKE ? OR e.notes LIKE ?)';
        $search = '%' . $_GET['search'] . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = implode(' AND ', $where);

    // Count total
    $countStmt = $db->prepare("SELECT COUNT(*) FROM expenses e WHERE {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Fetch page
    $stmt = $db->prepare("
        SELECT e.*,
               v.name AS vendor_name,
               u.full_name AS created_by_name,
               ma.file_path AS receipt_path,
               ma.stored_filename AS receipt_filename
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN users u ON u.id = e.created_by
        LEFT JOIN media_assets ma ON ma.id = e.receipt_media_id
        WHERE {$whereClause}
        ORDER BY e.expense_date DESC, e.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'expenses' => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);
}


function handleGet(PDO $db): void
{
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('Expense ID required');

    $stmt = $db->prepare("
        SELECT e.*,
               v.name AS vendor_name,
               v.aliases AS vendor_aliases,
               u.full_name AS created_by_name,
               ma.file_path AS receipt_path,
               ma.original_filename AS receipt_original_name,
               ma.mime_type AS receipt_mime_type
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN users u ON u.id = e.created_by
        LEFT JOIN media_assets ma ON ma.id = e.receipt_media_id
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) throw new Exception('Expense not found');

    // Get send history
    $logStmt = $db->prepare("
        SELECT rel.*, u.full_name AS sent_by_name
        FROM receipt_email_log rel
        LEFT JOIN users u ON u.id = rel.created_by
        WHERE rel.expense_id = ?
        ORDER BY rel.created_at DESC
    ");
    $logStmt->execute([$id]);
    $expense['send_history'] = $logStmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse line items from raw OCR text if available
    $expense['parsed_line_items'] = [];
    if (!empty($expense['raw_ocr_json'])) {
        require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
        $parsed = parseReceiptText($expense['raw_ocr_json']);
        $expense['parsed_line_items'] = $parsed['line_items'] ?? [];
    }

    echo json_encode(['success' => true, 'expense' => $expense]);
}


function handleCreate(PDO $db, ?array $input, array $user): void
{
    if (!$input) throw new Exception('No data provided');

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $expenseDate = $input['expense_date'] ?? date('Y-m-d');
    $total       = (float)($input['total'] ?? 0);

    if ($total <= 0 && empty($input['description'])) {
        throw new Exception('Total amount or description is required');
    }

    $stmt = $db->prepare("
        INSERT INTO expenses
            (expense_date, vendor_id, vendor_name_raw, description, amount, tax_amount, total,
             accounting_category, gbp_category, payment_method, receipt_media_id,
             receipt_lat, receipt_lng, match_confidence, raw_ocr_json,
             job_id, property_id, contact_id, notes, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $expenseDate,
        !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null,
        $input['vendor_name_raw'] ?? null,
        $input['description'] ?? null,
        (float)($input['amount'] ?? 0),
        (float)($input['tax_amount'] ?? 0),
        $total,
        $input['accounting_category'] ?? null,
        $input['gbp_category'] ?? null,
        $input['payment_method'] ?? null,
        !empty($input['receipt_media_id']) ? (int)$input['receipt_media_id'] : null,
        !empty($input['receipt_lat']) ? (float)$input['receipt_lat'] : null,
        !empty($input['receipt_lng']) ? (float)$input['receipt_lng'] : null,
        (int)($input['match_confidence'] ?? 0),
        $input['raw_ocr_json'] ?? null,
        !empty($input['job_id']) ? (int)$input['job_id'] : null,
        !empty($input['property_id']) ? (int)$input['property_id'] : null,
        !empty($input['contact_id']) ? (int)$input['contact_id'] : null,
        $input['notes'] ?? null,
        $input['status'] ?? 'draft',
        $user['id'],
    ]);

    $expenseId = (int)$db->lastInsertId();

    // Auto-send if enabled
    if (!empty($input['auto_send'])) {
        require_once APP_ROOT . '/Services/Receipts/ReceiptService.php';
        $config = getReceiptForwardingConfig($db);
        if ($config['enabled'] && $config['auto_send'] && !empty($input['receipt_media_id'])) {
            sendReceiptToAccounting([
                'expense_id'         => $expenseId,
                'media_id'           => (int)$input['receipt_media_id'],
                'vendor'             => $input['vendor_name_raw'] ?? 'Unknown',
                'total'              => (string)$total,
                'date'               => $expenseDate,
                'job_id'             => $input['job_id'] ?? null,
                'accounting_category'=> $input['accounting_category'] ?? '',
            ]);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Expense created',
        'expense_id' => $expenseId,
    ]);
}


function handleUpdate(PDO $db, ?array $input, array $user): void
{
    if (!$input) throw new Exception('No data provided');

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Expense ID required');

    // Verify exists
    $check = $db->prepare("SELECT id FROM expenses WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) throw new Exception('Expense not found');

    $stmt = $db->prepare("
        UPDATE expenses SET
            expense_date = ?,
            vendor_id = ?,
            vendor_name_raw = ?,
            description = ?,
            amount = ?,
            tax_amount = ?,
            total = ?,
            accounting_category = ?,
            gbp_category = ?,
            payment_method = ?,
            receipt_media_id = ?,
            match_confidence = ?,
            job_id = ?,
            property_id = ?,
            contact_id = ?,
            notes = ?,
            status = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $input['expense_date'] ?? date('Y-m-d'),
        !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null,
        $input['vendor_name_raw'] ?? null,
        $input['description'] ?? null,
        (float)($input['amount'] ?? 0),
        (float)($input['tax_amount'] ?? 0),
        (float)($input['total'] ?? 0),
        $input['accounting_category'] ?? null,
        $input['gbp_category'] ?? null,
        $input['payment_method'] ?? null,
        !empty($input['receipt_media_id']) ? (int)$input['receipt_media_id'] : null,
        (int)($input['match_confidence'] ?? 0),
        !empty($input['job_id']) ? (int)$input['job_id'] : null,
        !empty($input['property_id']) ? (int)$input['property_id'] : null,
        !empty($input['contact_id']) ? (int)$input['contact_id'] : null,
        $input['notes'] ?? null,
        $input['status'] ?? 'draft',
        $id,
    ]);

    echo json_encode(['success' => true, 'message' => 'Expense updated']);
}


function handleDelete(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Expense ID required');

    $stmt = $db->prepare("DELETE FROM expenses WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) throw new Exception('Expense not found');

    echo json_encode(['success' => true, 'message' => 'Expense deleted']);
}


function handleSuggest(?array $input): void
{
    require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';

    $ocrText = $input['ocr_text'] ?? '';
    $lat     = isset($input['lat']) && $input['lat'] !== '' ? (float)$input['lat'] : null;
    $lng     = isset($input['lng']) && $input['lng'] !== '' ? (float)$input['lng'] : null;
    $jobId   = !empty($input['job_id']) ? (int)$input['job_id'] : null;

    $suggestion = suggestReceiptMeta($ocrText, $lat, $lng, $jobId);

    echo json_encode(['success' => true, 'suggestion' => $suggestion]);
}


function handleStats(PDO $db): void
{
    // Summary stats for dashboard
    $currentMonth = date('Y-m-01');
    $currentMonthEnd = date('Y-m-t');

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(total), 0) AS total_amount,
            COUNT(CASE WHEN forwarded_to_accounting = 1 THEN 1 END) AS forwarded_count,
            COUNT(CASE WHEN status = 'draft' THEN 1 END) AS draft_count
        FROM expenses
        WHERE expense_date BETWEEN ? AND ?
    ");
    $stmt->execute([$currentMonth, $currentMonthEnd]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Category breakdown
    $catStmt = $db->prepare("
        SELECT accounting_category, COUNT(*) AS count, COALESCE(SUM(total), 0) AS total
        FROM expenses
        WHERE expense_date BETWEEN ? AND ?
        AND accounting_category IS NOT NULL
        GROUP BY accounting_category
        ORDER BY total DESC
    ");
    $catStmt->execute([$currentMonth, $currentMonthEnd]);
    $stats['categories'] = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'stats' => $stats]);
}


function handleCheckDuplicates(PDO $db): void
{
    // Accept both GET and POST (GET for quick checks from frontend)
    $vendorName = $_GET['vendor_name'] ?? null;
    $vendorId   = !empty($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : null;
    $total      = isset($_GET['total']) ? (float)$_GET['total'] : null;
    $date       = $_GET['expense_date'] ?? null;
    $excludeId  = !empty($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;

    if ($total === null || $total <= 0 || empty($date)) {
        echo json_encode(['success' => true, 'has_duplicates' => false, 'duplicates' => []]);
        return;
    }

    // Build query: match total exactly, date within +/- 3 days
    $where = ['ABS(e.total - ?) < 0.01', 'e.expense_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)'];
    $params = [$total, $date, $date];

    // Exclude self (for edit mode)
    if ($excludeId) {
        $where[] = 'e.id != ?';
        $params[] = $excludeId;
    }

    // Vendor matching (optional but strengthens signal)
    $vendorClause = [];
    if ($vendorId) {
        $vendorClause[] = 'e.vendor_id = ?';
        $params[] = $vendorId;
    }
    if ($vendorName && strlen(trim($vendorName)) >= 2) {
        $vendorClause[] = 'e.vendor_name_raw LIKE ?';
        $params[] = '%' . trim($vendorName) . '%';
    }

    // If we have vendor info, require vendor OR name match alongside total+date
    // If no vendor info, just match on total+date (weaker but still useful)
    if (!empty($vendorClause)) {
        $where[] = '(' . implode(' OR ', $vendorClause) . ')';
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT e.id, e.expense_date, e.total, e.status,
               e.vendor_name_raw,
               v.name AS vendor_name,
               ma.file_path AS receipt_path
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN media_assets ma ON ma.id = e.receipt_media_id
        WHERE {$whereClause}
        ORDER BY e.expense_date DESC
        LIMIT 5
    ");
    $stmt->execute($params);
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'        => true,
        'has_duplicates'  => count($duplicates) > 0,
        'duplicates'      => $duplicates,
    ]);
}
