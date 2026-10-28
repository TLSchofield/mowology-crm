<?php
/**
 * Agents — run the eval cases and store the scores (stage 5, Ittai audit 2026-10-27).
 *
 * The cases (app/Modules/Agents/Services/AgentEvals.php) are fixed questions with known answers, run against the
 * heads' real decision code: no model call, no database read, sends nothing, changes nothing but one row per skill
 * in agent_eval_runs (migration 1403). The Agent Studio shows the latest score per skill. A drop means a change made a
 * head worse at something it used to get right: that is the cue to look before raising any autonomy level.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Agents/Cron/agent_eval.php
 * Web:  /crm/cron/agent_eval.php (admin only, via _guard.php)
 * cPanel schedule: daily at 4:40 AM (40 4 * * *)
 * Registry key: agent_eval (public/crm/database_appstack.php) — recordCronRun() on every exit.
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
    \App\Core\CronLock::acquire('agent_eval');
} else {
    header('Content-Type: application/json');
}

require_once APP_ROOT . '/Services/CrmFunctions.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('agent_eval', $status, $summary,
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
        $db->query("SELECT 1 FROM agent_eval_runs LIMIT 0");
    } catch (Throwable $e) {
        $__finish('warning', 'Migration 1403 not run yet — nothing to do');
        exit;
    }
    // The classes the cases exercise (none of them autoloads).
    require_once APP_ROOT . '/Modules/Agents/Services/AgentEvals.php';
    require_once APP_ROOT . '/Services/Copy/DraftSafety.php';
    require_once APP_ROOT . '/Modules/Sales/Services/UnclaimedReplyService.php';
    require_once APP_ROOT . '/Services/HeadLessons.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/PennyLessons.php';
    require_once APP_ROOT . '/Modules/Marketing/Services/ArticleGeneratorService.php';

    $summary = AgentEvals::summarise(AgentEvals::run());
    $written = AgentEvals::record($db, $summary);
    $cases = array_sum(array_column($summary, 'cases'));
    $passed = array_sum(array_column($summary, 'passed'));
    $bad = [];
    foreach ($summary as $skill => $v) {
        if ($v['failures']) $bad[] = $skill . ' (' . implode('; ', $v['failures']) . ')';
    }
    $text = "{$passed} of {$cases} cases passed across " . count($summary) . " skills; {$written} scores stored";
    $__finish($bad ? 'warning' : 'success', $bad ? $text . ' — failing: ' . implode(' | ', $bad) : $text);
} catch (Throwable $e) {
    error_log('agent_eval: ' . $e->getMessage());
    $__finish('error', 'agent_eval failed', $e->getMessage());
    if ($__cli) {
        exit(1);
    }
}
