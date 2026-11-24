<?php
/**
 * Otto — supplier stops on the receipt editor (migration 1359, SupplierStopService / SupplierJobSuggestService).
 *
 * GET  ?mode=receipt&id=N   The supplier stop this receipt came from ("LAWNBOY Oct 7 11:27–11:34 · Nigel,
 *                           truck") and, for a material receipt with no job, Otto's job pick with his
 *                           reason (shadow mode: nothing is set until a person taps).
 * POST {mode: 'confirm', id, plan_id, csrf_token}   put the receipt on that job (through the expense gate)
 * POST {mode: 'reject', id, csrf_token}             "Not this job" — Otto learns, nothing changes
 *
 * Office only (admins / managers / expenses.approve): stops come from crew GPS. ?mode=, never ?action=.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseService.php';
    require_once APP_ROOT . '/Core/ApiResponse.php';
    requireLogin();
    $user = getCurrentUser() ?: [];
    $method = $_SERVER['REQUEST_METHOD'];
    $input = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? '');
    $csrfOk = $method !== 'POST' || verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    session_write_close();

    $office = userHasPermission('expenses.view')
        && ExpenseService::isOffice((string)($user['role'] ?? ''), userHasPermission('expenses.approve'));
    if (!$office) {
        // Crew GPS is shown to the office only — everyone else simply sees nothing.
        echo json_encode(['ok' => true, 'stop' => null, 'suggestion' => null]);
        exit;
    }
    if (!$csrfOk) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid security token — reload the page and try again.']);
        exit;
    }

    $db = getDB();
    require_once APP_ROOT . '/Modules/Operations/Services/SupplierJobSuggestService.php';
    $svc = new SupplierJobSuggestService($db);
    $id = (int)($method === 'POST' ? ($input['id'] ?? 0) : ($_GET['id'] ?? 0));
    if ($id <= 0) throw new InvalidArgumentException('Receipt id required');

    if ($method === 'GET' && $mode === 'receipt') {
        echo json_encode(['ok' => true] + $svc->forEditor($id));
        exit;
    }
    if ($method !== 'POST') throw new InvalidArgumentException('Unknown mode');
    if (!userHasPermission('expenses.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You need expenses.edit to change a receipt.']);
        exit;
    }
    $st = $db->prepare("SELECT id, created_by, status, forwarded_to_accounting FROM expenses WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Receipt not found');
    if (($why = ExpenseService::editBlockReason($row, ['id' => (int)($user['id'] ?? 0), 'is_admin' => true])) !== null) {
        echo json_encode(['ok' => false, 'error' => $why]);
        exit;
    }
    if ($mode === 'confirm') {
        $res = $svc->confirm($id, (int)($input['plan_id'] ?? 0), $user);
    } elseif ($mode === 'reject') {
        $res = $svc->reject($id, (int)($user['id'] ?? 0) ?: null);
    } else {
        throw new InvalidArgumentException('Unknown mode');
    }
    echo json_encode($res + ($res['ok'] ? [] : ['error' => $res['message'] ?? 'Not saved']));
} catch (Throwable $e) {
    http_response_code(ApiResponse::isSafe($e) ? 400 : 500);
    echo json_encode(['ok' => false, 'error' => ApiResponse::message($e, 'supplier-stops.php')]);
}
