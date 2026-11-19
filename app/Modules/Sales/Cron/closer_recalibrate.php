<?php
/**
 * Sam the Closer — weekly recalibration (Mondays 4:15 AM).
 *
 * Refits minutes per service from Tim's own timed, measured visits (drive and purchase timer
 * entries dropped, one person's overlapping entries merged, cluster-apportioned visits left
 * out) and records the margin actually earned per service over the last 56 days. A service
 * uses its fit only once it has closer_calibration_min (15) good visits.
 *
 * Writes closer_site_models 'fit' rows only. It never changes a rate or a price — drift is
 * shown on the rate card for Tim to act on.
 *
 * Cron — schedule in cPanel as:
 *   15 4 * * 1 /usr/local/bin/php /home/mowology/public_html/app/Modules/Sales/Cron/closer_recalibrate.php
 * Also runnable in a browser by an admin: /crm/cron/closer_recalibrate.php
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
require_once APP_ROOT . '/Core/config.php';
require_once CRM_INCLUDES . '/functions.php';          // brings CrmFunctions.php → recordCronRun()
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Modules/Sales/Services/CloserService.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    header('Content-Type: application/json; charset=utf-8');
}

$startMs = (int)(microtime(true) * 1000);
$lines = [];
$status = 'success';
$error = null;
try {
    $closer = new CloserService(getDB());
    if (!$closer->ready()) {
        $lines[] = 'Migration 1141 has not run — nothing to do';
    } else {
        foreach ($closer->recalibrate() as $svc => $s) {
            $lines[] = sprintf('%s n=%d %s mae=%s%% realised=%s%%', $svc, $s['n'],
                $s['calibrated'] ? 'calibrated' : 'below threshold', $s['mae_pct'] ?? '-', $s['realised_margin_pct'] ?? '-');
        }
        if (!$lines) $lines[] = 'No usable timed visits yet';
    }
} catch (Throwable $e) {
    $status = 'error';
    $error = $e->getMessage();
    error_log('[closer_recalibrate] ' . $error);
}
$summary = implode('; ', $lines);
if (function_exists('recordCronRun')) {
    recordCronRun('closer_recalibrate', $status, $summary, (int)(microtime(true) * 1000) - $startMs, $error, !$isCli);
}
if ($isCli) {
    echo '[closer_recalibrate] ' . ($error ?? $summary) . "\n";
} else {
    echo json_encode(['success' => $status === 'success', 'summary' => $lines, 'error' => $error]);
}
