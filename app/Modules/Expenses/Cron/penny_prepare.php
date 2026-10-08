<?php
/**
 * Penny prepares receipts — background version of the dashboard card's topUp().
 *
 * Until now Penny only prepared receipts while the dashboard was open (2 per round, at
 * most 3 rounds per page view). This cron works through the draft backlog in the
 * background: every run it calls BookkeeperDeskService::prepare() for up to N receipts
 * (default 5, the service's own ceiling), within the SAME daily cap
 * (ops_settings bookkeeper_daily_cap, else BookkeeperDeskService::DEFAULT_DAILY_CAP = 40).
 * Once the cap is reached the run does nothing until tomorrow. The cap is not raised here.
 *
 * Possible duplicates are held back exactly as bookkeeper.php's 'prepare' mode does:
 * DuplicateReceiptService::heldIds(pairsInLine(60)).
 *
 * Nothing is approved here — Penny only prepares a suggestion; Tim decides on the card.
 *
 * Options (CLI):  --dry-run   list what would be prepared; no API call, nothing written
 *                 --max=N     receipts this run (1..5, default 5)
 * Browser (admin, via /crm/cron/penny_prepare.php): ?dry_run=1, ?max=N
 *
 * Cron (every 15 min, offset from the receipt inbox poll so new email receipts are in) —
 * schedule in cPanel as:
 *   5,20,35,50 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Expenses/Cron/penny_prepare.php
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
// Under cron (CLI) nothing else defines getDB()/Database or loads secrets (ANTHROPIC_API_KEY).
require_once APP_ROOT . '/Core/config.php';
require_once CRM_INCLUDES . '/functions.php';          // brings CrmFunctions.php → recordCronRun()
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Modules/Expenses/Services/BookkeeperDeskService.php';
require_once APP_ROOT . '/Modules/Expenses/Services/DuplicateReceiptService.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    header('Content-Type: application/json; charset=utf-8');
}

const PENNY_PREPARE_KEY = 'penny_prepare';
$startMs = (int)(microtime(true) * 1000);

$dryRun = false;
$max = 5;
if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if ($arg === '--dry-run') $dryRun = true;
        elseif (strpos($arg, '--max=') === 0) $max = (int)substr($arg, 6);
    }
} else {
    $dryRun = !empty($_GET['dry_run']);
    if (isset($_GET['max'])) $max = (int)$_GET['max'];
}
$max = max(1, min(5, $max));

$log = [];
$pennyLog = function (string $m) use (&$log): void { $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $m; };
$finish = function (string $status, string $summary, ?string $error = null) use (&$log, $startMs, $isCli, $dryRun): void {
    if (!$dryRun) {
        recordCronRun(PENNY_PREPARE_KEY, $status, $summary, (int)(microtime(true) * 1000) - $startMs, $error, !$isCli);
    }
    if ($isCli) { echo implode("\n", $log) . "\n" . $summary . "\n"; exit($status === 'error' ? 1 : 0); }
    echo json_encode(['success' => $status !== 'error', 'dry_run' => $dryRun, 'message' => $summary, 'log' => $log]);
    exit;
};

try {
    $db = getDB();

    // Receipts feed the product catalogue: read a few past receipts (last 120 days) per run
    // for product proposals on Penny's card. Free (no AI), never creates anything.
    if (!$dryRun) {
        try {
            require_once APP_ROOT . '/Modules/Products/Services/ProductProposalService.php';
            $pps = new ProductProposalService($db);
            if ($pps->ready()) {
                $bf = $pps->backfill(ProductProposalService::BACKFILL_DAYS, 10);
                if ($bf['scanned'] > 0) $pennyLog("Product scan: read {$bf['scanned']} receipt(s), {$bf['proposals']} proposal(s), {$bf['left']} left.");
            }
        } catch (Throwable $e) {
            $pennyLog('Product scan skipped: ' . $e->getMessage());
        }
    }

    $svc = new ReceiptBookkeeperService($db);
    $desk = new BookkeeperDeskService($db, $svc);
    if (!$desk->ready()) {
        $finish('warning', 'Bookkeeper not set up (migration 1125) — nothing prepared.');
    }
    if (!$dryRun && !$svc->ready()) {
        $finish('warning', 'Bookkeeper not ready (ANTHROPIC_API_KEY missing) — nothing prepared.');
    }

    $hold = DuplicateReceiptService::heldIds((new DuplicateReceiptService($db))->pairsInLine(60));
    if ($hold) $pennyLog('Held back as possible duplicates: ' . count($hold));

    if ($dryRun) {
        $next = $desk->nextToPrepare($max, $hold);
        $pennyLog("Daily cap {$next['cap']}, prepared today {$next['today']}, room {$next['room']}.");
        $pennyLog('Would prepare expense(s): ' . ($next['ids'] ? '#' . implode(', #', $next['ids']) : 'none'));
        $finish('success', 'DRY RUN — would prepare ' . count($next['ids']) . ' receipt(s).');
    }

    if (function_exists('set_time_limit')) @set_time_limit(300);
    $res = $desk->prepare($max, $hold);
    if (!empty($res['capped'])) {
        $finish('success', 'Daily cap reached — nothing prepared until tomorrow.');
    }
    $ok = 0;
    $errors = [];
    foreach ($res['prepared'] as $p) {
        if ($p['error'] === null) {
            $ok++;
            $pennyLog("Prepared expense #{$p['expense_id']}");
        } else {
            $errors[] = "#{$p['expense_id']}: {$p['error']}";
            $pennyLog("Could not prepare expense #{$p['expense_id']}: {$p['error']}");
        }
    }
    $summary = "Prepared {$ok} receipt(s)" . ($errors ? ', ' . count($errors) . ' failed' : '')
             . (empty($res['prepared']) ? ' — backlog empty.' : '.');
    $finish($errors && $ok === 0 ? 'error' : ($errors ? 'warning' : 'success'), $summary, $errors ? implode('; ', $errors) : null);
} catch (Throwable $e) {
    $pennyLog('ERROR: ' . $e->getMessage());
    $finish('error', 'Penny prepare failed: ' . $e->getMessage(), $e->getMessage());
}
