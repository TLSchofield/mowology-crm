<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** ContactRosterSync fills only what's missing, leaves merged contacts out, and is safe to run twice. */
final class ContactRosterSyncTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT, company_type TEXT, primary_contact_id INT,
            billing_contact_id INT, billing_address TEXT, billing_city TEXT, billing_province TEXT, billing_postal_code TEXT,
            billing_email TEXT, billing_phone TEXT, account_status TEXT, payment_terms TEXT, payment_method TEXT,
            lifecycle_stage TEXT, invoice_routing_method TEXT)");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, company_id INT, email TEXT,
            phone TEXT, mobile TEXT, is_active INT DEFAULT 1, lifecycle_stage TEXT, merged_into_id INT)");
        $this->db->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY, site_id INT, client_type TEXT, display_name TEXT NOT NULL,
            billing_address TEXT, billing_city TEXT, billing_province TEXT, billing_postal_code TEXT, billing_email TEXT,
            billing_phone TEXT, payment_terms TEXT, payment_method TEXT, lifecycle_stage TEXT, status TEXT,
            managed_by_client_id INT, legacy_company_id INT UNIQUE, legacy_contact_id INT UNIQUE)");
        $this->db->exec("CREATE TABLE client_contacts (id INTEGER PRIMARY KEY, client_id INT, contact_id INT, role TEXT, is_primary INT,
            receives_invoices INT, receives_notifications INT, UNIQUE (client_id, contact_id, role))");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, company_id INT, site_contact_id INT, client_id INT)");
        $this->db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, company_id INT, contact_id INT, property_id INT, client_id INT)");
        $this->db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, company_id INT, property_id INT, client_id INT)");
        $this->db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, company_id INT, property_id INT, client_id INT)");

        // A strata whose rep is primary, plus a neighbour on file under the strata, a homeowner, a merged duplicate.
        $this->db->exec("INSERT INTO contacts (id, first_name, last_name, company_id, email) VALUES
            (1, 'Rep', 'Old', 10, 'rep@example.com'),
            (2, 'Neighbour', 'New', 10, 'new@example.com'),
            (3, 'Home', 'Owner', NULL, 'home@example.com')");
        $this->db->exec("INSERT INTO contacts (id, first_name, last_name, merged_into_id) VALUES (4, 'Home', 'Owner', 3)");
        $this->db->exec("INSERT INTO companies (id, company_name, company_type, primary_contact_id, invoice_routing_method)
            VALUES (10, 'Strata Plan 1', 'strata', 1, 'primary_contact')");
        $this->db->exec("INSERT INTO properties (id, company_id, site_contact_id) VALUES (100, 10, NULL), (101, NULL, 3)");
        $this->db->exec("INSERT INTO invoices (id, company_id, contact_id, property_id) VALUES (1000, 10, 1, 100), (1001, NULL, 3, 101), (1002, NULL, NULL, 101)");
        $this->db->exec("INSERT INTO quotes (id, company_id, property_id) VALUES (500, NULL, 101)");
        $this->db->exec("INSERT INTO job_plans (id, company_id, property_id) VALUES (600, 10, NULL)");
    }

    public function testFillsEverythingMissing(): void
    {
        $n = (new ContactRosterSync($this->db))->sync();

        $this->assertSame(1, $n['org_clients']);
        $this->assertSame(3, $n['person_clients'], 'merged contact 4 gets no client');
        $this->assertNull($this->one("SELECT id FROM clients WHERE legacy_contact_id = 4"));

        $strata = (int)$this->one("SELECT id FROM clients WHERE legacy_company_id = 10");
        $this->assertSame('owner', $this->one("SELECT role FROM client_contacts WHERE client_id = $strata AND contact_id = 1"));
        $this->assertSame('1', (string)$this->one("SELECT receives_invoices FROM client_contacts WHERE client_id = $strata AND contact_id = 1"));
        $this->assertSame('council_member', $this->one("SELECT role FROM client_contacts WHERE client_id = $strata AND contact_id = 2"));
        $this->assertSame('0', (string)$this->one("SELECT receives_invoices FROM client_contacts WHERE client_id = $strata AND contact_id = 2"));

        $home = (int)$this->one("SELECT id FROM clients WHERE legacy_contact_id = 3");
        $this->assertSame($strata, (int)$this->one("SELECT client_id FROM properties WHERE id = 100"));
        $this->assertSame($home, (int)$this->one("SELECT client_id FROM properties WHERE id = 101"));
        $this->assertSame($strata, (int)$this->one("SELECT client_id FROM invoices WHERE id = 1000"), 'company wins over contact');
        $this->assertSame($home, (int)$this->one("SELECT client_id FROM invoices WHERE id = 1002"), 'falls back to the property');
        $this->assertSame($home, (int)$this->one("SELECT client_id FROM quotes WHERE id = 500"));
        $this->assertSame($strata, (int)$this->one("SELECT client_id FROM job_plans WHERE id = 600"));
    }

    public function testSecondRunAddsNothingAndKeepsExistingRows(): void
    {
        $sync = new ContactRosterSync($this->db);
        $sync->sync();
        // Tim's own edit to an existing row must survive the next night's run.
        $this->db->exec("UPDATE client_contacts SET receives_invoices = 0 WHERE contact_id = 1");
        $before = (int)$this->one("SELECT COUNT(*) FROM client_contacts");

        $n = $sync->sync();

        $this->assertSame(0, array_sum($n));
        $this->assertSame($before, (int)$this->one("SELECT COUNT(*) FROM client_contacts"));
        $this->assertSame('0', (string)$this->one("SELECT receives_invoices FROM client_contacts WHERE contact_id = 1 AND role = 'owner' AND is_primary = 1 AND client_id = (SELECT id FROM clients WHERE legacy_company_id = 10)"));
    }

    public function testANewContactLandsOnTheRosterNextRun(): void
    {
        $sync = new ContactRosterSync($this->db);
        $sync->sync();
        $this->db->exec("INSERT INTO contacts (id, first_name, last_name, company_id) VALUES (5, 'Second', 'Neighbour', 10)");

        $n = $sync->sync();

        $this->assertSame(1, $n['person_clients']);
        $this->assertSame(1, $n['member_links']);
        $this->assertSame('council_member', $this->one("SELECT role FROM client_contacts WHERE contact_id = 5 AND client_id = (SELECT id FROM clients WHERE legacy_company_id = 10)"));
    }

    /** @return mixed */
    private function one(string $sql)
    {
        $v = $this->db->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }
}
