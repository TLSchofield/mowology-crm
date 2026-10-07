<?php
/**
 * Yui, the comms / client relations head — dashboard card endpoint.
 *
 * GET  ?mode=desk     Her five sections (inbox, promises, accounts, renewals, arrears), each
 *                     sendable item with a suggested email + text, the thread for replies,
 *                     her numbers and how many Claude drafts are left today.
 * GET  ?mode=brief    Her brief for Charlie (same contract as every head).
 * POST {mode: 'draft', key, csrf_token}     Claude drafts a reply to a client message — Tim's
 *                     click only, inbox items only, capped per day.
 * POST {mode: 'send', key, channel: email|sms, subject, body, suggested_body, drafted_by, csrf_token}
 *                     Tim sends (CRM messaging functions only). Yui never sends on her own.
 * POST {mode: 'handled', key, csrf_token}   Off the list (and dismissed in Charlie).
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
    require_once APP_ROOT . '/Modules/Comms/Services/YuiDraftService.php';
    $desk = new YuiDeskService($db);
    $name = SalesDeskService::ownerName((array)$user);

    if ($mode === 'brief') {
        echo json_encode(['ok' => true] + $desk->brief($name));
        exit;
    }
    if (!$desk->ready()) {
        echo json_encode(['ok' => false, 'error' => 'Migration 1191 has not run yet']);
        exit;
    }
    $drafts = new YuiDraftService($db);
    $key = substr((string)($input['key'] ?? ''), 0, 120);
    $gone = ['ok' => false, 'message' => 'That one is no longer on my list — refresh.'];

    switch ($mode) {
        case 'desk':
            echo json_encode(['ok' => true, 'name' => $name] + $desk->desk($name));
            break;

        case 'draft': {
            $item = $desk->find($key, $name);
            echo json_encode($item ? $drafts->draftReply($item, $name, (int)$user['id']) : $gone);
            break;
        }

        case 'send': {
            $item = $desk->find($key, $name);
            echo json_encode($item ? $drafts->send($item, $input, (array)$user, $name) : $gone);
            break;
        }

        case 'handled': {
            if (strpos($key, 'yui:') !== 0) { echo json_encode($gone); break; }
            $item = $desk->find($key, $name);
            echo json_encode($drafts->handled($key, (string)($item['kind'] ?? ''), isset($item['contact_id']) ? (int)$item['contact_id'] : null, (array)$user));
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[yui] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Yui hit a snag — try again.']);
}
