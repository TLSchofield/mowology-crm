<?php
/**
 * Seasonal Outlook Refresh — Cron Job
 * /crm/cron/seasonal_outlook_refresh.php  (shim)  →  this file
 *
 * Runs daily. Rebuilds the winter outlook numbers from NOAA CPC's ONI index and
 * Environment Canada's Vancouver Intl A station record, and stores the result in
 * ops_settings for the dashboard and Weather Actions card to read.
 *
 * The daily cadence is for the SEASON-TO-DATE ACTUALS, not the projection: ONI
 * updates monthly and the 30-winter climatology is cached for a year. Once
 * November starts, each run refreshes how many frost nights and snow days have
 * actually occurred against what was projected, so a wrong outlook is visible
 * while the season is still running.
 *
 * Thin controller by design — all logic lives in SeasonalOutlookRefreshService.
 *
 * CLI: php /home/mowology/app/Modules/Jobs/Cron/seasonal_outlook_refresh.php
 *      --force-climatology   rebuild the 30-year baseline instead of using cache
 * Web: POST /crm/cron/seasonal_outlook_refresh.php  (admin only, via _guard.php)
 *
 * Exit codes: 0 success, 1 refresh failed (previous payload left intact).
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

$isCli = (PHP_SAPI === 'cli');

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

if (!$isCli) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'POST method required']);
        exit;
    }
    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
    header('Content-Type: application/json');
}

require_once APP_ROOT . '/Modules/Jobs/Services/SeasonalOutlookService.php';
require_once APP_ROOT . '/Modules/Jobs/Services/SeasonalOutlookRefreshService.php';

$force = $isCli
    ? in_array('--force-climatology', $argv ?? [], true)
    : !empty($_POST['force_climatology']);

$started = microtime(true);

try {
    $service = new SeasonalOutlookRefreshService(getDB());
    $result  = $service->refresh(null, $force);
} catch (Throwable $e) {
    // An unexpected throw must not look like a successful no-op.
    $result = ['ok' => false, 'reason' => 'exception: ' . $e->getMessage(), 'log' => []];
    error_log('[seasonal_outlook_refresh] ' . $e->getMessage());
}

$result['duration_s'] = round(microtime(true) - $started, 2);

if ($isCli) {
    $stamp = date('Y-m-d H:i:s');
    echo "[{$stamp}] seasonal_outlook_refresh: " . ($result['ok'] ? 'OK' : 'FAILED') . "\n";
    foreach (($result['log'] ?? []) as $line) {
        echo "  - {$line}\n";
    }
    if ($result['ok']) {
        echo "  winter {$result['winter']} | {$result['enso']} (ONI {$result['oni']})"
           . " | analogs: " . implode(', ', $result['analogs'])
           . " | climatology {$result['climatology']}"
           . " | {$result['months']} months, {$result['actuals']} with actuals"
           . " | {$result['duration_s']}s\n";
    } else {
        echo "  reason: {$result['reason']} (previous outlook left unchanged)\n";
    }
    exit($result['ok'] ? 0 : 1);
}

echo json_encode(['success' => (bool) $result['ok']] + $result);
