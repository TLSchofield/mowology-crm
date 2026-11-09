<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeTidyPort.php';

/**
 * Tidy iCloud end to end against a fake mailbox: preview writes nothing to the mailbox,
 * apply moves only what the preview showed (and only on request), every move is logged,
 * and undo puts each message back.
 */
class MailTidyServiceTest extends TestCase
{
    private PDO $db;
    private FakeTidyPort $port;
    private float $now;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        foreach ([
            "CREATE TABLE mail_tidy_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_uid TEXT UNIQUE, rule_type TEXT, folder_key TEXT, match_value TEXT,
                label TEXT, note TEXT, is_existing INT DEFAULT 0, sort_order INT DEFAULT 0, is_active INT DEFAULT 1, updated_at TEXT)",
            "CREATE TABLE mail_tidy_previews (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT DEFAULT 'scanning', created_by INT, created_at TEXT, updated_at TEXT,
                scanned INT DEFAULT 0, to_move INT DEFAULT 0, rescue INT DEFAULT 0, stayed INT DEFAULT 0, note TEXT)",
            "CREATE TABLE mail_tidy_cursors (preview_id INT, folder TEXT, role TEXT DEFAULT 'tidy', uid_validity INT, uid_next INT, last_uid INT DEFAULT 0,
                scanned INT DEFAULT 0, stayed INT DEFAULT 0, recent INT DEFAULT 0, done INT DEFAULT 0, PRIMARY KEY (preview_id, folder))",
            "CREATE TABLE mail_tidy_items (id INTEGER PRIMARY KEY AUTOINCREMENT, preview_id INT, kind TEXT, folder TEXT, uid INT, uid_validity INT, message_id TEXT,
                from_addr TEXT, subject TEXT, msg_date TEXT, target_key TEXT, target_folder TEXT, confidence INT, reason TEXT, selected INT DEFAULT 1,
                status TEXT DEFAULT 'pending', batch_id INT, UNIQUE (preview_id, folder, uid))",
            "CREATE TABLE mail_tidy_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, preview_id INT, kind TEXT, undo_of INT, status TEXT DEFAULT 'running',
                started_by INT, started_at TEXT, finished_at TEXT, target INT DEFAULT 0, moved INT DEFAULT 0, failed INT DEFAULT 0, note TEXT)",
            "CREATE TABLE mail_tidy_moves (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INT, message_id TEXT, uid INT, uid_validity INT, from_folder TEXT,
                to_folder TEXT, moved_at TEXT, undone_at TEXT, undo_batch_id INT)",
        ] as $sql) $this->db->exec($sql);

        $this->now = (float)strtotime('2026-10-07 12:00:00');
        $this->port = new FakeTidyPort(['INBOX', 'Archive', 'Junk', 'Junk E-mailings oh', 'Sent Messages', 'Drafts', 'Deleted Items',
                                        'clients', 'RECEIPTS', 'Money', 'Newsletters &- Promos', 'Apple &- Tech', 'Arlo', 'Jobber', 'Personal']);
    }

    private function svc(array $opts = []): MailTidyService
    {
        $ctx = ['contacts' => ['jodi@vanmgmt.ca' => 7], 'vendors' => [['id' => 3, 'name' => 'Lawnboy', 'aliases' => null, 'website' => 'lawnboy.ca']],
                'ours' => ['mowology@icloud.com']];
        $now = &$this->now;
        return new MailTidyService($this->db, $this->port, $opts + [
            'context' => $ctx,
            'clock' => function () use (&$now): float { return $now; },
            'sleep' => function (int $ms): void {},
        ]);
    }

    private function seed(): void
    {
        $p = $this->port;
        $p->add('INBOX', ['from' => 'Jodi <jodi@vanmgmt.ca>', 'subject' => 'Re: snow contract', 'message_id' => '<c1@x>']);
        $p->add('INBOX', ['from' => 'notify@payments.interac.ca', 'subject' => 'INTERAC e-Transfer', 'message_id' => '<p1@x>']);
        $p->add('INBOX', ['from' => 'estatements@vancity.com', 'subject' => 'Your eStatement', 'message_id' => '<b1@x>']);
        $p->add('INBOX', ['from' => 'editor@gardenweekly.com', 'subject' => 'Shade plants', 'message_id' => '<n1@x>',
                          'headers' => "From: editor@gardenweekly.com\r\nList-Unsubscribe: <mailto:u@g.com>\r\n"]);
        $p->add('INBOX', ['from' => 'editor@gardenweekly.com', 'subject' => 'More plants', 'message_id' => '<n2@x>',
                          'headers' => "List-Unsubscribe: <mailto:u@g.com>\r\n"]);
        $p->add('INBOX', ['from' => 'bob@shaw.ca', 'subject' => 'Dinner?', 'message_id' => '<u1@x>']);               // unsure → stays
        $p->add('INBOX', ['from' => 'sales@lawnboy.ca', 'subject' => 'Parts', 'message_id' => null]);                  // no Message-ID → stays
        $p->add('INBOX', ['from' => 'Jodi <jodi@vanmgmt.ca>', 'subject' => 'Recent', 'message_id' => '<c2@x>',
                          'udate' => strtotime('2026-10-01')]);                                                        // last 30 days → stays
        $p->add('Archive', ['from' => 'sales@lawnboy.ca', 'subject' => 'Invoice 4411', 'message_id' => '<v1@x>']);
        $p->add('Sent Messages', ['from' => 'mowology@icloud.com', 'to' => 'jodi@vanmgmt.ca', 'subject' => 'sent', 'message_id' => '<s1@x>']);
        $p->add('Junk', ['from' => 'jodi@vanmgmt.ca', 'subject' => 'Payment for invoice', 'message_id' => '<j1@x>']);  // false positive
        $p->add('Junk', ['from' => 'win@bigprize.biz', 'subject' => 'You won', 'message_id' => '<j2@x>']);              // spam
        $p->add('Junk', ['from' => 'alerts@secure-interac.ru', 'subject' => 'INTERAC e-Transfer claim', 'message_id' => '<j3@x>']);
    }

    private function preview(MailTidyService $svc): int
    {
        $id = $svc->startPreview(1);
        for ($i = 0; $i < 10; $i++) {
            if ($svc->scanStep($id, 30)['status'] === 'ready') break;
        }
        return $id;
    }

    public function test_preview_reads_only_and_proposes_the_right_moves(): void
    {
        $this->seed();
        $before = $this->port->boxes;
        $svc = $this->svc();
        $id = $this->preview($svc);
        $this->assertSame($before, $this->port->boxes, 'a preview never changes the mailbox');
        $this->assertSame([], $this->port->moves);
        $this->assertSame([], $this->port->created);

        $s = $svc->summary($id, $this->port->folders());
        $this->assertSame('ready', $s['preview']['status']);
        $pairs = [];
        foreach ($s['pairs'] as $p) $pairs[$p['from'] . ' → ' . $p['to']] = $p['count'];
        ksort($pairs);
        $this->assertSame([
            'Archive → RECEIPTS' => 1,
            'INBOX → Banking &- Tax' => 1,
            'INBOX → Money' => 1,
            'INBOX → Newsletters &- Promos' => 2,
            'INBOX → clients' => 1,
        ], $pairs);
        $this->assertSame([['imap' => 'Banking &- Tax', 'label' => 'Banking & Tax']], $s['create'], 'only folders with no existing equivalent are created');
        $this->assertSame(['jodi@vanmgmt.ca'], array_column($s['rescue'], 'from_addr'), 'only the client mail is rescued from Junk');
        $this->assertSame('Payment for invoice', $s['rescue'][0]['subject']);
        // Sent / Drafts are never scanned; the newsletter sender's headers are fetched once.
        $this->assertSame(['Archive', 'INBOX', 'Junk', 'Junk E-mailings oh'], array_column($this->db->query('SELECT folder FROM mail_tidy_cursors ORDER BY folder')->fetchAll(), 'folder'));
        $this->assertSame(1, count(array_filter($this->port->headerFetches, fn($h) => in_array($h, ['INBOX#4', 'INBOX#5'], true))));
        $inbox = $this->db->query("SELECT stayed, recent FROM mail_tidy_cursors WHERE folder = 'INBOX'")->fetch();
        $this->assertSame([2, 1], [(int)$inbox['stayed'], (int)$inbox['recent']]);
    }

    public function test_preview_resumes_across_steps(): void
    {
        for ($i = 0; $i < 450; $i++) $this->port->add('INBOX', ['from' => 'statements' . ($i % 7) . '@vancity.com', 'subject' => "Statement $i", 'message_id' => "<m$i@x>"]);
        $svc = $this->svc();
        $id = $svc->startPreview(1);
        $tick = 0;
        $now = &$this->now;
        $svc2 = new MailTidyService($this->db, $this->port, ['context' => ['contacts' => [], 'vendors' => [], 'ours' => []],
            'clock' => function () use (&$now, &$tick): float { return $now + 7 * ($tick++); }, 'sleep' => function (int $ms): void {}]);
        $r = $svc2->scanStep($id, 10);        // budget runs out after a chunk
        $this->assertSame('scanning', $r['status']);
        for ($i = 0; $i < 20 && $r['status'] !== 'ready'; $i++) $r = $svc2->scanStep($id, 10);
        $this->assertSame('ready', $r['status']);
        $this->assertSame(450, (int)$this->db->query("SELECT COUNT(*) FROM mail_tidy_items WHERE preview_id = $id")->fetchColumn());
        $this->assertSame(5, (int)$this->db->query("SELECT COUNT(*) FROM mail_tidy_items WHERE subject IS NOT NULL")->fetchColumn(), 'subjects only for 5 samples');
    }

    public function test_apply_moves_logs_and_undo_round_trips(): void
    {
        $this->seed();
        $svc = $this->svc();
        $id = $this->preview($svc);

        $r = $svc->applyStep($id, 'apply', null, 1, 60);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['done']);
        $this->assertSame(6, $r['moved']);
        $this->assertSame(['Banking &- Tax'], $this->port->created);
        $this->assertContains('<c1@x>', $this->port->ids('clients'));
        $this->assertContains('<p1@x>', $this->port->ids('Money'));
        $this->assertContains('<v1@x>', $this->port->ids('RECEIPTS'));
        $this->assertSame(['<u1@x>', '', '<c2@x>'], $this->port->ids('INBOX'), 'unsure, no-Message-ID and recent mail stay');
        $this->assertSame(3, count($this->port->ids('Junk')), 'Apply never touches Junk — rescue is separate');
        $this->assertSame(['<s1@x>'], $this->port->ids('Sent Messages'));

        $log = $this->db->query("SELECT message_id, uid, uid_validity, from_folder, to_folder, batch_id FROM mail_tidy_moves ORDER BY id")->fetchAll();
        $this->assertCount(6, $log);
        $this->assertSame((int)$r['batch_id'], (int)$log[0]['batch_id']);
        $this->assertNotEmpty($log[0]['uid_validity']);

        $u = $svc->undoStep((int)$r['batch_id'], null, 1, 60);
        $this->assertTrue($u['done']);
        $this->assertSame(6, $u['moved']);
        $this->assertEqualsCanonicalizing(['<c1@x>', '<p1@x>', '<b1@x>', '<n1@x>', '<n2@x>', '<u1@x>', '', '<c2@x>'], $this->port->ids('INBOX'));
        $this->assertSame(['<v1@x>'], $this->port->ids('Archive'));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mail_tidy_moves WHERE batch_id = {$r['batch_id']} AND undone_at IS NULL")->fetchColumn());
        $this->assertFalse($svc->undoStep((int)$u['batch_id'], null, 1, 60)['ok'], 'an undo cannot itself be undone');
        // Undone items are not re-applied by the next click.
        $this->assertSame(0, $svc->applyStep($id, 'apply', null, 1, 60)['moved']);
    }

    public function test_rescue_only_moves_ticked_junk_to_inbox(): void
    {
        $this->seed();
        $svc = $this->svc();
        $id = $this->preview($svc);
        $item = (int)$this->db->query("SELECT id FROM mail_tidy_items WHERE kind = 'rescue'")->fetchColumn();
        $svc->setSelected($id, [$item], false);
        $this->assertSame(0, $svc->applyStep($id, 'rescue', null, 1, 60)['moved'], 'unticked → nothing moves');
        $svc->setSelected($id, [$item], true);
        $r = $svc->applyStep($id, 'rescue', null, 1, 60);
        $this->assertSame(1, $r['moved']);
        $this->assertContains('<j1@x>', $this->port->ids('INBOX'));
        $this->assertSame(['<j2@x>', '<j3@x>'], $this->port->ids('Junk'), 'spam stays in Junk');
    }

    public function test_changed_mail_is_skipped_and_a_refusal_stops_the_batch(): void
    {
        $this->seed();
        $svc = $this->svc();
        $id = $this->preview($svc);
        // The Vancity message was replaced under the same UID (different Message-ID) — skip it.
        $uid = (int)$this->db->query("SELECT uid FROM mail_tidy_items WHERE message_id = '<b1@x>'")->fetchColumn();
        $this->port->boxes['INBOX']['msgs'][$uid]['message_id'] = '<other@x>';
        // Archive has messages Tim marked deleted himself: ImapWriter refuses, batch stops.
        $this->port->refuse['Archive'] = '1 message(s) in Archive are already marked deleted';
        $r = $svc->applyStep($id, 'apply', null, 1, 60);
        $this->assertSame('stopped', $r['status']);
        $this->assertStringContainsString('already marked deleted', $r['error']);
        $this->assertSame(['<v1@x>'], $this->port->ids('Archive'));
        $this->assertSame('pending', $this->db->query("SELECT status FROM mail_tidy_items WHERE message_id = '<v1@x>'")->fetchColumn(), 'a refused chunk stays pending');
        unset($this->port->refuse['Archive']);
        $r = $svc->applyStep($id, 'apply', null, 1, 60);
        $this->assertSame('skipped', $this->db->query("SELECT status FROM mail_tidy_items WHERE message_id = '<b1@x>'")->fetchColumn());
        $this->assertContains('<other@x>', $this->port->ids('INBOX'));
    }

    public function test_batches_are_chunked_capped_and_paced(): void
    {
        for ($i = 0; $i < 25; $i++) $this->port->add('INBOX', ['from' => 'estatements@vancity.com', 'subject' => "S$i", 'message_id' => "<m$i@x>"]);
        $pauses = [];
        $svc = $this->svc(['sleep' => function (int $ms) use (&$pauses): void { $pauses[] = $ms; }]);
        $this->db->exec("INSERT INTO mail_tidy_rules (rule_uid, rule_type, folder_key, match_value) VALUES ('setting:chunk_size', 'setting', 'chunk_size', '10')");
        $this->db->exec("INSERT INTO mail_tidy_rules (rule_uid, rule_type, folder_key, match_value) VALUES ('setting:batch_max', 'setting', 'batch_max', '20')");
        foreach (MailTidyClassifier::defaultFolders() as $k => $f) {
            $this->db->prepare("INSERT INTO mail_tidy_rules (rule_uid, rule_type, folder_key, match_value, label, is_existing) VALUES (?, 'folder', ?, ?, ?, ?)")
                     ->execute(["folder:$k", $k, $f[0], $f[1], $f[2] ? 1 : 0]);
        }
        $this->db->exec("INSERT INTO mail_tidy_rules (rule_uid, rule_type, folder_key, match_value) VALUES ('domain:vancity.com', 'domain', 'banking', 'vancity.com')");
        $id = $this->preview($svc);
        $r = $svc->applyStep($id, 'apply', null, 1, 60);
        $this->assertSame(20, $r['target']);
        $this->assertSame(20, $r['moved']);
        $this->assertSame([10, 10], array_map(fn($m) => count($m[1]), $this->port->moves));
        $this->assertSame([1500], $pauses);
        $this->assertSame(5, count($this->port->ids('INBOX')), 'the next click moves the rest');
    }

    public function test_keep_tidy_is_off_by_default_and_waits_for_a_first_tidy(): void
    {
        $this->seed();
        $svc = $this->svc();
        $this->assertFalse($svc->keepTidy()['ran']);
        $svc->setSetting('keep_tidy', '1');
        $this->assertFalse($svc->keepTidy()['ran'], 'no tidy applied yet');

        $id = $this->preview($svc);
        $svc->applyStep($id, 'apply', null, 1, 60);
        // New mail after the tidy: one old enough to file, one too young.
        $this->port->add('INBOX', ['from' => 'estatements@vancity.com', 'subject' => 'Statement', 'message_id' => '<k1@x>', 'udate' => strtotime('2026-09-20')]);
        $this->port->add('INBOX', ['from' => 'estatements@vancity.com', 'subject' => 'Statement', 'message_id' => '<k2@x>', 'udate' => strtotime('2026-10-05')]);
        $r = $svc->keepTidy();
        $this->assertTrue($r['ran']);
        $this->assertSame(1, $r['moved']);
        $this->assertContains('<k1@x>', $this->port->ids('Banking &- Tax'));
        $this->assertContains('<k2@x>', $this->port->ids('INBOX'));
        $this->assertSame('keep_tidy', $this->db->query("SELECT kind FROM mail_tidy_batches ORDER BY id DESC LIMIT 1")->fetchColumn());
        $this->assertTrue($svc->undoStep((int)$r['batch_id'], null, 1, 60)['done'], 'keep-tidy moves are undoable');
        $this->assertContains('<k1@x>', $this->port->ids('INBOX'));
    }

    public function test_list_headers_keep_only_list_cues(): void
    {
        $raw = "From: Bob <bob@x.ca>\r\nSubject: hi\r\nList-Unsubscribe:\r\n <mailto:u@x.ca>\r\nPrecedence: bulk\r\nTo: tim\r\n";
        $this->assertSame("List-Unsubscribe: <mailto:u@x.ca>\r\nPrecedence: bulk", MailTidyService::listHeaders($raw));
    }
}
