<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** InvoiceRouting::stampClientId — a new invoice gets its client in the same order as the nightly roster sync. */
class InvoiceStampClientTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY, legacy_company_id INT, legacy_contact_id INT)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, client_id INT)");
        $this->db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, client_id INT)");
        $this->db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, company_id INT, contact_id INT, property_id INT, plan_id INT, client_id INT)");
        $this->db->exec("INSERT INTO clients VALUES (100, 5, NULL), (200, NULL, 7), (300, NULL, NULL)");
        $this->db->exec("INSERT INTO properties VALUES (1, 300)");
        $this->db->exec("INSERT INTO job_plans VALUES (9, 300)");
    }

    private function invoice(int $id, ?int $co, ?int $ct, ?int $prop, ?int $plan, ?int $client = null): void
    {
        $this->db->prepare("INSERT INTO invoices VALUES (?,?,?,?,?,?)")->execute([$id, $co, $ct, $prop, $plan, $client]);
    }

    private function client(int $id): ?int
    {
        $v = $this->db->query("SELECT client_id FROM invoices WHERE id = $id")->fetchColumn();
        return $v === null ? null : (int)$v;
    }

    public function testCompanyThenContactThenPropertyThenPlan(): void
    {
        $this->invoice(1, 5, 7, 1, 9);
        $this->invoice(2, null, 7, 1, 9);
        $this->invoice(3, null, null, 1, null);
        $this->invoice(4, null, null, null, 9);
        foreach ([1, 2, 3, 4] as $id) InvoiceRouting::stampClientId($this->db, $id);
        $this->assertSame([100, 200, 300, 300], [$this->client(1), $this->client(2), $this->client(3), $this->client(4)]);
    }

    public function testNeverOverwritesAnExistingClientAndNeverThrows(): void
    {
        $this->invoice(5, 5, null, null, null, 999);
        $this->assertSame(0, InvoiceRouting::stampClientId($this->db, 5));
        $this->assertSame(999, $this->client(5));
        $this->assertSame(0, InvoiceRouting::stampClientId($this->db, 0));
        $this->db->exec("DROP TABLE clients");
        $this->invoice(6, 5, null, null, null);
        $this->assertSame(0, InvoiceRouting::stampClientId($this->db, 6));
    }
}
