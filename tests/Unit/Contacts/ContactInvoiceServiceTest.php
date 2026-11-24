<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A strata rep's page lists the building's invoices, names the billing account, and totals like the team panel. */
class ContactInvoiceServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (method_exists($db, 'createFunction')) $db->createFunction('CURDATE', fn() => date('Y-m-d'), 0); else $db->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'), 0);
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, site_contact_id INT, quote_contact_id INT, client_id INT)');
        $db->exec('CREATE TABLE property_contacts (id INTEGER PRIMARY KEY, property_id INT, contact_id INT, ended_on TEXT)');
        $db->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, display_name TEXT, legacy_contact_id INT)');
        $db->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)');
        $db->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, status TEXT, total REAL, balance_due REAL,
            issue_date TEXT, due_date TEXT, amount_paid REAL, payment_method TEXT, payment_reference TEXT,
            client_id INT, company_id INT, contact_id INT, property_id INT, plan_id INT, created_at TEXT)');
        $db->exec("INSERT INTO clients VALUES (2126,'Roland Bowman',70),(2075,'Quay Pacific Property Management Ltd',NULL)");
        $db->exec("INSERT INTO companies VALUES (15,'Quay Pacific Property Management Ltd')");
        // Laburnum: site contact is someone else; Roland is quote contact + property_contacts
        $db->exec("INSERT INTO properties VALUES (77,1518,70,2075),(90,NULL,NULL,NULL),(91,NULL,NULL,NULL),(92,NULL,NULL,NULL)");
        $db->exec("INSERT INTO property_contacts VALUES (1,90,70,NULL),(2,91,70,'2026-01-01'),(3,92,70,'2999-01-01')");
        $db->exec("INSERT INTO invoices VALUES
          (1,'INV-88','overdue',897.87,42.76,NULL,NULL,855.11,NULL,NULL,2075,15,70,77,NULL,'2026-06-01'),
          (2,'INV-300','overdue',897.87,897.87,NULL,NULL,0,NULL,NULL,2126,NULL,70,77,NULL,'2026-08-01'),
          (3,'INV-525','sent',162.75,162.75,NULL,NULL,0,NULL,NULL,2075,15,1518,77,NULL,'2026-11-01'),
          (4,'INV-PC','paid',100,0,NULL,NULL,100,NULL,NULL,NULL,NULL,5,90,NULL,'2026-05-01'),
          (5,'INV-ENDED','sent',50,50,NULL,NULL,0,NULL,NULL,NULL,NULL,5,91,NULL,'2026-05-02'),
          (7,'INV-FUTURE','sent',20,20,NULL,NULL,0,NULL,NULL,NULL,NULL,5,92,NULL,'2026-05-04'),
          (6,'INV-OTHER','sent',70,70,NULL,NULL,0,NULL,NULL,9999,NULL,9,500,NULL,'2026-05-03')");
        return $db;
    }

    public function test_property_ids_use_every_link_and_skip_ended(): void
    {
        $this->assertEqualsCanonicalizing([77, 90, 92], (new ContactInvoiceService($this->db()))->propertyIds(70));
    }

    public function test_strata_rep_sees_the_building_invoices_labelled_by_billing_account(): void
    {
        $rows = (new ContactInvoiceService($this->db()))->invoices(70);
        $nums = array_column($rows, 'invoice_number');
        sort($nums);
        $this->assertSame(['INV-300', 'INV-525', 'INV-88', 'INV-FUTURE', 'INV-PC'], $nums);
        $by = array_column($rows, null, 'invoice_number');
        $this->assertSame('Quay Pacific Property Management Ltd', $by['INV-88']['billed_to']);
        $this->assertTrue($by['INV-88']['billed_to_other']);
        $this->assertSame('Roland Bowman', $by['INV-300']['billed_to']);
        $this->assertFalse($by['INV-300']['billed_to_other'], 'his own account');
    }

    public function test_totals_match_the_team_panel_open_rule(): void
    {
        $t = ContactInvoiceService::totals((new ContactInvoiceService($this->db()))->invoices(70));
        $this->assertSame(1123.38, $t['outstanding']);   // 42.76 + 897.87 + 162.75
        $this->assertSame(955.11, $t['paid']);
        $this->assertSame(0.0, ContactInvoiceService::totals([['status' => 'paid', 'balance_due' => 5, 'total' => 5]])['outstanding']);
    }

    public function test_unknown_contact_gets_nothing(): void
    {
        $this->assertSame([], (new ContactInvoiceService($this->db()))->invoices(424242));
    }
}
