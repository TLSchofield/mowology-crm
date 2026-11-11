<?php
/**
 * Payer aliases API — the client page's "Also pays as" (migration 1362, PayerIdentityService).
 *
 * GET  ?payer=<name>&contact_id=&company_id=   — live aliases for this payer
 * POST {action:'add', kind, alias, payer, contact_id?, company_id?}   — the owner's word (source 'tim')
 * POST {action:'remove', id}                                          — kept as removed, never relearned
 *
 * Reading needs billing.view; changing needs billing.edit (admins and managers). CSRF on writes.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once APP_ROOT . '/Core/ApiResponse.php';
require_once APP_ROOT . '/Modules/Accounting/Services/PayerIdentityService.php';

requireLogin();
requirePermission('billing.view');
$user = getCurrentUser();
session_write_close();

try {
    $svc = new PayerIdentityService(getDB());
    if (!$svc->ready()) throw new Exception('Payer aliases are not set up yet (migration 1362).', 503);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $payer = trim((string)($_GET['payer'] ?? ''));
        if ($payer === '') throw new Exception('payer required', 400);
        echo json_encode(['ok' => true, 'aliases' => $svc->aliasesFor($payer, (int)($_GET['contact_id'] ?? 0) ?: null, (int)($_GET['company_id'] ?? 0) ?: null)]);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
    $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '');
    if ($csrf === '' || !verifyCSRFToken($csrf)) throw new Exception('Your session expired — reload the page.', 403);
    if (!userHasPermission('billing.edit')) throw new Exception('Only the office (billing) can change who pays as whom.', 403);

    $action = (string)($input['action'] ?? '');
    if ($action === 'add') {
        $kind = (string)($input['kind'] ?? '');
        if (!in_array($kind, PayerIdentityService::KINDS, true)) throw new Exception('Unknown kind of alias.', 400);
        $alias = trim((string)($input['alias'] ?? ''));
        $payer = trim((string)($input['payer'] ?? ''));
        if ($alias === '' || $payer === '') throw new Exception('Type the wording the bank or Interac uses.', 400);
        $id = $svc->learn($kind, $alias, $payer, 'tim',
            ['contact_id' => (int)($input['contact_id'] ?? 0) ?: null, 'company_id' => (int)($input['company_id'] ?? 0) ?: null],
            'Added by ' . trim((string)($user['first_name'] ?? $user['username'] ?? 'the office')), (int)$user['id']);
        if ($id === null) throw new Exception('That wording is too short to match safely (3 letters or more).', 400);
        echo json_encode(['ok' => true, 'id' => $id]);
    } elseif ($action === 'remove') {
        echo json_encode(['ok' => $svc->remove((int)($input['id'] ?? 0))]);
    } else {
        throw new Exception('Unknown action', 400);
    }
} catch (Throwable $e) {
    http_response_code(ApiResponse::status($e));
    echo json_encode(['ok' => false, 'error' => ApiResponse::message($e, 'payer-aliases.php')]);
}
