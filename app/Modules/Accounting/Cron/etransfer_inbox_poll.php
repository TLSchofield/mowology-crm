<?php
/**
 * Interac e-Transfer Inbox Poller
 *
 * Reads the Mowology mailboxes over IMAP, parses each new Interac e-Transfer
 * notification, matches it to an open invoice, stores it in
 * etransfer_notifications, and emails a summary of new arrivals to the office.
 *
 * Mailboxes:
 *   - info@mowology.ca   (auto-deposit — ETRANSFER_IMAP_PASS in secrets.php)
 *   - office@mowology.ca (manual claim — reuses SMTP_USER / SMTP_PASS)
 *
 * Never modifies mail flags — office@ is a human-read mailbox. Re-processing is
 * prevented by the unique dedup_key (Interac reference #) in the DB, so a wide
 * SINCE window is safe.
 *
 * Cron (every 10 min) — schedule in cPanel as:
 *   0,10,20,30,40,50 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Accounting/Cron/etransfer_inbox_poll.php
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
require_once APP_ROOT . '/Modules/Accounting/Services/EtransferInboxService.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    // Web access (manual test trigger) must be an authenticated admin.
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    header('Content-Type: application/json; charset=utf-8');
}

$startMs = (int)(microtime(true) * 1000);
$log = [];
function pollLog(string $m): void { global $log; $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $m; }

if (!function_exists('imap_open')) {
    pollLog('FATAL: PHP imap extension not available.');
    recordCronRun('etransfer_inbox_poll', 'error', 'PHP imap extension not available.', (int)(microtime(true) * 1000) - $startMs, null, !$isCli);
    if ($isCli) { echo implode("\n", $log) . "\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => 'PHP imap extension not available.', 'log' => $log]);
    exit;
}

$db      = getDB();
$service = new EtransferInboxService($db);

$host    = 'mail.mowology.ca';
$port    = 993;
$interac = 'notify@payments.interac.ca';
$since   = date('d-M-Y', strtotime('-21 days'));   // IMAP date format (server-side window)

// Bound every IMAP operation so an unresponsive mail server can never hang the
// unattended cron (default is no timeout). Seconds.
imap_timeout(IMAP_OPENTIMEOUT,  15);
imap_timeout(IMAP_READTIMEOUT,  20);
imap_timeout(IMAP_WRITETIMEOUT, 20);
imap_timeout(IMAP_CLOSETIMEOUT, 10);

// Launch floor: ignore e-Transfers received before the feature went live so the
// panel starts clean (office@ holds months of already-handled history). Override
// with ETRANSFER_POLL_FLOOR in secrets.php to backfill further if ever needed.
$floorTs = strtotime((defined('ETRANSFER_POLL_FLOOR') ? ETRANSFER_POLL_FLOOR : '2026-06-16') . ' 00:00:00');

// Mailboxes to poll (skip any whose password constant is missing).
$mailboxes = [];
if (defined('ETRANSFER_IMAP_PASS') && ETRANSFER_IMAP_PASS !== '') {
    $mailboxes[] = ['user' => 'info@mowology.ca', 'pass' => ETRANSFER_IMAP_PASS];
}
if (defined('SMTP_USER') && defined('SMTP_PASS') && SMTP_PASS !== '') {
    $mailboxes[] = ['user' => SMTP_USER, 'pass' => SMTP_PASS];   // office@
}

/** Decode a MIME part body by its transfer-encoding. */
function pollDecode(string $data, int $enc): string {
    if ($enc === 3) { return base64_decode($data); }
    if ($enc === 4) { return quoted_printable_decode($data); }
    return $data;
}

/** Recursively collect the first text/plain (or fallback text/html) body. */
function pollWalk($mbox, int $msgNo, $part, string $pn, array &$acc): void {
    $type = strtolower($part->subtype ?? '');
    if (!empty($part->parts)) {
        foreach ($part->parts as $i => $child) {
            pollWalk($mbox, $msgNo, $child, $pn === '' ? (string)($i + 1) : $pn . '.' . ($i + 1), $acc);
        }
        return;
    }
    $data = pollDecode(imap_fetchbody($mbox, $msgNo, $pn ?: '1'), (int)($part->encoding ?? 0));
    if ($type === 'plain' && $acc['plain'] === '') { $acc['plain'] = $data; }
    elseif ($type === 'html' && $acc['html'] === '') { $acc['html'] = $data; }
}

function pollBody($mbox, int $msgNo): string {
    $struct = @imap_fetchstructure($mbox, $msgNo);
    if (!$struct) { return ''; }
    $acc = ['plain' => '', 'html' => ''];
    if (!empty($struct->parts)) {
        pollWalk($mbox, $msgNo, $struct, '', $acc);
    } else {
        $acc['plain'] = pollDecode(imap_body($mbox, $msgNo), (int)($struct->encoding ?? 0));
    }
    return $acc['plain'] !== '' ? $acc['plain'] : strip_tags($acc['html']);
}

$newItems = [];
$seen = 0;
$mailboxErrors = 0;

foreach ($mailboxes as $mb) {
    $ref = "{{$host}:{$port}/imap/ssl}INBOX";
    $mbox = @imap_open($ref, $mb['user'], $mb['pass'], 0, 1);
    if ($mbox === false) {
        $mbox = @imap_open("{{$host}:{$port}/imap/ssl/novalidate-cert}INBOX", $mb['user'], $mb['pass'], 0, 1);
    }
    if ($mbox === false) {
        pollLog("ERROR: could not log into {$mb['user']}: " . implode('; ', imap_errors() ?: ['unknown']));
        $mailboxErrors++;
        continue;
    }

    $hits = @imap_search($mbox, 'FROM "' . $interac . '" SINCE "' . $since . '"');
    $hits = is_array($hits) ? $hits : [];
    pollLog("{$mb['user']}: " . count($hits) . ' Interac email(s) in window');

    foreach ($hits as $msgNo) {
        $seen++;
        try {
            $hdr     = @imap_headerinfo($mbox, $msgNo);
            $subject = $hdr && isset($hdr->subject) ? imap_utf8($hdr->subject) : '';
            $msgId   = $hdr->message_id ?? null;
            $date    = $hdr->date ?? null;

            // Skip anything from before the launch floor (already-handled history).
            if ($date && $floorTs && strtotime($date) < $floorTs) { continue; }

            $body    = pollBody($mbox, $msgNo);

            $parsed = EtransferInboxService::parseInteracEmail($subject, $body);
            $res    = $service->ingest($parsed, $mb['user'], $msgId, $subject, $date);
        } catch (\Throwable $e) {
            // One bad email must never abort the batch.
            pollLog("ERROR processing msg {$msgNo} in {$mb['user']}: " . $e->getMessage());
            continue;
        }

        if ($res['inserted'] && $res['row'] && $res['row']['status'] === 'pending') {
            $newItems[] = $res['row'];
            pollLog("NEW: {$parsed['sender_name']} \${$parsed['amount']} ref={$parsed['reference_number']} match={$res['row']['match_confidence']}");
        }
    }
    imap_close($mbox);
}

pollLog("Scanned {$seen} email(s), " . count($newItems) . ' new.');

// Auto-record the narrow set of transfers that are 100% certain (hard
// identity match + high-confidence bank deposit + exact amount) — runs over
// ALL pending notifications each cycle, not just this run's new ones, since
// a bank deposit can import after the email arrives. See
// EtransferInboxService::autoRecordFullyCertain() for the exact bar.
$autoRecorded = ['recorded' => 0, 'recorded_ids' => []];
$systemUserId = (int) ($db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($systemUserId <= 0) {
    $systemUserId = (int) ($db->query("SELECT MIN(id) FROM users")->fetchColumn() ?: 0);
}
if ($systemUserId > 0) {
    $autoRecorded = $service->autoRecordFullyCertain($systemUserId);
    if ($autoRecorded['recorded'] > 0) {
        pollLog("Auto-recorded {$autoRecorded['recorded']} fully-certain transfer(s) — bank deposit + invoice + email all matched, exact amount.");
    }
}

// Drop anything just auto-recorded from the "please review" notification —
// it's already closed, staff don't need to look at it.
if (!empty($autoRecorded['recorded_ids'])) {
    $newItems = array_values(array_filter(
        $newItems,
        fn($n) => !in_array((int) $n['id'], $autoRecorded['recorded_ids'], true)
    ));
}

// Notify the office about new arrivals.
if (!empty($newItems)) {
    $rows = '';
    foreach ($newItems as $n) {
        $amt   = $n['amount'] !== null ? '$' . number_format((float)$n['amount'], 2) : '—';
        $who   = htmlspecialchars($n['sender_name'] ?: 'Unknown sender');
        $memo  = $n['memo'] ? '<div style="color:#555;font-size:13px;">“' . htmlspecialchars($n['memo']) . '”</div>' : '';
        if (!empty($n['matched_invoice_id'])) {
            $invStmt = $db->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
            $invStmt->execute([$n['matched_invoice_id']]);
            $invNo = $invStmt->fetchColumn() ?: ('#' . $n['matched_invoice_id']);
            $match = '<span style="color:#2D8659;">Suggested: ' . htmlspecialchars((string)$invNo)
                   . ' (' . htmlspecialchars($n['match_confidence']) . ' confidence)</span>';
        } else {
            $match = '<span style="color:#b45309;">No invoice match — attach manually</span>';
        }
        $type = $n['transfer_type'] === 'claim'
            ? ' <strong style="color:#b45309;">[needs claiming in online banking]</strong>' : '';
        $rows .= "<tr><td style=\"padding:10px;border-bottom:1px solid #eee;\">"
               . "<strong>{$who}</strong> — {$amt}{$type}<br>{$match}{$memo}</td></tr>";
    }

    $count   = count($newItems);
    $subject = "{$count} new e-Transfer" . ($count === 1 ? '' : 's') . ' received';
    $link    = (defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://mowology.ca') . '/crm/invoices/index.php#etransfers';
    $html    = '<div style="font-family:Arial,sans-serif;max-width:560px;">'
             . '<h2 style="color:#1A5F4A;">e-Transfer' . ($count === 1 ? '' : 's') . ' received</h2>'
             . '<p>Review and record ' . ($count === 1 ? 'it' : 'them') . ' in the CRM:</p>'
             . '<table style="width:100%;border-collapse:collapse;">' . $rows . '</table>'
             . '<p style="margin-top:18px;"><a href="' . $link . '" style="background:#2D8659;color:#fff;'
             . 'padding:10px 18px;border-radius:6px;text-decoration:none;">Open Pending e-Transfers</a></p>'
             . '</div>';

    $to = 'mowology@icloud.com';
    $ok = sendCrmEmail($to, $subject, $html);
    pollLog("Notification email to {$to}: " . ($ok ? 'sent' : 'FAILED'));
}

$count   = count($newItems);
$summary = "Scanned {$seen} email(s), {$count} new"
         . ($autoRecorded['recorded'] > 0 ? ", {$autoRecorded['recorded']} auto-recorded" : '') . '.';
recordCronRun(
    'etransfer_inbox_poll',
    $mailboxErrors > 0 ? 'warning' : 'success',
    $summary,
    (int)(microtime(true) * 1000) - $startMs,
    $mailboxErrors > 0 ? "{$mailboxErrors} mailbox(es) failed login" : null,
    !$isCli
);

if ($isCli) {
    echo implode("\n", $log) . "\n";
} else {
    echo json_encode([
        'success' => true,
        'message' => $summary,
        'log'     => $log,
    ]);
}
