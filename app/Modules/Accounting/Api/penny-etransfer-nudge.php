<?php
/**
 * Penny's "please e-Transfer to info@" thank-you — read-only preview.
 *
 * GET ?mode=dryrun[&days=60]   Recent claim-type (security-answer) e-Transfers: would
 *                              each trigger the email, to whom, and the rendered text.
 *                              Sends nothing, writes nothing.
 *
 * The live send happens inside EtransferInboxService (ingest / record / merge) —
 * see EtransferNudgeService. Kill switch: ops_settings.penny_etransfer_nudge_enabled.
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
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || ($_GET['mode'] ?? '') !== 'dryrun') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Use GET ?mode=dryrun']);
        exit;
    }

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Accounting/Services/EtransferInboxService.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/EtransferNudgeService.php';
    $svc  = new EtransferNudgeService($db);
    $rows = $svc->dryRun((int)($_GET['days'] ?? 60));

    echo json_encode([
        'ok'          => true,
        'enabled'     => $svc->enabled(),
        'already_sent'=> $svc->countSent(),
        'would_send'  => count(array_filter($rows, fn($r) => $r['would_send'])),
        'transfers'   => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[penny-etransfer-nudge] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
