// inbox-backfill-scan.php — one window of a read-only look back through a mailbox for mail from unknown senders (2026-11-10).
// Run through scripts/inbox-backfill.py, which sets $MB, $FOLDER, $DAYS, $CURSOR and keeps calling until done.
// READ-ONLY: ImapReader (EXAMINE) and FT_PEEK, no database write. Bulk envelopes by UID, a time budget, resume (a per-message
// header read over the 71,827-message iCloud INBOX is a 504). Only mail that matches an account is returned, so nobody's
// personal mail leaves this script.
require_once APP_ROOT . '/Modules/Sales/Services/SalesInboxService.php';
require_once APP_ROOT . '/Modules/Comms/Services/InboundIdentityService.php';
require_once APP_ROOT . '/Services/Mail/MailboxConfig.php';
require_once APP_ROOT . '/Services/Mail/ImapReader.php';

$deadline = microtime(true) + 150;
$config = MailboxConfig::forPurpose($MB === 'icloud' ? 'router' : $MB)[0] ?? null;
if ($config === null) throw new RuntimeException($MB . ' mailbox not configured');
$svc = new SalesInboxService($db);
$contacts = $svc->contactMap();
$join = $svc->joinContext();
$index = InboundIdentityService::buildIndex($db);
$seen = $db->prepare("SELECT (SELECT COUNT(*) FROM sales_messages WHERE message_key = ?) + (SELECT COUNT(*) FROM inbound_unplaced WHERE message_key = ?)");
ImapReader::timeouts();
$mbox = ImapReader::open($config, $FOLDER);
if ($mbox === false) throw new RuntimeException('could not open ' . $FOLDER);
$uids = @imap_search($mbox, 'SINCE "' . date('d-M-Y', strtotime('-' . (int)$DAYS . ' days')) . '"', SE_UID);
$uids = is_array($uids) ? $uids : [];
rsort($uids);
$out = ['total' => count($uids), 'cursor' => $CURSOR, 'done' => false, 'items' => [],
        'counts' => ['ours' => 0, 'known' => 0, 'noise_envelope' => 0, 'seen' => 0, 'bodies' => 0, 'no_match' => 0, 'match' => 0, 'unreadable' => 0]];
$todo = $CURSOR > 0 ? array_values(array_filter($uids, fn($u) => $u < $CURSOR)) : $uids;
$out['left_at_start'] = count($todo);
foreach (array_chunk($todo, 100) as $chunk) {
    if (microtime(true) > $deadline) break;
    $over = @imap_fetch_overview($mbox, implode(',', $chunk), FT_UID) ?: [];
    usort($over, fn($a, $b) => (int)$b->uid <=> (int)$a->uid);
    foreach ($over as $o) {
        if (microtime(true) > $deadline) break 2;
        $out['cursor'] = (int)$o->uid;
        $from = isset($o->from) ? imap_utf8((string)$o->from) : '';
        $to = isset($o->to) ? imap_utf8((string)$o->to) : '';
        $subject = InboundIdentityService::decodeHeader(isset($o->subject) ? imap_utf8((string)$o->subject) : '');
        $addr = SalesInboxService::addresses($from)[0] ?? '';
        if ($addr === '' || SalesInboxService::isOurs($addr)) { $out['counts']['ours']++; continue; }
        if (SalesInboxService::classifyAny($from, $to, $subject, $contacts, $join) !== null) { $out['counts']['known']++; continue; }
        if (InboundIdentityService::triage($from, $subject, '')['kind'] === 'noise') { $out['counts']['noise_envelope']++; continue; }
        $m = ['mailbox' => $MBLABEL . ' ' . $FOLDER, 'message_id' => $o->message_id ?? null, 'from' => $from, 'to' => $to, 'subject' => $subject, 'body' => '', 'date' => $o->date ?? 'now'];
        $key = SalesInboxService::messageKey($m);
        $seen->execute([$key, $key]);
        if ((int)$seen->fetchColumn() > 0) { $out['counts']['seen']++; continue; }
        $no = imap_msgno($mbox, (int)$o->uid);
        try { $body = ImapReader::textBody($mbox, $no); } catch (Throwable $e) { $out['counts']['unreadable']++; continue; }   // a charset PHP refuses
        $out['counts']['bodies']++;
        $id = InboundIdentityService::identify(['from' => $from, 'subject' => $subject, 'body' => $body], $index);
        if ($id['strength'] === 'none' && !preg_match('/^\s*(re|fw|fwd)\s*:/i', $subject)) { $out['counts']['no_match']++; continue; }
        $t = InboundIdentityService::triage($from, $subject, $body, ImapReader::rawHeaders($mbox, $no));
        if ($t['kind'] === 'person') $id = InboundIdentityService::withReplyEvidence($db, $id, $from, $subject);
        if ($id['strength'] === 'none' || $t['kind'] !== 'person') { $out['counts']['no_match']++; continue; }
        $out['counts']['match']++;
        $supplier = $svc->fromSupplier($from);
        $out['items'][] = ['uid' => (int)$o->uid, 'mb' => $MB, 'folder' => $FOLDER, 'would' => ($id['strength'] === 'strong' && !$supplier && $id['contact_id'] !== null) ? 'place' : ($supplier ? 'supplier' : 'ask'),
            'strength' => $id['strength'], 'from' => $from, 'subject' => $subject, 'date' => date('Y-m-d', strtotime((string)($o->date ?? 'now')) ?: time()),
            'contact_id' => $id['contact_id'], 'evidence' => $id['evidence'], 'snippet' => mb_substr(SalesInboxService::snippet($body), 0, 160),
            'candidates' => array_map(fn($c) => $c['name'] . ' [' . implode('+', $c['evidence']) . ']', $id['candidates'])];
    }
}
$out['done'] = !array_filter($todo, fn($u) => $u < $out['cursor']) && $out['cursor'] !== $CURSOR || !$todo;
@imap_close($mbox);
$out['seconds'] = round(150 - ($deadline - microtime(true)), 1);
