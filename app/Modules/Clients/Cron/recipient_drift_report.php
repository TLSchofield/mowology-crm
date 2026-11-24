<?php
/**
 * Recipient drift report, nightly and READ-ONLY: counts the properties/invoices/quotes/plans with no client and the
 * legacy customer links that disagree (RecipientDriftReport). Writes nothing but its own cron_runs line. Why: the
 * clients-only account migration starts only when these numbers are zero (Decision-Log, 2026-11-23).
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Clients/Cron/recipient_drift_report.php
 * Web:  /crm/cron/recipient_drift_report.php (admin only, via _guard.php)
 * Schedule: daily 6:05 am — 5 6 * * * (per business, via BusinessCrons). Registry key: recipient_drift_report.
 */

declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
$__cronStart = microtime(true);
require_once APP_ROOT . '/Core/config.php';

$__cli = php_sapi_name() === 'cli';
if ($__cli) {
    require_once APP_ROOT . '/Core/CronLock.php';
    \App\Core\CronLock::acquire('recipient_drift_report');
} else {
    header('Content-Type: application/json');
}
@set_time_limit(120);

require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Clients/Services/RecipientDriftReport.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('recipient_drift_report', $status, $summary,
            (int)round((microtime(true) - $__cronStart) * 1000), $error, !$__cli);
    }
    if ($__cli) {
        echo '[' . date('H:i:s') . "] {$summary}" . ($error ? " — {$error}" : '') . "\n";
    } else {
        if ($status === 'error') http_response_code(500);
        echo json_encode(['success' => $status !== 'error', 'summary' => $summary]);
    }
};

try {
    $report  = RecipientDriftReport::run(getDB());
    $summary = RecipientDriftReport::summary($report);
    $errors  = array_filter($report, static fn($r) => isset($r['error']));
    // Drift is a finding, not a failure: 'warning' when anything disagrees, so the Cron Jobs page shows it.
    $nonZero = array_filter($report, static fn($r) => ($r['count'] ?? 0) > 0);
    $__finish(($errors || $nonZero) ? 'warning' : 'success', $summary, $errors ? 'a check failed: ' . implode(',', array_keys($errors)) : null);
} catch (Throwable $e) {
    error_log('[recipient_drift_report] ' . $e->getMessage());
    $__finish('error', 'Drift report failed', $e->getMessage());
}
