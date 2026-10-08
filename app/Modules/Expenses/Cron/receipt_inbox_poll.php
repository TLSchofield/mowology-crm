<?php
/**
 * Receipt Inbox Poller — email-in expense capture.
 *
 * Reads the dedicated receipts mailbox over IMAP, extracts each PDF/image
 * attachment, runs it through the existing OCR + vendor-match pipeline, and
 * creates an expense:
 *   - clean 100% match (known vendor + total + date + vendor category) →
 *     'pending_approval', flagged high-confidence: Penny prepares it first, the owner
 *     approves. Nothing is auto-approved or posted here (changed 2026-10-07).
 *   - anything else → 'draft'
 *   Both are surfaced in the Expenses review panel and on Penny's card.
 *
 * Mailbox:
 *   - receipts@mowology.ca — RECEIPTS_IMAP_PASS in secrets.php
 *
 * Never modifies mail flags: every mailbox opened OP_READONLY (ImapReader), fetches FT_PEEK. Re-processing is prevented by the unique dedup_key
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
// Under cron (CLI) nothing else defines getDB()/Database — the web shim gets them from auth.php.
// Missing since the start: every cPanel run fatalled at getDB() before it could log (2026-10-06).
require_once APP_ROOT . '/Core/config.php';

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';
require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptInboxService.php';
require_once APP_ROOT . '/Modules/Accounting/Services/AccountingService.php';
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
function rpLog(string $m): void { global $log; $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $m; }

function rpFail(string $msg): void {
    global $log, $isCli, $startMs;
    rpLog($msg);

    // Alert once when the cron *transitions* into failure, not on every 15-min
    // tick while it stays broken — avoids re-creating the stdout-spam problem
    // this alert exists to fix. cPanel's raw per-invocation mail keeps firing
    // regardless; this is a second, actually-legible channel for the first hit.
    $wasAlreadyFailing = false;
    try {
        $lastStatus = getDB()
            ->query("SELECT status FROM cron_runs WHERE cron_key = 'receipt_inbox_poll' ORDER BY ran_at DESC LIMIT 1")
            ->fetchColumn();
        $wasAlreadyFailing = ($lastStatus === 'error');
    } catch (\Throwable $e) {
        // cron_runs may not exist yet (first run ever) — treat as a fresh failure.
    }

    recordCronRun('receipt_inbox_poll', 'error', $msg, (int)(microtime(true) * 1000) - $startMs, null, !$isCli);

    if (!$wasAlreadyFailing) {
        try {
            sendCrmEmail(
                'mowology@icloud.com',
                'Receipt inbox poller is failing',
                '<div style="font-family:Arial,sans-serif;max-width:560px;">'
                    . '<h2 style="color:#b45309;">Receipt inbox poller failed</h2>'
                    . '<p>' . htmlspecialchars($msg) . '</p>'
                    . '<p style="color:#555;font-size:13px;">This alert fires once when the cron starts failing '
                    . '(not on every 15-min run) — check the Cron Jobs dashboard for ongoing status.</p>'
                    . '</div>'
            );
        } catch (\Throwable $e) {
            error_log('[receipt_inbox_poll] failure-alert email error: ' . $e->getMessage());
        }
    }

    if ($isCli) { echo implode("\n", $log) . "\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => $msg, 'log' => $log]);
    exit;
}

if (!function_exists('imap_open')) {
    rpFail('FATAL: PHP imap extension not available.');
}
$receiptsBox = MailboxConfig::get('receipts');
if (!$receiptsBox || !$receiptsBox['configured']) {
    rpFail('FATAL: RECEIPTS_IMAP_PASS not configured in secrets.php.');
}

$db      = getDB();
$service = new ReceiptInboxService($db);

$user  = $receiptsBox['user'];
$since = date('d-M-Y', strtotime('-21 days'));

// Attribute auto-created expenses to an admin user (created_by is NOT NULL).
$systemUserId = (int) ($db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($systemUserId <= 0) {
    $systemUserId = (int) ($db->query("SELECT MIN(id) FROM users")->fetchColumn() ?: 0);
}
if ($systemUserId <= 0) {
    rpFail('FATAL: no users found to attribute expenses to.');
}

ImapReader::timeouts();

// Launch floor: ignore receipts received before the feature went live.
$floorTs = strtotime((defined('RECEIPTS_POLL_FLOOR') ? RECEIPTS_POLL_FLOOR : '2026-06-19') . ' 00:00:00');

// MIME helpers moved to ImapReader (2026-10-07) so the iCloud router walks attachments the same way.
function rpDecode(string $data, int $enc): string { return ImapReader::decode($data, $enc); }
function rpBodyPart($part, string $pn = ''): ?array { return ImapReader::bodyPart($part, $pn); }
function rpWalk($part, string $pn, array &$acc): void { $acc = array_merge($acc, ImapReader::attachments($part, $pn)); }

// Mailboxes: receipts@ takes everything; office@ (shared business inbox, added 2026-10-06)
// only receipt-looking mail, read-only, from the day it was added.
$mailboxes = [$receiptsBox + ['filter' => false, 'floor' => $floorTs]];
foreach (MailboxConfig::forPurpose('receipts') as $mbCfg) {
    if ($mbCfg['key'] === 'office' && strtolower($mbCfg['user']) !== strtolower($user)) {
        $mailboxes[] = $mbCfg + ['filter' => true, 'floor' => strtotime('2026-10-06 00:00:00')];
    }
}
// Tim's personal iCloud inbox (2026-10-06, his call) is NOT read here any more (2026-10-07):
// IcloudInboxRouter reads it once and hands receipt-looking mail to ReceiptInboxService with the
// same personal filter (a mail from himself is not automatically a receipt there).
$lower = fn($rows) => array_values(array_filter(array_map(fn($e) => strtolower(trim((string)$e)), $rows)));
$ownerEmails = ['mowology@icloud.com'];
$clientEmails = [];
try { $ownerEmails = array_merge($ownerEmails, $lower($db->query("SELECT email FROM users WHERE email IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN))); } catch (\Throwable $e) {}
try { $clientEmails = $lower($db->query("SELECT email FROM contacts WHERE email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_COLUMN)); } catch (\Throwable $e) {}

$cleanMatches = [];
$pending    = [];
$seen = 0;
$searchFailed = false;
$searchError  = null;

foreach ($mailboxes as $mb) {
    $user = $mb['user'];
    $mbox = ImapReader::open($mb, 'INBOX');   // OP_READONLY for every mailbox (fetches use FT_PEEK)
    if ($mbox === false) {
        if (!$mb['filter']) rpFail("ERROR: could not log into {$user}: " . implode('; ', imap_errors() ?: ['unknown']));
        rpLog("WARNING: could not log into {$user}: " . implode('; ', imap_errors() ?: ['unknown']));
        continue;
    }

    // imap_search() returns false for "no matches" too — only an IMAP error on the stack is a
    // real failure (the Sep 22 version reported every empty window as a failed search).
    imap_errors();   // clear notices left over from the login
    $rawHits = @imap_search($mbox, 'SINCE "' . $since . '"');
    $searchErrors = $rawHits === false ? (imap_errors() ?: []) : [];
    $searchFailed = $searchFailed || (bool)$searchErrors;
    if ($rawHits === false && !$searchErrors) {
        $rawHits = [];   // nothing new in the window
    }
    if ($searchErrors) {
        $searchError = implode('; ', $searchErrors);
        rpLog("WARNING: imap_search failed ({$searchError}) — treating as 0 results this run, not a confirmed empty mailbox.");
        $hits = [];
    } else {
        $hits = $rawHits;
    }
    rpLog("{$user}: " . count($hits) . ' email(s) in window');


    foreach ($hits as $msgNo) {
        try {
            $hdr     = @imap_headerinfo($mbox, $msgNo);
            $subject = $hdr && isset($hdr->subject) ? imap_utf8($hdr->subject) : '';
            $msgId   = $hdr->message_id ?? null;
            $date    = $hdr->date ?? null;
            $from    = ($hdr && !empty($hdr->from) && isset($hdr->from[0]))
                     ? (($hdr->from[0]->mailbox ?? '') . '@' . ($hdr->from[0]->host ?? '')) : null;

            if ($date && $mb['floor'] && strtotime($date) < $mb['floor']) { continue; }

            $struct = @imap_fetchstructure($mbox, $msgNo);
            if (!$struct) { continue; }

            $parts = [];
            if (!empty($struct->parts)) {
                rpWalk($struct, '', $parts);
            }
            if (empty($parts) && ReceiptInboxService::isBodyReceipt($from, $subject, empty($mb['personal']) ? $ownerEmails : [], $clientEmails)) {
                // The receipt IS the email (RONA, Amazon…): read its text (2026-10-06).
                $bp = rpBodyPart($struct);
                if ($bp) {
                    $body = rpDecode((string)imap_fetchbody($mbox, $msgNo, $bp['pn'], FT_PEEK), $bp['encoding']);
                    if ($bp['charset'] !== 'UTF-8' && $bp['charset'] !== 'US-ASCII') {
                        $body = (string)@mb_convert_encoding($body, 'UTF-8', $bp['charset']);
                    }
                    if (!$bp['html']) $body = nl2br(htmlspecialchars($body));
                    $seen++;
                    $res = $service->ingestEmailBody(['message_id' => $msgId, 'sender_email' => $from, 'subject' => $subject, 'email_date' => $date], $body, $systemUserId);
                    rpLog("BODY RECEIPT: {$from} — {$subject} → {$res['status']}" . ($res['expense_id'] ? " expense #{$res['expense_id']}" : ''));
                    if ($res['status'] === 'pending') {
                        $pending[] = ['who' => $from, 'subject' => $subject, 'file' => 'email', 'id' => $res['expense_id'], 'note' => 'read from the email itself'];
                    }
                }
                continue;
            }
            if (empty($parts)) {
                if ($mb['filter'] && empty($mb['personal'])) rpLog("office@ skip (no PDF/photo attached): {$from} — {$subject}");
                // No PDF/image attachment — nothing to ingest. Body-only receipts
                // (Stripe/Amazon/Uber HTML) are phase 2. Cheap to re-scan headers.
                continue;
            }

            // office@ is a shared inbox: only receipt-looking mail (ReceiptInboxService::isOfficeReceipt).
            if ($mb['filter'] && !ReceiptInboxService::isOfficeReceipt($from, $subject, (string)($parts[0]['filename'] ?? ''), empty($mb['personal']) ? $ownerEmails : [], $clientEmails)) {
                if (empty($mb['personal'])) rpLog("office@ skip (doesn't look like a receipt): {$from} — {$subject} — " . ($parts[0]['filename'] ?? ''));
                continue;
            }

            $msgMeta = ['message_id' => $msgId, 'sender_email' => $from, 'subject' => $subject, 'email_date' => $date];

            foreach ($parts as $p) {
                $seen++;
                $raw   = imap_fetchbody($mbox, $msgNo, $p['pn'], FT_PEEK);   // never marks mail as read
                $bytes = rpDecode($raw, $p['encoding']);
                if ($bytes === '' || strlen($bytes) > 15 * 1024 * 1024) { continue; } // skip empty / >15MB

                $res = $service->ingestAttachment($msgMeta, $bytes, $p['filename'], $p['mime'], $systemUserId);
                $line = "{$from} — {$p['filename']} ({$p['mime']}) → {$res['status']}";
                if ($res['status'] === 'pending' && !empty($res['high_confidence'])) {
                    // Clean match: waits for approval (Penny prepares it first) — never auto-approved.
                    $cleanMatches[] = ['who' => $from, 'subject' => $subject, 'file' => $p['filename'], 'id' => $res['expense_id']];
                    rpLog("CLEAN MATCH (awaiting approval): {$line} expense #{$res['expense_id']}");
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
}
rpLog("Scanned {$seen} attachment(s): " . count($cleanMatches) . ' clean match(es) awaiting approval, ' . count($pending) . ' pending.');

// Nothing is posted to the books here any more: every receipt waits for the owner's
// approval (the sync-ledger cron posts it after that).

// Summary email — only when something new arrived.
if (!empty($cleanMatches) || !empty($pending)) {
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

    $count   = count($cleanMatches) + count($pending);
    $subject = "{$count} emailed receipt" . ($count === 1 ? '' : 's') . ' processed';
    $link    = (defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://mowology.ca') . '/crm/expenses_appstack.php#receipt-inbox';
    $html    = '<div style="font-family:Arial,sans-serif;max-width:560px;">'
             . '<h2 style="color:#1A5F4A;">Emailed receipts processed</h2>'
             . $section('Clean match — high confidence, waiting for your approval', $cleanMatches, '#2D8659')
             . $section('Needs review', $pending, '#b45309')
             . '<p style="margin-top:18px;"><a href="' . $link . '" style="background:#2D8659;color:#fff;'
             . 'padding:10px 18px;border-radius:6px;text-decoration:none;">Open Expenses review</a></p>'
             . '</div>';

    $to = 'mowology@icloud.com';
    $ok = sendCrmEmail($to, $subject, $html);
    rpLog("Summary email to {$to}: " . ($ok ? 'sent' : 'FAILED'));
}

$summary = "Scanned {$seen} attachment(s): " . count($cleanMatches) . ' clean match(es) awaiting approval, ' . count($pending) . ' pending.';
if ($searchFailed) {
    $summary = 'IMAP search failed (mail server may be flaky) — ' . $summary;
}
recordCronRun(
    'receipt_inbox_poll',
    $searchFailed ? 'warning' : 'success',
    $summary,
    (int)(microtime(true) * 1000) - $startMs,
    $searchFailed ? $searchError : null,
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
