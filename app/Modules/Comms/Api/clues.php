<?php
/**
 * The clues check — facts Penny and Yui spotted in payments and mail, as one-click fixes.
 *
 * GET  ?mode=list[&owner=penny|yui][&contact_id=N]   open clues for the card / contact page
 * POST {mode: 'apply'|'dismiss', id, csrf_token}     Tim's decision. Apply performs exactly the
 *                     stored change (guarded by the values it saw — if the record changed since,
 *                     nothing happens); "Not right" dismisses it for good. Both are recorded and
 *                     logged to activity_log; the pattern counters learn from them.
 *
 * Reading needs billing.edit (same as Yui's card); deciding needs an admin. ?mode=, not ?action=
 * (the /api/ router appends its own action). Nothing changes a record without Tim's click.
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
    requirePermission('billing.edit');
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'list');

    if ($method === 'POST') {
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Only an admin can apply or dismiss a clue']);
            exit;
        }
    }

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Comms/Services/ClueService.php';
    $clues = new ClueService($db);
    if (!$clues->ready()) {
        echo json_encode(['ok' => false, 'error' => 'Migration 1206 has not run yet']);
        exit;
    }

    switch ($mode) {
        case 'list': {
            $owner = in_array($_GET['owner'] ?? '', [ClueService::HEAD_PENNY, ClueService::HEAD_YUI], true) ? (string)$_GET['owner'] : null;
            $contact = isset($_GET['contact_id']) ? (int)$_GET['contact_id'] : null;
            $rows = $clues->open($owner, $contact ?: null, 25);
            echo json_encode(['ok' => true, 'can_decide' => isAdmin(), 'clues' => array_map([ClueService::class, 'forCard'], $rows)]);
            break;
        }

        case 'apply':
        case 'dismiss': {
            $id = (int)($input['id'] ?? 0);
            $res = $clues->decide($id, $mode, (int)$user['id']);
            if (!empty($res['ok'])) {
                logActivity((int)$user['id'], $res['contact_id'] ?? null, $mode === 'apply' ? 'clue_applied' : 'clue_dismissed',
                    'Clue #' . $id . ' (' . ($res['kind'] ?? '') . '): ' . mb_substr((string)($res['summary'] ?? ''), 0, 400));
            }
            echo json_encode(['ok' => !empty($res['ok']), 'message' => $res['message'] ?? '']);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[clues] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The clues check hit a snag — try again.']);
}
