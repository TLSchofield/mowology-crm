<?php
/**
 * Visit Edit API (session) — the writes the schedule Timeline modal needs that no other endpoint offers.
 *
 * POST JSON { action, visit_id | note_id, csrf_token, ... }
 *   cancel       visit_id                         → cancels ONE visit (never a completed, locked, invoiced or running one)
 *   note_edit    note_id, text                    → the author, or anyone with jobs.edit; system notes are refused
 *   note_delete  note_id
 *   plan_update  visit_id, title?, description?, price_per_visit? + confirm_price   → the job this visit belongs to
 *
 * Time, date, crew, adding a note, start, complete and skip use their existing endpoints
 * (calendar-edit.php, reschedule-visit.php, assign-crew.php, pow-actions.php). Needs jobs.edit.
 * All rules live in VisitEditService.
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
    require_once CRM_INCLUDES . '/plan-functions.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitLifecycleService.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitEditService.php';

    requireLogin();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        exit;
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];

    // CSRF is checked before the session is released, or verifyCSRFToken() always fails.
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCSRFToken(is_string($token) ? $token : '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Your page expired. Refresh the schedule and try again.']);
        exit;
    }
    requirePermission('jobs.edit');
    $user = getCurrentUser();
    session_write_close();

    $uid = (int)($user['id'] ?? 0);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $svc = new VisitEditService(getDB());
    $action = (string)($input['action'] ?? '');
    $visitId = (int)($input['visit_id'] ?? 0);
    $noteId = (int)($input['note_id'] ?? 0);

    switch ($action) {
        case 'cancel':
            $res = $visitId > 0 ? $svc->cancelVisit($visitId, $uid, $ip) : ['success' => false, 'error' => 'visit_id is required'];
            break;
        case 'note_edit':
            $res = $noteId > 0 ? $svc->editNote($noteId, $uid, true, $input['text'] ?? '', $ip) : ['success' => false, 'error' => 'note_id is required'];
            break;
        case 'note_delete':
            $res = $noteId > 0 ? $svc->deleteNote($noteId, $uid, true, $ip) : ['success' => false, 'error' => 'note_id is required'];
            break;
        case 'plan_update':
            $res = $visitId > 0 ? $svc->updatePlan($visitId, $uid, $input, $ip) : ['success' => false, 'error' => 'visit_id is required'];
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
            exit;
    }
    if (empty($res['success'])) {
        http_response_code(409);
    }
    echo json_encode($res);
} catch (Throwable $e) {
    error_log('visit-edit.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong on our side. Nothing was changed.']);
}
