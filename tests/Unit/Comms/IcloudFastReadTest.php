<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeImapClient.php';

/**
 * The iCloud reader is fast on a 71,827-message INBOX: envelopes in bulk, a time budget that
 * stops cleanly and resumes, one live run at a time, and every folder but junk/trash/drafts.
 */
class IcloudFastReadTest extends TestCase
{
    private const MB = ['key' => 'icloud', 'host' => 'imap.mail.me.com', 'port' => 993, 'user' => 'mowology@icloud.com', 'pass' => 'x'];

    private function ctx(): array
    {
        return [
            'contacts' => ['jodi@vanmgmt.ca' => 7],
            'vendors'  => [['id' => 9, 'name' => 'Lawnboy', 'aliases' => 'Lawnboy Supply', 'website' => 'https://www.lawnboy.ca/'],
                           ['id' => 12, 'name' => 'London Drugs', 'aliases' => '', 'website' => null]],
            'ours'     => ['mowology@icloud.com'],
        ];
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE mailbox_poll_state (mailbox_key TEXT, folder TEXT, uid_validity INT, last_uid INT NOT NULL DEFAULT 0,
                   first_run_at TEXT, last_run_at TEXT, last_summary TEXT, PRIMARY KEY (mailbox_key, folder))");
        return $db;
    }

    /** $n newsletters (robot senders) from UID $from. */
    private function newsletters(int $n, int $from = 1): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[$from + $i] = ['from' => 'Shop <noreply@shop' . $i . '.com>', 'to' => 'mowology@icloud.com', 'subject' => 'Deals ' . $i,
                                'message_id' => '<n' . ($from + $i) . '@shop.com>'];
        }
        return $out;
    }

    private function router(FakeImapClient $imap, ?PDO $db = null, $lock = null): IcloudInboxRouter
    {
        $opts = ['imap' => $imap, 'clock' => $imap->clock(), 'mailbox' => self::MB, 'context' => $this->ctx()];
        if ($lock) $opts['lock'] = $lock;
        return new IcloudInboxRouter($db ?? $this->db(), null, $opts);
    }

    // ── chunking ──────────────────────────────────────────────────────────

    public function test_uid_sets_are_ranges_and_chunks_are_ascending(): void
    {
        $this->assertSame('3:5,9,11:12', IcloudInboxRouter::uidSet([12, 3, 4, 5, 9, 11, 4]));
        $this->assertSame('7', IcloudInboxRouter::uidSet([7]));
        $this->assertSame('', IcloudInboxRouter::uidSet([]));
        $chunks = IcloudInboxRouter::uidChunks(range(450, 1, -1), 200);
        $this->assertSame([200, 200, 50], array_map('count', $chunks));
        $this->assertSame('1:200', IcloudInboxRouter::uidSet($chunks[0]));
        $this->assertSame([], IcloudInboxRouter::uidChunks([]));
    }

    public function test_envelopes_come_in_bulk_not_one_round_trip_per_message(): void
    {
        $imap = new FakeImapClient(['INBOX' => ['uidvalidity' => 1, 'messages' => $this->newsletters(450)]]);
        $res = $this->router($imap)->poll(true, 7);
        $this->assertSame(450, $res['scanned']);
        $this->assertSame(450, $res['counts']['ignore']);
        $this->assertSame(3, $imap->calls('overview'));            // 200 + 200 + 50
        $this->assertSame(['1:200', '201:400', '401:450'], $imap->overviewSets);
        $this->assertSame(0, $imap->calls('header'));               // robots are decided from the envelope
        $this->assertSame(0, $imap->calls('structure'));
        $this->assertSame(0, $imap->calls('textBody'));
        $this->assertFalse($res['partial']);
        $this->assertArrayHasKey('elapsed', $res);
    }

    // ── triage from the envelope alone ────────────────────────────────────

    public function test_triage_decides_most_mail_from_the_envelope(): void
    {
        $t = fn(array $m, ?string $hint = null) => IcloudInboxRouter::triage($m + ['folder' => 'inbox', 'to' => 'mowology@icloud.com'], $this->ctx(), $hint);

        $this->assertSame(['final', 'interac'], [$t(['from' => 'notify@payments.interac.ca', 'subject' => 'INTERAC e-Transfer: X sent you money.'])['stage'], 'interac']);
        $this->assertSame('interac', $t(['from' => 'notify@payments.interac.ca', 'subject' => 'x'])['route']);
        $this->assertSame('yardi', $t(['from' => 'DoNotReply@yardi.com', 'subject' => 'EFT Payment'])['route']);
        $c = $t(['from' => 'Jodi <jodi@vanmgmt.ca>', 'subject' => 'Re: quote']);
        $this->assertSame(['final', 'contact', 7], [$c['stage'], $c['route'], $c['contact_id']]);
        $this->assertSame('ignore', $t(['from' => 'noreply@shop.com', 'subject' => 'Deals'])['route']);
        // A robot whose subject says receipt still gets its attachments looked at.
        $this->assertSame('full', $t(['from' => 'noreply@shop.com', 'subject' => 'Your receipt #123'])['stage']);
        // A vendor, our own mail and any hinted folder: the full read.
        $this->assertSame('full', $t(['from' => 'orders@lawnboy.ca', 'subject' => 'Shipping update'])['stage']);
        $this->assertSame('full', $t(['from' => 'mowology@icloud.com', 'subject' => 'fwd'])['stage']);
        $this->assertSame('full', $t(['from' => 'noreply@shop.com', 'subject' => 'Deals'], 'receipts')['stage']);
        // An unknown person: header first (List-Unsubscribe?), then maybe the body.
        $this->assertSame('header', $t(['from' => 'dave.k@outlook.com', 'subject' => 'Hello'])['stage']);
        $this->assertSame('ignore', $t(['from' => '', 'subject' => 'x'])['route']);
    }

    public function test_overview_only_classification_matches_and_bodies_are_read_only_when_needed(): void
    {
        $msgs = [
            1 => ['from' => 'Jodi <jodi@vanmgmt.ca>', 'to' => 'mowology@icloud.com', 'subject' => 'Re: fall cleanup'],
            2 => ['from' => 'Interac e-Transfer <notify@payments.interac.ca>', 'to' => 'mowology@icloud.com', 'subject' => 'INTERAC e-Transfer: RON sent you money.'],
            3 => ['from' => 'Hello Fresh <hello@hellofresh.ca>', 'to' => 'mowology@icloud.com', 'subject' => 'Your box',
                  'header' => "From: hello@hellofresh.ca\r\nList-Unsubscribe: <mailto:u@hellofresh.ca>\r\n"],
            4 => ['from' => 'Dave K <dave.k@outlook.com>', 'to' => 'mowology@icloud.com', 'subject' => 'Lawn mowing quote',
                  'header' => "From: dave.k@outlook.com\r\n",
                  'body' => "Hi, could you give me a quote for weekly lawn mowing and hedge trimming at 4512 Oak St? Thanks"],
            5 => ['from' => 'Mum <margaret@shaw.ca>', 'to' => 'mowology@icloud.com', 'subject' => 'Sunday dinner',
                  'header' => "From: margaret@shaw.ca\r\n", 'body' => 'See you at 5.'],
        ];
        $imap = new FakeImapClient(['INBOX' => ['uidvalidity' => 1, 'messages' => $msgs]]);
        $res = $this->router($imap)->poll(true);
        $c = $res['counts'];
        $this->assertSame([1, 1, 1, 2], [$c['contact'], $c['interac'], $c['lead'], $c['ignore']]);
        $this->assertSame(1, $imap->calls('overview'));
        $this->assertSame(3, $imap->calls('header'));        // only the three unknown people
        $this->assertSame(2, $imap->calls('textBody'));      // dry run: only the enquiry check reads text
        $this->assertSame('Lawn mowing quote', $res['samples']['lead'][0]['subject']);
    }

    // ── folders ───────────────────────────────────────────────────────────

    public function test_every_folder_but_junk_trash_drafts_and_notes_is_read(): void
    {
        $names = ['INBOX', 'Archive', 'Apple & Tech', 'London Drugs', 'Junk', 'Jobber', 'Jobber/JOBBER PAYMENTS', 'clients', 'RECEIPTS',
                  'Arlo', 'Wing Chun', 'Junk E-mailings oh', 'Deleted Items', 'Deleted Messages', 'Drafts', 'Notes', 'Notes/New Folder', 'Sent Messages'];
        $list = array_map(fn($n) => ['name' => $n], $names);
        $list[] = ['name' => '[Mailbox]', 'noselect' => true];
        $read = IcloudInboxRouter::readableFolders($list, 'Sent Messages');
        $this->assertSame(['INBOX', 'Archive', 'Apple & Tech', 'London Drugs', 'Jobber', 'Jobber/JOBBER PAYMENTS', 'clients', 'RECEIPTS',
                           'Arlo', 'Wing Chun', 'Sent Messages'], array_keys($read));
        $this->assertSame('sent', $read['Sent Messages']);
        $this->assertSame('inbox', $read['clients']);

        $v = $this->ctx()['vendors'];
        $this->assertSame('receipts', IcloudInboxRouter::folderHint('RECEIPTS', $v));
        $this->assertSame('receipts', IcloudInboxRouter::folderHint('London Drugs', $v));
        $this->assertSame('payments', IcloudInboxRouter::folderHint('Jobber/JOBBER PAYMENTS', $v));
        $this->assertSame('clients', IcloudInboxRouter::folderHint('clients', $v));
        $this->assertNull(IcloudInboxRouter::folderHint('INBOX', $v));
        $this->assertNull(IcloudInboxRouter::folderHint('Arlo', $v));
        $this->assertSame('icloud clients', IcloudInboxRouter::mailboxLabel('clients', 'inbox'));
        $this->assertSame('icloud Sent', IcloudInboxRouter::mailboxLabel('Sent Messages', 'sent'));
    }

    public function test_dry_run_reports_per_folder_and_small_folders_go_first(): void
    {
        $imap = new FakeImapClient([
            'INBOX'      => ['uidvalidity' => 1, 'messages' => $this->newsletters(30)],
            'RECEIPTS'   => ['uidvalidity' => 2, 'messages' => $this->newsletters(2, 500)],
            'Junk'       => ['uidvalidity' => 3, 'messages' => $this->newsletters(9)],
            'Sent Messages' => ['uidvalidity' => 4, 'messages' => [7 => ['from' => 'mowology@icloud.com', 'to' => 'Jodi <jodi@vanmgmt.ca>', 'subject' => 'Tuesday']]],
        ]);
        $res = $this->router($imap)->poll(true);
        $this->assertSame(['INBOX', 'RECEIPTS', 'Sent Messages'], array_keys($res['folders']));
        $this->assertSame(30, $res['folders']['INBOX']['scanned']);
        $this->assertSame(['ignore' => 2], $res['folders']['RECEIPTS']['routes']);
        $this->assertSame('receipts', $res['folders']['RECEIPTS']['hint']);
        $this->assertSame(['contact' => 1], $res['folders']['Sent Messages']['routes']);
        $this->assertSame(2, $imap->calls('structure'));   // hinted folder: robots' attachments are checked too
        $this->assertSame(['Sent Messages', 'RECEIPTS', 'INBOX'], IcloudInboxRouter::folderOrder(['INBOX' => 30, 'RECEIPTS' => 2, 'Sent Messages' => 1]));
    }

    // ── time budget + resume ──────────────────────────────────────────────

    public function test_a_dry_run_stops_at_its_budget_and_resumes_from_the_cursor(): void
    {
        $imap = new FakeImapClient(['INBOX' => ['uidvalidity' => 1, 'messages' => $this->newsletters(500)]], 1.0);
        $res = $this->router($imap)->poll(true, 7, 8);
        $this->assertTrue($res['partial']);
        $this->assertGreaterThan(0, $res['scanned']);
        $this->assertSame(500 - $res['scanned'], $res['remaining']);
        $this->assertSame(['INBOX' => $res['scanned']], $res['resume']);
        $this->assertStringContainsString('left for the next run', $res['message']);

        $imap2 = new FakeImapClient(['INBOX' => ['uidvalidity' => 1, 'messages' => $this->newsletters(500)]]);
        $res2 = $this->router($imap2)->poll(true, 7, null, $res['resume']);
        $this->assertFalse($res2['partial']);
        $this->assertSame($res['remaining'], $res2['scanned']);
    }

    public function test_a_live_backfill_cut_short_saves_its_place_and_the_next_run_carries_on(): void
    {
        $db = $this->db();
        $folders = ['INBOX' => ['uidvalidity' => 77, 'messages' => $this->newsletters(1000)]];
        // Old mail: arrived 60 days ago — outside the normal 3-day re-scan window.
        $old = (float)strtotime('2026-10-07 12:00:00 UTC') - 60 * 86400;
        foreach ($folders['INBOX']['messages'] as &$m) $m['udate'] = (int)$old;
        unset($m);

        $imap = new FakeImapClient($folders, 0.5);
        $r1 = $this->router($imap, $db)->poll(false, null, 4);
        $this->assertTrue($r1['partial']);
        $done = $r1['scanned'];
        $this->assertGreaterThan(0, $done);
        $state = $db->query("SELECT uid_validity, last_uid FROM mailbox_poll_state WHERE folder = 'INBOX'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([77, $done], [(int)$state['uid_validity'], (int)$state['last_uid']]);

        // Next run (normal budget): continues from last_uid even though the mail is 60 days old.
        $imap2 = new FakeImapClient($folders);
        $r2 = $this->router($imap2, $db)->poll(false);
        $this->assertFalse($r2['partial']);
        $this->assertSame(1000 - $done, $r2['scanned']);
        $this->assertSame(1000, (int)$db->query("SELECT last_uid FROM mailbox_poll_state WHERE folder = 'INBOX'")->fetchColumn());

        // And a run with nothing new reads nothing.
        $imap3 = new FakeImapClient($folders);
        $this->assertSame(0, $this->router($imap3, $db)->poll(false)['scanned']);
        $this->assertSame(0, $imap3->calls('header') + $imap3->calls('structure'));
    }

    public function test_search_window(): void
    {
        $now = 2000000000;
        $this->assertSame($now - 7 * 86400, IcloudInboxRouter::searchFrom(true, null, true, null, null, $now));
        $this->assertSame($now - 30 * 86400, IcloudInboxRouter::searchFrom(true, 30, true, null, null, $now));
        $this->assertSame($now - 90 * 86400, IcloudInboxRouter::searchFrom(false, null, true, null, null, $now));
        $this->assertSame($now - 3 * 86400, IcloudInboxRouter::searchFrom(false, null, false, $now - 3600, $now - 86400, $now));
        $this->assertSame($now - 61 * 86400, IcloudInboxRouter::searchFrom(false, null, false, $now - 60 * 86400, $now - 86400, $now));
        $this->assertSame($now - 91 * 86400, IcloudInboxRouter::searchFrom(false, null, false, null, $now - 86400, $now));
    }

    // ── one live run at a time ───────────────────────────────────────────

    public function test_a_second_live_run_is_skipped_while_one_holds_the_lock(): void
    {
        $db = $this->db();
        $held = new MailboxPollLock($db, 'icloud_test_' . getmypid());
        $this->assertTrue($held->acquire());
        $other = new MailboxPollLock($db, 'icloud_test_' . getmypid());
        $this->assertFalse($other->acquire());

        $imap = new FakeImapClient(['INBOX' => ['uidvalidity' => 1, 'messages' => $this->newsletters(5)]]);
        $res = $this->router($imap, $db, $other)->poll(false);
        $this->assertTrue($res['skipped']);
        $this->assertSame(0, $imap->calls('open'));   // never touched the mailbox

        $held->release();
        $res = $this->router($imap, $db, $other)->poll(false);
        $this->assertArrayNotHasKey('skipped', $res);
        $this->assertSame(5, $res['scanned']);
        // …and the run let go of it.
        $this->assertTrue($held->acquire());
        $held->release();
    }

    public function test_dry_runs_take_no_lock(): void
    {
        $lock = new class {
            public int $n = 0;
            public function acquire(): bool { $this->n++; return false; }
            public function release(): void {}
        };
        $imap = new FakeImapClient(['INBOX' => ['uidvalidity' => 1, 'messages' => $this->newsletters(3)]]);
        $res = $this->router($imap, null, $lock)->poll(true);
        $this->assertSame(3, $res['scanned']);
        $this->assertSame(0, $lock->n);
    }
}
