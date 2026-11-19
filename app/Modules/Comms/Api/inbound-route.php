<?php
/**
 * Inbound mail routing — admin endpoint (InboundRouteService). Thin: every rule lives in the service.
 *
 * POST {mode: 'move', to: penny|sam|otto|mia|yui, key? | message_key? | contact_id?, csrf_token}
 *      "Move to…" on a message card (the Action Board row's key: sam|yui|penny|otto|mia:reply:…
 *      or sam:contact:c<id>). Re-routes it and teaches sender + topic → head.
 *      → {ok, message, from, head, topic, taught}
 * POST {mode: 'done', key? | message_key?, csrf_token}   The message leaves every head's list.
 * GET  ?mode=messages&head=penny|otto|mia                That head's routed messages (Penny's as tasks,
 *      with attachment links).
 * GET  ?mode=reroute[&days=60]                           DRY RUN: inbound mail of the last N days still on
 *      Sam (or never stamped) that is billing — what would move to Penny. Nothing changes.
 * POST {mode: 'reroute_apply', days?, csrf_token}         Apply that list (logged in inbound_route_moves).
 * GET  ?mode=attachment&id=N                              Stream one kept attachment (inline).
 *
 * Admin only (the owner's mail). ?mode=, never ?action=. Penny never fills in or sends banking
 * details — there is no "send" here.
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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Admins only']);
        exit;
    }
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? '');

    if ($method === 'POST' && !verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $db = getDB();
    session_write_close();
    require_once APP_ROOT . '/Modules/Comms/Services/InboundRouteService.php';
    $svc = new InboundRouteService($db);
    $uid = (int)($user['id'] ?? 0);
    $ref = [
        'key'         => substr((string)($input['key'] ?? ''), 0, 120),
        'message_key' => substr((string)($input['message_key'] ?? ''), 0, 191),
        'contact_id'  => (int)($input['contact_id'] ?? 0),
    ];

    if ($mode === 'attachment') {
        $a = $svc->attachments()->find((int)($_GET['id'] ?? 0));
        if (!$a) { http_response_code(404); header('Content-Type: text/plain'); echo 'Not found'; exit; }
        InboundAttachmentService::stream($a);
        exit;
    }

    header('Content-Type: application/json');
    switch ($mode) {
        case 'move':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            echo json_encode($svc->move($ref, (string)($input['to'] ?? ''), $uid));
            break;

        case 'done':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            echo json_encode($svc->done($ref, $uid));
            break;

        case 'messages':
            echo json_encode(['ok' => true, 'messages' => $svc->messages(strtolower((string)($_GET['head'] ?? 'penny')))]);
            break;

        case 'reroute':
            echo json_encode($svc->reroute(false, $uid, (int)($_GET['days'] ?? InboundRouteService::REROUTE_DAYS)));
            break;

        case 'reroute_apply':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            echo json_encode($svc->reroute(true, $uid, (int)($input['days'] ?? InboundRouteService::REROUTE_DAYS)));
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[inbound-route] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Routing hit a snag — try again.']);
}
