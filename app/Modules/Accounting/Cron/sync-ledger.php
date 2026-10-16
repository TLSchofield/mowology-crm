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
// Under cron (CLI) nothing else defines getDB()/Database — the web shim gets them from auth.php.
require_once APP_ROOT . '/Core/config.php';

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
// recordCronRun() lives here and is NOT pulled in by the CLI bootstrap
// chain (paths.php → loginAuth/auth.php). Without this require the cron
// fatals at the end with "Call to undefined function recordCronRun()"
// AFTER doing the sync — which is why it logged "Last run: Never run".
require_once APP_ROOT . '/Services/CrmFunctions.php';

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

    // ── 2b. Approved debit/card receipts with no statement line (Tim, 2026-10-15) ──
    // Waits for the statement; links a clear line; notes duplicates; otherwise back to draft.
    try {
        require_once APP_ROOT . '/Modules/Accounting/Services/ReceiptBankCheck.php';
        $output['receipt_bank_check'] = (new ReceiptBankCheck($db))->run(true)['counts'];
        // Receipts flagged DATE_UNREADABLE: their bank lines may have arrived since (ReceiptDateCheck).
        try {
            require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptDateCheck.php';
            $output['receipt_date_check'] = (new ReceiptDateCheck($db))->sweep();
        } catch (Throwable $e) {
            $output['receipt_date_check'] = ['error' => $e->getMessage()];
        }
    } catch (Throwable $e) {
        $output['receipt_bank_check_error'] = $e->getMessage();
        error_log('[accounting_sync] receipt bank check: ' . $e->getMessage());
    }

    // ── 3. Books vs bank + quiet signals (Charlie emails what's new) ───────────
    try {
        require_once APP_ROOT . '/Modules/Accounting/Services/BooksWatchService.php';
        $output['books_watch'] = count((new BooksWatchService($db))->run()['alerts']);
    } catch (Throwable $e) {
        $output['books_watch_error'] = $e->getMessage();
        error_log('[accounting_sync] books watch: ' . $e->getMessage());
    }

} catch (Throwable $e) {
    $success = false;
    $output['error'] = $e->getMessage();
}

$durationMs = (int)(round(microtime(true) - $cronStart, 3) * 1000);
$summary    = 'Invoices: ' . ($output['invoices_synced'] ?? 0)
            . ', Expenses: ' . ($output['expenses_synced'] ?? 0)
            . ', Alerts: ' . ($output['alerts_active'] ?? 0);
// The journal half of syncAll() catches its own exception — report it, never as 'success'.
$status  = $success ? 'success' : 'error';
$errText = $success ? null : ($output['error'] ?? null);
if ($success && ($jp = AccountingService::journalProblem($output['journal'] ?? null))) {
    [$status, $errText] = $jp;
    $summary .= ' — ' . $errText;
    if ($status === 'error') $success = false;
}
// Defensive: a missing run-tracker must never fatal the actual sync.
if (function_exists('recordCronRun')) {
    recordCronRun(
        'sync_ledger',
        $status,
        $summary,
        $durationMs,
        $errText,
        php_sapi_name() !== 'cli'
    );
} else {
    error_log('[accounting_sync] recordCronRun() unavailable — ' . ($success ? 'OK' : 'ERROR') . ' ' . $summary);
}

// ── Output ─────────────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    $status = $success ? 'OK' : 'ERROR';
    echo "[accounting_sync] $status — " . json_encode($output) . "\n";
} else {
    echo json_encode(['ok' => $success, 'result' => $output]);
}
