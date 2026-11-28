<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CompanyDetailsServiceTest extends TestCase
{
    private function db(int $filed = 3): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, status TEXT)");
        $db->exec("CREATE TABLE business_settings (id INTEGER PRIMARY KEY, company_website TEXT, company_email TEXT, company_phone TEXT, company_address TEXT, fiscal_year_end TEXT, gst_registration TEXT)");
        $db->exec("INSERT INTO business_settings (id, gst_registration, fiscal_year_end) VALUES (1, '123456789RT0001', '12-31')");
        $db->exec("CREATE TABLE business_identity (id INTEGER PRIMARY KEY, legal_name TEXT, operating_name TEXT, registration_number TEXT, legal_structure TEXT, jurisdiction TEXT,
            legal_name_provenance TEXT, operating_name_provenance TEXT, registration_number_provenance TEXT, legal_structure_provenance TEXT, jurisdiction_provenance TEXT,
            owner_confirmed_at TEXT, updated_by INTEGER)");
        $db->exec("INSERT INTO business_identity (id, legal_name, operating_name, registration_number, legal_structure, jurisdiction, legal_name_provenance, operating_name_provenance, registration_number_provenance, legal_structure_provenance, jurisdiction_provenance)
                   VALUES (1, 'Acme Ltd.', 'Acme', 'BC123', 'Corporation', 'BC', 'registry_verified', 'registry_verified', 'registry_verified', 'owner_provided', NULL)");
        for ($i = 1; $i <= $filed; $i++) $db->exec("INSERT INTO expenses (id, status) VALUES ($i, 'approved')");
        return $db;
    }

    public function test_gst_format_check_only(): void
    {
        $this->assertTrue(CompanyDetailsService::gstFormatOk('123456789RT0001'));
        $this->assertTrue(CompanyDetailsService::gstFormatOk('123456789 rt 0001'));
        $this->assertFalse(CompanyDetailsService::gstFormatOk('12345678RT0001'));
        $this->assertFalse(CompanyDetailsService::gstFormatOk('123456789'));
    }

    public function test_form_shows_sources_and_gaps_and_prefills_gst_yes(): void
    {
        $f = (new CompanyDetailsService($this->db()))->form();
        $this->assertSame(['registry', 'registry', 'registry', 'you', null], array_column($f['rows'], 'source'));
        $keys = array_column($f['fields'], 'key');
        $this->assertSame(['website', 'email', 'phone', 'address', 'fiscal_year_end', 'charges_gst', 'gst_number'], $keys);
        $by = array_column($f['fields'], null, 'key');
        $this->assertSame('yes', $by['charges_gst']['value']);
        $this->assertSame('123456789RT0001', $by['gst_number']['value']);
        $this->assertSame(['key' => 'charges_gst', 'equals' => 'yes'], $by['gst_number']['show_if']);
    }

    public function test_confirm_writes_only_changed_rows_and_keeps_other_provenance(): void
    {
        $db = $this->db();
        $r = (new CompanyDetailsService($db))->confirm(['operating_name' => 'Acme Gardens', 'legal_name' => 'Acme Ltd.', 'website' => 'acme.ca', 'email' => 'hi@acme.ca', 'phone' => '604-555-0100', 'fiscal_year_end' => '06-30'], 7);
        $this->assertTrue($r['ok']);
        $i = $db->query('SELECT * FROM business_identity')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Acme Gardens', $i['operating_name']);
        $this->assertSame('owner_provided', $i['operating_name_provenance']);
        $this->assertSame('registry_verified', $i['legal_name_provenance']);   // unchanged text: provenance kept
        $this->assertNotNull($i['owner_confirmed_at']);
        $this->assertSame(7, (int)$i['updated_by']);
        $b = $db->query('SELECT * FROM business_settings')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('https://acme.ca', $b['company_website']);
        $this->assertSame('06-30', $b['fiscal_year_end']);
        $this->assertSame('123456789RT0001', $b['gst_registration']);   // untouched
        $this->assertTrue((new CompanyDetailsService($db))->checked());
    }

    public function test_bad_values_write_nothing(): void
    {
        $db = $this->db();
        $r = (new CompanyDetailsService($db))->confirm(['email' => 'nope', 'fiscal_year_end' => '13-45', 'phone' => '123', 'operating_name' => 'X', 'bogus' => 'y'], 7);
        $this->assertFalse($r['ok']);
        $this->assertSame(['email', 'fiscal_year_end', 'phone', 'bogus'], array_keys($r['errors']));
        $this->assertSame('Acme', $db->query('SELECT operating_name FROM business_identity')->fetchColumn());
        $this->assertFalse((new CompanyDetailsService($db))->checked());
    }

    public function test_gst_yes_with_a_bad_number_is_refused_without_saying_verified(): void
    {
        $r = (new CompanyDetailsService($this->db()))->confirm(['charges_gst' => 'yes', 'gst_number' => '12345'], 1);
        $this->assertFalse($r['ok']);
        $this->assertStringNotContainsStringIgnoringCase('verified', json_encode($r));
        $this->assertStringNotContainsStringIgnoringCase('confirmed', json_encode($r));
    }

    public function test_yes_without_any_number_is_refused_but_a_stored_one_counts(): void
    {
        $db = $this->db(); $db->exec('UPDATE business_settings SET gst_registration = NULL');
        $s = new CompanyDetailsService($db);
        $this->assertFalse($s->confirm(['charges_gst' => 'yes'], 1)['ok']);
        $db->exec("UPDATE business_settings SET gst_registration = '123456789RT0001'");
        $this->assertTrue($s->confirm(['charges_gst' => 'yes'], 1)['ok']);
    }

    public function test_gst_yes_stores_the_typed_number_and_no_clears_it(): void
    {
        $db = $this->db(); $s = new CompanyDetailsService($db);
        $this->assertTrue($s->confirm(['charges_gst' => 'yes', 'gst_number' => '987654321 rt 0001'], 1)['ok']);
        $this->assertSame('987654321RT0001', $db->query('SELECT gst_registration FROM business_settings')->fetchColumn());
        $this->assertTrue($s->confirm(['charges_gst' => 'no'], 1)['ok']);
        $this->assertNull($db->query('SELECT gst_registration FROM business_settings')->fetchColumn());
        $by = array_column($s->form()['fields'], null, 'key');
        $this->assertSame('no', $by['charges_gst']['value']);
    }

    public function test_due_after_third_filed_receipt_or_first_invoice_and_not_after_answer(): void
    {
        $two = new OwnerQuestionService($this->db(2));
        $this->assertNotContains('company_details', array_column($two->open(), 'key'));
        $three = new OwnerQuestionService($this->db(3));
        $this->assertContains('company_details', array_column($three->open(), 'key'));
        $db = $this->db(1); $db->exec('INSERT INTO invoices (id) VALUES (1)');
        $this->assertContains('company_details', array_column((new OwnerQuestionService($db))->open(), 'key'));
        $db = $this->db(3); $svc = new OwnerQuestionService($db);
        $this->assertTrue($svc->answer('company_details', [], 1, [])['ok']);
        $this->assertNotContains('company_details', array_column($svc->open(), 'key'));
    }

    public function test_draft_and_rejected_receipts_do_not_count(): void
    {
        $db = $this->db(0);
        foreach (['draft', 'rejected', 'draft'] as $i => $st) $db->exec("INSERT INTO expenses (id, status) VALUES (" . ($i + 1) . ", '$st')");
        $this->assertSame(0, (new CompanyDetailsService($db))->filedReceipts());
    }

    public function test_the_open_shape_is_the_form_shape(): void
    {
        $q = array_column((new OwnerQuestionService($this->db()))->open(), null, 'key')['company_details'];
        $this->assertSame(['key', 'title', 'body', 'multi', 'choices', 'form', 'confirm_label', 'answer'], array_keys($q));
        $this->assertSame([], $q['choices']);
        $this->assertSame('Looks right', $q['confirm_label']);
        $this->assertSame(['mode' => 'question_answer', 'key' => 'company_details', 'values' => true], $q['answer']);
    }
}
