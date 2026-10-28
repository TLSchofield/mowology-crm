<?php
/**
 * Agents — turn the desks' own lists into durable tasks, and tidy old ones (stage 4, Ittai audit 2026-10-27).
 *
 * Every run: ask Sam's, Yui's and Penny's desks what is waiting (quote follow-ups, unanswered client replies,
 * invoices >60 days overdue), create one task each (never twice — dedupe key), close tasks whose item has left the
 * list, expire tasks past their time limit, and reopen any stuck 'working'. Deterministic SQL only; no model call,
 * sends nothing, changes no client record. Needs migration 1402; before it runs this exits with a warning.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Agents/Cron/agent_detect.php
 * Web:  /crm/cron/agent_detect.php (admin only, via _guard.php)
 * cPanel schedule: every 15 minutes (3,18,33,48 * * * *)
 * Registry key: agent_detect (public/crm/database_appstack.php) — recordCronRun() on every exit.
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
    \App\Core\CronLock::acquire('agent_detect');
} else {
    header('Content-Type: application/json');
}

require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Modules/Agents/Services/AgentDetectors.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('agent_detect', $status, $summary,
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
    $db = getDB();
    try {
        $db->query("SELECT 1 FROM agent_tasks LIMIT 0");
    } catch (Throwable $e) {
        $__finish('warning', 'Migration 1402 not run yet — nothing to do');
        exit;
    }
    $tasks = new TaskService($db);
    $res = AgentDetectors::run($db, $tasks, date('Y-m-d H:i:s'));
    $expired = $tasks->expire();
    $reopened = $tasks->reapStuck();

    $parts = [];
    $failed = 0;
    foreach ($res as $kind => $r) {
        if (is_string($r)) {
            $failed++;
            $parts[] = "{$kind}: {$r}";
        } else {
            $parts[] = "{$kind}: {$r['seen']} listed, {$r['created']} new, {$r['closed']} closed";
        }
    }
    if ($expired) $parts[] = "{$expired} expired";
    if ($reopened) $parts[] = "{$reopened} stuck reopened";
    $__finish($failed ? 'warning' : 'success', implode(' · ', $parts));
} catch (Throwable $e) {
    error_log('agent_detect: ' . $e->getMessage());
    $__finish('error', 'agent_detect failed', $e->getMessage());
}
