<?php
/**
 * Otto + Penny — price yesterday's dump / supplier runs from the truck trail.
 *
 * Splits the day's Trackimo pings into stops and drives (TripSegmentService), prices each
 * overhead stop (TripCostService: driver's rate + burden, km × truck_cost_per_km, matching
 * receipts) and stores it in ops_trip_runs. Idempotent: re-running a date rewrites its rows
 * and keeps the owner's one-man / two-man toggles.
 * Then (migration 1218) Penny tags each run to the job that caused it (TripAttributionService) and
 * Otto recomputes the shared cost facts Sam reads (CostFactsService::refresh).
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
    $tot = ['stored' => 0, 'removed' => 0, 'open' => 0, 'unnamed' => 0, 'days' => 0];
    for ($t = strtotime($__from); $t <= strtotime($__to); $t = strtotime('+1 day', $t)) {
        $r = $svc->process(date('Y-m-d', $t));
        foreach (['stored', 'removed', 'open', 'unnamed'] as $k) $tot[$k] += $r[$k];
        $tot['days']++;
    }
    // Shared facts (migration 1218): Penny tags the runs to jobs, then Otto recomputes the medians
    // the other heads read. Additive — a failure here never loses the priced runs above.
    $__facts = '';
    try {
        require_once APP_ROOT . '/Modules/Expenses/Services/TripAttributionService.php';
        require_once APP_ROOT . '/Modules/Operations/Services/CostFactsService.php';
        $attr = new TripAttributionService(getDB());
        $cf = new CostFactsService(getDB());
        if ($attr->ready() && $cf->ready()) {
            $at = ['rows' => 0, 'tagged' => 0];
            for ($t = strtotime($__from); $t <= strtotime($__to); $t = strtotime('+1 day', $t)) {
                $a = $attr->attribute(date('Y-m-d', $t));
                $at['rows'] += $a['rows'];
                $at['tagged'] += $a['tagged'];
            }
            $f = $cf->refresh();
            $__facts = "; {$at['rows']} job cost line(s), {$at['tagged']} receipt(s) tagged to a job, {$f['facts']} cost fact(s)";
        } else {
            $__facts = '; cost facts need migration 1218';
        }
    } catch (Throwable $e) {
        error_log('[trip_runs_daily] cost facts: ' . $e->getMessage());
        $__facts = '; cost facts failed: ' . $e->getMessage();
    }
    $span = $__from === $__to ? $__from : "{$__from} → {$__to} ({$tot['days']} days)";
    $__finish('success', "{$span}: {$tot['stored']} overhead stop(s) priced, {$tot['removed']} removed, "
        . "{$tot['unnamed']} unnamed stop(s) to name" . ($tot['open'] ? ", {$tot['open']} still away" : '') . $__facts);
} catch (Throwable $e) {
    error_log('[trip_runs_daily] ' . $e->getMessage());
    $__finish('error', 'Cron failed', $e->getMessage());
}
