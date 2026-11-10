<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/IcloudDedupePdo.php';

/**
 * Yui's "Who is this?" (2026-11-10): the owner names an unknown sender in place; the answer makes a contact with NO consent, stores the
 * mail under its own key, teaches the poll (a domain only from two different people at a business domain, never free mail), and Undo
 * reverses exactly what the answer created. Names, addresses and companies are invented.
 */
class InboundQuestionServiceTest extends TestCase
{
    private PDO $db;
    private InboundQuestionService $svc;

    protected function setUp(): void
    {
        $this->db = new IcloudDedupePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE inbound_unplaced (id INTEGER PRIMARY KEY AUTOINCREMENT, message_key TEXT UNIQUE, mailbox TEXT DEFAULT '', from_addr TEXT DEFAULT '',
            from_name TEXT, subject TEXT DEFAULT '', snippet TEXT, sent_at TEXT, kind TEXT DEFAULT 'person', reason TEXT DEFAULT '', strength TEXT DEFAULT 'none',
            client_id INT, contact_id INT, evidence TEXT DEFAULT '', candidates TEXT, status TEXT DEFAULT 'open', decided_by INT, decided_at TEXT, created_at TEXT, decision TEXT)");
        $this->db->exec("CREATE TABLE inbound_sender_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, sender_key TEXT UNIQUE, verdict TEXT, client_id INT, contact_id INT, decided_by INT, created_at TEXT)");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT, last_name TEXT, email TEXT, is_active INT, prospect_status TEXT, contact_role TEXT,
            preferred_contact_method TEXT, consent_source TEXT, notes TEXT, receive_sms INT, receive_marketing INT, consent_quote_followup INT, consent_marketing_email INT, consent_sms INT, merged_into_id INT)");
        $this->db->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, display_name TEXT, billing_email TEXT, status TEXT DEFAULT 'active', legacy_contact_id INT)");
        $this->db->exec("CREATE TABLE client_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INT, contact_id INT, role TEXT, is_primary INT, receives_invoices INT, receives_notifications INT, ended_on TEXT)");
        $this->db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, mailbox TEXT, message_key TEXT UNIQUE, direction TEXT, channel TEXT, contact_id INT, from_addr TEXT, to_addr TEXT,
            subject TEXT, snippet TEXT, signature TEXT, sent_at TEXT, from_name TEXT, quote_id INT, joined_by TEXT, handled_at TEXT)");
        $this->db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INT)");
        $this->db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INT)");
        $this->db->exec("INSERT INTO clients (id, display_name) VALUES (1, 'Acme Holdings Ltd'), (2, 'Birch Strata VR100')");
        $this->db->exec("INSERT INTO clients (id, display_name, status) VALUES (3, 'Closed Account', 'inactive')");
        $this->svc = new InboundQuestionService($this->db);
    }

    private function row(string $addr, string $name = 'Pat Lee', array $over = []): int
    {
        static $n = 0;
        $n++;
        $r = array_merge(['message_key' => '<m' . $n . '@x.test>', 'mailbox' => 'office@ INBOX', 'from_addr' => $addr, 'from_name' => $name, 'subject' => 'Balance please',
            'snippet' => 'What do we owe?', 'sent_at' => '2026-11-09 10:00:00', 'kind' => 'person', 'strength' => 'medium', 'evidence' => 'company', 'status' => 'open',
            'created_at' => '2026-11-09 10:00:00'], $over);
        $cols = implode(', ', array_keys($r));
        $this->db->prepare("INSERT INTO inbound_unplaced ($cols) VALUES (" . implode(', ', array_fill(0, count($r), '?')) . ")")->execute(array_values($r));
        return (int)$this->db->lastInsertId();
    }

    private function rows(string $table, string $where = '1=1'): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();
    }

    public function testAManagersDomainServingAnotherAccountNeverBecomesARule(): void
    {
        $this->svc->confirm($this->row('a@pmfirm.test'), 2, 'Ann', 'A', 5);
        $this->svc->confirm($this->row('b@pmfirm.test'), 1, 'Bo', 'B', 5);
        $this->svc->confirm($this->row('c@pmfirm.test'), 1, 'Cy', 'C', 5);
        $this->assertSame(0, $this->rows('inbound_sender_rules', "sender_key = '@pmfirm.test'"));
    }

    public function testAConfirmOnASecondAccountRemovesTheDomainRuleAndUndoPutsItBack(): void
    {
        $this->svc->confirm($this->row('a@firm.test'), 1, 'Ann', 'A', 5);
        $this->svc->confirm($this->row('b@firm.test'), 1, 'Bo', 'B', 5);
        $this->assertSame(1, $this->rows('inbound_sender_rules', "sender_key = '@firm.test' AND client_id = 1"));
        $third = $this->row('c@firm.test');
        $this->svc->confirm($third, 2, 'Cy', 'C', 5);
        $this->assertSame(0, $this->rows('inbound_sender_rules', "sender_key = '@firm.test'"));
        $this->assertTrue($this->svc->undo($third, 5)['ok']);
        $this->assertSame(1, $this->rows('inbound_sender_rules', "sender_key = '@firm.test' AND client_id = 1"));
    }

    public function testUndoKeepsAContactNowOnAnotherAccount(): void
    {
        $id = $this->row('pat@x.test');
        $this->svc->confirm($id, 1, 'Pat', 'Lee', 5);
        $cid = (int)$this->db->query("SELECT id FROM contacts WHERE email = 'pat@x.test'")->fetchColumn();
        $this->db->exec("INSERT INTO client_contacts (client_id, contact_id, role) VALUES (2, $cid, 'billing')");
        $this->assertStringContainsString('kept the contact', $this->svc->undo($id, 5)['message']);
        $this->assertSame(1, $this->rows('contacts', "id = $cid"));
    }

    public function testSomeoneWhoWroteManyTimesIsOneQuestion(): void
    {
        $this->row('pat@x.test', 'Pat Lee', ['sent_at' => '2026-11-01 10:00:00', 'subject' => 'Older']);
        $this->row('pat@x.test', 'Pat Lee', ['sent_at' => '2026-11-05 10:00:00', 'subject' => 'Newest']);
        $this->row('kim@y.test', 'Kim Fox', ['sent_at' => '2026-11-03 10:00:00']);
        $q = $this->svc->questions();
        $this->assertCount(2, $q);
        $this->assertSame('Newest', $q[0]['subject']);
        $this->assertSame(1, $q[0]['more']);
        $this->assertSame(0, $q[1]['more']);
    }

    public function testANewPersonBecomesAContactWithNoConsentAtAll(): void
    {
        $id = $this->row('pat@acme-holdings.test');
        $r = $this->svc->confirm($id, 1, 'Pat', 'Lee', 5);
        $this->assertTrue($r['ok']);
        $this->assertSame('Pat Lee is now on Acme Holdings Ltd. Their mail will land there from now on.', $r['message']);
        $this->assertSame($id, $r['undo_id']);
        $c = $this->db->query("SELECT * FROM contacts")->fetch();
        $this->assertSame('pat@acme-holdings.test', $c['email']);
        $this->assertSame('inbox_confirmed', $c['consent_source']);
        foreach (['receive_sms', 'receive_marketing', 'consent_quote_followup', 'consent_marketing_email', 'consent_sms'] as $col) $this->assertSame(0, (int)$c[$col], $col);
        $link = $this->db->query("SELECT * FROM client_contacts")->fetch();
        $this->assertSame('billing', $link['role']);
        $this->assertSame([0, 0, 0], [(int)$link['is_primary'], (int)$link['receives_invoices'], (int)$link['receives_notifications']]);
    }

    public function testAnExistingEmailReusesTheContact(): void
    {
        $this->db->exec("INSERT INTO contacts (first_name, last_name, email, merged_into_id) VALUES ('Old', 'Merged', 'pat@acme-holdings.test', 99), ('Pat', 'Lee', ' Pat@Acme-Holdings.test ', NULL)");
        $id = $this->row('pat@acme-holdings.test');
        $this->assertTrue($this->svc->confirm($id, 1, 'Patricia', 'Lee', 5)['ok']);
        $this->assertSame(2, $this->rows('contacts'), 'no third contact');
        $this->assertSame(2, (int)$this->db->query("SELECT contact_id FROM client_contacts")->fetchColumn(), 'the live, unmerged one');
        $this->assertSame('Pat', $this->db->query("SELECT first_name FROM contacts WHERE id = 2")->fetchColumn(), 'a name already on file is not rewritten');
    }

    public function testTheMailIsStoredUnderItsOriginalKeyAndOtherRowsFromThatAddressClose(): void
    {
        $a = $this->row('pat@acme-holdings.test', 'Pat Lee', ['message_key' => '<orig-1@x.test>']);
        $b = $this->row('PAT@acme-holdings.test', 'Pat Lee', ['strength' => 'none', 'message_key' => '<orig-2@x.test>']);
        $other = $this->row('sam@elsewhere.test');
        $this->assertTrue($this->svc->confirm($a, 1, 'Pat', 'Lee', 5)['ok']);
        $m = $this->db->query("SELECT message_key, joined_by, direction, from_addr, snippet FROM sales_messages ORDER BY message_key")->fetchAll();
        $this->assertSame(['<orig-1@x.test>', '<orig-2@x.test>'], array_column($m, 'message_key'));
        $this->assertSame(['confirmed', 'confirmed'], array_column($m, 'joined_by'));
        $this->assertSame('inbound', $m[0]['direction']);
        $this->assertSame('What do we owe?', $m[0]['snippet']);
        $this->assertSame('confirmed', $this->db->query("SELECT status FROM inbound_unplaced WHERE id = $b")->fetchColumn());
        $this->assertSame('open', $this->db->query("SELECT status FROM inbound_unplaced WHERE id = $other")->fetchColumn());
    }

    public function testOnePersonMakesNoDomainRuleTwoPeopleMakeOne(): void
    {
        $this->assertTrue($this->svc->confirm($this->row('pat@acme-holdings.test'), 1, 'Pat', 'Lee', 5)['ok']);
        $this->assertSame(0, $this->rows('inbound_sender_rules'));
        $this->assertTrue($this->svc->confirm($this->row('kim@acme-holdings.test', 'Kim Ho'), 1, 'Kim', 'Ho', 5)['ok']);
        $rule = $this->db->query("SELECT * FROM inbound_sender_rules")->fetchAll();
        $this->assertCount(1, $rule);
        $this->assertSame(['@acme-holdings.test', 'account', 1, 2], [$rule[0]['sender_key'], $rule[0]['verdict'], (int)$rule[0]['client_id'], (int)$rule[0]['contact_id']]);
    }

    public function testTwoPeopleAtDifferentAccountsOrOnFreeMailNeverMakeARule(): void
    {
        $this->svc->confirm($this->row('a@gmail.com'), 1, 'A', '', 5);
        $this->svc->confirm($this->row('b@gmail.com'), 1, 'B', '', 5);
        $this->svc->confirm($this->row('c@birch.test'), 1, 'C', '', 5);
        $this->svc->confirm($this->row('d@birch.test'), 2, 'D', '', 5);
        $this->assertSame(0, $this->rows('inbound_sender_rules'));
    }

    public function testNotAClientWritesAnAddressRuleAndClosesThatSendersRows(): void
    {
        $a = $this->row('spam@promo.test');
        $b = $this->row('Spam@Promo.test', 'S', ['strength' => 'none']);
        $c = $this->row('other@promo.test');
        $r = $this->svc->notClient($a, 5);
        $this->assertTrue($r['ok']);
        $this->assertSame("I won't ask about spam@promo.test again.", $r['message']);
        $this->assertSame([['spam@promo.test', 'noise']], array_map('array_values', $this->db->query("SELECT sender_key, verdict FROM inbound_sender_rules")->fetchAll()));
        $this->assertSame('dismissed', $this->db->query("SELECT status FROM inbound_unplaced WHERE id = $b")->fetchColumn());
        $this->assertSame('open', $this->db->query("SELECT status FROM inbound_unplaced WHERE id = $c")->fetchColumn());
    }

    public function testUndoRemovesExactlyWhatConfirmCreated(): void
    {
        $this->db->exec("INSERT INTO contacts (first_name, email) VALUES ('Kim', 'kim@acme-holdings.test')");   // pre-existing
        $this->db->exec("INSERT INTO client_contacts (client_id, contact_id, role, ended_on) VALUES (1, 1, 'owner', NULL)");
        $this->svc->confirm($this->row('kim@acme-holdings.test'), 1, 'Kim', '', 5);   // reuses contact and link; no domain rule (one person)
        $a = $this->row('pat@acme-holdings.test', 'Pat Lee', ['message_key' => '<p1@x.test>', 'client_id' => 2]);
        $b = $this->row('pat@acme-holdings.test', 'Pat Lee', ['message_key' => '<p2@x.test>']);
        $this->assertTrue($this->svc->confirm($a, 1, 'Pat', 'Lee', 5)['ok']);   // second person: rule, contact, link, two messages
        $this->assertSame(1, $this->rows('inbound_sender_rules'));
        $r = $this->svc->undo($a, 5);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(0, $this->rows('inbound_sender_rules'));
        $this->assertSame(1, $this->rows('contacts'), 'Kim stays, Pat is gone');
        $this->assertSame(1, $this->rows('client_contacts'));
        $this->assertSame(1, $this->rows('sales_messages'), "Kim's own mail stays");
        $rows = $this->db->query("SELECT id, status, client_id, decision, decided_at FROM inbound_unplaced WHERE id IN ($a, $b) ORDER BY id")->fetchAll();
        $this->assertSame(['open', 'open'], array_column($rows, 'status'));
        $this->assertSame([2, null], array_map(fn($x) => $x === null ? null : (int)$x, array_column($rows, 'client_id')), 'the first row got its old account back');
        $this->assertSame([null, null], array_column($rows, 'decision'));
    }

    public function testUndoPutsBackARuleItReplaced(): void
    {
        $this->svc->confirm($this->row('a@acme-holdings.test'), 2, 'A', '', 5);
        $this->svc->confirm($this->row('b@acme-holdings.test'), 2, 'B', '', 5);   // rule -> account 2, B's contact
        $before = (int)$this->db->query("SELECT contact_id FROM inbound_sender_rules")->fetchColumn();
        $c = $this->row('c@acme-holdings.test');
        $this->svc->confirm($c, 2, 'C', '', 5);   // the rule now points at C
        $this->assertNotSame($before, (int)$this->db->query("SELECT contact_id FROM inbound_sender_rules")->fetchColumn());
        $this->assertTrue($this->svc->undo($c, 5)['ok']);
        $this->assertSame($before, (int)$this->db->query("SELECT contact_id FROM inbound_sender_rules")->fetchColumn(), 'the replaced rule is back');
    }

    public function testUndoKeepsAContactThatHasSinceGotAnInvoiceAndAMessageThatWasHandled(): void
    {
        $a = $this->row('pat@acme-holdings.test', 'Pat Lee', ['message_key' => '<h1@x.test>']);
        $this->svc->confirm($a, 1, 'Pat', 'Lee', 5);
        $this->db->exec("INSERT INTO invoices (contact_id) VALUES (1)");
        $this->db->exec("UPDATE sales_messages SET handled_at = '2026-11-10 08:00:00'");
        $r = $this->svc->undo($a, 5);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('kept the contact', $r['message']);
        $this->assertStringContainsString('already handled', $r['message']);
        $this->assertSame(1, $this->rows('contacts'));
        $this->assertSame(1, $this->rows('sales_messages'));
        $this->assertSame(0, $this->rows('client_contacts'), 'the link is still removed');
    }

    public function testUndoAfterADayOrOnAnUndecidedRowIsRefused(): void
    {
        $a = $this->row('pat@acme-holdings.test');
        $this->assertFalse($this->svc->undo($a, 5)['ok']);
        $this->svc->confirm($a, 1, 'Pat', 'Lee', 5);
        $this->db->exec("UPDATE inbound_unplaced SET decided_at = '" . date('Y-m-d H:i:s', time() - 25 * 3600) . "' WHERE id = $a");
        $r = $this->svc->undo($a, 5);
        $this->assertFalse($r['ok']);
        $this->assertSame(1, $this->rows('contacts'), 'nothing was touched');
    }

    public function testRefusals(): void
    {
        $done = $this->row('x@acme-holdings.test', 'X', ['status' => 'dismissed']);
        $noise = $this->row('n@acme-holdings.test', 'N', ['kind' => 'noise']);
        $ok = $this->row('p@acme-holdings.test');
        $this->assertFalse($this->svc->confirm($done, 1, 'X', '', 5)['ok'], 'not open');
        $this->assertFalse($this->svc->confirm($noise, 1, 'N', '', 5)['ok'], 'not a person');
        $this->assertFalse($this->svc->confirm($ok, 99, 'P', '', 5)['ok'], 'unknown account');
        $this->assertFalse($this->svc->confirm($ok, 3, 'P', '', 5)['ok'], 'inactive account');
        $this->assertFalse($this->svc->confirm($ok, 1, '  ', '', 5)['ok'], 'no first name');
        $this->assertFalse($this->svc->notClient($done, 5)['ok']);
        $this->assertSame(0, $this->rows('contacts') + $this->rows('inbound_sender_rules'));
    }

    public function testQuestionsSayWhyInPlainWordsAndTheQuietListLeavesThemOut(): void
    {
        $this->row('pat@acme-holdings.test', 'Pat Lee', ['evidence' => 'invoice,company', 'client_id' => 1,
            'candidates' => json_encode([['client_id' => 1, 'contact_id' => null, 'name' => 'Acme Holdings Ltd', 'evidence' => ['invoice']]])]);
        $this->row('weak@x.test', 'W', ['strength' => 'weak', 'evidence' => 'domain', 'candidates' => json_encode([
            ['client_id' => 1, 'name' => 'Acme Holdings Ltd'], ['client_id' => 2, 'name' => 'Birch Strata VR100']])]);
        $this->row('quiet@x.test', 'Q', ['strength' => 'none']);
        $this->row('noise@x.test', 'N', ['kind' => 'noise', 'strength' => 'none']);
        $q = $this->svc->questions();
        $this->assertCount(2, $q);
        $byAddr = array_column($q, null, 'addr');
        $this->assertSame('mentions one of their invoices; names the company', $byAddr['pat@acme-holdings.test']['why']);
        $this->assertSame(1, $byAddr['pat@acme-holdings.test']['suggested_client_id']);
        $this->assertSame(['Pat', 'Lee'], [$byAddr['pat@acme-holdings.test']['first_name'], $byAddr['pat@acme-holdings.test']['last_name']]);
        $this->assertSame('same email domain as someone on the account', $byAddr['weak@x.test']['why']);
        $this->assertCount(2, $byAddr['weak@x.test']['candidates']);
        $this->assertNull($byAddr['weak@x.test']['suggested_client_id']);
        $this->assertSame(1, $this->svc->quietCount());
        $this->assertSame(['quiet@x.test'], array_column($this->svc->quiet(), 'addr'));
        $this->assertSame('replied to mail we sent them', InboundQuestionService::why('replied'));
        $this->assertSame('names the address', InboundQuestionService::why('address'));
    }

    public function testARepliedRowOffersTheAccountsThatContactBelongsTo(): void
    {
        $this->db->exec("INSERT INTO contacts (first_name, email) VALUES ('Jo', 'jo@x.test')");
        $this->db->exec("INSERT INTO client_contacts (client_id, contact_id, role, ended_on) VALUES (1, 1, 'billing', NULL), (2, 1, 'billing', '2026-01-01')");
        $this->row('jo@home.test', 'Jo', ['evidence' => 'replied', 'contact_id' => 1]);
        $this->assertSame([1], array_column($this->svc->questions()[0]['candidates'], 'client_id'), 'the ended role is not offered');
    }

    public function testAccountSearchEscapesWildcardsAndSkipsInactive(): void
    {
        $this->assertSame([], $this->svc->accounts('a'), 'two characters at least');
        $this->assertSame(['Acme Holdings Ltd'], array_column($this->svc->accounts('acme'), 'name'));
        $this->assertSame([], $this->svc->accounts('Closed'), 'inactive');
        $this->assertSame([], $this->svc->accounts('%%'), 'a wildcard is a plain character');
    }

    public function testReadyNeedsBothMigrations(): void
    {
        $this->assertTrue($this->svc->ready());
        $db = new PDO('sqlite::memory:');
        $this->assertFalse((new InboundQuestionService($db))->ready());
    }

    // ── The poll's reading of the rules (SalesInboxService) ─────────────────

    public function testAnAddressNoiseRuleBeatsADomainRule(): void
    {
        $rules = ['@acme.test' => ['verdict' => 'account', 'client_id' => 1, 'contact_id' => 7], 'spam@acme.test' => ['verdict' => 'noise', 'client_id' => null, 'contact_id' => null]];
        $this->assertSame('noise', SalesInboxService::ruleFor($rules, 'Spam@Acme.test')['verdict']);
        $this->assertSame(7, SalesInboxService::ruleFor($rules, 'pat@acme.test')['contact_id']);
        $this->assertNull(SalesInboxService::ruleFor($rules, 'pat@other.test'));
        $this->assertNull(SalesInboxService::ruleFor([], 'pat@acme.test'));
    }

    public function testRulesMissingIsANoOpForThePoll(): void
    {
        $db = new IcloudDedupePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame([], (new SalesInboxService($db))->senderRules());
        $this->assertSame(['@acme.test' => ['verdict' => 'account', 'client_id' => 1, 'contact_id' => 7]], (function () {
            $this->db->exec("INSERT INTO inbound_sender_rules (sender_key, verdict, client_id, contact_id) VALUES ('@Acme.test', 'account', 1, 7)");
            return (new SalesInboxService($this->db))->senderRules();
        })());
    }
}
