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
 * Logs to cron_runs_log table (silently skips if the table doesn't exist).
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

    try {
        $db->prepare("
            INSERT INTO cron_runs_log (cron_name, status, output, duration_ms, ran_at)
            VALUES ('receipt_archive', 'success', ?, ?, NOW())
        ")->execute([json_encode($output), (int) ($elapsed * 1000)]);
    } catch (PDOException $logE) {
        // cron_runs_log may not exist — silently ignore
    }

} catch (Throwable $e) {
    $success = false;
    $output['error'] = $e->getMessage();

    try {
        $db->prepare("
            INSERT INTO cron_runs_log (cron_name, status, output, ran_at)
            VALUES ('receipt_archive', 'error', ?, NOW())
        ")->execute([json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()])]);
    } catch (PDOException $logE) { /* ignore */ }
}

// ── Output ─────────────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    $status = $success ? 'OK' : 'ERROR';
    echo "[receipt_archive] $status — " . json_encode($output) . "\n";
} else {
    echo json_encode(['ok' => $success, 'result' => $output]);
}
