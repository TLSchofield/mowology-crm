<?php
/**
 * Data Retention Cron — purge expired location data.
 * Runs daily at 3:00 AM.
 *
 * cPanel cron: 0 3 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Privacy/Cron/data_retention.php
 *
 * Can also be triggered via web (admin only):
 *   POST /crm/api/privacy.php?action=run_purge
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');

// Bootstrap
$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once APP_ROOT . '/Core/config.php';

if (!$isCli) {
    // Web mode: require admin login
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    requirePermission('database.manage');
    session_write_close();
    header('Content-Type: application/json');
}

require_once APP_ROOT . '/Modules/Privacy/Services/PrivacyService.php';
// recordCronRun() lives here; the CLI bootstrap (config.php only) doesn't load it.
require_once APP_ROOT . '/Services/CrmFunctions.php';

// Every run is recorded in cron_runs (Ada, 2026-10-15: this cron never was, so its runs couldn't be checked).
$cronStart = microtime(true);
$logRun = static function (string $status, string $summary, ?string $error = null) use ($cronStart, $isCli): void {
    if (!function_exists('recordCronRun')) return;
    try {
        recordCronRun('data_retention', $status, $summary, (int)round((microtime(true) - $cronStart) * 1000), $error, !$isCli);
    } catch (\Throwable $e) {
        error_log('data_retention: could not record the run — ' . $e->getMessage());
    }
};

$db  = getDB();
$svc = new PrivacyService($db);

try {
    $result = $svc->purgeExpiredLocationData();

    // Log to cron_runs_log if available
    try {
        $db->prepare("
            INSERT INTO cron_runs_log (cron_name, status, message, created_at)
            VALUES ('privacy_purge', 'success', ?, NOW())
        ")->execute([json_encode($result)]);
    } catch (\Throwable $logErr) {
        // cron_runs_log may not exist — non-fatal but log so schema drift is visible
        error_log(sprintf('[%s] cron_runs_log insert (privacy_purge success) failed — %s in %s:%d',
            basename(__FILE__), $logErr->getMessage(), $logErr->getFile(), $logErr->getLine()));
    }

    $logRun('success', sprintf('Deleted: %d crew location, %d visit GPS, %d audit log rows',
        (int)($result['crew_location_deleted'] ?? 0), (int)($result['visit_gps_deleted'] ?? 0), (int)($result['audit_log_deleted'] ?? 0)));

    if ($isCli) {
        echo "Privacy purge complete:\n";
        echo "  crew_location_history: {$result['crew_location_deleted']} rows deleted\n";
        echo "  visit_gps_points:      {$result['visit_gps_deleted']} rows deleted\n";
        echo "  visit_audit_log:       {$result['audit_log_deleted']} rows deleted\n";
        echo "  Purged at: {$result['purged_at']}\n";
    } else {
        echo json_encode(['success' => true, 'result' => $result]);
    }
} catch (\Throwable $e) {
    $errMsg = $e->getMessage();

    try {
        $db->prepare("
            INSERT INTO cron_runs_log (cron_name, status, message, created_at)
            VALUES ('privacy_purge', 'error', ?, NOW())
        ")->execute([$errMsg]);
    } catch (\Throwable $logErr) {
        // cron_runs_log may not exist — non-fatal but log so schema drift is visible
        error_log(sprintf('[%s] cron_runs_log insert (privacy_purge error) failed — %s in %s:%d',
            basename(__FILE__), $logErr->getMessage(), $logErr->getFile(), $logErr->getLine()));
    }

    $logRun('error', 'Privacy purge failed', $errMsg);

    if ($isCli) {
        fwrite(STDERR, "Privacy purge error: {$errMsg}\n");
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Purge failed — see server logs']);
    }
}
