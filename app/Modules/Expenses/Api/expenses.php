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

        case 'merge_receipt':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleMergeReceipt($db, $input);
            break;

        case 'link_product':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleLinkProduct($db, $input);
            break;

        case 'budget_status':
            handleBudgetStatus($db);
            break;

        case 'job_margin':
            handleJobMargin($db);
            break;

        case 'price_trend':
            handlePriceTrend($db);
            break;

        case 'approve':
            $canApprove = userHasPermission('expenses.approve');
            if (!$canApprove) throw new Exception('Permission denied: expenses.approve required');
            handleApprove($db, $input, $user);
            break;

        case 'reject':
            $canApprove = userHasPermission('expenses.approve');
            if (!$canApprove) throw new Exception('Permission denied: expenses.approve required');
            handleReject($db, $input, $user);
            break;

        case 'pending_approval':
            $canApprove = userHasPermission('expenses.approve');
            if (!$canApprove) throw new Exception('Permission denied: expenses.approve required');
            handlePendingApproval($db);
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
        ORDER BY e.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Route receipt images through the auth-gated proxy instead of exposing
    // direct /uploads/receipts/ paths. Uses media_id for clean URL.
    foreach ($rows as &$row) {
        if (!empty($row['receipt_media_id'])) {
            $row['receipt_path'] = '/crm/api/serve-receipt.php?id=' . (int)$row['receipt_media_id'];
        } else {
            $row['receipt_path'] = null;
        }
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

    // Route receipt image through auth-gated proxy
    if (!empty($expense['receipt_media_id'])) {
        $expense['receipt_path'] = '/crm/api/serve-receipt.php?id=' . (int)$expense['receipt_media_id'];
    } else {
        $expense['receipt_path'] = null;
    }

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

    // Load stored line items (with product details)
    $liStmt = $db->prepare("
        SELECT eli.*,
               p.name AS product_name,
               p.sku AS product_sku,
               p.track_inventory
        FROM expense_line_items eli
        LEFT JOIN products p ON p.id = eli.product_id
        WHERE eli.expense_id = ?
        ORDER BY eli.sort_order, eli.id
    ");
    $liStmt->execute([$id]);
    $storedItems = $liStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($storedItems)) {
        $expense['line_items'] = $storedItems;
        $expense['line_items_stored'] = true;
    } else {
        // Fallback: parse from raw OCR text
        $expense['line_items'] = [];
        if (!empty($expense['raw_ocr_json'])) {
            require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
            $parsed = parseReceiptText($expense['raw_ocr_json']);
            $expense['line_items'] = $parsed['line_items'] ?? [];
        }
        $expense['line_items_stored'] = false;
    }
    // Keep backward-compatible key
    $expense['parsed_line_items'] = $expense['line_items'];

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

    // ── Run anomaly detection ─────────────────────────────────────
    $anomalyFlags = '';
    $anomalyScore = 0;
    try {
        require_once APP_ROOT . '/Services/Receipts/AnomalyDetector.php';
        $anomalyData = array_merge($input, [
            'total' => $total,
            'expense_date' => $expenseDate,
            'created_by' => $user['id'],
        ]);
        $anomalyResult = detectAnomalies($anomalyData, $db);
        $anomalyFlags = $anomalyResult['flags'] ?? '';
        $anomalyScore = $anomalyResult['score'] ?? 0;
    } catch (Throwable $e) {
        error_log('Anomaly detection error: ' . $e->getMessage());
    }

    // ── Check budget variance ────────────────────────────────────
    $budgetWarning = null;
    try {
        require_once APP_ROOT . '/Services/Receipts/BudgetService.php';
        $budgetWarning = checkBudgetOnSave($input, $db);
    } catch (Throwable $e) {
        // Budget service non-critical
    }

    $stmt = $db->prepare("
        INSERT INTO expenses
            (expense_date, vendor_id, vendor_name_raw, description, amount, gst_amount, pst_amount, total,
             accounting_category, gbp_category, payment_method, receipt_media_id,
             receipt_lat, receipt_lng, match_confidence, anomaly_flags, anomaly_score, raw_ocr_json,
             job_id, property_id, contact_id, notes, status,
             odometer_start, odometer_end, fuel_litres, fuel_price_per_litre,
             created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $expenseDate,
        !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null,
        $input['vendor_name_raw'] ?? null,
        $input['description'] ?? null,
        (float)($input['amount'] ?? 0),
        (float)($input['gst_amount'] ?? 0),
        (float)($input['pst_amount'] ?? 0),
        $total,
        $input['accounting_category'] ?? null,
        $input['gbp_category'] ?? null,
        $input['payment_method'] ?? null,
        !empty($input['receipt_media_id']) ? (int)$input['receipt_media_id'] : null,
        !empty($input['receipt_lat']) ? (float)$input['receipt_lat'] : null,
        !empty($input['receipt_lng']) ? (float)$input['receipt_lng'] : null,
        (int)($input['match_confidence'] ?? 0),
        $anomalyFlags ?: null,
        $anomalyScore,
        $input['raw_ocr_json'] ?? null,
        !empty($input['job_id']) ? (int)$input['job_id'] : null,
        !empty($input['property_id']) ? (int)$input['property_id'] : null,
        !empty($input['contact_id']) ? (int)$input['contact_id'] : null,
        $input['notes'] ?? null,
        $input['status'] ?? 'draft',
        !empty($input['odometer_start']) ? (int)$input['odometer_start'] : null,
        !empty($input['odometer_end']) ? (int)$input['odometer_end'] : null,
        !empty($input['fuel_litres']) ? (float)$input['fuel_litres'] : null,
        !empty($input['fuel_price_per_litre']) ? (float)$input['fuel_price_per_litre'] : null,
        $user['id'],
    ]);

    $expenseId = (int)$db->lastInsertId();

    // Save line items if provided
    if (!empty($input['line_items']) && is_array($input['line_items'])) {
        saveLineItems($db, $expenseId, $input['line_items']);

        // ── Record line item prices for intelligence ──────────────
        if (!empty($input['vendor_id'])) {
            try {
                require_once APP_ROOT . '/Services/Receipts/PriceIntelligence.php';
                recordLineItemPrices($expenseId, (int)$input['vendor_id'], $input['line_items'], $expenseDate);
            } catch (Throwable $e) {
                error_log('Price intelligence error: ' . $e->getMessage());
            }
        }
    }

    // Record OCR corrections for learning (only if receipt was OCR'd)
    if (!empty($input['raw_ocr_json']) && !empty($input['ocr_parsed'])) {
        try {
            require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
            $ocrParsed = is_string($input['ocr_parsed']) ? json_decode($input['ocr_parsed'], true) : $input['ocr_parsed'];
            if (is_array($ocrParsed)) {
                recordCorrections(
                    !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null,
                    $input['vendor_name_raw'] ?? null,
                    $ocrParsed,
                    $input,
                    $input['raw_ocr_json']
                );
            }
        } catch (Throwable $e) {
            // Learning is non-critical — log and continue
            error_log('Receipt learning error: ' . $e->getMessage());
        }
    }

    // Auto-send if enabled
    if (!empty($input['auto_send'])) {
        require_once APP_ROOT . '/Services/Receipts/ReceiptService.php';
        $config = getReceiptForwardingConfig($db);
        if ($config['enabled'] && $config['auto_send'] && !empty($input['receipt_media_id'])) {
            sendReceiptToAccounting([
                'expense_id'         => $expenseId,
                'media_id'           => (int)$input['receipt_media_id'],
                'vendor'             => $input['vendor_name_raw'] ?? 'Unknown',
                'subtotal'           => (string)($input['amount'] ?? '0.00'),
                'gst_amount'         => (string)($input['gst_amount'] ?? '0.00'),
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

    // Verify exists and fetch OCR text for learning
    $check = $db->prepare("SELECT id, raw_ocr_json, vendor_id FROM expenses WHERE id = ?");
    $check->execute([$id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) throw new Exception('Expense not found');

    $expenseDate = $input['expense_date'] ?? date('Y-m-d');
    $total = (float)($input['total'] ?? 0);

    // ── Re-run anomaly detection on update ──────────────────────────
    $anomalyFlags = '';
    $anomalyScore = 0;
    try {
        require_once APP_ROOT . '/Services/Receipts/AnomalyDetector.php';
        $anomalyData = array_merge($input, [
            'id'           => $id,
            'total'        => $total,
            'expense_date' => $expenseDate,
            'created_by'   => $user['id'],
        ]);
        $anomalyResult = detectAnomalies($anomalyData, $db);
        $anomalyFlags = $anomalyResult['flags'] ?? '';
        $anomalyScore = $anomalyResult['score'] ?? 0;
    } catch (Throwable $e) {
        error_log('Anomaly detection error (update): ' . $e->getMessage());
    }

    $stmt = $db->prepare("
        UPDATE expenses SET
            expense_date = ?,
            vendor_id = ?,
            vendor_name_raw = ?,
            description = ?,
            amount = ?,
            gst_amount = ?,
            pst_amount = ?,
            total = ?,
            accounting_category = ?,
            gbp_category = ?,
            payment_method = ?,
            receipt_media_id = ?,
            match_confidence = ?,
            anomaly_flags = ?,
            anomaly_score = ?,
            job_id = ?,
            property_id = ?,
            contact_id = ?,
            notes = ?,
            status = ?,
            odometer_start = ?,
            odometer_end = ?,
            fuel_litres = ?,
            fuel_price_per_litre = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $expenseDate,
        !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null,
        $input['vendor_name_raw'] ?? null,
        $input['description'] ?? null,
        (float)($input['amount'] ?? 0),
        (float)($input['gst_amount'] ?? 0),
        (float)($input['pst_amount'] ?? 0),
        $total,
        $input['accounting_category'] ?? null,
        $input['gbp_category'] ?? null,
        $input['payment_method'] ?? null,
        !empty($input['receipt_media_id']) ? (int)$input['receipt_media_id'] : null,
        (int)($input['match_confidence'] ?? 0),
        $anomalyFlags ?: null,
        $anomalyScore,
        !empty($input['job_id']) ? (int)$input['job_id'] : null,
        !empty($input['property_id']) ? (int)$input['property_id'] : null,
        !empty($input['contact_id']) ? (int)$input['contact_id'] : null,
        $input['notes'] ?? null,
        $input['status'] ?? 'draft',
        !empty($input['odometer_start']) ? (int)$input['odometer_start'] : null,
        !empty($input['odometer_end']) ? (int)$input['odometer_end'] : null,
        !empty($input['fuel_litres']) ? (float)$input['fuel_litres'] : null,
        !empty($input['fuel_price_per_litre']) ? (float)$input['fuel_price_per_litre'] : null,
        $id,
    ]);

    // Update line items if provided
    if (isset($input['line_items']) && is_array($input['line_items'])) {
        // Reverse inventory for old linked products
        reverseLineItemInventory($db, $id);
        // Delete old line items and insert new ones
        $delStmt = $db->prepare("DELETE FROM expense_line_items WHERE expense_id = ?");
        $delStmt->execute([$id]);
        if (!empty($input['line_items'])) {
            saveLineItems($db, $id, $input['line_items']);

            // ── Record line item prices for intelligence ──────────────
            $vendorId = !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null;
            if ($vendorId) {
                try {
                    require_once APP_ROOT . '/Services/Receipts/PriceIntelligence.php';
                    recordLineItemPrices($id, $vendorId, $input['line_items'], $expenseDate);
                } catch (Throwable $e) {
                    error_log('Price intelligence error (update): ' . $e->getMessage());
                }
            }
        }
    }

    // Record corrections for learning (re-parse stored OCR and compare to updated values)
    $ocrText = $existing['raw_ocr_json'] ?? '';
    if (!empty($ocrText)) {
        try {
            require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
            require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
            $ocrParsed = parseReceiptText($ocrText);
            recordCorrections(
                !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null,
                $input['vendor_name_raw'] ?? null,
                $ocrParsed,
                $input,
                $ocrText
            );
        } catch (Throwable $e) {
            error_log('Receipt learning error (update): ' . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'message' => 'Expense updated']);
}


function handleDelete(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Expense ID required');

    // Reverse inventory for linked line items before CASCADE delete removes them
    reverseLineItemInventory($db, $id);

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

    // Route receipt images through proxy
    foreach ($duplicates as &$dup) {
        if (!empty($dup['receipt_media_id'])) {
            $dup['receipt_path'] = '/crm/api/serve-receipt.php?id=' . (int)$dup['receipt_media_id'];
        } else {
            $dup['receipt_path'] = null;
        }
    }
    unset($dup);

    echo json_encode([
        'success'        => true,
        'has_duplicates'  => count($duplicates) > 0,
        'duplicates'      => $duplicates,
    ]);
}


/**
 * Merge a newly scanned receipt into an existing expense.
 * The "source" hasn't been saved yet — it's just an intake result.
 * If the user picks the new receipt, we update the target's receipt_media_id.
 */
function handleMergeReceipt(PDO $db, ?array $input): void
{
    if (!$input) throw new Exception('No data provided');

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $targetId = (int)($input['target_id'] ?? 0);
    if (!$targetId) throw new Exception('Target expense ID required');

    // Verify target exists
    $stmt = $db->prepare("SELECT id, receipt_media_id FROM expenses WHERE id = ?");
    $stmt->execute([$targetId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) throw new Exception('Target expense not found');

    $keepReceipt = $input['keep_receipt'] ?? 'target';
    $sourceMediaId = !empty($input['source_media_id']) ? (int)$input['source_media_id'] : null;

    // If user chose the new receipt, update the target
    if ($keepReceipt === 'source' && $sourceMediaId) {
        $stmt = $db->prepare("UPDATE expenses SET receipt_media_id = ? WHERE id = ?");
        $stmt->execute([$sourceMediaId, $targetId]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Receipt merged into expense #' . $targetId,
    ]);
}


/**
 * Link (or unlink) a product to a specific expense line item.
 * Lightweight AJAX endpoint — no full expense save needed.
 */
function handleLinkProduct(PDO $db, ?array $input): void
{
    if (!$input) throw new Exception('No data provided');

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $lineItemId = (int)($input['line_item_id'] ?? 0);
    if (!$lineItemId) throw new Exception('Line item ID required');

    $newProductId = isset($input['product_id']) && $input['product_id'] !== null && $input['product_id'] !== ''
        ? (int)$input['product_id']
        : null;

    // Fetch current line item
    $stmt = $db->prepare("SELECT id, product_id, quantity FROM expense_line_items WHERE id = ?");
    $stmt->execute([$lineItemId]);
    $li = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$li) throw new Exception('Line item not found');

    $oldProductId = $li['product_id'] ? (int)$li['product_id'] : null;
    $qty = (float)$li['quantity'];

    // Reverse old product inventory
    if ($oldProductId) {
        updateProductInventory($db, $oldProductId, -$qty);
    }

    // Update the link
    $upd = $db->prepare("UPDATE expense_line_items SET product_id = ? WHERE id = ?");
    $upd->execute([$newProductId, $lineItemId]);

    // Apply new product inventory
    if ($newProductId) {
        updateProductInventory($db, $newProductId, $qty);
    }

    // Return updated line item with product details
    $result = $db->prepare("
        SELECT eli.*, p.name AS product_name, p.sku AS product_sku, p.track_inventory
        FROM expense_line_items eli
        LEFT JOIN products p ON p.id = eli.product_id
        WHERE eli.id = ?
    ");
    $result->execute([$lineItemId]);
    $updated = $result->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'line_item' => $updated]);
}


/**
 * Save line items array for an expense. Also applies inventory adjustments
 * for any items that already have a product_id set.
 */
function saveLineItems(PDO $db, int $expenseId, array $lineItems): void
{
    $stmt = $db->prepare("
        INSERT INTO expense_line_items
            (expense_id, product_id, name, quantity, unit_price, line_total, sku_raw, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($lineItems as $idx => $li) {
        $productId = !empty($li['product_id']) ? (int)$li['product_id'] : null;
        $qty = (float)($li['quantity'] ?? 1);
        $unitPrice = isset($li['unit_price']) && $li['unit_price'] !== null && $li['unit_price'] !== ''
            ? (float)$li['unit_price']
            : null;
        $lineTotal = (float)($li['line_total'] ?? $li['amount'] ?? 0);
        $name = $li['name'] ?? 'Unknown Item';
        $skuRaw = $li['sku_raw'] ?? null;

        $stmt->execute([
            $expenseId,
            $productId,
            $name,
            $qty,
            $unitPrice,
            $lineTotal,
            $skuRaw,
            $idx,
        ]);

        // Apply inventory adjustment for linked products
        if ($productId) {
            updateProductInventory($db, $productId, $qty);
        }
    }
}


/**
 * Reverse inventory adjustments for all linked line items of an expense.
 * Call this BEFORE deleting line items or the expense itself.
 */
function reverseLineItemInventory(PDO $db, int $expenseId): void
{
    $stmt = $db->prepare("
        SELECT product_id, quantity
        FROM expense_line_items
        WHERE expense_id = ? AND product_id IS NOT NULL
    ");
    $stmt->execute([$expenseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        updateProductInventory($db, (int)$row['product_id'], -(float)$row['quantity']);
    }
}


/**
 * Adjust current_stock on a product (only if track_inventory = 1).
 * Positive delta = purchase adds stock; negative = reversal.
 */
function updateProductInventory(PDO $db, int $productId, float $qtyDelta): void
{
    if ($qtyDelta == 0) return;

    $stmt = $db->prepare("
        UPDATE products
        SET current_stock = current_stock + ?
        WHERE id = ? AND track_inventory = 1
    ");
    $stmt->execute([$qtyDelta, $productId]);
}


// ============================================================================
//  Budget, Margin, Price Trend, and Approval handlers
// ============================================================================

/**
 * GET ?action=budget_status[&month=2026-03]
 * Returns budget variance for all categories in the given month.
 */
function handleBudgetStatus(PDO $db): void
{
    require_once APP_ROOT . '/Services/Receipts/BudgetService.php';

    $month = $_GET['month'] ?? date('Y-m-01');
    // Normalize to first of month
    $month = date('Y-m-01', strtotime($month));

    $variances = getAllBudgetVariances($month, $db);

    echo json_encode([
        'success'   => true,
        'month'     => $month,
        'budgets'   => $variances,
    ]);
}


/**
 * GET ?action=job_margin&plan_id=X
 * Returns quoted vs actual materials margin for a job plan.
 */
function handleJobMargin(PDO $db): void
{
    require_once APP_ROOT . '/Services/Receipts/MarginTracker.php';

    $planId = (int)($_GET['plan_id'] ?? 0);
    if (!$planId) throw new Exception('plan_id required');

    $margin = getJobMargin($planId, $db);
    if (!$margin) {
        echo json_encode(['success' => true, 'margin' => null, 'message' => 'No margin data available']);
        return;
    }

    echo json_encode(['success' => true, 'margin' => $margin]);
}


/**
 * GET ?action=price_trend&vendor_id=X&product=Name
 * Returns 12-month price history for a product at a vendor.
 */
function handlePriceTrend(PDO $db): void
{
    require_once APP_ROOT . '/Services/Receipts/PriceIntelligence.php';

    $vendorId = (int)($_GET['vendor_id'] ?? 0);
    $product  = $_GET['product'] ?? '';

    if (!$vendorId || empty($product)) {
        throw new Exception('vendor_id and product required');
    }

    $trend = getPriceTrend($vendorId, $product);

    // Also check for price anomaly on latest price
    $anomaly = null;
    if (!empty($trend)) {
        $latestPrice = (float)$trend[count($trend) - 1]['avg_price'];
        $anomaly = checkPriceAnomaly($vendorId, $product, $latestPrice);
    }

    echo json_encode([
        'success' => true,
        'trend'   => $trend,
        'anomaly' => $anomaly,
    ]);
}


/**
 * POST {action: 'approve', id: X}
 * Marks an expense as approved.
 */
function handleApprove(PDO $db, ?array $input, array $user): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Expense ID required');

    // Verify expense exists
    $check = $db->prepare("SELECT id, status, created_by FROM expenses WHERE id = ?");
    $check->execute([$id]);
    $expense = $check->fetch(PDO::FETCH_ASSOC);
    if (!$expense) throw new Exception('Expense not found');

    // Prevent self-approval (the creator cannot approve their own expense)
    if ((int)$expense['created_by'] === (int)$user['id']) {
        throw new Exception('Cannot approve your own expense');
    }

    $stmt = $db->prepare("
        UPDATE expenses SET
            status = 'approved',
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$user['id'], $id]);

    echo json_encode(['success' => true, 'message' => 'Expense approved']);
}


/**
 * POST {action: 'reject', id: X, rejection_reason: '...'}
 * Rejects an expense and records the reason.
 */
function handleReject(PDO $db, ?array $input, array $user): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Expense ID required');

    $reason = trim($input['rejection_reason'] ?? '');
    if (empty($reason)) throw new Exception('Rejection reason is required');

    // Verify expense exists
    $check = $db->prepare("SELECT id, created_by FROM expenses WHERE id = ?");
    $check->execute([$id]);
    $expense = $check->fetch(PDO::FETCH_ASSOC);
    if (!$expense) throw new Exception('Expense not found');

    $stmt = $db->prepare("
        UPDATE expenses SET
            status = 'rejected',
            approved_by = ?,
            approved_at = NOW(),
            rejection_reason = ?
        WHERE id = ?
    ");
    $stmt->execute([$user['id'], $reason, $id]);

    // Notify the expense creator via activity log
    try {
        $db->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at)
            VALUES (?, 'expense_rejected', 'expense', ?, ?, NOW())
        ")->execute([$user['id'], $id, json_encode(['reason' => $reason])]);
    } catch (Throwable $e) {
        // Activity log is non-critical
    }

    echo json_encode(['success' => true, 'message' => 'Expense rejected']);
}


/**
 * GET ?action=pending_approval[&page=1]
 * Lists expenses that need approval (anomaly_score > 30 or status = 'pending_approval').
 */
function handlePendingApproval(PDO $db): void
{
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;
    $offset  = ($page - 1) * $perPage;

    $countStmt = $db->prepare("
        SELECT COUNT(*)
        FROM expenses e
        WHERE (e.anomaly_score > 30 AND e.status = 'draft')
           OR e.status = 'pending_approval'
    ");
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT e.*,
               v.name AS vendor_name,
               u.full_name AS created_by_name,
               ma.file_path AS receipt_path
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN users u ON u.id = e.created_by
        LEFT JOIN media_assets ma ON ma.id = e.receipt_media_id
        WHERE (e.anomaly_score > 30 AND e.status = 'draft')
           OR e.status = 'pending_approval'
        ORDER BY e.anomaly_score DESC, e.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Route receipt images through proxy
    foreach ($rows as &$row) {
        $row['receipt_path'] = !empty($row['receipt_media_id'])
            ? '/crm/api/serve-receipt.php?id=' . (int)$row['receipt_media_id']
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
}
