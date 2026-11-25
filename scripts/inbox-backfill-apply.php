// inbox-backfill-apply.php — bring reviewed look-back matches in through the normal placement step (2026-11-10).
// Set by the caller: $MB, $FOLDER, $MBLABEL, $UIDS. Preview without --commit first. The mailbox is only ever read.
require_once APP_ROOT . '/Core/RePrepareStatement.php';
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['RePrepareStatement', [$db]]);
require_once APP_ROOT . '/Modules/Sales/Services/SalesInboxService.php';
require_once APP_ROOT . '/Services/Mail/MailboxConfig.php';
require_once APP_ROOT . '/Services/Mail/ImapReader.php';
$config = MailboxConfig::forPurpose($MB === 'icloud' ? 'router' : $MB)[0] ?? null;
if ($config === null) throw new RuntimeException($MB . ' mailbox not configured');
$svc = new SalesInboxService($db);
ImapReader::timeouts();
$mbox = ImapReader::open($config, $FOLDER);
if ($mbox === false) throw new RuntimeException('could not open ' . $FOLDER);
$out = ['results' => []];
foreach (@imap_fetch_overview($mbox, implode(',', $UIDS), FT_UID) ?: [] as $o) {
    $no = imap_msgno($mbox, (int)$o->uid);
    $m = ['mailbox' => $MBLABEL . ' ' . $FOLDER, 'message_id' => $o->message_id ?? null, 'from' => isset($o->from) ? imap_utf8((string)$o->from) : '',
          'to' => isset($o->to) ? imap_utf8((string)$o->to) : '', 'subject' => isset($o->subject) ? imap_utf8((string)$o->subject) : '', 'body' => '', 'date' => $o->date ?? 'now'];
    $out['results'][(int)$o->uid] = $svc->placeUnknown($m, fn() => [ImapReader::textBody($mbox, $no), ImapReader::rawHeaders($mbox, $no)], null);
}
@imap_close($mbox);
