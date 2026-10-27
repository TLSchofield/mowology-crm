<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OnboardingStepServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, full_name TEXT, preferred_name TEXT, role TEXT, phone TEXT, contact_channel TEXT, quiet_start TEXT, quiet_end TEXT, quiet_weekends INT DEFAULT 0)');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)');
        $db->exec('CREATE TABLE business_settings (id INTEGER PRIMARY KEY, gst_registration TEXT)');
        $db->exec('CREATE TABLE business_identity (id INTEGER PRIMARY KEY, entered_name TEXT, entered_name_provenance TEXT, legal_name TEXT, legal_name_provenance TEXT, operating_name TEXT, operating_name_provenance TEXT, legal_structure TEXT, legal_structure_provenance TEXT, jurisdiction TEXT, jurisdiction_provenance TEXT, registration_number TEXT, registration_number_provenance TEXT, registration_type TEXT, registration_type_provenance TEXT, registration_status TEXT, registration_status_provenance TEXT, source TEXT, source_record_id TEXT, registry_business_number TEXT, registry_verified_at TEXT, owner_confirmed_at TEXT, updated_by INT, updated_at TEXT)');
        $db->exec('CREATE TABLE qbo_connections (site_id INT, status TEXT, refresh_token_enc TEXT)');
        $db->exec('CREATE TABLE qbo_onboarding_parts (site_id INT, part TEXT)');
        $db->exec("INSERT INTO users (id, role) VALUES (7, 'admin')");
        $db->exec('INSERT INTO business_settings (id) VALUES (1)');
        return $db;
    }

    public function test_name_dispatch_saves_name_and_reach_and_returns_next(): void
    {
        $db = $this->db();
        $result = (new OnboardingStepService($db, 'tenant_test'))->apply(7, [
            'key' => 'name', 'first' => 'Ada', 'last' => 'Lovelace', 'preferred' => 'Ada',
            'channel' => 'text', 'mobile' => '+1 604 555 0101',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('name', $result['next']);
        $user = $db->query('SELECT first_name, last_name, preferred_name, contact_channel, phone FROM users WHERE id = 7')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'preferred_name' => 'Ada', 'contact_channel' => 'text', 'phone' => '(604) 555-0101'], $user);
    }

    public function test_name_dispatch_returns_reach_validation_failure(): void
    {
        $result = (new OnboardingStepService($this->db(), 'tenant_test'))->apply(7, [
            'key' => 'name', 'first' => 'Ada', 'last' => 'Lovelace', 'channel' => 'text', 'mobile' => 'short',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayNotHasKey('next', $result);
    }

    public function test_company_dispatch_validates_and_saves_identity(): void
    {
        $db = $this->db();
        $service = new OnboardingStepService($db, 'tenant_test');

        $invalid = $service->apply(7, ['key' => 'company', 'name' => '']);
        $this->assertSame(['ok' => false, 'message' => 'Please check what you typed and try again.'], $invalid);

        $this->assertFalse($service->apply(7, ['key' => 'company', 'name' => 'A'])['ok'], 'the web minimum of two characters holds');

        $saved = $service->apply(7, ['key' => 'company', 'name' => 'Ab']);
        $this->assertTrue($saved['ok']);
        $this->assertSame('name', $saved['next']);
        $this->assertSame('Ab', $db->query('SELECT legal_name FROM business_identity WHERE id = 1')->fetchColumn());
    }

    public function test_unsupported_step_is_refused(): void
    {
        $this->assertSame(
            ['ok' => false, 'message' => 'That step has no phone form yet.'],
            (new OnboardingStepService($this->db(), 'tenant_test'))->apply(7, ['key' => 'books'])
        );
    }
}
