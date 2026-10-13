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
 * GET:  ?action=penny_read&id=X  — Penny's pending read where it differs (PennyReadService)
 * POST: {action: 'penny_apply'|'penny_keep', id, csrf_token}  — "Use Penny's version" / "Keep mine"
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
    require_once APP_ROOT . '/Services/Receipts/ExpenseLineItems.php';
    // Every change to an expense goes through the one gate (migration 1233).
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseGate.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseService.php';
    require_once APP_ROOT . '/Core/ApiResponse.php';

    requireLogin();
    $user = getCurrentUser();
    session_write_close(); // release session lock before DB queries
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
            handleDelete($db, $input, $user);
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

        case 'merge':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleMergeExpenses($db, $input);
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

        case 'batch_approve':
            $canApprove = userHasPermission('expenses.approve');
            if (!$canApprove) throw new Exception('Permission denied: expenses.approve required');
            handleBatchApprove($db, $input, $user);
            break;

        case 'batch_reject':
            $canApprove = userHasPermission('expenses.approve');
            if (!$canApprove) throw new Exception('Permission denied: expenses.approve required');
            handleBatchReject($db, $input, $user);
            break;

        case 'pending_approval':
            $canApprove = userHasPermission('expenses.approve');
            if (!$canApprove) throw new Exception('Permission denied: expenses.approve required');
            handlePendingApproval($db);
            break;

        case 'review_queue':
            $canApprove2 = userHasPermission('expenses.approve');
            if (!$canApprove2) throw new Exception('Permission denied: expenses.approve required');
            handleReviewQueue($db);
            break;

        case 'margin_summary':
            handleMarginSummary($db);
            break;

        case 'qb_status':
            handleQbStatus($db);
            break;

        case 'batch_forward':
            if (!$canSend) throw new Exception('Permission denied: expenses.send required');
            handleBatchForward($db, $input);
            break;

        case 'qb_retry':
            if (!$canSend) throw new Exception('Permission denied: expenses.send required');
            handleQbRetry($db, $input ?? []);
            break;

        case 'reassign_job':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleReassignJob($db, $input);
            break;

        case 'search_jobs':
            handleSearchJobs($db);
            break;

        case 'rescan':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleRescan($db, $input, $user);
            break;

        case 'add_line_item':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleAddLineItem($db, $input);
            break;

        case 'delete_line_item':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleDeleteLineItem($db, $input);
            break;

        case 'split':
            // GET ?action=split&id=N — the split by line saved on the receipt, and Penny's
            // per-line proposal (job / category / stock) for the edit modal's "Split by line".
            require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseSplitService.php';
            $splitSvc = new ExpenseSplitService($db);
            echo json_encode($splitSvc->ready()
                ? ['success' => true, 'ready' => true] + $splitSvc->propose((int)($_GET['id'] ?? 0))
                : ['success' => true, 'ready' => false, 'lines' => [], 'current' => [], 'jobs' => [], 'suggest' => false]);
            break;

        case 'update_line_item':
            if (!$canEdit) throw new Exception('Permission denied: expenses.edit required');
            handleUpdateLineItem($db, $input);
            break;

        case 'penny_read':
        case 'penny_apply':
        case 'penny_keep':
            // "Penny's read" in the expense modal (web + Android): her pending suggestion where it
            // differs, "Use Penny's version" / "Keep mine" (PennyReadService, 2026-10-13). Rights are
            // per receipt — crew see and apply on their own open receipts, approvers apply + approve.
            handlePennyRead($db, $action, $input ?? null, $user);
            break;

        default:
            throw new Exception('Invalid action: ' . htmlspecialchars($action));
    }

} catch (Throwable $e) {
    http_response_code(ApiResponse::isSafe($e) ? 400 : 500);
    echo json_encode(['success' => false, 'error' => ApiResponse::message($e, 'expenses.php')]);
}

/**
 * Who is asking, for the shared receipt rules (review round 2, 2026-10-12): the office (admin/manager
 * or expenses.approve) sees and edits everyone's receipts; crew see and edit their own.
 */
function expenseActor(): array
{
    $u = getCurrentUser() ?: [];
    return ['id' => (int)($u['id'] ?? 0),
            'is_admin' => ExpenseService::isOffice((string)($u['role'] ?? ''), userHasPermission('expenses.approve'))];
}

/** GET ?action=penny_read&id=N · POST {action: penny_apply|penny_keep, id, csrf_token} */
function handlePennyRead(PDO $db, string $action, ?array $input, array $user): void
{
    require_once APP_ROOT . '/Modules/Expenses/Services/PennyReadService.php';
    $canApprove = userHasPermission('expenses.approve');
    $actor = ['id' => (int)($user['id'] ?? 0), 'office' => ExpenseService::isOffice((string)($user['role'] ?? ''), $canApprove),
              'can_approve' => $canApprove];
    $svc = new PennyReadService($db);
    if ($action === 'penny_read') {
        $res = $svc->read((int)($_GET['id'] ?? 0), $actor);
        if (!$res['ok']) http_response_code((int)($res['code'] ?? 400));
        echo json_encode($res['ok'] ? ['success' => true, 'read' => $res['read']] : ['success' => false, 'error' => $res['error']]);
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) throw new Exception('Invalid security token');
    $id = (int)($input['id'] ?? 0);
    $res = $action === 'penny_apply' ? $svc->apply($id, $user, $actor) : $svc->keep($id, $user, $actor);
    echo json_encode(['success' => !empty($res['ok'])] + $res);
}

/** Refuse a desktop change the iPhone path would refuse (ExpenseService::editBlockReason). */
function assertCanEditExpense(PDO $db, int $expenseId): void
{
    if ($expenseId <= 0) throw new Exception('Expense ID required');
    $st = $db->prepare("SELECT id, created_by, status, forwarded_to_accounting FROM expenses WHERE id = ?");
    $st->execute([$expenseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Expense not found');
    if (($why = ExpenseService::editBlockReason($row, expenseActor())) !== null) throw new Exception($why);
}

/** The expense a line item belongs to. */
function lineItemExpenseId(PDO $db, int $lineItemId): int
{
    $st = $db->prepare("SELECT expense_id FROM expense_line_items WHERE id = ?");
    $st->execute([$lineItemId]);
    return (int)$st->fetchColumn();
}


function handleList(PDO $db): void
{
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $where = ['1=1'];
    $params = [];
    // Crew see their own receipts; the office sees everyone's (same as the iPhone list).
    $actor = expenseActor();
    if (!$actor['is_admin']) {
        $where[]  = 'e.created_by = ?';
        $params[] = $actor['id'];
    }

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

    // Sort — whitelist columns to prevent SQL injection
    $sortableColumns = [
        'date'     => 'e.expense_date',
        'vendor'   => 'COALESCE(v.name, e.vendor_name_raw)',
        'category' => 'e.accounting_category',
        'total'    => 'e.total',
        'status'   => 'e.status',
    ];
    $sortBy  = $_GET['sort_by']  ?? 'date';
    $sortDir = strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $orderCol = $sortableColumns[$sortBy] ?? 'e.expense_date';
    // Secondary sort for stable ordering within same value
    $secondary = ($sortBy === 'date') ? ", e.created_at {$sortDir}" : ", e.expense_date DESC";
    $orderBy = "{$orderCol} {$sortDir}{$secondary}";

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
        ORDER BY {$orderBy}
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


/**
 * GET ?action=review_queue
 * Returns auto-approved expenses that have NOT yet been forwarded to accounting.
 * These are normal (low anomaly) draft expenses ready for QB forwarding.
 */
function handleReviewQueue(PDO $db): void
{
    // Not while Penny has the receipt on her desk (prepared, waiting for the owner): it
    // would be sent before she's approved it, and she'd then show it as already handled.
    $pennyHolds = '';
    try {
        if ($db->query("SHOW TABLES LIKE 'expense_suggestions'")->rowCount() > 0) {
            $pennyHolds = "AND NOT EXISTS (SELECT 1 FROM expense_suggestions ps
                                           WHERE ps.expense_id = e.id AND ps.source = 'live' AND ps.status = 'pending')";
        }
    } catch (Throwable $e) { /* no Penny → no hold */ }

    $countStmt = $db->prepare("
        SELECT COUNT(*), SUM(e.total)
        FROM expenses e
        WHERE e.status = 'approved'
          AND (e.forwarded_to_accounting IS NULL OR e.forwarded_to_accounting = 0)
          AND (e.anomaly_score <= 30 OR e.anomaly_score IS NULL)
          {$pennyHolds}
    ");
    $countStmt->execute();
    $totals      = $countStmt->fetch(PDO::FETCH_NUM);
    $total       = (int)($totals[0] ?? 0);
    $totalAmount = (float)($totals[1] ?? 0);

    $stmt = $db->prepare("
        SELECT e.id, e.expense_date, e.vendor_id, e.vendor_name_raw, e.accounting_category,
               e.total, e.status, e.job_id, e.receipt_media_id, e.created_at,
               e.anomaly_score,
               v.name AS vendor_name
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        WHERE e.status = 'approved'
          AND (e.forwarded_to_accounting IS NULL OR e.forwarded_to_accounting = 0)
          AND (e.anomaly_score <= 30 OR e.anomaly_score IS NULL)
          {$pennyHolds}
        ORDER BY e.expense_date DESC, e.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'      => true,
        'expenses'     => $rows,
        'total'        => $total,
        'total_amount' => $totalAmount,
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
               ma.mime_type AS receipt_mime_type,
               jp.plan_number AS job_plan_number,
               jp.service_type AS job_service_type,
               p.address AS job_address,
               CONCAT(c.first_name, ' ', c.last_name) AS job_contact_name
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN users u ON u.id = e.created_by
        LEFT JOIN media_assets ma ON ma.id = e.receipt_media_id
        LEFT JOIN job_plans jp ON jp.id = e.job_id
        LEFT JOIN properties p ON p.id = jp.property_id
        LEFT JOIN contacts c ON c.id = p.site_contact_id
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) throw new Exception('Expense not found');
    $actor = expenseActor();
    if (!$actor['is_admin'] && (int)$expense['created_by'] !== $actor['id']) throw new Exception('Expense not found');

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

    // Matched bank transaction, if any (drives the "Matched Transaction" section
    // in the Edit Expense modal — see BankImportService::attachExpenseMatch()).
    $matchStmt = $db->prepare("
        SELECT id AS transaction_id, transaction_date, description, amount,
               bank_account, matched_at, matched_by
        FROM accounting_transactions
        WHERE matched_expense_id = ?
        LIMIT 1
    ");
    $matchStmt->execute([$id]);
    $expense['matched_transaction'] = $matchStmt->fetch(PDO::FETCH_ASSOC) ?: null;

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
        // Fallback: parse from raw OCR text (after a rescan this column holds the JSON
        // Vision response, not text — ocrTextFromStored() handles both)
        $expense['line_items'] = [];
        if (!empty($expense['raw_ocr_json'])) {
            require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
            require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
            $storedText = ocrTextFromStored($expense['raw_ocr_json']);
            if ($storedText !== '') {
                $parsed = parseReceiptText($storedText, null, getVendorLineItemProfile(!empty($expense['vendor_id']) ? (int)$expense['vendor_id'] : null));
                $expense['line_items'] = $parsed['line_items'] ?? [];
            }
        }
        $expense['line_items_stored'] = false;
    }
    // Keep backward-compatible key
    $expense['parsed_line_items'] = $expense['line_items'];

    // Printed facts (receipt_facts, migration 1227) — "12:22–12:39 · ticket 43176009 · ••1234".
    try {
        require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptFactsService.php';
        $expense['receipt_facts'] = (new ReceiptFactsService($db))->forExpense($id);
        $expense['receipt_facts_line'] = ReceiptFactsService::line($expense['receipt_facts']);
    } catch (Throwable $e) {
        $expense['receipt_facts'] = null;
        $expense['receipt_facts_line'] = '';
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

    // Status on create is restricted to non-approval states — 'approved'/'rejected'/
    // 'forwarded' require the audited handleApprove()/handleReject()/sendReceiptToAccounting()
    // paths, which stamp approved_by/approved_at. Never let a caller set those directly here.
    $requestedStatus = $input['status'] ?? 'draft';
    $status = in_array($requestedStatus, ['draft', 'pending_approval'], true) ? $requestedStatus : 'draft';

    // One photo, one expense: a repeat of this create (second tap, retry) answers with
    // the expense already made from the photo instead of inserting another (#408-#410).
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseCreateGuard.php';
    $createGuard  = new ExpenseCreateGuard($db);
    $guardMediaId = !empty($input['receipt_media_id']) ? (int)$input['receipt_media_id'] : null;
    $existingId   = $createGuard->claim($guardMediaId);
    if ($existingId !== null) {
        echo json_encode(ExpenseCreateGuard::existingResponse($existingId));
        return;
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

    // Through the expense gate (migration 1233): the insert, its line items, the printed
    // facts, the duplicate check, the capture baseline + line-item lessons (only when the
    // receipt was OCR'd), line prices, and the audit row — the same door as every other save.
    try {
        $created = expenseGate($db)->apply(null, ExpenseGate::rowFromInput($input, [
            'expense_date'  => $expenseDate,
            'total'         => $total,
            'anomaly_flags' => $anomalyFlags ?: null,
            'anomaly_score' => $anomalyScore,
            'status'        => $status,
            'created_by'    => (int)$user['id'],
        ]) + (!empty($input['line_items']) && is_array($input['line_items']) ? ['line_items' => $input['line_items']] : []),
            ExpenseGate::actor($user), 'desktop_create', [
            'ocr_parsed'  => !empty($input['raw_ocr_json']) && !empty($input['ocr_parsed']) ? $input['ocr_parsed'] : null,
            'learn_lines' => !empty($input['raw_ocr_json']) && !empty($input['ocr_parsed']) ? $input : null,
            'price_intel' => true,
        ]);
        $expenseId = (int)$created['expense_id'];
    } finally {
        $createGuard->release($guardMediaId);   // the INSERT is in: the next request sees it
    }

    // No auto-send on create (review round 2, 2026-10-12): a new expense is always draft /
    // pending_approval, and only approved receipts go to the accountant — the old auto_send emailed
    // the client-supplied values of an unapproved receipt. Approve it, then send.

    echo json_encode([
        'success' => true,
        'message' => 'Expense created',
        'expense_id' => $expenseId,
    ]);
}


function handleReassignJob(PDO $db, ?array $input): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) throw new Exception('Invalid security token');

    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Expense ID required');
    assertCanEditExpense($db, $id);

    $jobId = isset($input['job_id']) && $input['job_id'] !== null && $input['job_id'] !== ''
        ? (int)$input['job_id'] : null;

    // Through the expense gate (audited; a posted receipt is re-posted on the new job).
    expenseGate($db)->apply($id, ['job_id' => $jobId], ExpenseGate::actor(getCurrentUser()), 'desktop_reassign_job');

    echo json_encode(['success' => true, 'job_id' => $jobId]);
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
    $check = $db->prepare("SELECT id, created_by, raw_ocr_json, vendor_id, status, forwarded_to_accounting, match_confidence, property_id, contact_id FROM expenses WHERE id = ?");
    $check->execute([$id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) throw new Exception('Expense not found');

    // The JWT path's rule (ExpenseService::editBlockReason): crew edit only their own, nobody edits a
    // receipt sent to accounting, an approved receipt is the office's to change.
    if (($why = ExpenseService::editBlockReason($existing, expenseActor())) !== null) throw new Exception($why);

    $expenseDate = $input['expense_date'] ?? date('Y-m-d');
    $total = (float)($input['total'] ?? 0);

    // Status can only move between non-approval states here — 'approved'/'rejected'/
    // 'forwarded' require the audited handleApprove()/handleReject()/sendReceiptToAccounting()
    // paths, which stamp approved_by/approved_at. A plain edit never downgrades or upgrades
    // past that boundary; anything outside the safe set falls back to whatever it already was.
    $requestedStatus = $input['status'] ?? $existing['status'];
    $status = in_array($requestedStatus, ['draft', 'pending_approval'], true)
        ? $requestedStatus
        : $existing['status'];

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

    // Through the expense gate (migration 1233): only the fields that changed are written and
    // audited; line items replaced when sent; the split by line kept in step (or set / ended
    // when the modal sends one); printed facts and the duplicate check re-run; line-item
    // lessons recorded (header / category lessons are recorded once, at approval or send,
    // against the capture baseline). A posted receipt is reversed and posted again.
    $changes = [
        'expense_date'        => $expenseDate,
        'vendor_id'           => !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null,
        'vendor_name_raw'     => $input['vendor_name_raw'] ?? null,
        'description'         => $input['description'] ?? null,
        'amount'              => (float)($input['amount'] ?? 0),
        'gst_amount'          => (float)($input['gst_amount'] ?? 0),
        'pst_amount'          => (float)($input['pst_amount'] ?? 0),
        'total'               => $total,
        'accounting_category' => $input['accounting_category'] ?? null,
        'gbp_category'        => $input['gbp_category'] ?? null,
        'payment_method'      => $input['payment_method'] ?? null,
        'receipt_media_id'    => !empty($input['receipt_media_id']) ? (int)$input['receipt_media_id'] : null,
        // The desktop edit form doesn't send match_confidence / property_id / contact_id —
        // absent keys keep the stored value instead of being wiped to 0/NULL on every edit.
        'match_confidence'    => array_key_exists('match_confidence', $input) ? (int)$input['match_confidence'] : (int)($existing['match_confidence'] ?? 0),
        'anomaly_flags'       => $anomalyFlags ?: null,
        'anomaly_score'       => $anomalyScore,
        'job_id'              => !empty($input['job_id']) ? (int)$input['job_id'] : null,
        'property_id'         => expenseKeptId($input, $existing, 'property_id'),
        'contact_id'          => expenseKeptId($input, $existing, 'contact_id'),
        'notes'               => $input['notes'] ?? null,
        'status'              => $status,
        'odometer_start'      => !empty($input['odometer_start']) ? (int)$input['odometer_start'] : null,
        'odometer_end'        => !empty($input['odometer_end']) ? (int)$input['odometer_end'] : null,
        'fuel_litres'         => !empty($input['fuel_litres']) ? (float)$input['fuel_litres'] : null,
        'fuel_price_per_litre'=> !empty($input['fuel_price_per_litre']) ? (float)$input['fuel_price_per_litre'] : null,
    ];
    if (isset($input['line_items']) && is_array($input['line_items'])) $changes['line_items'] = $input['line_items'];
    if (array_key_exists('split', $input)) {
        $choices = ExpenseSplitService::choices($input['split']);
        if ($choices !== null) $changes['allocations'] = $choices;
    }
    $res = expenseGate($db)->apply($id, $changes, ExpenseGate::actor($user), 'desktop_modal', [
        'learn_lines' => !empty($existing['raw_ocr_json']) ? $input : null,
        'price_intel' => true,
    ]);

    echo json_encode(['success' => true, 'message' => 'Expense updated', 'reposted' => !empty($res['reposted'])]);
}



/** The expense gate for this request — the one door for every expense change. */
function expenseGate(PDO $db): ExpenseGate
{
    static $gate = null;
    return $gate ?? ($gate = new ExpenseGate($db));
}

/** Sent → use it (empty clears it); absent → keep what's stored. */
function expenseKeptId(array $input, array $existing, string $key): ?int
{
    if (array_key_exists($key, $input)) {
        return !empty($input[$key]) ? (int)$input[$key] : null;
    }
    return !empty($existing[$key]) ? (int)$existing[$key] : null;
}

function handleDelete(PDO $db, ?array $input, array $user): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    // Shared with the iOS swipe-to-delete (expense-delete.php): owner-or-admin, and a
    // receipt already forwarded to accounting can't be deleted from either client.
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseService.php';
    $isAdmin = in_array($user['role'] ?? '', ['admin', 'manager'], true) || userHasPermission('expenses.approve');
    $result  = (new ExpenseService($db, expenseGate($db)))->delete(
        (int)($input['id'] ?? 0),
        ['id' => (int)$user['id'], 'is_admin' => $isAdmin, 'source' => 'desktop_delete']
    );

    echo json_encode($result);
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
    // Shared with the iOS review form (expense-lookup.php?type=duplicates).
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseLookupService.php';
    $duplicates = (new ExpenseLookupService($db))->findDuplicates(
        $_GET['vendor_name'] ?? null,
        !empty($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : null,
        isset($_GET['total']) ? (float)$_GET['total'] : null,
        $_GET['expense_date'] ?? null,
        !empty($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null
    );

    echo json_encode([
        'success'        => true,
        'has_duplicates' => count($duplicates) > 0,
        'duplicates'     => $duplicates,
    ]);
}


/**
 * POST {action:'merge', keep_id, discard_id, csrf_token}
 * Merge two duplicate expense records: keep one; the other is rejected "Merged into
 * receipt #N" and kept on record (never deleted — CRA six-year retention).
 * If the discarded expense has a receipt and the kept one doesn't,
 * the receipt is transferred before deletion.
 */
function handleMergeExpenses(PDO $db, ?array $input): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) throw new Exception('Invalid security token');

    $keepId    = (int)($input['keep_id']    ?? 0);
    $discardId = (int)($input['discard_id'] ?? 0);
    if (!$keepId || !$discardId) throw new Exception('keep_id and discard_id are required');
    if ($keepId === $discardId) throw new Exception('Cannot merge an expense with itself');

    // Fetch both expenses (full rows for per-field merge)
    // Ids are ints, so plain (unprepared) queries are safe here — and they avoid MySQL
    // 1615 "Prepared statement needs to be re-prepared", which this host raises when a
    // statement touches many tables (the DELETE cascades) and the table cache is short.
    $stmt = $db->query("SELECT * FROM expenses WHERE id IN (" . $keepId . ", " . $discardId . ")");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $keep    = null;
    $discard = null;
    foreach ($rows as $row) {
        if ((int)$row['id'] === $keepId)    $keep    = $row;
        if ((int)$row['id'] === $discardId) $discard = $row;
    }
    if (!$keep)    throw new Exception('Expense to keep (#' . $keepId . ') not found');
    if (!$discard) throw new Exception('Expense to delete (#' . $discardId . ') not found');

    $fields = $input['fields'] ?? [];

    // Per-field merge: apply selected values from the discard to the keep expense
    if (!empty($fields)) {
        // Map field keys to DB columns (some fields group multiple columns)
        $fieldMap = [
            'vendor'             => ['vendor_id', 'vendor_name_raw'],
            'expense_date'       => ['expense_date'],
            'total'              => ['total'],
            'amount'             => ['amount'],
            'gst'                => ['gst_amount'],
            'pst'                => ['pst_amount'],
            'accounting_category'=> ['accounting_category'],
            'payment_method'     => ['payment_method'],
            'job_id'             => ['job_id'],
            'description'        => ['description'],
            'notes'              => ['notes'],
            'receipt'            => ['receipt_media_id'],
        ];

        $merged = [];
        foreach ($fields as $key => $choice) {
            if ($choice !== 'discard' || !isset($fieldMap[$key])) continue;
            foreach ($fieldMap[$key] as $col) $merged[$col] = $discard[$col] ?? null;
        }

        // Through the expense gate (audited as the merge; MySQL 1615 re-prepare is the gate's).
        if ($merged) {
            expenseGate($db)->apply($keepId, $merged, ExpenseGate::actor(getCurrentUser()), 'desktop_merge');
        }
    } else {
        // Legacy behavior: transfer receipt if kept has none and discarded has one
        if (empty($keep['receipt_media_id']) && !empty($discard['receipt_media_id'])) {
            expenseGate($db)->apply($keepId, ['receipt_media_id' => (int)$discard['receipt_media_id']], ExpenseGate::actor(getCurrentUser()), 'desktop_merge');
        }
    }

    // Never delete (six-year record): the merged-away receipt is rejected "Merged into
    // receipt #N" and kept — through the gate's audited 'reject', which also reverses its
    // journal entry if it had already posted to the books.
    expenseGate($db)->apply($discardId, ['status' => 'rejected', 'rejection_reason' => 'Merged into receipt #' . $keepId],
        ExpenseGate::actor(getCurrentUser()), 'desktop_merge', ['transition' => 'reject', 'allow_locked' => true]);

    echo json_encode(['success' => true, 'message' => 'Merged: expense #' . $discardId . ' set aside (kept on record), #' . $keepId . ' kept.']);
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
        expenseGate($db)->apply($targetId, ['receipt_media_id' => $sourceMediaId], ExpenseGate::actor(getCurrentUser()), 'desktop_merge_receipt');
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

    // Shared with the iOS line-item endpoint (expense-line-items.php): links, trains the
    // vendor catalog, and records the SKU → product memory for the next receipt.
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseLineItemService.php';
    $newProductId = isset($input['product_id']) && $input['product_id'] !== null && $input['product_id'] !== ''
        ? (int)$input['product_id']
        : null;
    $updated = (new ExpenseLineItemService($db))->link((int)($input['line_item_id'] ?? 0), $newProductId);

    echo json_encode(['success' => true, 'line_item' => $updated]);
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

    $month = $_GET['month'] ?? date('Y-m');
    // Normalise to YYYY-MM
    $monthKey = date('Y-m', strtotime($month . '-01'));
    $monthStart = $monthKey . '-01';
    $monthEnd   = date('Y-m-t', strtotime($monthStart));

    // Get budget-configured variances first
    $variances = getAllBudgetVariances($monthKey, $db);

    // Also get actual spending by category for this month (regardless of budgets)
    // This powers the "no budget set" cards — show spend even without a limit
    $spendStmt = $db->prepare("
        SELECT accounting_category AS category,
               SUM(total) AS spent,
               COUNT(*) AS expense_count
        FROM expenses
        WHERE expense_date BETWEEN ? AND ?
          AND accounting_category IS NOT NULL
          AND accounting_category != ''
          AND status != 'rejected'
        GROUP BY accounting_category
        ORDER BY spent DESC
    ");
    $spendStmt->execute([$monthStart, $monthEnd]);
    $spendRows = $spendStmt->fetchAll(PDO::FETCH_ASSOC);

    // Merge: categories with budgets already have full variance info.
    // For categories without budgets, synthesize a "spend-only" card.
    $budgetCategories = array_column($variances, 'category');
    foreach ($spendRows as $row) {
        if (!in_array($row['category'], $budgetCategories, true)) {
            $variances[] = [
                'category'      => $row['category'],
                'budget'        => null,          // no limit set
                'spent'         => (float)$row['spent'],
                'remaining'     => null,
                'pct'           => null,
                'status'        => 'no_budget',
                'expense_count' => (int)$row['expense_count'],
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'month'   => $monthKey,
        'budgets' => $variances,
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

    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseApprovalService.php';
    $result = (new ExpenseApprovalService($db, expenseGate($db)))->approve((int)($input['id'] ?? 0), $user, ['source' => 'desktop_approval']);
    echo json_encode($result);
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

    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseApprovalService.php';
    $result = (new ExpenseApprovalService($db))->reject(
        (int)($input['id'] ?? 0),
        $user,
        (string)($input['rejection_reason'] ?? '')
    );
    echo json_encode($result);
}


/**
 * POST {action: 'batch_approve', expense_ids: [1,2,3]}
 * Approves multiple expenses in one call — mobile multi-select approval.
 */
function handleBatchApprove(PDO $db, ?array $input, array $user): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseApprovalService.php';
    $result = (new ExpenseApprovalService($db))->approveBatch($input['expense_ids'] ?? [], $user);
    echo json_encode($result);
}


/**
 * POST {action: 'batch_reject', expense_ids: [1,2,3], rejection_reason: '...'}
 * Rejects multiple expenses with the same reason — mobile multi-select rejection.
 */
function handleBatchReject(PDO $db, ?array $input, array $user): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseApprovalService.php';
    $result = (new ExpenseApprovalService($db))->rejectBatch(
        $input['expense_ids'] ?? [],
        $user,
        (string)($input['rejection_reason'] ?? '')
    );
    echo json_encode($result);
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


/**
 * GET ?action=margin_summary
 * Returns margin summary across all active jobs that have expenses.
 * Used by profitability_appstack.php dashboard.
 */
function handleMarginSummary(PDO $db): void
{
    require_once APP_ROOT . '/Services/Receipts/MarginTracker.php';
    $summary = getMarginSummary($db);
    echo json_encode(['success' => true, 'summary' => $summary]);
}

// ── QB Abstraction Layer handlers ──────────────────────────────────────────

function handleQbStatus(PDO $db): void
{
    require_once APP_ROOT . '/Services/QuickBooks/QBService.php';
    $status = qbGetStatus($db);
    echo json_encode(['success' => true, 'status' => $status]);
}

function handleBatchForward(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken(ApiResponse::csrfToken($input ?? []))) throw new Exception('Invalid security token');
    require_once APP_ROOT . '/Services/QuickBooks/QBService.php';
    // Only approved receipts are forwarded — qbForwardExpense() refuses the rest with the reason.

    $ids = $input['expense_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        throw new Exception('expense_ids array is required');
    }

    // Sanitize
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, fn($id) => $id > 0);
    $ids = array_values(array_unique($ids));

    if (count($ids) > 50) {
        throw new Exception('Maximum 50 expenses per batch');
    }

    $result = qbForwardBatch($ids, $db);
    echo json_encode(['success' => true] + $result);
}

function handleQbRetry(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken(ApiResponse::csrfToken($input ?? []))) throw new Exception('Invalid security token');
    require_once APP_ROOT . '/Services/QuickBooks/QBService.php';
    $result = qbRetryFailed($db);
    echo json_encode(['success' => true] + $result);
}


/**
 * GET ?action=search_jobs&q=SearchTerm
 * Returns matching job plans for autocomplete in the expense editor.
 * Searches plan_number, service_type, property address, and contact name.
 */
function handleSearchJobs(PDO $db): void
{
    // Shared with the iOS review form (expense-lookup.php?type=jobs). Rows now carry
    // property_id + contact_id so the mobile job pill can persist all three identifiers.
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseLookupService.php';
    $jobs = (new ExpenseLookupService($db))->searchJobs($_GET['q'] ?? '', isset($_GET['date']) ? (string)$_GET['date'] : null);

    echo json_encode(['success' => true, 'jobs' => $jobs]);
}


/**
 * Re-run OCR on an already-stored receipt image for an existing expense.
 * The image is already in media_assets — we just re-process it through
 * the full receipt pipeline and return the parsed results.
 */
function handleRescan(PDO $db, ?array $input, array $user): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }
    $expenseId = (int)($input['expense_id'] ?? 0);
    if (!$expenseId) throw new Exception('Expense ID required');
    assertCanEditExpense($db, $expenseId);
    // The pipeline lives in ExpenseRescanService (shared with the iPhone, 2026-10-11).
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseRescanService.php';
    echo json_encode((new ExpenseRescanService($db, expenseGate($db)))->rescan($expenseId, ExpenseGate::actor($user), 'desktop_rescan'));
}


/**
 * Add a single manual line item to an expense (office staff can type items
 * that OCR missed, then link them to products).
 */
function handleAddLineItem(PDO $db, ?array $input): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    assertCanEditExpense($db, (int)($input['expense_id'] ?? 0));
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseLineItemService.php';
    $item = (new ExpenseLineItemService($db, expenseGate($db)))->by(ExpenseGate::actor(getCurrentUser()), 'desktop_line')->add((int)($input['expense_id'] ?? 0), $input);

    echo json_encode(['success' => true, 'line_item' => $item]);
}


/**
 * Delete a single line item from an expense.
 */
function handleDeleteLineItem(PDO $db, ?array $input): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    assertCanEditExpense($db, lineItemExpenseId($db, (int)($input['line_item_id'] ?? 0)));
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseLineItemService.php';
    (new ExpenseLineItemService($db, expenseGate($db)))->by(ExpenseGate::actor(getCurrentUser()), 'desktop_line')->delete((int)($input['line_item_id'] ?? 0));

    echo json_encode(['success' => true]);
}


/**
 * Update a single line item's name/quantity/unit_price/line_total —
 * a correction path for OCR mistakes (mis-parsed discounts, wrong qty,
 * bad item name) that previously required delete + re-add.
 */
function handleUpdateLineItem(PDO $db, ?array $input): void
{
    if (!$input) throw new Exception('No data provided');
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    assertCanEditExpense($db, lineItemExpenseId($db, (int)($input['line_item_id'] ?? 0)));
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseLineItemService.php';
    $service = (new ExpenseLineItemService($db, expenseGate($db)))->by(ExpenseGate::actor(getCurrentUser()), 'desktop_line');
    $item = $service->update((int)($input['line_item_id'] ?? 0), $input);

    echo json_encode(['success' => true, 'line_item' => $item]);
}


// teachVendorProduct() now lives in app/Services/Receipts/ExpenseLineItems.php so the
// JWT line-item endpoint and ExpenseLineItemService share it with this file.
