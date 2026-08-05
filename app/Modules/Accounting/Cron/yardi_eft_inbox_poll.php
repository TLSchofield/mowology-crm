<?php
/**
 * Yardi/Tribe EFT Remittance Inbox Poller
 *
 * Reads office@mowology.ca over IMAP for Yardi property-management "EFT
 * Payment" remittance emails (From: DoNotReply@yardi.com, Reply-To:
 * apqueries@tribemgmt.com), parses the invoice-by-invoice breakdown, and
 * either auto-records each line against its exact invoice number (see
 * YardiEftInboxService for the auto-record bar) or drops it into the same
 * "Pending e-Transfers" panel used by the Interac poller.
 *
 * Never modifies mail flags. Re-processing is prevented by the unique
 * dedup_key (yardi:{transaction reference}:{invoice number}) in the DB, so a
 * wide SINCE window is safe.
 *
 * Cron (every 10 min) — schedule in cPanel as:
 *   0,10,20,30,40,50 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Accounting/Cron/yardi_eft_inbox_poll.php
 *
 * Also runnable in a browser by an admin (for testing) — see the SAPI guard below.
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

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';
require_once APP_ROOT . '/Modules/Accounting/Services/YardiEftInboxService.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    header('Content-Type: application/json; charset=utf-8');
}

$startMs = (int)(microtime(true) * 1000);
$log = [];
function yardiPollLog(string $m): void { global $log; $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $m; }

if (!function_exists('imap_open')) {
    yardiPollLog('FATAL: PHP imap extension not available.');
    recordCronRun('yardi_eft_inbox_poll', 'error', 'PHP imap extension not available.', (int)(microtime(true) * 1000) - $startMs, null, !$isCli);
    if ($isCli) { echo implode("\n", $log) . "\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => 'PHP imap extension not available.', 'log' => $log]);
    exit;
}

$db      = getDB();
$service = new YardiEftInboxService($db);

$host   = 'mail.mowology.ca';
$port   = 993;
$sender = 'DoNotReply@yardi.com';
$since  = date('d-M-Y', strtotime('-21 days'));

imap_timeout(IMAP_OPENTIMEOUT,  15);
imap_timeout(IMAP_READTIMEOUT,  20);
imap_timeout(IMAP_WRITETIMEOUT, 20);
imap_timeout(IMAP_CLOSETIMEOUT, 10);

// Launch floor — ignore remittances received before this feature went live.
// Override with YARDI_EFT_POLL_FLOOR in secrets.php to backfill further.
$floorTs = strtotime((defined('YARDI_EFT_POLL_FLOOR') ? YARDI_EFT_POLL_FLOOR : '2026-08-05') . ' 00:00:00');

$systemUserId = (int) ($db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($systemUserId <= 0) {
    $systemUserId = (int) ($db->query("SELECT MIN(id) FROM users")->fetchColumn() ?: 0);
}

/** Decode a MIME part body by its transfer-encoding. */
function yardiPollDecode(string $data, int $enc): string {
    if ($enc === 3) { return base64_decode($data); }
    if ($enc === 4) { return quoted_printable_decode($data); }
    return $data;
}

/** Recursively collect the first text/plain (or fallback text/html) body. */
function yardiPollWalk($mbox, int $msgNo, $part, string $pn, array &$acc): void {
    $type = strtolower($part->subtype ?? '');
    if (!empty($part->parts)) {
        foreach ($part->parts as $i => $child) {
            yardiPollWalk($mbox, $msgNo, $child, $pn === '' ? (string)($i + 1) : $pn . '.' . ($i + 1), $acc);
        }
        return;
    }
    $data = yardiPollDecode(imap_fetchbody($mbox, $msgNo, $pn ?: '1'), (int)($part->encoding ?? 0));
    if ($type === 'plain' && $acc['plain'] === '') { $acc['plain'] = $data; }
    elseif ($type === 'html' && $acc['html'] === '') { $acc['html'] = $data; }
}

function yardiPollBody($mbox, int $msgNo): string {
    $struct = @imap_fetchstructure($mbox, $msgNo);
    if (!$struct) { return ''; }
    $acc = ['plain' => '', 'html' => ''];
    if (!empty($struct->parts)) {
        yardiPollWalk($mbox, $msgNo, $struct, '', $acc);
    } else {
        $acc['plain'] = yardiPollDecode(imap_body($mbox, $msgNo), (int)($struct->encoding ?? 0));
    }
    return $acc['plain'] !== '' ? $acc['plain'] : strip_tags($acc['html']);
}

$seen = 0;
$totals = ['processed' => 0, 'auto_recorded' => 0, 'pending' => 0, 'skipped_duplicate' => 0];
$mailboxErrors = 0;

if (!defined('SMTP_USER') || !defined('SMTP_PASS') || SMTP_PASS === '') {
    yardiPollLog('FATAL: office@ mailbox credentials (SMTP_USER/SMTP_PASS) not configured.');
    recordCronRun('yardi_eft_inbox_poll', 'error', 'Mailbox credentials not configured.', (int)(microtime(true) * 1000) - $startMs, null, !$isCli);
    if ($isCli) { echo implode("\n", $log) . "\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => 'Mailbox credentials not configured.', 'log' => $log]);
    exit;
}

$ref  = "{{$host}:{$port}/imap/ssl}INBOX";
$mbox = @imap_open($ref, SMTP_USER, SMTP_PASS, 0, 1);
if ($mbox === false) {
    $mbox = @imap_open("{{$host}:{$port}/imap/ssl/novalidate-cert}INBOX", SMTP_USER, SMTP_PASS, 0, 1);
}

if ($mbox === false) {
    yardiPollLog('ERROR: could not log into ' . SMTP_USER . ': ' . implode('; ', imap_errors() ?: ['unknown']));
    $mailboxErrors++;
} else {
    $hits = @imap_search($mbox, 'FROM "' . $sender . '" SINCE "' . $since . '"');
    $hits = is_array($hits) ? $hits : [];
    yardiPollLog(SMTP_USER . ': ' . count($hits) . ' Yardi remittance email(s) in window');

    foreach ($hits as $msgNo) {
        $seen++;
        try {
            $hdr     = @imap_headerinfo($mbox, $msgNo);
            $subject = $hdr && isset($hdr->subject) ? imap_utf8($hdr->subject) : '';
            $msgId   = $hdr->message_id ?? null;
            $date    = $hdr->date ?? null;

            if ($date && $floorTs && strtotime($date) < $floorTs) { continue; }

            $body   = yardiPollBody($mbox, $msgNo);
            $parsed = YardiEftInboxService::parseRemittanceEmail($subject, $body);

            if (empty($parsed['lines'])) {
                yardiPollLog("SKIP msg {$msgNo}: no invoice lines parsed (subject: {$subject})");
                continue;
            }

            $res = $service->ingest($parsed, SMTP_USER, $msgId, $subject, $date, $systemUserId);
            foreach ($totals as $k => $v) { $totals[$k] += $res[$k] ?? 0; }
            yardiPollLog("msg {$msgNo}: ref={$parsed['transaction_reference']} lines=" . count($parsed['lines'])
                . " auto_recorded={$res['auto_recorded']} pending={$res['pending']} dup={$res['skipped_duplicate']}");
        } catch (\Throwable $e) {
            yardiPollLog("ERROR processing msg {$msgNo}: " . $e->getMessage());
            continue;
        }
    }
    imap_close($mbox);
}

$summary = "Scanned {$seen} email(s), {$totals['processed']} invoice line(s): "
         . "{$totals['auto_recorded']} auto-recorded, {$totals['pending']} pending review, {$totals['skipped_duplicate']} already seen.";
yardiPollLog($summary);

recordCronRun(
    'yardi_eft_inbox_poll',
    $mailboxErrors > 0 ? 'warning' : 'success',
    $summary,
    (int)(microtime(true) * 1000) - $startMs,
    $mailboxErrors > 0 ? 'Mailbox login failed' : null,
    !$isCli
);

if ($isCli) {
    echo implode("\n", $log) . "\n";
} else {
    echo json_encode(['success' => true, 'message' => $summary, 'log' => $log]);
}
