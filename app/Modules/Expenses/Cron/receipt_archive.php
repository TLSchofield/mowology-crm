<?php
/**
 * Receipt Archive — Monthly Cron
 *
 * Bundles all confirmed expense receipts older than the configured age gate
 * (ops_settings receipt_archive_after_days, default 90) into ZIP(s), emails them to the
 * configured recipients, then removes the originals from the web disk (keeping a
 * thumbnail) once delivery is confirmed. Reclaims disk used by accumulated receipt images.
 *
 * Recommended cPanel cron schedule: 0 3 2 * *  (3 AM on the 2nd of each month)
 * Command: /usr/local/bin/php /home/mowology/public_html/app/Modules/Expenses/Cron/receipt_archive.php
 *
 * Records every run in cron_runs via recordCronRun('receipt_archive', …) like the other crons
 * (it used to write to a cron_runs_log table that doesn't exist — review round 2, 2026-10-12).
 */
declare(strict_types=1);

$cronStart = microtime(true);

// ── Bootstrap ──────────────────────────────────────────────────────────────────
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
// Under cron (CLI) nothing else defines getDB()/Database — the web shim gets them from auth.php.
require_once APP_ROOT . '/Core/config.php';

require_once PUBLIC_ROOT . '/loginAuth/auth.php';

// Allow CLI execution without a web session; web requires an admin session.
if (php_sapi_name() !== 'cli') {
    requireLogin();
    $u = getCurrentUser();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    header('Content-Type: application/json');
}

require_once CRM_INCLUDES . '/messaging.php';
require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptArchiveService.php';
// recordCronRun() is not pulled in by the CLI bootstrap chain (Known-Failure-Patterns).
require_once APP_ROOT . '/Services/CrmFunctions.php';

$db      = getDB();
$output  = [];
$success = true;

try {
    @set_time_limit(0);
    $svc    = new ReceiptArchiveService($db);
    // Open range (null,null) — the service applies the configured age gate.
    $output = $svc->run(null, null, 'cron', null);

    $elapsed = round(microtime(true) - $cronStart, 3);
    $output['elapsed_seconds'] = $elapsed;
} catch (Throwable $e) {
    $success = false;
    $output['error'] = $e->getMessage();
}

// Every exit path records (a silent no-op is what misleads — Known-Failure-Patterns).
$st      = (string)($output['status'] ?? '');
$cronSt  = !$success || $st === 'email_failed' ? 'error' : (in_array($st, ['no_recipients', 'no_files'], true) ? 'warning' : 'success');
$summary = $success
    ? ('Status: ' . ($st ?: 'done') . ', archived: ' . (int)($output['archived'] ?? 0))
    : 'Failed: ' . $output['error'];
if (function_exists('recordCronRun')) {
    recordCronRun('receipt_archive', $cronSt, $summary, (int)round((microtime(true) - $cronStart) * 1000),
        $cronSt === 'success' ? null : ($output['error'] ?? $st), php_sapi_name() !== 'cli');
} else {
    error_log('[receipt_archive] recordCronRun() unavailable — ' . $summary);
}

// ── Output ─────────────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    $status = $success ? 'OK' : 'ERROR';
    echo "[receipt_archive] $status — " . json_encode($output) . "\n";
} else {
    echo json_encode(['ok' => $success, 'result' => $output]);
}
