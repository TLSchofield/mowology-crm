<?php
/**
 * Keep iCloud tidy — files NEW INBOX mail older than 7 days into Tim's folder plan, by the
 * same rules as the "Tidy iCloud" page (MailTidyService::keepTidy).
 *
 * OFF by default: does nothing until Tim turns on "Keep it tidy" on /crm/icloud_tidy_appstack.php,
 * and nothing until a first tidy has been applied. MOVE only (ImapWriter), every move logged and
 * undoable from the page. Unsure mail stays in INBOX. The regular icloud_inbox_poll stays read-only.
 * Needs migration 1226.
 *
 * Cron (every 15 min) — schedule in cPanel as:
 *   11,26,41,56 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Comms/Cron/icloud_tidy_keep.php
 * Web (admin): /crm/cron/icloud_tidy_keep.php        Registry key: icloud_tidy_keep
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

$isCli = (PHP_SAPI === 'cli');
if ($isCli) {
    require_once APP_ROOT . '/Core/CronLock.php';
    \App\Core\CronLock::acquire('icloud_tidy_keep');
} else {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    header('Content-Type: application/json; charset=utf-8');
}
@set_time_limit(240);

require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Services/Mail/MailboxConfig.php';
require_once APP_ROOT . '/Modules/Comms/Services/MailTidyService.php';
require_once APP_ROOT . '/Modules/Comms/Services/ImapTidyPort.php';

const ICLOUD_TIDY_KEY = 'icloud_tidy_keep';
$startMs = (int)(microtime(true) * 1000);
$log = [];
$add = function (string $m) use (&$log): void { $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $m; };
$finish = function (string $status, string $summary, ?string $error = null) use (&$log, $startMs, $isCli): void {
    recordCronRun(ICLOUD_TIDY_KEY, $status, $summary, (int)(microtime(true) * 1000) - $startMs, $error, !$isCli);
    if ($isCli) { echo implode("\n", $log) . "\n"; exit($status === 'error' ? 1 : 0); }
    echo json_encode(['success' => $status !== 'error', 'message' => $summary, 'log' => $log]);
    exit;
};

$db = getDB();
$offline = new MailTidyService($db);
if (!$offline->ready()) { $add('Migration 1226 not run.'); $finish('warning', 'Migration 1226 not run.'); }
if ($offline->setting('keep_tidy') !== '1') { $add('Keep it tidy is off.'); $finish('success', 'Keep it tidy is off.'); }
$mb = MailboxConfig::icloud();
if ($mb === null || !function_exists('imap_open')) { $add('iCloud not configured.'); $finish('warning', 'iCloud not configured.'); }

try {
    $port = new ImapTidyPort($mb, $add);
    $res = (new MailTidyService($db, $port, ['log' => $add]))->keepTidy(180.0, 500);
    $port->close();
} catch (Throwable $e) {
    $add('ERROR: ' . $e->getMessage());
    $finish('error', 'Keep it tidy failed.', $e->getMessage());
}
$add($res['message']);
$finish($res['ok'] ? 'success' : 'warning', $res['message']);
