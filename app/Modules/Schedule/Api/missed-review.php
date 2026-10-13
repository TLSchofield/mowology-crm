<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/missed-review.php — Otto's end-of-day missed-visit review for the iPhone.
 *
 * GET  /api/schedule/missed-review?mode=list[&review_id=N]
 *   → {ok, review{id,date,label,opened_by,item_count}|null, waiting, with_pick, items[], reasons[], tomorrow, today}
 * POST /api/schedule/missed-review {mode: decide, item_id, choice: accept|tomorrow|date|skip|cancel, date?, reason?, note?}
 * POST /api/schedule/missed-review {mode: accept_all, review_id?}
 * POST /api/schedule/missed-review {mode: build}
 *
 * Exactly the web's /crm/api/otto-missed.php — both call MissedVisitReviewService::handle().
 * Auth: Bearer JWT + schedule.edit (admins, managers, the truck logins). Crew get 403.
 * ?mode=, never ?action= (the /api/ router appends its own action).
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    $jwtUser = requireJwt();
    if (!jwtUserHasPermission($jwtUser, 'schedule.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => "Otto's missed-visit review is for people who run the schedule."]);
        exit;
    }
    $method = $_SERVER['REQUEST_METHOD'];
    $input = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
    unset($input['action'], $input['module']);   // the /api/ router's own parameters

    require_once APP_ROOT . '/Modules/Operations/Services/MissedVisitReviewService.php';
    [$status, $body] = (new MissedVisitReviewService(getDB()))->handle($method, $input, (int)$jwtUser['id']);
    http_response_code($status);
    echo json_encode($body);
} catch (Throwable $e) {
    error_log('[missed-review] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong. Pull to refresh.']);
}
