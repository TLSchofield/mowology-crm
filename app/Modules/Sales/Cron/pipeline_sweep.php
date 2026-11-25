<?php
/**
 * Sam — nightly pipeline stage sweep (daily 3:40 AM).
 *
 * Re-derives contacts.lifecycle_stage (and companies.lifecycle_stage) from the owner-approved
 * rules in PipelineStageService: client / opportunity / inactive / lost / lead. Event hooks
 * (contract created, quote sent / accepted, invoice paid, job plan created) keep stages current
 * during the day; this catches everything else — above all, clients going quiet after 12 months.
 * Never touches a pinned (hand-set) stage or a custom kanban stage.
 *
 * Cron — schedule in cPanel as:
 *   40 3 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Sales/Cron/pipeline_sweep.php
 * Preview without writing:   ... pipeline_sweep.php --dry-run   (or /crm/api/pipeline-sweep.php?mode=dry_run)
 * Also runnable in a browser by an admin: /crm/cron/pipeline_sweep.php (add ?dry_run=1 to preview)
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
require_once APP_ROOT . '/Modules/Sales/Services/PipelineStageService.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    header('Content-Type: application/json; charset=utf-8');
}

$dryRun = $isCli
    ? in_array('--dry-run', $argv ?? [], true)
    : !empty($_GET['dry_run']);

$startMs = (int)(microtime(true) * 1000);
$status = 'success';
$error = null;
$result = null;
try {
    $result = (new PipelineStageService(getDB()))->sweep(null, $dryRun);
    $summary = ($dryRun ? '[dry run] ' : '') . PipelineStageService::summarize($result);
    if (($result['contacts']['errors'] ?? 0) + ($result['companies']['errors'] ?? 0) > 0) $status = 'warning';
} catch (Throwable $e) {
    $status = 'error';
    $error = $e->getMessage();
    $summary = 'Sweep failed';
    error_log('[pipeline_sweep] ' . $error);
}

// A dry run is a preview, not a run — it must not make the dashboard read "ran last night".
if (!$dryRun && function_exists('recordCronRun')) {
    recordCronRun('pipeline_sweep', $status, $summary, (int)(microtime(true) * 1000) - $startMs, $error, !$isCli);
}
if ($isCli) {
    echo '[pipeline_sweep] ' . ($error ?? $summary) . "\n";
} else {
    $counts = [];
    if ($result) {
        foreach (['contacts', 'companies'] as $k) {
            $t = $result[$k];
            unset($t['samples']);
            $counts[$k] = $t;
        }
    }
    echo json_encode(['success' => $status !== 'error', 'dry_run' => $dryRun, 'summary' => $summary, 'counts' => $counts, 'error' => $error]);
}
