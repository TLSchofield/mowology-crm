<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class RecipientDriftReportTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = $this->db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, is_active INT DEFAULT 1)");
        $db->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY, legacy_company_id INT, legacy_contact_id INT, managed_by_client_id INT)");
        $db->exec("CREATE TABLE client_contacts (client_id INT, contact_id INT, role TEXT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, client_id INT, site_contact_id INT, property_manager_id INT, billing_company_id INT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, client_id INT, company_id INT)");
        $db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, client_id INT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, client_id INT)");
    }

    private function counts(): array
    {
        $r = RecipientDriftReport::run($this->db);
        $this->assertSame([], array_filter($r, fn($x) => isset($x['error'])), 'every check must run');
        return array_map(fn($x) => $x['count'], $r);
    }

    public function testCleanDatabaseReportsZeroEverywhere(): void
    {
        $this->db->exec("INSERT INTO contacts VALUES (1,1)");
        $this->db->exec("INSERT INTO clients VALUES (10, 5, NULL, NULL), (11, NULL, 1, NULL)");
        $this->db->exec("INSERT INTO client_contacts VALUES (11, 1, 'primary')");
        $this->db->exec("INSERT INTO properties VALUES (1, 11, 1, NULL, NULL)");
        $this->db->exec("INSERT INTO invoices VALUES (1, 10, 5)");
        $this->assertSame(0, array_sum($this->counts()));
        $this->assertSame('Drift 0 — every link agrees', RecipientDriftReport::summary(RecipientDriftReport::run($this->db)));
    }

    public function testOrphansAndDisagreementsAreCountedWithSamples(): void
    {
        $this->db->exec("INSERT INTO contacts VALUES (1,1), (2,0)");
        $this->db->exec("INSERT INTO clients VALUES (10, 5, NULL, NULL), (11, NULL, 2, NULL), (12, 6, NULL, NULL)");
        $this->db->exec("INSERT INTO properties VALUES (1, NULL, NULL, NULL, NULL), (2, 11, 1, NULL, NULL), (3, 11, NULL, 5, NULL), (4, 11, NULL, NULL, 6)");
        $this->db->exec("INSERT INTO invoices VALUES (1, NULL, 5), (2, 11, 5)");
        $this->db->exec("INSERT INTO quotes VALUES (1, NULL)");
        $this->db->exec("INSERT INTO job_plans VALUES (1, NULL), (2, NULL)");
        $this->db->exec("INSERT INTO client_contacts VALUES (11, 2, 'x')");
        $c = $this->counts();
        $this->assertSame(1, $c['orphan_properties']);
        $this->assertSame(1, $c['orphan_invoices']);
        $this->assertSame(1, $c['orphan_quotes']);
        $this->assertSame(2, $c['orphan_job_plans']);
        $this->assertSame(1, $c['site_contact_off_client']);     // property 2: contact 1 is not client 11's person
        $this->assertSame(1, $c['manager_not_on_client']);        // property 3: manager firm 5 is client 10, client 11 isn't managed by it
        $this->assertSame(1, $c['billing_company_off_client']);   // property 4: billing company 6 is client 12
        $this->assertSame(1, $c['invoice_company_off_client']);   // invoice 2: company 5 is client 10, not 11
        $this->assertSame(1, $c['client_on_inactive_contact']);
        $this->assertSame(1, $c['recipient_on_inactive_contact']);
        $this->assertStringStartsWith('Drift ', RecipientDriftReport::summary(RecipientDriftReport::run($this->db)));
    }

    public function testNeverWrites(): void
    {
        $this->db->exec("INSERT INTO properties VALUES (1, NULL, NULL, NULL, NULL)");
        RecipientDriftReport::run($this->db);
        $this->assertNull($this->db->query("SELECT client_id FROM properties WHERE id = 1")->fetchColumn() ?: null);
    }

    public function testAMissingColumnLosesOneCheckNotTheReport(): void
    {
        $this->db->exec("DROP TABLE quotes");
        $r = RecipientDriftReport::run($this->db);
        $this->assertArrayHasKey('error', $r['orphan_quotes']);
        $this->assertArrayHasKey('count', $r['orphan_invoices']);
        $this->assertStringContainsString('orphan_quotes: ERROR', RecipientDriftReport::summary($r));
    }
}
