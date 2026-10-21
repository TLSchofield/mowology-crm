<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's QuickBooks onboarding (2026-10-20): the snapshot moves between sites, the previews read it the
 * way the sandbox stores things (sub-customers are jobs, Category items are folders, the GST number is on
 * the tax agency), and nothing is written on the live site.
 */
class QboOnboardingTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            "CREATE TABLE qbo_onboarding_snapshot (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, qbo_id TEXT, data TEXT, pulled_at TEXT)",
            "CREATE TABLE qbo_onboarding_parts (id INTEGER PRIMARY KEY, site_id INT, part TEXT, summary TEXT, applied_by INT, applied_at TEXT)",
            "CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, notes TEXT)",
            "CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, site_contact_id INT)",
            "CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)",
            "CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT, phone TEXT, notes TEXT, is_active INT)",
        ] as $sql) $db->exec($sql);
        return $db;
    }

    private function snapshot(): string
    {
        $rows = [
            ['company', '1', ['CompanyName' => 'Sandbox CA', 'LegalName' => 'Sandbox CA Ltd', 'CompanyAddr' => ['Line1' => '2845 West 15th', 'City' => 'Vancouver', 'CountrySubDivisionCode' => 'BC'], 'FiscalYearStartMonth' => 'January']],
            ['agency', '1', ['Id' => '1', 'DisplayName' => 'Canada Revenue Agency', 'TaxRegistrationNumber' => '99-1234567']],
            ['term', '3', ['Id' => '3', 'Name' => 'Net 30', 'DueDays' => 30]],
            ['customer', '1', ['Id' => '1', 'DisplayName' => 'Adwin Ko', 'GivenName' => 'Adwin', 'FamilyName' => 'Ko', 'CompanyName' => 'Ko International Ltd', 'BillAddr' => ['Line1' => '6412 av des Erables', 'City' => 'Vancouver']]],
            ['customer', '2', ['Id' => '2', 'DisplayName' => 'Oxon Insurance Agency', 'CompanyName' => 'Oxon Insurance Agency', 'PrimaryEmailAddr' => ['Address' => 'Oxon@Example.ca']]],
            ['customer', '3', ['Id' => '3', 'DisplayName' => 'Oxon - Retreat', 'ParentRef' => ['value' => '2'], 'Job' => true, 'ShipAddr' => ['Line1' => '348 Worthington St. E.']]],
            ['customer', '4', ['Id' => '4', 'DisplayName' => 'Gone Ltd', 'Active' => false]],
            ['item', '1', ['Id' => '1', 'Name' => 'General services', 'Type' => 'Category']],
            ['item', '2', ['Id' => '2', 'Name' => 'Catering', 'FullyQualifiedName' => 'General services:Catering', 'Type' => 'Service', 'UnitPrice' => 0]],
            ['item', '3', ['Id' => '3', 'Name' => 'Lawn Cut', 'Type' => 'Service', 'UnitPrice' => 45]],
            ['vendor', '1', ['Id' => '1', 'DisplayName' => 'Supply Depot']],
            ['vendor', '2', ['Id' => '2', 'DisplayName' => 'Old Vendor', 'Active' => false]],
        ];
        return json_encode(['v' => 1, 'rows' => array_map(fn($r) => ['kind' => $r[0], 'qbo_id' => $r[1], 'data' => json_encode($r[2])], $rows)]);
    }

    private function loaded(PDO $db, bool $local = true): QboOnboardingService
    {
        $ob = new QboOnboardingService($db, null, 1, fn() => 1792540800, true);
        $ob->import($this->snapshot());
        return $local ? $ob : new QboOnboardingService($db, null, 1, fn() => 1792540800, false);
    }

    public function test_a_snapshot_moves_between_sites_and_the_live_site_never_loads_one(): void
    {
        $db = $this->db();
        $ob = $this->loaded($db);
        $this->assertTrue($ob->hasSnapshot());
        $copy = $this->db();
        $this->assertTrue((new QboOnboardingService($copy, null, 1, null, true))->import($ob->export())['ok']);
        $this->assertSame(12, (int)$copy->query('SELECT COUNT(*) FROM qbo_onboarding_snapshot')->fetchColumn());
        $this->assertFalse((new QboOnboardingService($this->db(), null, 1, null, false))->import($ob->export())['ok'], 'live: refused');
        $this->assertFalse((new QboOnboardingService($this->db(), null, 1, null, true))->import('{"x":1}')['ok']);
    }

    public function test_business_details_come_from_the_company_and_the_gst_number_from_the_tax_agency(): void
    {
        $b = $this->loaded($this->db())->business();
        $this->assertSame('Sandbox CA', $b['name']);
        $this->assertSame('99-1234567', $b['gst_number']);
        $this->assertSame('January', $b['fiscal_start']);
        $this->assertSame('Net 30', $b['terms']);
        $this->assertSame('2845 West 15th, Vancouver BC', $b['address']);
    }

    public function test_clients_jobs_and_matches(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name, last_name, email) VALUES (9, 'O', 'X', 'oxon@example.ca')");
        $c = $this->loaded($db)->clients();
        $this->assertSame(1, $c['new'], 'Adwin Ko');
        $this->assertSame(1, $c['match'], 'Oxon by email, case-insensitive');
        $this->assertSame(1, $c['properties'], 'the sub-customer is a job of Oxon, not a client');
        $this->assertSame('A job of Oxon Insurance Agency — kept with that client.', $c['rows'][2]['why']);
        $this->assertCount(3, $c['rows'], 'inactive customers left out');
    }

    public function test_a_client_imported_before_is_recognised_even_with_no_email_or_address(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name, last_name, notes) VALUES (5, 'Adwin', 'Ko', '" . QboOnboardingService::importNote('1') . "')");
        $this->assertSame('Imported from QuickBooks already.', $this->loaded($db)->clients()['rows'][0]['why']);
    }

    public function test_services_skip_folders_and_keep_their_group(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO products (name) VALUES ('LAWN CUT')");
        $s = $this->loaded($db)->services();
        $this->assertSame(1, $s['new']);
        $this->assertSame(1, $s['match'], 'Lawn Cut already in Ittai (by name)');
        $this->assertSame('Catering', $s['rows'][0]['name']);
        $this->assertSame('General services', $s['rows'][0]['group']);
    }

    public function test_suppliers_and_inactive_ones(): void
    {
        $s = $this->loaded($this->db())->suppliers();
        $this->assertSame(1, $s['new']);
        $this->assertSame('Supply Depot', $s['rows'][0]['name']);
    }

    public function test_the_live_site_previews_but_never_imports(): void
    {
        $db = $this->db();
        $live = $this->loaded($db, false);
        $r = $live->apply('suppliers', 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('preview', $r['message']);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM vendors')->fetchColumn());
        $this->assertSame([], $live->partSummaries());
    }

    public function test_the_local_import_of_suppliers_runs_once(): void
    {
        $db = $this->db();
        $ob = $this->loaded($db);
        $this->assertSame('1 suppliers added for Penny.', $ob->apply('suppliers', 1)['message']);
        $this->assertSame('0 suppliers added for Penny.', $ob->apply('suppliers', 1)['message'], 'a second press adds nothing');
        $this->assertSame(['suppliers' => '0 suppliers added for Penny.'], $ob->partSummaries());
    }

    public function test_a_new_read_is_reviewed_again(): void
    {
        $db = $this->db();
        $ob = $this->loaded($db);
        $ob->apply('suppliers', 1);
        $this->assertNotEmpty($ob->partSummaries());
        $ob->import($this->snapshot());
        $this->assertSame([], $ob->partSummaries(), 'old approvals belonged to the old data');
    }

    public function test_names_and_companies(): void
    {
        $this->assertSame(['Adwin', 'Ko'], QboOnboardingService::personName(['GivenName' => 'Adwin', 'FamilyName' => 'Ko']));
        $this->assertSame(['Oxon', 'Insurance Agency'], QboOnboardingService::personName(['DisplayName' => 'Oxon Insurance Agency']));
        $this->assertSame('Ko International Ltd', QboOnboardingService::companyOf(['GivenName' => 'Adwin', 'FamilyName' => 'Ko', 'CompanyName' => 'Ko International Ltd']));
        $this->assertNull(QboOnboardingService::companyOf(['GivenName' => 'Adwin', 'FamilyName' => 'Ko', 'CompanyName' => 'adwin ko']), 'a person\'s own name is not a company');
        $this->assertNull(QboOnboardingService::address(['City' => 'Vancouver']));
    }
}
