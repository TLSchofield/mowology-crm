<?php
/**
 * GET/POST /api/schedule/special-requests — special requests on a visit, for the iOS app (JWT).
 *
 * GET  ?mode=visits&ids=1,2,3   → { success, enabled, requests: { "<visit_id>": [request, …] } }
 * POST { mode: 'ack', request_visit_id }                                   "Got it"
 * POST { mode: 'outcome', request_visit_id, outcome: done|not_done|extra_done,
 *        reason?, extra_description?, extra_minutes? }
 *
 * enabled:false and no requests unless ops_settings.special_requests_enabled = '1' (and, while
 * special_requests_user_ids is set, only for those users) — the app shows nothing then.
 * ?mode=, never ?action= (the /api/ rewrite owns `action`).
 */
declare(strict_types=1);

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
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once APP_ROOT . '/Modules/Operations/Services/SpecialRequestService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $svc     = new SpecialRequestService(getDB());
    $on      = $svc->ready() && $svc->appliesToUser($userId);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))), 0, 200);
        echo json_encode([
            'success'  => true,
            'enabled'  => $on,
            'requests' => $on ? (object)$svc->forVisits($ids, $userId) : (object)[],
        ]);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $mode  = (string)($input['mode'] ?? '');
    $srvId = (int)($input['request_visit_id'] ?? 0);

    if ($mode === 'ack') {
        $r = $svc->ack($srvId, $userId, 'ios');
    } elseif ($mode === 'outcome') {
        $r = $svc->outcome($srvId, $userId, (string)($input['outcome'] ?? ''), (string)($input['reason'] ?? ''),
            (string)($input['extra_description'] ?? ''), (int)($input['extra_minutes'] ?? 0));
    } else {
        $r = ['ok' => false, 'error' => 'Unknown mode.'];
    }
    if (!$r['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $r['error'] ?? 'Could not save.']);
        exit;
    }
    echo json_encode(['success' => true, 'request' => $r['request']]);
} catch (Throwable $e) {
    error_log('schedule/special-requests: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error — try again.']);
}
