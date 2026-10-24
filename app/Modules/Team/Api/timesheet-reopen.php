<?php
/**
 * Reopen an approved timesheet so its hours can be changed (JSON, for the week view).
 * POST JSON: { timesheet_id, reason, csrf_token }  →  { success } | { success:false, error }
 * Admin/manager only; never your own week; a reason is required. Logic: TimesheetReopenService.
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Team/Services/TimesheetReopenService.php';

    requireLogin();
    $actor = getCurrentUser();
    if (!in_array($actor['role'] ?? '', ['admin', 'manager'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }
    $body = json_decode((string)file_get_contents('php://input'), true) ?? [];
    if (!verifyCSRFToken((string)($body['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }
    session_write_close();
    $err = TimesheetReopenService::reopen(getDB(), (int)($body['timesheet_id'] ?? 0), $actor, (string)($body['reason'] ?? ''));
    if ($err !== null) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('[timesheet-reopen] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Nothing was changed.']);
}
