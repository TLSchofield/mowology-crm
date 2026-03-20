<?php
/**
 * Accounting Ledger — Nightly Sync Cron
 *
 * Runs AccountingService::syncAll() to pull new paid invoices and expenses
 * into accounting_transactions, then runs AlertEngine::runAll() to refresh
 * financial intelligence alerts.
 *
 * Recommended cPanel cron schedule: 0 2 * * *  (2 AM daily)
 * Command: /usr/local/bin/php /home/mowology/public_html/app/Modules/Accounting/Cron/sync-ledger.php
 *
 * Logs to cron_runs_log table (silently skips if table doesn't exist).
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

// Allow CLI execution without a web session
if (php_sapi_name() !== 'cli') {
    // Web request — require admin session
    requireLogin();
    $u = getCurrentUser();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    header('Content-Type: application/json');
}

require_once APP_ROOT . '/Modules/Accounting/Services/AccountingService.php';
require_once APP_ROOT . '/Modules/Accounting/Services/RulesEngine.php';
require_once APP_ROOT . '/Modules/Accounting/Services/AlertEngine.php';

$db       = getDB();
$output   = [];
$success  = true;

try {
    // ── 1. Sync invoices + expenses into unified ledger ────────────────────────
    $svc    = new AccountingService($db);
    $sync   = $svc->syncAll();
    $output = array_merge($output, $sync);

    // ── 2. Run alert engine ────────────────────────────────────────────────────
    $alerts      = new AlertEngine($db);
    $alertResult = $alerts->runAll();
    $output['alerts_active'] = count($alertResult);

    // ── 3. Log to cron_runs_log ────────────────────────────────────────────────
    $elapsed = round(microtime(true) - $cronStart, 3);
    $output['elapsed_seconds'] = $elapsed;

    try {
        $db->prepare("
            INSERT INTO cron_runs_log (cron_name, status, output, duration_ms, ran_at)
            VALUES ('accounting_sync', 'success', ?, ?, NOW())
        ")->execute([
            json_encode($output),
            (int)($elapsed * 1000),
        ]);
    } catch (PDOException $logE) {
        // cron_runs_log may not exist — silently ignore
    }

} catch (Throwable $e) {
    $success = false;
    $output['error'] = $e->getMessage();

    try {
        $db->prepare("
            INSERT INTO cron_runs_log (cron_name, status, output, ran_at)
            VALUES ('accounting_sync', 'error', ?, NOW())
        ")->execute([json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()])]);
    } catch (PDOException $logE) { /* ignore */ }
}

// ── Output ─────────────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    $status = $success ? 'OK' : 'ERROR';
    echo "[accounting_sync] $status — " . json_encode($output) . "\n";
} else {
    echo json_encode(['ok' => $success, 'result' => $output]);
}
