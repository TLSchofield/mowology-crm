<?php
/**
 * Sales inbox poll — customer email history for Sam (SalesInboxService).
 *
 * Reads office@mowology.ca (INBOX + the Sent folder) READ-ONLY: the mailbox is opened with
 * OP_READONLY and bodies are fetched with FT_PEEK, so nothing is marked read, moved or
 * deleted — office@ is a human-read mailbox. Only mail from or to a known contact is kept.
 * Re-runs are safe: each message is stored once (unique Message-ID).
 *
 * Window: the last 3 days normally; 90 days on the first run (or with ?backfill=1 /
 * --backfill), so quotes already out get their replies.
 *
 * Cron (every 15 min) — schedule in cPanel as:
 *   0,15,30,45 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Sales/Cron/sales_inbox_poll.php
 * Also runnable in a browser by an admin: /crm/cron/sales_inbox_poll.php
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
require_once CRM_INCLUDES . '/functions.php';          // brings CrmFunctions.php → recordCronRun()
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Modules/Sales/Services/SalesInboxService.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    if (!isAdmin()) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Admin only'])); }
    header('Content-Type: application/json; charset=utf-8');
}

const SALES_POLL_KEY = 'sales_inbox_poll';
$startMs = (int)(microtime(true) * 1000);
$log = [];
$salesLog = function (string $m) use (&$log): void { $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $m; };
$finish = function (string $status, string $summary, ?string $error = null) use (&$log, $startMs, $isCli): void {
    recordCronRun(SALES_POLL_KEY, $status, $summary, (int)(microtime(true) * 1000) - $startMs, $error, !$isCli);
    if ($isCli) { echo implode("\n", $log) . "\n"; exit($status === 'error' ? 1 : 0); }
    echo json_encode(['success' => $status !== 'error', 'message' => $summary, 'log' => $log]);
    exit;
};

if (!function_exists('imap_open')) {
    $salesLog('FATAL: PHP imap extension not available.');
    $finish('error', 'PHP imap extension not available.', 'imap missing');
}
if (!defined('SMTP_USER') || !defined('SMTP_PASS') || SMTP_PASS === '') {
    $salesLog('office@ credentials (SMTP_USER / SMTP_PASS) not configured.');
    $finish('warning', 'office@ credentials not configured — nothing read.');
}

$db = getDB();
$svc = new SalesInboxService($db);
if (!$svc->ready()) {
    $finish('warning', 'Migration 1140 not run yet — nothing read.');
}

$first = (int)$db->query("SELECT COUNT(*) FROM sales_messages WHERE mailbox LIKE 'office@%'")->fetchColumn() === 0;
$backfill = $first || ($isCli ? in_array('--backfill', $argv ?? [], true) : !empty($_GET['backfill']));
$since = date('d-M-Y', strtotime($backfill ? '-90 days' : '-3 days'));

imap_timeout(IMAP_OPENTIMEOUT, 15);
imap_timeout(IMAP_READTIMEOUT, 20);
imap_timeout(IMAP_WRITETIMEOUT, 20);
imap_timeout(IMAP_CLOSETIMEOUT, 10);

$host = 'mail.mowology.ca';
$port = 993;
$open = function (string $folder) use ($host, $port) {
    $flags = OP_READONLY;
    $m = @imap_open("{{$host}:{$port}/imap/ssl}{$folder}", SMTP_USER, SMTP_PASS, $flags, 1);
    if ($m === false) $m = @imap_open("{{$host}:{$port}/imap/ssl/novalidate-cert}{$folder}", SMTP_USER, SMTP_PASS, $flags, 1);
    return $m;
};

/** First text/plain (else text/html) body, fetched with FT_PEEK so \Seen is never set. */
$decode = fn(string $d, int $enc): string => $enc === 3 ? (string)base64_decode($d) : ($enc === 4 ? quoted_printable_decode($d) : $d);
$body = function ($mbox, int $no) use ($decode): string {
    $st = @imap_fetchstructure($mbox, $no);
    if (!$st) return '';
    $acc = ['plain' => '', 'html' => ''];
    $walk = function ($part, string $pn) use (&$walk, &$acc, $mbox, $no, $decode): void {
        if (!empty($part->parts)) {
            foreach ($part->parts as $i => $child) $walk($child, $pn === '' ? (string)($i + 1) : $pn . '.' . ($i + 1));
            return;
        }
        $type = strtolower($part->subtype ?? '');
        if (!in_array($type, ['plain', 'html'], true) || $acc[$type] !== '') return;
        $data = $decode((string)imap_fetchbody($mbox, $no, $pn ?: '1', FT_PEEK), (int)($part->encoding ?? 0));
        $cs = '';
        foreach (($part->parameters ?? []) as $p) if (strtolower($p->attribute) === 'charset') $cs = $p->value;
        if ($cs !== '' && strtoupper($cs) !== 'UTF-8') $data = (string)@mb_convert_encoding($data, 'UTF-8', $cs);
        $acc[$type] = $data;
    };
    if (!empty($st->parts)) $walk($st, '');
    else $acc['plain'] = $decode((string)imap_body($mbox, $no, FT_PEEK), (int)($st->encoding ?? 0));
    return $acc['plain'] !== '' ? $acc['plain'] : $acc['html'];
};

$counts = ['stored' => 0, 'dupe' => 0, 'skipped' => 0];
$errors = 0;
$folders = ['INBOX'];

$inbox = $open('INBOX');
if ($inbox === false) {
    $salesLog('ERROR: could not open office@ INBOX: ' . implode('; ', imap_errors() ?: ['unknown']));
    $finish('error', 'Could not log into office@.', 'imap login failed');
}
// The Sent folder's name depends on the server (Sent, INBOX.Sent, Sent Items…).
foreach ((array)@imap_list($inbox, "{{$host}:{$port}/imap/ssl}", '*') as $full) {
    $name = preg_replace('/^\{[^}]*\}/', '', (string)$full);
    if (preg_match('/(^|[.\/])Sent( Items| Messages)?$/i', $name)) { $folders[] = $name; break; }
}
@imap_close($inbox);

foreach ($folders as $folder) {
    $mbox = $open($folder);
    if ($mbox === false) { $salesLog("ERROR: could not open {$folder}"); $errors++; continue; }
    $hits = @imap_search($mbox, 'SINCE "' . $since . '"');
    $hits = is_array($hits) ? $hits : [];
    $salesLog("{$folder}: " . count($hits) . " message(s) since {$since}");
    foreach ($hits as $no) {
        try {
            $h = @imap_headerinfo($mbox, $no);
            if (!$h) continue;
            $res = $svc->ingest([
                'mailbox'    => 'office@ ' . $folder,
                'message_id' => $h->message_id ?? null,
                'from'       => isset($h->fromaddress) ? imap_utf8($h->fromaddress) : '',
                'to'         => trim((isset($h->toaddress) ? imap_utf8($h->toaddress) : '') . ', ' . (isset($h->ccaddress) ? imap_utf8($h->ccaddress) : ''), ', '),
                'subject'    => isset($h->subject) ? imap_utf8($h->subject) : '',
                'body'       => '',   // fetched only for customer mail, below
                'date'       => $h->date ?? 'now',
            ]);
            if ($res === 'skipped') { $counts['skipped']++; continue; }
            if ($res === 'stored') {
                // Customer mail: now read its text (FT_PEEK) and fill the snippet in.
                $raw = $body($mbox, $no);
                $snip = SalesInboxService::snippet($raw);
                if ($snip !== '') $svc->setSnippet($svc->lastKey, $snip);
                // Inbound from a known contact: keep the signature too (clues check, migration 1206).
                if ($svc->lastDirection === 'inbound') {
                    $sig = SalesInboxService::signature($raw);
                    if ($sig !== '') $svc->setSignature($svc->lastKey, $sig);
                }
            }
            $counts[$res]++;
        } catch (Throwable $e) {
            $salesLog("ERROR on message {$no} in {$folder}: " . $e->getMessage());
            $errors++;
        }
    }
    @imap_close($mbox);
}

$summary = "Read " . implode(' + ', $folders) . ": {$counts['stored']} new customer email(s), {$counts['dupe']} already kept, {$counts['skipped']} not customer mail.";
$salesLog($summary);
$finish($errors > 0 ? 'warning' : 'success', $summary, $errors > 0 ? "{$errors} error(s)" : null);
