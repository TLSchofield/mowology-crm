<?php
/**
 * Penny chases missing receipts (MissingReceiptService, migration 1245).
 *
 * Every run: scan the 2026 card / bank spending lines for ones with no receipt (and close items whose
 * receipt turned up), push the people due a nudge (never 21:00–07:00 Pacific — ops_settings
 * penny_chase_quiet_start / _end), and on Monday morning send Tim the weekly "Still missing" summary.
 * Pushes are queued in push_queue; the push-drain cron sends them (APNs; FCM when configured).
 *
 * Options (CLI):  --dry-run   scan + list who would be nudged; nothing written, nothing pushed
 *                 --summary   send Tim's weekly summary now (whatever the day)
 * Browser (admin, via /crm/cron/penny_chase.php): ?dry_run=1, ?summary=1
 *
 * Cron (hourly, at :10) — schedule in cPanel as:
 *   10 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Expenses/Cron/penny_chase.php
 * Records every exit in cron_runs under 'penny_chase' (the key in database_appstack.php's registry).
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
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/CrmFunctions.php';          // recordCronRun()
require_once APP_ROOT . '/Modules/Expenses/Services/MissingReceiptService.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
}

const PENNY_CHASE_KEY = 'penny_chase';
$startMs = (int)(microtime(true) * 1000);

$dryRun = false;
$forceSummary = false;
if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if ($arg === '--dry-run') $dryRun = true;
        elseif ($arg === '--summary') $forceSummary = true;
    }
} else {
    $dryRun = !empty($_GET['dry_run']);
    $forceSummary = !empty($_GET['summary']);
}

$log = [];
$say = function (string $m) use (&$log): void { $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $m; };
$finish = function (string $status, string $summary, ?string $error = null) use (&$log, $startMs, $isCli, $dryRun): void {
    if (!$dryRun) {
        recordCronRun(PENNY_CHASE_KEY, $status, $summary, (int)(microtime(true) * 1000) - $startMs, $error, !$isCli);
    }
    if ($isCli) { echo implode("\n", $log) . "\n" . $summary . "\n"; exit($status === 'error' ? 1 : 0); }
    echo json_encode(['success' => $status !== 'error', 'dry_run' => $dryRun, 'message' => $summary, 'log' => $log]);
    exit;
};

try {
    $db = getDB();
    if ($dryRun) {
        // Nothing is written: the scan runs inside a transaction that is rolled back.
        $db->beginTransaction();
        $svc = new MissingReceiptService($db, null, function (int $uid, string $title, string $body) use ($say): string {
            $say("Would push user #{$uid}: {$title} — {$body}");
            return 'dry run';
        });
    } else {
        $svc = new MissingReceiptService($db);
    }
    if (!$svc->ready()) {
        if ($dryRun) $db->rollBack();
        $finish('warning', 'Missing-receipt chaser not set up (migration 1245) — nothing done.');
    }

    $scan = $svc->scan();
    $say("Scan: {$scan['found']} new missing, {$scan['received']} answered by a receipt, {$scan['exempt']} never need one, "
         . "{$scan['has_receipt']} have a receipt to link, {$scan['rechecked']} open re-checked");
    $nudge = $svc->nudge();
    if ($nudge['quiet']) {
        $say('Quiet hours — no pushes this run');
    } else {
        $say("Nudged {$nudge['sent']} item(s) for {$nudge['people']} person(s)");
        foreach ($nudge['notes'] as $uid => $note) $say("  user #{$uid}: {$note}");
    }
    $summary = $svc->weeklySummary($forceSummary);
    if ($summary) $say('Weekly summary: ' . $summary['text'] . ' (' . $summary['push'] . ')');
    $t = $svc->totals();

    if ($dryRun) $db->rollBack();
    $finish('success', MissingReceiptService::summaryText($t['open'], $t['open_amount'], $t['no_receipt'], $t['no_receipt_amount'])
                       . " · {$scan['found']} new, {$nudge['sent']} nudged" . ($dryRun ? ' (dry run)' : ''));
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $say('Error: ' . $e->getMessage());
    $finish('error', 'Missing-receipt chaser failed', $e->getMessage());
}
