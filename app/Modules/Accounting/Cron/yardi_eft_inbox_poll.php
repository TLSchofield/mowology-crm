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
 * Never modifies mail flags (OP_READONLY + FT_PEEK since 2026-10-07; iCloud copies come
 * through IcloudInboxRouter into the same ingest()). Re-processing is prevented by the unique
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
// Under cron (CLI) nothing else defines getDB()/Database — the web shim gets them from auth.php.
require_once APP_ROOT . '/Core/config.php';

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';
require_once APP_ROOT . '/Modules/Accounting/Services/YardiEftInboxService.php';
require_once APP_ROOT . '/Services/Mail/MailboxConfig.php';
require_once APP_ROOT . '/Services/Mail/ImapReader.php';

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

$sender = 'DoNotReply@yardi.com';
$since  = date('d-M-Y', strtotime('-21 days'));

ImapReader::timeouts();

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
    $data = yardiPollDecode((string)imap_fetchbody($mbox, $msgNo, $pn ?: '1', FT_PEEK), (int)($part->encoding ?? 0));
    if ($type === 'plain' && $acc['plain'] === '') { $acc['plain'] = $data; }
    elseif ($type === 'html' && $acc['html'] === '') { $acc['html'] = $data; }
}

// Unlike the Interac poller, the HTML body is kept as-is (not strip_tags'd) —
// the real Yardi email is HTML-only, built from nested tables, and
// YardiEftInboxService::parseRemittanceEmail() parses the table structure
// directly via DOMDocument rather than relying on stripped-text line layout.
function yardiPollBody($mbox, int $msgNo): string {
    $struct = @imap_fetchstructure($mbox, $msgNo);
    if (!$struct) { return ''; }
    $acc = ['plain' => '', 'html' => ''];
    if (!empty($struct->parts)) {
        yardiPollWalk($mbox, $msgNo, $struct, '', $acc);
    } else {
        $acc['plain'] = yardiPollDecode((string)imap_body($mbox, $msgNo, FT_PEEK), (int)($struct->encoding ?? 0));
    }
    return $acc['plain'] !== '' ? $acc['plain'] : $acc['html'];
}

$seen = 0;
$totals = ['processed' => 0, 'auto_recorded' => 0, 'pending' => 0, 'skipped_duplicate' => 0];
$mailboxErrors = 0;

$office = MailboxConfig::forPurpose('yardi')[0] ?? null;   // office@ (SMTP_USER / SMTP_PASS)
if ($office === null) {
    yardiPollLog('FATAL: office@ mailbox credentials (SMTP_USER/SMTP_PASS) not configured.');
    recordCronRun('yardi_eft_inbox_poll', 'error', 'Mailbox credentials not configured.', (int)(microtime(true) * 1000) - $startMs, null, !$isCli);
    if ($isCli) { echo implode("\n", $log) . "\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => 'Mailbox credentials not configured.', 'log' => $log]);
    exit;
}

$mbox = ImapReader::open($office, 'INBOX');   // OP_READONLY + FT_PEEK — never marks office@ mail read

if ($mbox === false) {
    yardiPollLog('ERROR: could not log into ' . $office['user'] . ': ' . implode('; ', imap_errors() ?: ['unknown']));
    $mailboxErrors++;
} else {
    $hits = @imap_search($mbox, 'FROM "' . $sender . '" SINCE "' . $since . '"');
    $hits = is_array($hits) ? $hits : [];
    yardiPollLog($office['user'] . ': ' . count($hits) . ' Yardi remittance email(s) in window');

    foreach ($hits as $msgNo) {
        $seen++;
        try {
            $hdr     = @imap_headerinfo($mbox, $msgNo);
            $subject = $hdr && isset($hdr->subject) ? imap_utf8($hdr->subject) : '';
            $msgId   = $hdr->message_id ?? null;
            $date    = $hdr->date ?? null;

            if ($date && $floorTs && strtotime($date) < $floorTs) {
                yardiPollLog("SKIP msg {$msgNo}: dated before launch floor (subject: {$subject})");
                continue;
            }

            $body   = yardiPollBody($mbox, $msgNo);
            $parsed = YardiEftInboxService::parseRemittanceEmail($subject, $body);

            if (empty($parsed['lines'])) {
                yardiPollLog("SKIP msg {$msgNo}: no invoice lines parsed (subject: {$subject})");
                continue;
            }

            $res = $service->ingest($parsed, $office['user'], $msgId, $subject, $date, $systemUserId);
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
