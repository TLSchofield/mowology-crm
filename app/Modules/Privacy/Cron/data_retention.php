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
        // cron_runs_log may not exist — silently skip
    }

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
    } catch (\Throwable $ignored) {}

    if ($isCli) {
        fwrite(STDERR, "Privacy purge error: {$errMsg}\n");
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Purge failed — see server logs']);
    }
}
