<?php
/**
 * Tasks for the phone: /api/tasks/tasks-mobile (Add → Task, 2026-11-08).
 *
 * GET                                    → { success, people: [{id, name}], clients: [{contact_id, name, property_id, address}] }
 *                                          kept on the phone for no-signal use; clients only with clients.view
 * POST { title, description?, due_date?(Y-m-d), assigned_to?, contact_id?, property_id?, priority?, client_request_id }
 *                                        → { success, task_id } or { success: false, error }
 *
 * Authorization: Bearer <jwt>. No client = a task for the business ("pick up salt").
 * `client_request_id` is made once on the phone and replayed by the offline outbox (CreateOutbox), so a resend whose
 * first answer was lost never makes a second task.
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
header('X-Content-Type-Options: nosniff');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once APP_ROOT . '/Core/IdempotencyHelper.php';
    require_once APP_ROOT . '/Modules/Tasks/Services/TaskCreateService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $db      = getDB();
    $service = new TaskCreateService($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'success' => true,
            'people'  => $service->people($jwtUser),
            'clients' => jwtUserHasPermission($jwtUser, 'clients.view') ? $service->directory() : [],
        ]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $idempKey = trim((string)($input['client_request_id'] ?? ''));
    if ($idempKey !== '') {
        $cached = idempotencyCheck($db, $idempKey, $userId);
        if ($cached !== null) { echo $cached; exit; }
    }

    $result = $service->create($input, $jwtUser);
    if (empty($result['success'])) {
        http_response_code((int)($result['status'] ?? 422));
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Could not save the task.']);
        exit;
    }

    $json = json_encode($result);
    if ($idempKey !== '') {
        idempotencyStore($db, $idempKey, $userId, 'tasks-mobile', 'create', $json, 720);
    }
    echo $json;
} catch (Throwable $e) {
    error_log('tasks/tasks-mobile API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
