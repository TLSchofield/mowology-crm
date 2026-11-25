<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Yui proposes the building's manager from who accepted its quote; nothing is recorded until a person answers. Synthetic. */
class BuildingManagerServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT)');
        $db->exec('CREATE TABLE quotes (id INTEGER PRIMARY KEY, quote_number TEXT, property_id INTEGER, status TEXT, contact_id INTEGER,
                   accepted_by_contact_id INTEGER, accepted_by_email TEXT, accepted_at TEXT)');
        $db->exec('CREATE TABLE property_contacts (id INTEGER PRIMARY KEY, property_id INTEGER, contact_id INTEGER, contact_role TEXT, is_primary INTEGER)');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)');
        $db->exec("INSERT INTO properties VALUES (1)");
        $db->exec("INSERT INTO contacts VALUES (5, 'Pat', 'Manager', 'pat@example.test'), (6, 'Sam', 'Other', 'sam@example.test')");
        return $db;
    }

    public function test_the_signer_of_the_accepted_quote_is_proposed_by_email(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO quotes VALUES (1, 'QUO-1', 1, 'accepted', 6, NULL, 'PAT@example.test', '2026-10-09 09:43:00')");
        $s = (new BuildingManagerService($db))->suggest(1);
        $this->assertSame(5, $s['contact_id']);
        $this->assertSame('accepted QUO-1 on Oct 9', $s['why']);
    }

    public function test_yes_records_the_manager_once_and_no_stops_asking(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO quotes VALUES (1, 'QUO-1', 1, 'accepted', 5, 5, NULL, '2026-10-09')");
        $svc = new BuildingManagerService($db);
        $this->assertTrue($svc->confirm(1, 5));
        $this->assertTrue($svc->confirm(1, 5));
        $this->assertSame('1', (string)$db->query('SELECT COUNT(*) FROM property_contacts')->fetchColumn(), 'never doubled');
        $this->assertSame(5, $svc->onFile(1)['contact_id']);
        $this->assertNull($svc->suggest(1), 'on file: nothing to ask');

        $db->exec('DELETE FROM property_contacts');
        $svc->decline(1, 5);
        $this->assertNull($svc->suggest(1), 'the owner said no to that person');
    }

    public function test_no_accepted_quote_no_question(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO quotes VALUES (1, 'QUO-1', 1, 'sent', 5, NULL, NULL, NULL)");
        $this->assertNull((new BuildingManagerService($db))->suggest(1));
    }
}
