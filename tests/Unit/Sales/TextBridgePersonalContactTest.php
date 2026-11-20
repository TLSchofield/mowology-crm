<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TextBridgePersonalContactTest extends TestCase
{
    private const SALT = 'personal-contact-test-salt';

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT, mobile TEXT, company_id INTEGER, is_active INTEGER DEFAULT 1)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, mailbox TEXT NOT NULL, message_key TEXT NOT NULL UNIQUE,
                   direction TEXT NOT NULL, channel TEXT NOT NULL DEFAULT 'email', contact_id INTEGER, from_addr TEXT, from_name TEXT, to_addr TEXT,
                   subject TEXT, snippet TEXT, sent_at TEXT NOT NULL)");
        $db->exec("CREATE TABLE text_unmatched (id INTEGER PRIMARY KEY AUTOINCREMENT, phone10 TEXT NOT NULL, message_key TEXT NOT NULL UNIQUE,
                   direction TEXT NOT NULL, snippet TEXT, sent_at TEXT NOT NULL)");
        $db->exec("CREATE TABLE text_personal (number_hash TEXT PRIMARY KEY, marked_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, contact_id INTEGER)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, contact_id INTEGER)");
        $db->exec("INSERT INTO ops_settings VALUES ('text_bridge_salt', '" . self::SALT . "', '')");
        $db->exec("INSERT INTO contacts VALUES
                   (1, 'Ada', 'Friend', '(604) 555-0101', '778-555-0102', NULL, 1),
                   (2, 'Bea', 'Other', '6045550201', NULL, NULL, 1),
                   (3, 'Cal', 'Worker', '6045550301', NULL, NULL, 1),
                   (4, 'Dee', 'Shared', '7785550102', NULL, NULL, 1),
                   (5, 'No', 'Phone', NULL, '', NULL, 1)");
        return $db;
    }

    private function message(string $phone, string $guid = 'one'): array
    {
        return ['hash' => TextBridgeService::hash($phone, self::SALT), 'direction' => 'in', 'sent_at' => time() - 60,
                'guid' => $guid, 'text' => 'private words'];
    }

    private function addMessage(PDO $db, int $contactId, string $mailbox, string $key, string $at = '2026-10-01 10:00:00'): void
    {
        $st = $db->prepare("INSERT INTO sales_messages (mailbox, message_key, direction, channel, contact_id, snippet, sent_at) VALUES (?, ?, 'inbound', 'sms', ?, 'secret', ?)");
        $st->execute([$mailbox, $key, $contactId, $at]);
    }

    public function test_personal_contact_hash_is_removed_from_numbers_and_old_bridge_match_is_dropped(): void
    {
        $db = $this->db();
        $hash = TextBridgeService::hash('6045550101', self::SALT);
        $db->prepare("INSERT INTO text_personal (number_hash) VALUES (?)")->execute([$hash]);
        $svc = new TextBridgeService($db);
        [, $numbers] = $svc->numbers();
        $this->assertArrayNotHasKey($hash, $numbers);
        $result = $svc->ingest([$this->message('6045550101')]);
        $this->assertSame(1, $result['personal']);
        $this->assertSame(0, $result['stored']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM sales_messages")->fetchColumn());
    }

    public function test_mark_contact_personal_only_deletes_its_imessage_rows_and_writes_both_hashes(): void
    {
        $db = $this->db();
        $this->addMessage($db, 1, 'imessage', 'a');
        $this->addMessage($db, 1, 'email', 'b');
        $this->addMessage($db, 2, 'imessage', 'c');
        $db->exec("INSERT INTO text_unmatched (phone10, message_key, direction, snippet, sent_at) VALUES ('6045550101', 'u1', 'inbound', 'x', '2026-10-01')");
        $result = (new TextBridgeService($db))->markContactPersonal(1, 9);
        $this->assertSame(['ok' => true, 'deleted' => 1], $result);
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM sales_messages")->fetchColumn());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM sales_messages WHERE contact_id = 1 AND mailbox = 'email'")->fetchColumn());
        $hashes = $db->query("SELECT number_hash FROM text_personal ORDER BY number_hash")->fetchAll(PDO::FETCH_COLUMN);
        $expected = [TextBridgeService::hash('6045550101', self::SALT), TextBridgeService::hash('7785550102', self::SALT)];
        sort($expected);
        $this->assertSame($expected, $hashes);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM text_unmatched")->fetchColumn());
        $this->assertSame('Ada', $db->query("SELECT first_name FROM contacts WHERE id = 1")->fetchColumn());
    }

    public function test_work_on_contact_requires_confirmation_and_confirmed_proceeds(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO quotes VALUES (1, 1)");
        $this->addMessage($db, 1, 'imessage', 'a');
        $svc = new TextBridgeService($db);
        $refused = $svc->markContactPersonal(1, 9);
        $this->assertTrue($refused['needs_confirm']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM sales_messages")->fetchColumn());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM text_personal")->fetchColumn());
        $this->assertSame(1, $svc->markContactPersonal(1, 9, true)['deleted']);
    }

    public function test_shared_number_with_another_contacts_work_requires_confirmation(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO invoices VALUES (1, 4)");
        $this->addMessage($db, 1, 'imessage', 'a');
        $result = (new TextBridgeService($db))->markContactPersonal(1, null);
        $this->assertTrue($result['needs_confirm']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM sales_messages")->fetchColumn());
    }

    public function test_not_found_and_no_phone_errors(): void
    {
        $svc = new TextBridgeService($this->db());
        $this->assertSame(['ok' => false, 'error' => 'Contact not found.'], $svc->markContactPersonal(999, null));
        $this->assertSame(['ok' => false, 'error' => 'This contact has no phone number on file.'], $svc->markContactPersonal(5, null));
    }

    public function test_texted_without_work_excludes_kept_and_work_and_returns_no_private_fields(): void
    {
        $db = $this->db();
        $this->addMessage($db, 1, 'imessage', 'a', '2026-10-03 10:00:00');
        $this->addMessage($db, 1, 'imessage', 'b', '2026-10-04 10:00:00');
        $this->addMessage($db, 2, 'imessage', 'c', '2026-10-02 10:00:00');
        $this->addMessage($db, 3, 'imessage', 'd', '2026-10-05 10:00:00');
        $db->exec("INSERT INTO quotes VALUES (1, 3)");
        $svc = new TextBridgeService($db);
        $this->assertTrue($svc->keepContact(2)['ok']);
        $rows = $svc->textedWithoutWork();
        $this->assertSame([['id' => 1, 'name' => 'Ada Friend', 'texts' => 2, 'last_at' => '2026-10-04 10:00:00']], $rows);
        $this->assertSame(['id', 'name', 'texts', 'last_at'], array_keys($rows[0]));
    }

    public function test_keep_contact_persists_across_service_instances_and_unknown_is_refused(): void
    {
        $db = $this->db();
        $this->addMessage($db, 2, 'imessage', 'c');
        $this->assertTrue((new TextBridgeService($db))->keepContact(2)['ok']);
        $this->assertSame([], (new TextBridgeService($db))->textedWithoutWork());
        $this->assertSame(['ok' => false, 'error' => 'Contact not found.'], (new TextBridgeService($db))->keepContact(999));
    }
}
