<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ContactMergeLinksTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE client_contacts (client_id INT, contact_id INT, role TEXT, is_primary INT DEFAULT 0,
                         receives_invoices INT DEFAULT 0, receives_notifications INT DEFAULT 0)");
        $this->db->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY, legacy_contact_id INT)");
    }

    private function link(int $client, int $contact, ?string $role, int $p = 0, int $inv = 0, int $n = 0): void
    {
        $this->db->prepare("INSERT INTO client_contacts VALUES (?,?,?,?,?,?)")->execute([$client, $contact, $role, $p, $inv, $n]);
    }

    public function testMovesLinksAndRepointsTheIdentityRow(): void
    {
        $this->link(1, 20, 'primary_recipient', 1, 1);
        $this->db->exec("INSERT INTO clients VALUES (1, 20)");
        $r = ContactMergeLinks::repoint($this->db, 10, 20);
        $this->assertSame(['client_contacts_moved' => 1, 'client_contacts_dropped' => 0, 'clients_repointed' => 1], $r);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM client_contacts WHERE contact_id = 10")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM client_contacts WHERE contact_id = 20")->fetchColumn());
        $this->assertSame(10, (int)$this->db->query("SELECT legacy_contact_id FROM clients WHERE id = 1")->fetchColumn());
    }

    public function testDuplicateLinkIsDroppedAndKeptRowAbsorbsTheInvoiceFlag(): void
    {
        $this->link(1, 10, 'billing_contact', 0, 0, 0);
        $this->link(1, 20, 'billing_contact', 1, 1, 1);
        $r = ContactMergeLinks::repoint($this->db, 10, 20);
        $this->assertSame(1, $r['client_contacts_dropped']);
        $row = $this->db->query("SELECT * FROM client_contacts")->fetchAll();
        $this->assertCount(1, $row);
        $this->assertSame([10, 1, 1, 1], [(int)$row[0]['contact_id'], (int)$row[0]['is_primary'], (int)$row[0]['receives_invoices'], (int)$row[0]['receives_notifications']]);
    }

    public function testNullRolesAreComparedAsEqual(): void
    {
        $this->link(1, 10, null, 0, 0);
        $this->link(1, 20, null, 0, 1);
        ContactMergeLinks::repoint($this->db, 10, 20);
        $row = $this->db->query("SELECT * FROM client_contacts")->fetchAll();
        $this->assertCount(1, $row);
        $this->assertSame(1, (int)$row[0]['receives_invoices']);
    }

    public function testTwoRealClientsAreNeverFused(): void
    {
        $this->db->exec("INSERT INTO clients VALUES (1, 10), (2, 20)");
        $r = ContactMergeLinks::repoint($this->db, 10, 20);
        $this->assertSame(0, $r['clients_repointed']);
        $this->assertSame(20, (int)$this->db->query("SELECT legacy_contact_id FROM clients WHERE id = 2")->fetchColumn());
    }

    public function testSelfMergeIsANoOp(): void
    {
        $this->link(1, 10, 'x');
        $this->assertSame(0, ContactMergeLinks::repoint($this->db, 10, 10)['client_contacts_moved']);
    }
}
