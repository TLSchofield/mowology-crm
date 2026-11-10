// inbox-replay.php — what the unknown-sender placement WOULD do to the last 30 days of office@ (2026-11-09).
// Run with: scripts/prod-run.sh scripts/inbox-replay.php        (plain PHP, no opening tag; gets $db and $out)
// READ-ONLY: opens INBOX through ImapReader (EXAMINE), bodies and headers with FT_PEEK only, writes nothing —
// no database row, no flag, no move. Mail classifyAny() already keeps (known contacts, quote joins) and our own mail are skipped.
// (A snippet is pasted inline by prod-run.sh, so it never uses return — errors are thrown and show as _error.)
// Output: counts by kind / strength / reason; every strong / medium / weak placement; a sample of 'person' with nothing matched.
require_once APP_ROOT . '/Modules/Sales/Services/SalesInboxService.php';
require_once APP_ROOT . '/Modules/Comms/Services/InboundIdentityService.php';
require_once APP_ROOT . '/Services/Mail/MailboxConfig.php';
require_once APP_ROOT . '/Services/Mail/ImapReader.php';

$deadline = microtime(true) + 250;
$out = ['window' => '30 days', 'read' => 0, 'already_kept' => 0, 'ours' => 0, 'unknown' => 0, 'stopped_early' => false,
        'by_kind' => [], 'by_strength' => [], 'by_reason' => [], 'placements' => [], 'person_none_sample' => []];

$office = MailboxConfig::forPurpose('sales')[0] ?? null;
if ($office === null || !function_exists('imap_open')) throw new RuntimeException('office@ not configured or imap missing');
$svc = new SalesInboxService($db);
$contacts = $svc->contactMap();
$join = $svc->joinContext();
$index = InboundIdentityService::buildIndex($db);
$out['accounts_in_index'] = count($index);

ImapReader::timeouts();
$mbox = ImapReader::open($office, 'INBOX');
if ($mbox === false) throw new RuntimeException('could not open office@ INBOX');
$hits = @imap_search($mbox, 'SINCE "' . date('d-M-Y', strtotime('-30 days')) . '"');
$hits = is_array($hits) ? $hits : [];
$out['messages_in_window'] = count($hits);

foreach (array_reverse($hits) as $no) {   // newest first, so an early stop loses the oldest
    if (microtime(true) > $deadline) { $out['stopped_early'] = true; break; }
    $h = @imap_headerinfo($mbox, $no);
    if (!$h) continue;
    $out['read']++;
    $from = isset($h->fromaddress) ? imap_utf8($h->fromaddress) : '';
    $to = trim((isset($h->toaddress) ? imap_utf8($h->toaddress) : '') . ', ' . (isset($h->ccaddress) ? imap_utf8($h->ccaddress) : ''), ', ');
    $subject = InboundIdentityService::decodeHeader(isset($h->subject) ? imap_utf8($h->subject) : '');
    $addr = SalesInboxService::addresses($from)[0] ?? '';
    if ($addr === '' || SalesInboxService::isOurs($addr)) { $out['ours']++; continue; }
    if (SalesInboxService::classifyAny($from, $to, $subject, $contacts, $join) !== null) { $out['already_kept']++; continue; }
    $out['unknown']++;
    $body = ImapReader::textBody($mbox, $no);
    $headers = ImapReader::rawHeaders($mbox, $no);
    $id = InboundIdentityService::identify(['from' => $from, 'subject' => $subject, 'body' => $body], $index);
    $t = InboundIdentityService::triage($from, $subject, $body, $headers);
    if ($t['kind'] === 'person') $id = InboundIdentityService::withReplyEvidence($db, $id, $from, $subject);
    $supplier = $t['kind'] === 'person' && $svc->fromSupplier($from);
    if ($supplier) $out['supplier'] = ($out['supplier'] ?? 0) + 1;
    $out['by_kind'][$t['kind']] = ($out['by_kind'][$t['kind']] ?? 0) + 1;
    $out['by_strength'][$id['strength']] = ($out['by_strength'][$id['strength']] ?? 0) + 1;
    $rk = $t['kind'] . ': ' . $t['reason'];
    $out['by_reason'][$rk] = ($out['by_reason'][$rk] ?? 0) + 1;
    $date = date('Y-m-d H:i', strtotime((string)($h->date ?? 'now')) ?: time());
    if ($id['strength'] !== 'none') {
        // What the poll would do: strong + person = stored on the contact; anything else waits in inbound_unplaced.
        $out['placements'][] = ['would' => ($id['strength'] === 'strong' && $t['kind'] === 'person' && !$supplier && $id['contact_id'] !== null) ? 'place on contact' : 'keep for owner',
            'kind' => $t['kind'], 'strength' => $id['strength'], 'from' => $from, 'subject' => $subject, 'date' => $date,
            'account' => $id['client_id'] === null ? '(several)' : implode('', array_column(array_filter($id['candidates'], fn($c) => $c['client_id'] === $id['client_id']), 'name')),
            'contact_id' => $id['contact_id'], 'evidence' => $id['evidence'],
            'candidates' => array_map(fn($c) => $c['name'] . ' [' . implode('+', $c['evidence']) . ']', $id['candidates'])];
    } elseif ($t['kind'] === 'person' && !$supplier && ($sc = EmailLeadService::score($subject, SalesInboxService::snippet($body), $addr, $headers))['strength'] !== null) {
        $out['would_lead'][] = ['strength' => $sc['strength'], 'from' => $from, 'subject' => $subject, 'date' => $date];
    } elseif ($t['kind'] === 'person' && !$supplier && count($out['person_none_sample']) < 40) {
        $out['person_none_sample'][] = ['from' => $from, 'subject' => $subject, 'date' => $date];
    }
}
@imap_close($mbox);
arsort($out['by_reason']);
