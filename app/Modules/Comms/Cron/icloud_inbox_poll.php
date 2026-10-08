<?php
/**
 * iCloud inbox poll — Tim's mowology@icloud.com as a read-only source for the heads
 * (IcloudInboxRouter): customer mail → Sam/Yui's history, Interac / Yardi notices → Penny's
 * payment matching, vendor receipts → receipt intake (pending, never approved), vendor mail →
 * vendor history, new work enquiries → Sam's leads. Everything else is personal and is NOT
 * stored; the log holds counts only.
 *
 * READ-ONLY: OP_READONLY + FT_PEEK (ImapReader) — nothing is marked, moved, flagged or deleted.
 * Every folder except junk / trash / drafts / notes. First run reads 90 days back (customer
 * history, vendors, recent enquiries; payments and receipts only from the first run on), then
 * only new UIDs (mailbox_poll_state). Each run has a time budget and resumes where it stopped.
 * Inert until ICLOUD_IMAP_USER + ICLOUD_IMAP_PASS (Apple app-specific password) are in
 * secrets.php. Needs migration 1223.
 *
 * Cron (every 15 min) — schedule in cPanel as:
 *   7,22,37,52 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Comms/Cron/icloud_inbox_poll.php
 * Web (admin): /crm/cron/icloud_inbox_poll.php        Registry key: icloud_inbox_poll
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
    \App\Core\CronLock::acquire('icloud_inbox_poll');
} else {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    header('Content-Type: application/json; charset=utf-8');
}

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Modules/Comms/Services/IcloudInboxRouter.php';
// The router stops itself at its budget: 600 s from the CLI cron (every 15 min, CronLock +
// MailboxPollLock stop overlaps), 240 s from the web "run now" (the gateway gives up ~300 s).
$budget = $isCli ? IcloudInboxRouter::LIVE_BUDGET : IcloudInboxRouter::DRY_RUN_BUDGET;
@set_time_limit($isCli ? 840 : 300);

const ICLOUD_POLL_KEY = 'icloud_inbox_poll';
$startMs = (int)(microtime(true) * 1000);
$log = [];
$add = function (string $m) use (&$log): void { $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $m; };
$finish = function (string $status, string $summary, ?string $error = null) use (&$log, $startMs, $isCli): void {
    recordCronRun(ICLOUD_POLL_KEY, $status, $summary, (int)(microtime(true) * 1000) - $startMs, $error, !$isCli);
    if ($isCli) { echo implode("\n", $log) . "\n"; exit($status === 'error' ? 1 : 0); }
    echo json_encode(['success' => $status !== 'error', 'message' => $summary, 'log' => $log]);
    exit;
};

$off = IcloudInboxRouter::notConfigured();
if ($off !== '') {
    $add($off);
    $finish('warning', $off);
}

try {
    $res = (new IcloudInboxRouter(getDB(), $add))->poll(false, null, $budget);
} catch (Throwable $e) {
    $add('ERROR: ' . $e->getMessage());
    $finish('error', 'iCloud read failed.', $e->getMessage());
}
$add($res['message']);
if (!empty($res['skipped'])) $finish('success', 'iCloud: ' . $res['message']);
if (!$res['ok']) {
    $read = array_sum(array_intersect_key($res['counts'], array_flip(IcloudInboxRouter::ROUTES)));
    $finish($read === 0 && $res['errors'] > 0 ? 'error' : 'warning', $res['message'], $res['errors'] > 0 ? $res['errors'] . ' error(s)' : null);
}
$finish('success', 'iCloud: ' . $res['message']);
