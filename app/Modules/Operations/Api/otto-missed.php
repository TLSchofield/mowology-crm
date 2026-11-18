<?php
/**
 * Otto's end-of-day missed-visit review — web (dashboard card section, /crm/ops/missed-visits.php)
 * and Android (same web pages). The iPhone uses /api/schedule/missed-review (JWT).
 *
 * GET  ?mode=list[&review_id=N]
 * POST {mode: 'decide', item_id, choice: accept|tomorrow|date|skip|cancel, date?, reason?, note?, csrf_token}
 * POST {mode: 'accept_all', review_id?, csrf_token}
 * POST {mode: 'build', csrf_token}     open / top up today's review now (no push)
 *
 * schedule.edit only (admins and managers). ?mode=, never ?action=. All logic:
 * MissedVisitReviewService::handle(). Nothing here messages a client.
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
    requireLogin();
    if (!userHasPermission('schedule.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => "Otto's missed-visit review is for people who run the schedule (schedule.edit)."]);
        exit;
    }
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];
    $input = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — reload the page and try again.']);
        exit;
    }
    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Operations/Services/MissedVisitReviewService.php';
    [$status, $body] = (new MissedVisitReviewService($db))->handle($method, $input, (int)$user['id']);
    http_response_code($status);
    echo json_encode($body);
} catch (Throwable $e) {
    error_log('[otto-missed] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — nothing was changed that you did not see confirmed.']);
}
