<?php
/**
 * Otto + Penny — price yesterday's dump / supplier runs from the truck trail.
 *
 * Splits the day's Trackimo pings into stops and drives (TripSegmentService), prices each
 * overhead stop (TripCostService: driver's rate + burden, km × truck_cost_per_km, matching
 * receipts) and stores it in ops_trip_runs.
 * First Otto asks Penny (StopEvidenceService::learnPlaces): the last 120 days of supplier / dump /
 * fuel receipts are lined up with the truck stops they came from, and every vendor seen at the same
 * spot on 2+ days becomes a named place — so stops are named from history, not by Tim. Idempotent: re-running a date rewrites its rows
 * and keeps the owner's one-man / two-man toggles.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Operations/Cron/trip_runs_daily.php [YYYY-MM-DD [YYYY-MM-DD]]
 *       one date, or a from–to range for a backfill (max 120 days). Default: yesterday.
 * Web:  /crm/cron/trip_runs_daily.php (admin only, via _guard.php) ?date=YYYY-MM-DD[&to=YYYY-MM-DD]
 * cPanel schedule: daily at 1:20 AM (20 1 * * *).
 * Registry key: trip_runs_daily (public/crm/database_appstack.php) — recordCronRun() on every exit.
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
    \App\Core\CronLock::acquire('trip_runs_daily');
} else {
    header('Content-Type: application/json');
}

require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/CrmFunctions.php';   // recordCronRun() — the CLI bootstrap does not load it
require_once APP_ROOT . '/Modules/Operations/Services/TripCostService.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('trip_runs_daily', $status, $summary,
            (int)round((microtime(true) - $__cronStart) * 1000), $error, !$__cli);
    }
    if ($__cli) {
        echo '[' . date('H:i:s') . "] {$summary}" . ($error ? " — {$error}" : '') . "\n";
    } else {
        if ($status === 'error') http_response_code(500);
        echo json_encode(['success' => $status !== 'error', 'summary' => $summary]);
    }
};

$__args = $__cli
    ? array_values(array_slice($argv ?? [], 1))
    : array_values(array_filter([(string)($_GET['date'] ?? ''), (string)($_GET['to'] ?? '')]));
$__from = $__args[0] ?? date('Y-m-d', strtotime('-1 day'));
$__to = $__args[1] ?? $__from;
foreach ([$__from, $__to] as $__d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $__d) || !strtotime($__d)) {
        $__finish('error', 'Bad date', 'Expected YYYY-MM-DD, got ' . $__d);
        exit(1);
    }
}
if ($__to < $__from || (strtotime($__to) - strtotime($__from)) / 86400 > 120) {
    $__finish('error', 'Bad range', 'From must not be after to, and at most 120 days');
    exit(1);
}

try {
    $svc = new TripCostService(getDB());
    if (!$svc->ready()) {
        $__finish('warning', 'Migration 1216 not run yet — nothing to do');
        exit;
    }
    $learned = ['created' => []];
    try {
        $learned = $svc->ev->learnPlaces(120);
    } catch (Throwable $e) {
        error_log('[trip_runs_daily] learnPlaces: ' . $e->getMessage());   // pricing still runs
    }
    $tot = ['stored' => 0, 'removed' => 0, 'open' => 0, 'unnamed' => 0, 'days' => 0];
    for ($t = strtotime($__from); $t <= strtotime($__to); $t = strtotime('+1 day', $t)) {
        $r = $svc->process(date('Y-m-d', $t));
        foreach (['stored', 'removed', 'open', 'unnamed'] as $k) $tot[$k] += $r[$k];
        $tot['days']++;
    }
    $span = $__from === $__to ? $__from : "{$__from} → {$__to} ({$tot['days']} days)";
    $__finish('success', "{$span}: {$tot['stored']} overhead stop(s) priced, {$tot['removed']} removed, "
        . "{$tot['unnamed']} unnamed stop(s) to name" . ($tot['open'] ? ", {$tot['open']} still away" : '')
        . ($learned['created'] ? '; Penny named ' . count($learned['created']) . ' place(s) from past receipts: '
            . implode(', ', array_column($learned['created'], 'name')) : ''));
} catch (Throwable $e) {
    error_log('[trip_runs_daily] ' . $e->getMessage());
    $__finish('error', 'Cron failed', $e->getMessage());
}
