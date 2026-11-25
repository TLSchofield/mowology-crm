<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Texts from numbers that are not a customer yet (bridge 1.2, migration 1396, 2026-10-22):
 * kept for Tim's list, never stored with a number the bridge did not really have, deleted + blocked
 * when marked personal, and moved into the customer's history once the number is on their record.
 */
class TextBridgeUnmatchedTest extends TestCase
{
    private const SALT = 'mowology-test-salt';

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT, mobile TEXT, is_active INTEGER DEFAULT 1)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, mailbox TEXT NOT NULL, message_key TEXT NOT NULL UNIQUE,
                   direction TEXT NOT NULL, channel TEXT NOT NULL DEFAULT 'email', contact_id INTEGER, from_addr TEXT, from_name TEXT, to_addr TEXT,
                   subject TEXT, snippet TEXT, sent_at TEXT NOT NULL)");
        $db->exec("CREATE TABLE text_unmatched (id INTEGER PRIMARY KEY AUTOINCREMENT, phone10 TEXT NOT NULL, message_key TEXT NOT NULL UNIQUE,
                   direction TEXT NOT NULL, snippet TEXT, sent_at TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE text_personal (number_hash TEXT PRIMARY KEY, marked_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("INSERT INTO ops_settings VALUES ('text_bridge_salt', '" . self::SALT . "', '')");
        // John Hughes: the account has his OLD number; he texts from a new one.
        $db->exec("INSERT INTO contacts (id, first_name, last_name, phone, mobile, is_active) VALUES (21, 'John', 'Hughes', '6047321932', NULL, 1),
                   (22, 'Full', 'Record', '6045550001', '7785550002', 1)");
        return $db;
    }

    private function msg(string $phone, array $extra = []): array
    {
        return $extra + ['hash' => TextBridgeService::hash($phone, self::SALT), 'phone' => $phone, 'direction' => 'in',
                         'sent_at' => time() - 600, 'guid' => 'p:0/' . $phone, 'text' => 'Please change my number'];
    }

    public function test_a_text_from_a_new_number_is_kept_not_dropped(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        $r = $svc->ingest([$this->msg('6047273925')]);
        $this->assertSame(1, $r['unmatched']);
        $this->assertSame(0, $r['stored']);
        $t = $svc->unknownThreads();
        $this->assertCount(1, $t);
        $this->assertSame('6047273925', $t[0]['phone10']);
        $this->assertSame('Please change my number', $t[0]['messages'][0]['snippet']);
        $this->assertSame(1, $svc->unknownCount());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM sales_messages")->fetchColumn(), 'not in a customer\'s history yet');
    }

    public function test_the_same_text_twice_is_one_row(): void
    {
        $svc = new TextBridgeService($this->db());
        $svc->ingest([$this->msg('6047273925')]);
        $r = $svc->ingest([$this->msg('6047273925')]);
        $this->assertSame(0, $r['unmatched']);
        $this->assertSame(1, $r['dupe']);
        $this->assertSame(1, $svc->unknownThreads()[0]['count']);
    }

    public function test_a_number_the_bridge_did_not_really_have_is_refused(): void
    {
        $svc = new TextBridgeService($this->db());
        $bad = $this->msg('6047273925', ['phone' => '6047273926']);   // hash is for ...925, phone says ...926
        $this->assertSame(0, $svc->ingest([$bad])['unmatched']);
        $noPhone = $this->msg('6047273925'); unset($noPhone['phone']);   // an old bridge: nothing to keep
        $this->assertSame(0, $svc->ingest([$noPhone])['unmatched']);
        $this->assertSame(0, $svc->unknownCount());
    }

    public function test_a_customer_text_still_goes_straight_to_the_customer(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        $r = $svc->ingest([$this->msg('6047321932')]);   // John's old number: matches contact 21
        $this->assertSame(1, $r['stored']);
        $this->assertSame(0, $r['unmatched']);
        $this->assertSame(21, (int)$db->query("SELECT contact_id FROM sales_messages")->fetchColumn());
    }

    public function test_marking_a_number_personal_deletes_its_texts_and_blocks_it(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        $svc->ingest([$this->msg('6045559999', ['guid' => 'a']), $this->msg('6045559999', ['guid' => 'b', 'direction' => 'out'])]);
        $this->assertSame(1, $svc->unknownCount());
        $r = $svc->markPersonal('6045559999', 3);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['deleted']);
        $this->assertSame(0, $svc->unknownCount());
        $h = TextBridgeService::hash('6045559999', self::SALT);
        $this->assertSame([$h], $svc->personalHashes());
        // never stored again, and the raw number is not what is kept
        $again = $svc->ingest([$this->msg('6045559999', ['guid' => 'c'])]);
        $this->assertSame(1, $again['personal']);
        $this->assertSame(0, $again['unmatched']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM text_unmatched")->fetchColumn());
        $this->assertSame($h, (string)$db->query("SELECT number_hash FROM text_personal")->fetchColumn());
    }

    public function test_a_customers_number_cannot_be_marked_personal(): void
    {
        $svc = new TextBridgeService($this->db());
        $r = $svc->markPersonal('6047321932');
        $this->assertFalse($r['ok']);
        $this->assertSame([], $svc->personalHashes());
    }

    public function test_linking_the_new_number_moves_the_texts_into_the_customers_history(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        $svc->ingest([$this->msg('6047273925', ['guid' => 'm1'])]);
        // John has one number (phone) and a blank mobile: the new number goes in the mobile.
        $r = $svc->linkToContact('6047273925', 21);
        $this->assertTrue($r['ok']);
        $this->assertSame('mobile', $r['field']);
        $this->assertSame(1, $r['moved']);
        $this->assertSame(0, $svc->unknownCount());
        $row = $db->query("SELECT contact_id, mailbox, channel, direction FROM sales_messages")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['contact_id' => 21, 'mailbox' => 'imessage', 'channel' => 'sms', 'direction' => 'inbound'], array_map(fn($v) => is_numeric($v) ? (int)$v : $v, $row));
        // later texts from that number now match without any list
        $this->assertSame(1, $svc->ingest([$this->msg('6047273925', ['guid' => 'm2'])])['stored']);
    }

    public function test_a_full_record_needs_to_be_told_which_number_to_replace(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        $svc->ingest([$this->msg('6047273925')]);
        $r = $svc->linkToContact('6047273925', 22);   // contact 22 has both a phone and a mobile
        $this->assertFalse($r['ok']);
        $this->assertSame('6045550001', $db->query("SELECT phone FROM contacts WHERE id = 22")->fetchColumn());
        $this->assertSame(1, $svc->unknownCount());
        $r2 = $svc->linkToContact('6047273925', 22, 'phone');
        $this->assertTrue($r2['ok']);
        $this->assertSame('6047273925', $db->query("SELECT phone FROM contacts WHERE id = 22")->fetchColumn());
        $this->assertSame('7785550002', $db->query("SELECT mobile FROM contacts WHERE id = 22")->fetchColumn(), 'the other number is untouched');
    }

    public function test_adopting_after_the_number_is_added_by_hand_also_moves_the_texts(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        $svc->ingest([$this->msg('6047273925')]);
        $db->exec("UPDATE contacts SET mobile = '(604) 727-3925' WHERE id = 21");   // Tim edits the customer himself
        $this->assertSame(1, (new TextBridgeService($db))->adoptUnmatched());
        $this->assertSame(0, $svc->unknownCount());
    }

    public function test_before_the_migration_nothing_is_stored_and_nothing_breaks(): void
    {
        $db = $this->db();
        $db->exec("DROP TABLE text_unmatched"); $db->exec("DROP TABLE text_personal");
        $svc = new TextBridgeService($db);
        $r = $svc->ingest([$this->msg('6047273925')]);
        $this->assertSame(0, $r['unmatched']);
        $this->assertSame(1, $r['unknown']);
        $this->assertSame([], $svc->personalHashes());
        $this->assertSame(0, $svc->unknownCount());
    }

    public function test_customer_search_by_name_or_part_of_a_number(): void
    {
        $svc = new TextBridgeService($this->db());
        $this->assertSame([21], array_column($svc->searchContacts('hughes'), 'id'));
        $this->assertSame([21], array_column($svc->searchContacts('John H'), 'id'));
        $this->assertSame([21], array_column($svc->searchContacts('(604) 732'), 'id'));
        $this->assertSame([22], array_column($svc->searchContacts('555-0002'), 'id'));
        $this->assertSame([], $svc->searchContacts('h'), 'one character is not a search');
        $this->assertSame([], $svc->searchContacts('zzzz'));
    }

    public function test_only_a_number_that_is_waiting_can_be_linked(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        $r = $svc->linkToContact('6047273925', 21);   // nothing from that number is waiting
        $this->assertFalse($r['ok']);
        $this->assertNull($db->query("SELECT mobile FROM contacts WHERE id = 21")->fetchColumn());
    }

    public function test_a_number_that_belongs_to_another_customer_is_not_moved_to_this_one(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        $svc->ingest([$this->msg('6047273925')]);
        // the number is put on customer 22 by hand, and a stale tab then tries to link it to customer 21
        $db->exec("UPDATE contacts SET mobile = '6047273925' WHERE id = 22");
        $r = $svc->linkToContact('6047273925', 21);
        $this->assertFalse($r['ok']);
        $this->assertNull($db->query("SELECT mobile FROM contacts WHERE id = 21")->fetchColumn());
    }

    public function test_a_number_marked_personal_after_the_batch_started_is_not_stored(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        // the blocklist is checked again at the moment of insert, not only at the start of the request
        $db->exec("INSERT INTO text_personal (number_hash) VALUES ('" . TextBridgeService::hash('6045559999', self::SALT) . "')");
        $this->assertSame(0, $svc->ingest([$this->msg('6045559999')])['unmatched']);
        $this->assertSame(0, $svc->unknownCount());
    }

    public function test_waiting_texts_are_capped(): void
    {
        $db = $this->db(); $svc = new TextBridgeService($db);
        $db->exec("INSERT INTO text_unmatched (phone10, message_key, direction, snippet, sent_at)
                   SELECT '6045550000', 'bulk-' || value, 'inbound', 'x', '2026-10-01 10:00:00' FROM (WITH RECURSIVE c(value) AS (SELECT 1 UNION ALL SELECT value + 1 FROM c WHERE value < " . TextBridgeService::UNMATCHED_CAP . ") SELECT value FROM c)");
        $this->assertSame(0, $svc->ingest([$this->msg('6047273925')])['unmatched'], 'a full table takes no more');
    }
}
