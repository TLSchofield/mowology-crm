<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InboundTopicClassifierTest.php';   // the VML email

/**
 * Routing inbound mail to the right head, "Move to…" teaching it, Penny's task for the VML
 * direct-deposit form, and the one-off re-route of misfiled mail (2026-10-08).
 */
class InboundRouteServiceTest extends TestCase
{
    private const NOW = '2026-10-08 09:00:00';
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            rmdir($this->dir);
        }
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, company_id INTEGER)");
        $db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, mailbox TEXT, message_key TEXT UNIQUE, direction TEXT,
                   channel TEXT DEFAULT 'email', contact_id INTEGER, from_addr TEXT, to_addr TEXT, subject TEXT, snippet TEXT, sent_at TEXT,
                   head TEXT, topic TEXT, head_source TEXT, head_reason TEXT, handled_at TEXT)");
        $db->exec("CREATE TABLE inbound_route_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, sender_key TEXT, topic TEXT, head TEXT, hits INTEGER,
                   created_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE (sender_key, topic))");
        $db->exec("CREATE TABLE inbound_route_moves (id INTEGER PRIMARY KEY AUTOINCREMENT, message_key TEXT, from_head TEXT, to_head TEXT,
                   topic TEXT, source TEXT, moved_by INTEGER, moved_at TEXT)");
        $db->exec("CREATE TABLE inbound_message_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, message_key TEXT, filename TEXT, mime TEXT,
                   size_bytes INTEGER, sha256 TEXT, stored_path TEXT, created_at TEXT, UNIQUE (message_key, sha256))");
        $db->exec("INSERT INTO companies VALUES (3, 'Vancouver Management Ltd.')");
        $db->exec("INSERT INTO contacts VALUES (7, 'Alena', 'Radosovska', 'alena@vml.ca', 3), (8, 'Bob', 'Ng', 'bob@acme.ca', NULL), (9, 'Gaby', 'Ruiz', 'gaby@cambridge.ca', NULL)");
        return $db;
    }

    private function msg(PDO $db, string $key, int $cid, string $from, string $subject, string $snippet, string $at, array $extra = []): void
    {
        $row = $extra + ['head' => null, 'head_source' => null, 'handled_at' => null, 'direction' => 'inbound'];
        $db->prepare("INSERT INTO sales_messages (mailbox, message_key, direction, contact_id, from_addr, to_addr, subject, snippet, sent_at, head, head_source, handled_at)
                      VALUES ('icloud INBOX', ?, ?, ?, ?, 'mowology@icloud.com', ?, ?, ?, ?, ?, ?)")
           ->execute([$key, $row['direction'], $cid, $from, $subject, $snippet, $at, $row['head'], $row['head_source'], $row['handled_at']]);
    }

    private function head(PDO $db, string $key): array
    {
        $s = $db->prepare('SELECT head, topic, head_source, handled_at FROM sales_messages WHERE message_key = ?');
        $s->execute([$key]);
        return $s->fetch();
    }

    private function vml(PDO $db, array $extra = [], string $key = '<vml-1@vml.ca>', string $at = '2026-10-07 10:12:00'): void
    {
        $this->msg($db, $key, 7, 'alena@vml.ca', InboundTopicClassifierTest::VML_SUBJECT, InboundTopicClassifierTest::VML_BODY, $at, $extra);
    }

    // ── Stamping at ingest ───────────────────────────────────────────────

    public function test_the_vml_email_is_stamped_pennys_and_a_quote_request_sams(): void
    {
        $db = $this->db();
        $this->vml($db);
        $this->msg($db, '<q-1>', 8, 'bob@acme.ca', 'Quote please', 'Could you quote the spring cleanup at 4180 Oak Ct?', '2026-10-07 11:00:00');
        $svc = new InboundRouteService($db);
        $this->assertSame('penny', $svc->stamp('<vml-1@vml.ca>')['head']);
        $this->assertSame('sam', $svc->stamp('<q-1>')['head']);
        $this->assertSame(['head' => 'penny', 'topic' => 'billing', 'head_source' => 'rule', 'handled_at' => null], $this->head($db, '<vml-1@vml.ca>'));
    }

    public function test_the_mail_readers_route_once_the_text_is_in_and_say_whose_it_is(): void
    {
        $db = $this->db();
        // As ingest() leaves it (INSERT IGNORE is MySQL-only): stored, no text yet.
        $this->msg($db, '<vml-2@vml.ca>', 7, 'alena@vml.ca', InboundTopicClassifierTest::VML_SUBJECT, '', '2026-10-07 10:12:00');
        $in = new SalesInboxService($db);
        $in->setSnippet('<vml-2@vml.ca>', InboundTopicClassifierTest::VML_BODY);
        $this->assertSame('penny', $in->lastHead);
        $this->assertSame('penny', $this->head($db, '<vml-2@vml.ca>')['head']);
        // Only Penny's mail keeps attachments.
        $this->dir = sys_get_temp_dir() . '/mw-ima-' . bin2hex(random_bytes(4));
        $this->assertNull((new SalesInboxService($db))->keepAttachment('<vml-2@vml.ca>', 'f.pdf', 'application/pdf', '%PDF'));
    }

    // ── Move to… ─────────────────────────────────────────────────────────

    public function test_move_reroutes_the_message_and_teaches_sender_and_topic(): void
    {
        $db = $this->db();
        $this->msg($db, '<b-1>', 8, 'bob@acme.ca', 'Re: hello', 'Thanks Tim, talk soon', '2026-10-07 09:00:00');
        $svc = new InboundRouteService($db);
        $this->assertSame('yui', $svc->stamp('<b-1>')['head']);

        $key = UnclaimedReplyService::key(8, '<b-1>', 'client');
        $r = $svc->move(['key' => $key], 'otto', 1, self::NOW);
        $this->assertTrue($r['ok']);
        $this->assertSame(['yui', 'otto', 'general', true], [$r['from'], $r['head'], $r['topic'], $r['taught']]);
        $this->assertStringContainsString('bob@acme.ca goes straight to Otto', $r['message']);
        $this->assertSame(['head' => 'otto', 'topic' => 'general', 'head_source' => 'moved', 'handled_at' => null], $this->head($db, '<b-1>'));

        $rules = $db->query('SELECT sender_key, topic, head, hits FROM inbound_route_rules ORDER BY sender_key')->fetchAll();
        $this->assertSame([['sender_key' => '@acme.ca', 'topic' => 'general', 'head' => 'otto', 'hits' => 1],
                           ['sender_key' => 'bob@acme.ca', 'topic' => 'general', 'head' => 'otto', 'hits' => 1]], $rules);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM inbound_route_moves WHERE source = 'move' AND to_head = 'otto'")->fetchColumn());

        // The next similar email from Bob goes straight to Otto…
        $this->msg($db, '<b-2>', 8, 'bob@acme.ca', 'Re: hello again', 'Thanks again Tim', '2026-10-08 08:00:00');
        $d = $svc->stamp('<b-2>');
        $this->assertSame(['otto', 'learned'], [$d['head'], $d['source']]);
        // …a quote request from him is still Sam's (another topic)…
        $this->msg($db, '<b-3>', 8, 'bob@acme.ca', 'Quote', 'Could you quote the hedges?', '2026-10-08 08:30:00');
        $this->assertSame('sam', $svc->stamp('<b-3>')['head']);
        // …and Tim's move is never re-stamped by the rules.
        $this->assertNull($svc->stamp('<b-1>'));
        // Moving again counts the rule twice.
        $svc->move(['message_key' => '<b-2>'], 'otto', 1, self::NOW);
        $this->assertSame(2, (int)$db->query("SELECT hits FROM inbound_route_rules WHERE sender_key = 'bob@acme.ca'")->fetchColumn());
    }

    public function test_move_finds_sams_replied_card_and_rejects_unknown_heads(): void
    {
        $db = $this->db();
        $this->vml($db);
        $svc = new InboundRouteService($db);
        $this->assertFalse($svc->move(['key' => 'sam:contact:c7'], 'charlie', 1, self::NOW)['ok']);
        $r = $svc->move(['key' => 'sam:contact:c7'], 'penny', 1, self::NOW);
        $this->assertTrue($r['ok']);
        $this->assertSame('penny', $this->head($db, '<vml-1@vml.ca>')['head']);
        $this->assertFalse($svc->move(['key' => 'penny:reply:7:000000000000'], 'sam', 1, self::NOW)['ok']);
    }

    public function test_done_takes_it_off_every_list(): void
    {
        $db = $this->db();
        $this->vml($db);
        $svc = new InboundRouteService($db);
        $this->assertTrue($svc->done(['message_key' => '<vml-1@vml.ca>'], 1, self::NOW)['ok']);
        $this->assertSame(self::NOW, $this->head($db, '<vml-1@vml.ca>')['handled_at']);
    }

    // ── Sam no longer counts billing mail as "they replied" ─────────────

    public function test_only_sams_and_plain_conversation_mail_counts_as_a_reply_to_a_quote(): void
    {
        $vml = ['from_addr' => 'alena@vml.ca', 'subject' => InboundTopicClassifierTest::VML_SUBJECT, 'snippet' => InboundTopicClassifierTest::VML_BODY];
        $this->assertFalse(SalesDeskService::countsAsSamReply($vml));                                   // unstamped: classified now
        $this->assertFalse(SalesDeskService::countsAsSamReply(['head' => 'penny'] + $vml));
        $this->assertTrue(SalesDeskService::countsAsSamReply(['head' => 'sam', 'head_source' => 'moved'] + $vml));
        $this->assertTrue(SalesDeskService::countsAsSamReply(['subject' => 'Re: QUO-2026-0144', 'snippet' => 'Any news?']));
        $this->assertTrue(SalesDeskService::countsAsSamReply(['head' => 'yui', 'head_source' => 'rule']));
        $this->assertFalse(SalesDeskService::countsAsSamReply(['head' => 'yui', 'head_source' => 'moved']));
    }

    public function test_a_billing_email_from_a_customer_sam_shows_as_replied_is_still_pennys(): void
    {
        $items = UnclaimedReplyService::unclaimed([
            ['message_key' => '<vml-1@vml.ca>', 'contact_id' => 7, 'channel' => 'email', 'from_addr' => 'alena@vml.ca',
             'subject' => InboundTopicClassifierTest::VML_SUBJECT, 'snippet' => InboundTopicClassifierTest::VML_BODY,
             'sent_at' => '2026-10-07 10:12:00', 'first_name' => 'Alena'],
        ], ['sam' => [7]], self::NOW);
        $this->assertCount(1, $items);
        $this->assertSame('billing', $items[0]['lane']);
        $this->assertSame('billing_reply', $items[0]['kind']);
        $this->assertMatchesRegularExpression('/^penny:reply:7:[0-9a-f]{12}$/', $items[0]['key']);
        // A row marked done never shows; a stamped head wins over the words.
        $this->assertSame([], UnclaimedReplyService::unclaimed([['handled_at' => self::NOW] + ['message_key' => 'x', 'contact_id' => 7,
            'from_addr' => 'a@b.ca', 'subject' => 'EFT', 'snippet' => 'form', 'sent_at' => '2026-10-07 10:00:00', 'first_name' => 'A']], [], self::NOW));
        $moved = UnclaimedReplyService::unclaimed([['head' => 'otto', 'message_key' => 'y', 'contact_id' => 8, 'from_addr' => 'a@b.ca',
            'subject' => 'EFT', 'snippet' => 'form', 'sent_at' => '2026-10-07 10:00:00', 'first_name' => 'Bob']], [], self::NOW);
        $this->assertSame('ops', $moved[0]['lane']);
    }

    // ── Penny's task ─────────────────────────────────────────────────────

    public function test_penny_turns_the_vml_email_into_a_reminder_for_tim(): void
    {
        $msg = ['from_addr' => 'alena@vml.ca', 'subject' => InboundTopicClassifierTest::VML_SUBJECT,
                'snippet' => InboundTopicClassifierTest::VML_BODY, 'first_name' => 'Alena', 'company_name' => 'Vancouver Management Ltd.'];
        $t = InboundRouteService::pennyTask($msg, true);
        $this->assertSame('banking_form', $t['kind']);
        $this->assertSame('vidhya@vml.ca', $t['email_to']);
        $this->assertSame('Vancouver Management wants your direct-deposit details — the form is attached; fill it in and email vidhya@vml.ca.', $t['text']);

        $it = InboundRouteService::pennyItem(['key' => 'penny:reply:7:abc', 'priority' => 2, 'url' => '/crm/x'], $msg,
                                             [['id' => 12, 'filename' => 'VML EFT form.pdf', 'mime' => 'application/pdf', 'size' => 1000]]);
        $this->assertTrue($it['never_send']);
        $this->assertSame(InboundRouteService::NEVER_SEND_NOTE, $it['note']);
        $this->assertSame('/crm/api/inbound-route.php?mode=attachment&id=12', $it['url']);
        $this->assertSame(1, $it['priority']);

        $app = InboundRouteService::forApp([$it + ['contact_id' => 7]], 2000000000, 'secret');
        $this->assertStringStartsWith(InboundAttachmentService::APP_URL . '?a=12&e=2000000000&s=', $app[0]['attachments'][0]['url']);
        $this->assertSame('/crm/clients_appstack.php?action=view_contact&id=7', $app[0]['contact_url']);
    }

    public function test_other_billing_mail_reads_plainly(): void
    {
        $this->assertSame('remittance', InboundRouteService::pennyTask(['subject' => 'Remittance advice', 'snippet' => 'Payment of $402.02 sent today.', 'first_name' => 'Jodi'])['kind']);
        $t = InboundRouteService::pennyTask(['subject' => 'Re: INV-2026-0358', 'snippet' => 'Is this one overdue?', 'from_addr' => 'jodi@x.ca', 'first_name' => 'Jodi']);
        $this->assertSame('message', $t['kind']);
        $this->assertSame('Jodi wrote about billing: "Is this one overdue?" (INV-2026-0358) — answer them.', $t['text']);
        $this->assertSame('jodi@x.ca', $t['email_to']);
    }

    // ── The one-off re-route ─────────────────────────────────────────────

    public function test_reroute_dry_run_lists_billing_mail_on_sam_and_changes_nothing(): void
    {
        $db = $this->db();
        $this->vml($db);                                                                         // unstamped, billing → moves
        $this->vml($db, ['head' => 'sam', 'head_source' => 'rule'], '<vml-0@vml.ca>', '2026-09-20 10:00:00');   // stamped Sam, billing → moves
        $this->msg($db, '<q-1>', 8, 'bob@acme.ca', 'Quote please', 'Could you quote the spring cleanup?', '2026-10-01 09:00:00');   // stays Sam
        $this->vml($db, [], '<vml-old@vml.ca>', '2026-07-01 10:00:00');                          // older than 60 days
        $this->vml($db, ['head' => 'sam', 'head_source' => 'moved'], '<vml-moved@vml.ca>', '2026-10-02 10:00:00');   // Tim put it on Sam
        $this->vml($db, ['head' => 'penny', 'head_source' => 'rule'], '<vml-p@vml.ca>', '2026-10-03 10:00:00');       // already Penny's

        $svc = new InboundRouteService($db);
        $dry = $svc->reroute(false, 1, 60, self::NOW);
        $this->assertTrue($dry['ok']);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(3, $dry['checked']);
        $this->assertSame(['2026-10-07 10:12:00', '2026-09-20 10:00:00'], array_column($dry['moving'], 'sent_at'));
        $this->assertSame(['unstamped', 'sam'], array_column($dry['moving'], 'from_head'));
        $this->assertSame('Alena Radosovska', $dry['moving'][0]['contact']);
        $this->assertSame(['sam' => 1], $dry['others']);
        $this->assertArrayNotHasKey('message_key', $dry['moving'][0]);
        $this->assertNull($this->head($db, '<vml-1@vml.ca>')['head']);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM inbound_route_moves')->fetchColumn());

        $applied = $svc->reroute(true, 1, 60, self::NOW);
        $this->assertSame(2, $applied['moved']);
        $this->assertSame(['head' => 'penny', 'topic' => 'billing', 'head_source' => 'reroute', 'handled_at' => null], $this->head($db, '<vml-1@vml.ca>'));
        $this->assertSame('sam', $this->head($db, '<vml-moved@vml.ca>')['head']);
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM inbound_route_moves WHERE source = 'reroute'")->fetchColumn());
        $this->assertSame([], $svc->reroute(false, 1, 60, self::NOW)['moving']);
    }

    public function test_reroute_waits_for_migration_1280(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY, message_key TEXT)");
        $this->assertFalse((new InboundRouteService($db))->reroute(false)['ok']);
    }

    // ── Attachments ──────────────────────────────────────────────────────

    public function test_penny_keeps_the_form_once_and_serves_it_only_from_its_folder(): void
    {
        $db = $this->db();
        $this->dir = sys_get_temp_dir() . '/mw-ima-' . bin2hex(random_bytes(4));
        $a = new InboundAttachmentService($db, $this->dir);
        $id = $a->store('<vml-1@vml.ca>', '../../VML "EFT" form.pdf', 'application/pdf', '%PDF-1.4 test', self::NOW);
        $this->assertIsInt($id);
        $this->assertSame($id, $a->store('<vml-1@vml.ca>', 'again.pdf', 'application/pdf', '%PDF-1.4 test', self::NOW));
        $this->assertNull($a->store('<vml-1@vml.ca>', 'x.exe', 'application/x-msdownload', 'MZ', self::NOW));
        $f = $a->find($id);
        $this->assertSame('VML _EFT_ form.pdf', $f['filename']);
        $this->assertSame('%PDF-1.4 test', file_get_contents($f['abs_path']));
        $this->assertSame([$id], array_column($a->forKeys(['<vml-1@vml.ca>'])['<vml-1@vml.ca>'], 'id'));
        $db->exec("UPDATE inbound_message_attachments SET stored_path = '../../etc/passwd'");
        $this->assertNull($a->find($id));
    }

    public function test_signed_links_expire_and_cannot_be_forged(): void
    {
        $url = InboundAttachmentService::appUrl(12, 2000, 'k');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertTrue(InboundAttachmentService::validSignature(12, 2000, $q['s'], 'k', 1000));
        $this->assertFalse(InboundAttachmentService::validSignature(13, 2000, $q['s'], 'k', 1000));
        $this->assertFalse(InboundAttachmentService::validSignature(12, 2000, $q['s'], 'k', 3000));
        $this->assertFalse(InboundAttachmentService::validSignature(12, 2000, $q['s'], 'other', 1000));
    }
}
