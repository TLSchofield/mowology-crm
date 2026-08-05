<?php
/**
 * Receipt Inbox Poller — email-in expense capture.
 *
 * Reads the dedicated receipts mailbox over IMAP, extracts each PDF/image
 * attachment, runs it through the existing OCR + vendor-match pipeline, and
 * creates an expense:
 *   - clean 100% match (known vendor + total + date + vendor category) → 'approved'
 *     (the sync-ledger cron posts it to the books)
 *   - anything else → 'draft' (surfaced in the Expenses review panel)
 *
 * Mailbox:
 *   - receipts@mowology.ca — RECEIPTS_IMAP_PASS in secrets.php
 *
 * Never modifies mail flags. Re-processing is prevented by the unique dedup_key
 * (message-id:sha256, else sha:sha256) in receipt_inbox_messages, so a wide SINCE
 * window is safe.
 *
 * Cron (every 15 min) — schedule in cPanel as:
 *   0,15,30,45 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Expenses/Cron/receipt_inbox_poll.php
 *
 * Also runnable in a browser by an admin (for testing) — see the SAPI guard.
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
require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptInboxService.php';
require_once APP_ROOT . '/Modules/Accounting/Services/AccountingService.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    header('Content-Type: application/json; charset=utf-8');
}

$startMs = (int)(microtime(true) * 1000);
$log = [];
function rpLog(string $m): void { global $log; $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $m; }

function rpFail(string $msg): void {
    global $log, $isCli, $startMs;
    rpLog($msg);
    recordCronRun('receipt_inbox_poll', 'error', $msg, (int)(microtime(true) * 1000) - $startMs, null, !$isCli);
    if ($isCli) { echo implode("\n", $log) . "\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => $msg, 'log' => $log]);
    exit;
}

if (!function_exists('imap_open')) {
    rpFail('FATAL: PHP imap extension not available.');
}
if (!defined('RECEIPTS_IMAP_PASS') || RECEIPTS_IMAP_PASS === '') {
    rpFail('FATAL: RECEIPTS_IMAP_PASS not configured in secrets.php.');
}

$db      = getDB();
$service = new ReceiptInboxService($db);

$host  = 'mail.mowology.ca';
$port  = 993;
$user  = 'receipts@mowology.ca';
$since = date('d-M-Y', strtotime('-21 days'));

// Attribute auto-created expenses to an admin user (created_by is NOT NULL).
$systemUserId = (int) ($db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($systemUserId <= 0) {
    $systemUserId = (int) ($db->query("SELECT MIN(id) FROM users")->fetchColumn() ?: 0);
}
if ($systemUserId <= 0) {
    rpFail('FATAL: no users found to attribute expenses to.');
}

imap_timeout(IMAP_OPENTIMEOUT,  15);
imap_timeout(IMAP_READTIMEOUT,  20);
imap_timeout(IMAP_WRITETIMEOUT, 20);
imap_timeout(IMAP_CLOSETIMEOUT, 10);

// Launch floor: ignore receipts received before the feature went live.
$floorTs = strtotime((defined('RECEIPTS_POLL_FLOOR') ? RECEIPTS_POLL_FLOOR : '2026-06-19') . ' 00:00:00');

/** Decode a MIME part body by its transfer-encoding. */
function rpDecode(string $data, int $enc): string {
    if ($enc === 3) { return base64_decode($data); }            // BASE64
    if ($enc === 4) { return quoted_printable_decode($data); }  // QUOTED-PRINTABLE
    return $data;
}

/** Build a MIME type string from an IMAP part's numeric type + subtype. */
function rpMime($part): string {
    $primary = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application',
                4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'][(int)($part->type ?? 7)] ?? 'other';
    $sub = strtolower((string)($part->subtype ?? ''));
    return $primary . '/' . $sub;
}

/** Read a parameter (e.g. name / filename) from a part's parameter lists. */
function rpParam($part, string $key): ?string {
    foreach (['parameters' => 'ifparameters', 'dparameters' => 'ifdparameters'] as $list => $flag) {
        if (!empty($part->$flag) && !empty($part->$list)) {
            foreach ($part->$list as $p) {
                if (strtolower($p->attribute) === strtolower($key)) {
                    return $p->value;
                }
            }
        }
    }
    return null;
}

/**
 * Recursively collect attachment parts (PDF + images). Accumulates
 * ['pn'=>section, 'filename'=>?, 'mime'=>str, 'encoding'=>int].
 */
function rpWalk($part, string $pn, array &$acc): void {
    if (!empty($part->parts)) {
        foreach ($part->parts as $i => $child) {
            rpWalk($child, $pn === '' ? (string)($i + 1) : $pn . '.' . ($i + 1), $acc);
        }
        return;
    }
    $type = (int)($part->type ?? 7);
    $sub  = strtolower((string)($part->subtype ?? ''));
    $disp = strtolower((string)($part->disposition ?? ''));
    $name = rpParam($part, 'filename') ?? rpParam($part, 'name');

    $isPdf   = ($type === 3 && $sub === 'pdf');
    $isImage = ($type === 5 && in_array($sub, ['jpeg', 'jpg', 'png', 'gif', 'webp', 'heic', 'heif'], true));
    $looksAttached = ($disp === 'attachment' || $disp === 'inline' || $name !== null);

    if (($isPdf || $isImage) && $looksAttached) {
        $acc[] = [
            'pn'       => $pn ?: '1',
            'filename' => $name ?: ($isPdf ? 'receipt.pdf' : 'receipt.jpg'),
            'mime'     => rpMime($part),
            'encoding' => (int)($part->encoding ?? 0),
        ];
    }
}

$ref  = "{{$host}:{$port}/imap/ssl}INBOX";
$mbox = @imap_open($ref, $user, RECEIPTS_IMAP_PASS, 0, 1);
if ($mbox === false) {
    $mbox = @imap_open("{{$host}:{$port}/imap/ssl/novalidate-cert}INBOX", $user, RECEIPTS_IMAP_PASS, 0, 1);
}
if ($mbox === false) {
    rpFail("ERROR: could not log into {$user}: " . implode('; ', imap_errors() ?: ['unknown']));
}

$hits = @imap_search($mbox, 'SINCE "' . $since . '"');
$hits = is_array($hits) ? $hits : [];
rpLog("{$user}: " . count($hits) . ' email(s) in window');

$autoPosted = [];
$pending    = [];
$seen = 0;

foreach ($hits as $msgNo) {
    try {
        $hdr     = @imap_headerinfo($mbox, $msgNo);
        $subject = $hdr && isset($hdr->subject) ? imap_utf8($hdr->subject) : '';
        $msgId   = $hdr->message_id ?? null;
        $date    = $hdr->date ?? null;
        $from    = ($hdr && !empty($hdr->from) && isset($hdr->from[0]))
                 ? (($hdr->from[0]->mailbox ?? '') . '@' . ($hdr->from[0]->host ?? '')) : null;

        if ($date && $floorTs && strtotime($date) < $floorTs) { continue; }

        $struct = @imap_fetchstructure($mbox, $msgNo);
        if (!$struct) { continue; }

        $parts = [];
        if (!empty($struct->parts)) {
            rpWalk($struct, '', $parts);
        }
        if (empty($parts)) {
            // No PDF/image attachment — nothing to ingest. Body-only receipts
            // (Stripe/Amazon/Uber HTML) are phase 2. Cheap to re-scan headers.
            continue;
        }

        $msgMeta = ['message_id' => $msgId, 'sender_email' => $from, 'subject' => $subject, 'email_date' => $date];

        foreach ($parts as $p) {
            $seen++;
            $raw   = imap_fetchbody($mbox, $msgNo, $p['pn']);
            $bytes = rpDecode($raw, $p['encoding']);
            if ($bytes === '' || strlen($bytes) > 15 * 1024 * 1024) { continue; } // skip empty / >15MB

            $res = $service->ingestAttachment($msgMeta, $bytes, $p['filename'], $p['mime'], $systemUserId);
            $line = "{$from} — {$p['filename']} ({$p['mime']}) → {$res['status']}";
            if ($res['status'] === 'auto_posted') {
                $autoPosted[] = ['who' => $from, 'subject' => $subject, 'file' => $p['filename'], 'id' => $res['expense_id']];
                rpLog("AUTO-POST: {$line} expense #{$res['expense_id']}");
            } elseif ($res['status'] === 'pending') {
                $pending[] = ['who' => $from, 'subject' => $subject, 'file' => $p['filename'], 'id' => $res['expense_id'], 'note' => $res['note']];
                rpLog("PENDING: {$line}" . ($res['note'] ? " ({$res['note']})" : ''));
            } else {
                rpLog($line);
            }
        }
    } catch (\Throwable $e) {
        rpLog("ERROR processing msg {$msgNo}: " . $e->getMessage());
        continue;
    }
}

imap_close($mbox);
rpLog("Scanned {$seen} attachment(s): " . count($autoPosted) . ' auto-posted, ' . count($pending) . ' pending.');

// Post auto-approved expenses to the ledger now (idempotent; sync-ledger cron also runs).
if (!empty($autoPosted)) {
    try {
        (new AccountingService($db))->syncFromExpenses();
        rpLog('Ledger sync run for auto-posted expenses.');
    } catch (\Throwable $e) {
        rpLog('Ledger sync error (non-fatal): ' . $e->getMessage());
    }
}

// Summary email — only when something new arrived.
if (!empty($autoPosted) || !empty($pending)) {
    $section = function (string $title, array $items, string $colour) {
        if (empty($items)) { return ''; }
        $rows = '';
        foreach ($items as $it) {
            $who  = htmlspecialchars($it['who'] ?: 'Unknown sender');
            $file = htmlspecialchars($it['file'] ?: 'attachment');
            $sub  = $it['subject'] ? '<div style="color:#555;font-size:13px;">' . htmlspecialchars($it['subject']) . '</div>' : '';
            $note = !empty($it['note']) ? ' <span style="color:#b45309;">(' . htmlspecialchars($it['note']) . ')</span>' : '';
            $rows .= "<tr><td style=\"padding:8px 10px;border-bottom:1px solid #eee;\"><strong>{$who}</strong> — {$file}{$note}{$sub}</td></tr>";
        }
        return '<h3 style="color:' . $colour . ';margin:18px 0 6px;">' . htmlspecialchars($title)
             . ' (' . count($items) . ')</h3><table style="width:100%;border-collapse:collapse;">' . $rows . '</table>';
    };

    $count   = count($autoPosted) + count($pending);
    $subject = "{$count} emailed receipt" . ($count === 1 ? '' : 's') . ' processed';
    $link    = (defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://mowology.ca') . '/crm/expenses_appstack.php#receipt-inbox';
    $html    = '<div style="font-family:Arial,sans-serif;max-width:560px;">'
             . '<h2 style="color:#1A5F4A;">Emailed receipts processed</h2>'
             . $section('Auto-posted to the books', $autoPosted, '#2D8659')
             . $section('Needs review', $pending, '#b45309')
             . '<p style="margin-top:18px;"><a href="' . $link . '" style="background:#2D8659;color:#fff;'
             . 'padding:10px 18px;border-radius:6px;text-decoration:none;">Open Expenses review</a></p>'
             . '</div>';

    $to = 'mowology@icloud.com';
    $ok = sendCrmEmail($to, $subject, $html);
    rpLog("Summary email to {$to}: " . ($ok ? 'sent' : 'FAILED'));
}

$summary = "Scanned {$seen} attachment(s): " . count($autoPosted) . ' auto-posted, ' . count($pending) . ' pending.';
recordCronRun('receipt_inbox_poll', 'success', $summary, (int)(microtime(true) * 1000) - $startMs, null, !$isCli);

if ($isCli) {
    echo implode("\n", $log) . "\n";
} else {
    echo json_encode([
        'success' => true,
        'message' => $summary,
        'log'     => $log,
    ]);
}
