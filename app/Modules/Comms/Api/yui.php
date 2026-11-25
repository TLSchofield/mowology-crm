<?php
/**
 * Yui, the comms / client relations head — dashboard card endpoint.
 *
 * GET  ?mode=desk     Her five sections (inbox, promises, accounts, renewals, arrears), each
 *                     sendable item with a suggested email + text, the thread for replies,
 *                     her numbers and how many Claude drafts are left today.
 * GET  ?mode=jobs     Her decisions as one queue of cards (YuiJobAdapter).
 * GET  ?mode=brief    Her brief for Charlie (same contract as every head).
 * POST {mode: 'draft', key, csrf_token}     Claude drafts a reply to a client message — Tim's
 *                     click only, inbox items only, capped per day.
 * POST {mode: 'send', key, channel: email|sms, subject, body, suggested_body, drafted_by, csrf_token}
 *                     Tim sends (CRM messaging functions only). Yui never sends on her own.
 * POST {mode: 'handled', key, csrf_token}   Off the list (and dismissed in Charlie).
 * "Who is this?" (mail from people not on file; desk gains a top-level `who`, never inside `sections`):
 * POST {mode: 'who_confirm', id, client_id, first_name, last_name} / {mode: 'who_not', id} / {mode: 'who_undo', id}
 * GET  ?mode=who_accounts&q=   active accounts by name, for "Another account…".
 *
 * The item is always re-read on the server (key → YuiDeskService::find()), so the recipient
 * comes from the CRM, never from the browser.
 * ?mode=, not ?action= (see the /api/ router note in the vault). Permission: billing.edit
 * (same as Sam's card).
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
    require_once CRM_INCLUDES . '/messaging.php';
    requireLogin();
    requirePermission('billing.edit');
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'desk');

    if ($method === 'POST' && !verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Comms/Services/YuiDeskService.php';
    require_once APP_ROOT . '/Modules/Comms/Services/YuiActions.php';
    $name = SalesDeskService::ownerName((array)$user);

    if ($mode === 'brief') {
        $desk = new YuiDeskService($db);
        echo json_encode(['ok' => true] + $desk->brief($name));
        exit;
    }
    $out = YuiActions::dispatch($db, $mode, $input + ['q' => (string)($_GET['q'] ?? '')], (array)$user, $name);
    if (isset($out['_status'])) { http_response_code((int)$out['_status']); unset($out['_status']); }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('[yui] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Yui hit a snag — try again.']);
}
