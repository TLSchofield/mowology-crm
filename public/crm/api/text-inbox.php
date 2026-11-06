<?php
/**
 * Texts from numbers that are not a customer yet — JSON for the panel on Yui's card (2026-10-22).
 * Logic: TextBridgeService (migration 1396). Owner only; CSRF on every POST. `mode`, never `action`.
 *
 * GET  ?mode=list                       waiting numbers plus texted contacts with no work on file
 * GET  ?mode=search&q=…                 customers to link a number to
 * POST {mode:'link', phone10, contact_id, replace?}   put the number on that customer; their texts move across
 * POST {mode:'personal', phone10}       delete these texts and never bring this number in again
 * POST {mode:'personal_contact', contact_id, confirmed?} delete a saved personal contact's texts and block its numbers
 * POST {mode:'keep_contact', contact_id} stop offering a saved contact for privacy review
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Owner only']);
    exit;
}
require_once APP_ROOT . '/Modules/Sales/Services/TextBridgeService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
$mode   = (string)($input['mode'] ?? 'list');
$user   = getCurrentUser();

if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Your session token is stale — reload the page.']);
    exit;
}
session_write_close();

try {
    $svc = new TextBridgeService(getDB());
    if (!$svc->inboxReady()) {
        echo json_encode(['ok' => false, 'error' => 'Run migration 1396 first (texts from numbers we do not know).']);
        exit;
    }
    if ($method === 'GET' && $mode === 'list') {
        $svc->adoptUnmatched();   // a number Tim just added to a customer by hand
        echo json_encode(['ok' => true, 'count' => $svc->unknownCount(), 'threads' => $svc->unknownThreads(),
                          'contacts' => $svc->textedWithoutWork()]);
    } elseif ($method === 'GET' && $mode === 'search') {
        echo json_encode(['ok' => true, 'results' => $svc->searchContacts((string)($input['q'] ?? ''))]);
    } elseif ($method === 'POST' && $mode === 'link') {
        $replace = isset($input['replace']) ? (string)$input['replace'] : null;
        echo json_encode($svc->linkToContact((string)($input['phone10'] ?? ''), (int)($input['contact_id'] ?? 0), $replace));
    } elseif ($method === 'POST' && $mode === 'personal') {
        echo json_encode($svc->markPersonal((string)($input['phone10'] ?? ''), (int)($user['id'] ?? 0)));
    } elseif ($method === 'POST' && $mode === 'personal_contact') {
        echo json_encode($svc->markContactPersonal((int)($input['contact_id'] ?? 0), (int)($user['id'] ?? 0), (bool)($input['confirmed'] ?? false)));
    } elseif ($method === 'POST' && $mode === 'keep_contact') {
        echo json_encode($svc->keepContact((int)($input['contact_id'] ?? 0)));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[text-inbox] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
