<?php
/**
 * Admin web shim: re-run invoice-number extraction + matching against
 * already-ingested pending e-Transfer notifications. Needed after a parser
 * improvement (e.g. recognising "invoice 2026-0308" as well as
 * "INV-2026-0308") since matching only runs once, at ingest time.
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Admin only']); exit; }

header('Content-Type: application/json; charset=utf-8');

require_once APP_ROOT . '/Modules/Accounting/Services/EtransferInboxService.php';

$db      = getDB();
$service = new EtransferInboxService($db);
$result  = $service->rematchPending();

echo json_encode(['success' => true] + $result);
