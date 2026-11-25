<?php
/**
 * The clues check, daily: Penny and Yui read e-Transfers, bank lines that paid an invoice and
 * inbound customer mail for facts nobody entered — a strata plan paying a "single-family" home,
 * a job title and firm in a signature, a phone number the contact doesn't have, an accountant
 * set up as the quote signer — and store them as suggestions (clue_suggestions). The first run
 * looks back 90 days; later runs read what came in since (with a week's overlap). Nothing here
 * changes a CRM record: every fix waits for Tim's Apply on Yui's card.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Comms/Cron/clues_daily.php
 * Web:  /crm/cron/clues_daily.php (admin only, via _guard.php)
 * cPanel schedule: daily 6:10 am — 10 6 * * *
 * Registry key: clues_daily (public/crm/database_appstack.php). Needs migration 1206.
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
    \App\Core\CronLock::acquire('clues_daily');
} else {
    header('Content-Type: application/json');
}
@set_time_limit(180);

require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Comms/Services/ClueService.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('clues_daily', $status, $summary,
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
    $svc = new ClueService(getDB());
    if (!$svc->ready()) {
        $__finish('warning', 'Migration 1206 not run yet — nothing to do');
        exit;
    }
    $r = $svc->scan();
    $__finish('success', sprintf('Since %s: read %d e-Transfer(s), %d bank line(s), %d email(s) — %d clue(s), %d new',
        substr($r['since'], 0, 10), $r['etransfer'], $r['bank'], $r['email'], $r['found'], $r['new']));
} catch (Throwable $e) {
    error_log('[clues_daily] ' . $e->getMessage());
    $__finish('error', 'Clues check failed', $e->getMessage());
}
