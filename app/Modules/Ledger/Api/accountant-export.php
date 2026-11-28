<?php
/**
 * GET /api/ledger/accountant-export?period=this_month|last_month|this_year|last_year   (JWT, ledger.view)
 *
 * "Prepare for my accountant" for the apps: the journal for the period as an .xlsx download
 * (Content-Disposition attachment, filename <business>-for-accountant-<from>-<to>.xlsx). The same file the web
 * Ledger home gives (ledger/export.php?report=accountant). Receipts are not included. Errors are JSON:
 * 400 unknown period · 401 not signed in · 403 no ledger.view. Needs a signal: nothing is queued.
 * Each file sent is one activity_log 'ledger.export' row.
 */
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
    unset($__dir, $__i);
}

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Ledger/Services/FiscalYear.php';
    require_once APP_ROOT . '/Modules/Ledger/Services/JournalBrowserService.php';
    require_once APP_ROOT . '/Modules/Ledger/Services/LedgerExport.php';
    require_once APP_ROOT . '/Modules/Ledger/Services/AccountantExport.php';

    $jwtUser = requireJwt();
    $json = static function (int $code, string $msg): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    };
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') $json(405, 'GET only');
    if (!jwtUserHasPermission($jwtUser, 'ledger.view')) $json(403, 'Permission denied: ledger.view required');

    $db = getDB();
    $p = AccountantExport::period((string)($_GET['period'] ?? 'last_year'), date('Y-m-d'), FiscalYear::setting($db));
    if ($p === null) $json(400, 'Unknown period');
    [$from, $to] = $p;

    LedgerExport::$actingUser = (int)$jwtUser['id'];
    $entity = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower(LedgerExport::entity($db)));
    $t = AccountantExport::table(new JournalBrowserService($db), JournalBrowserService::normalizeFilters(['from' => $from, 'to' => $to]), $from, $to);
    LedgerExport::sendTable($db, $t, 'xlsx', "$entity-for-accountant-$from-$to", 'accountant', ['from' => $from, 'to' => $to]);
} catch (Throwable $e) {
    error_log('[ledger/accountant-export] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
    echo json_encode(['success' => false, 'error' => 'Could not prepare the file. Try again.']);
}
