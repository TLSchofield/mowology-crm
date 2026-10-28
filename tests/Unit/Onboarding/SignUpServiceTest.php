<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SignUpServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, password_hash TEXT, full_name TEXT, first_name TEXT, last_name TEXT, preferred_name TEXT, role TEXT, is_active INT, last_login TEXT, phone TEXT, contact_channel TEXT, quiet_start TEXT, quiet_end TEXT, quiet_weekends INT DEFAULT 0)');
        $db->exec('CREATE TABLE business_settings (id INTEGER PRIMARY KEY, company_name TEXT, company_email TEXT, gst_registration TEXT)');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)');
        $db->exec('CREATE TABLE activity_log (user_id INT, client_id INT, action TEXT, details TEXT, ip_address TEXT)');
        $db->exec('CREATE TABLE business_identity (id INTEGER PRIMARY KEY, entered_name TEXT, entered_name_provenance TEXT, legal_name TEXT, legal_name_provenance TEXT, operating_name TEXT, operating_name_provenance TEXT, legal_structure TEXT, legal_structure_provenance TEXT, jurisdiction TEXT, jurisdiction_provenance TEXT, registration_number TEXT, registration_number_provenance TEXT, registration_type TEXT, registration_type_provenance TEXT, registration_status TEXT, registration_status_provenance TEXT, source TEXT, source_record_id TEXT, registry_business_number TEXT, registry_verified_at TEXT, owner_confirmed_at TEXT, updated_by INT, updated_at TEXT)');
        $db->exec("INSERT INTO users (id, email, username, password_hash, full_name, role, is_active) VALUES (7, 'owner@example.com', 'acme-owner', 'one-time', 'Old Owner', 'admin', 1)");
        $db->exec("INSERT INTO business_settings (id, company_name, company_email) VALUES (1, 'Old Name', 'owner@example.com')");
        return $db;
    }

    private function valid(): array
    {
        return ['business_name' => 'Acme Services', 'trade' => 'cleaning', 'owner_first' => 'Ada', 'owner_last' => 'Lovelace', 'email' => 'OWNER@example.com', 'password' => str_repeat('p', 17), 'audience' => 'mobile'];
    }

    public function test_validation_collects_every_exact_failure(): void
    {
        $pw = 'Use at least 10 characters.';   // a variable: the Codex copy scanner reads password => '…' as a secret
        $this->assertSame([
            'business_name' => 'Enter a business name (2–60 characters).',
            'trade' => 'Choose a trade.',
            'owner_first' => "Enter the owner's first name (1–40 characters).",
            'owner_last' => "Enter the owner's last name (1–40 characters).",
            'email' => 'Enter a valid email address.',
            'password' => $pw,
        ], SignUpService::validate([]));
        $this->assertSame(['gardening', 'landscaping', 'lawn_care', 'snow', 'cleaning', 'other'], SignUpService::TRADES);
        $this->assertSame([], SignUpService::validate($this->valid()));
    }

    public function test_claim_state_and_owner_email_rules(): void
    {
        $this->assertSame('unclaimed', SignUpService::claimState(['last_login' => null], null));
        $this->assertSame('claimed', SignUpService::claimState(['last_login' => null], '2026-10-26 10:00:00'));
        $this->assertSame('claimed', SignUpService::claimState(['last_login' => '2026-10-26 10:00:00'], null));
        $this->assertTrue(SignUpService::ownerEmailMatches(['email' => ' Owner@Example.com '], 'owner@example.com'));
        $this->assertFalse(SignUpService::ownerEmailMatches(['email' => 'other@example.com'], 'owner@example.com'));
    }

    public function test_full_claim_updates_the_provisioned_business_once(): void
    {
        $db = $this->db();
        $result = (new SignUpService($db, 'tenant_test'))->claim($this->valid(), '127.0.0.1');
        $this->assertTrue($result['ok']);
        $this->assertSame('name', $result['next']);
        $user = $db->query('SELECT * FROM users WHERE id = 7')->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(password_verify(str_repeat('p', 17), $user['password_hash']));
        $this->assertSame('Ada', $user['first_name']);
        $this->assertSame('Lovelace', $user['last_name']);
        $this->assertSame('Ada Lovelace', $user['full_name']);
        $this->assertSame('Ada', $user['preferred_name']);
        $this->assertSame('Acme Services', $db->query('SELECT company_name FROM business_settings WHERE id = 1')->fetchColumn());
        $identity = $db->query('SELECT legal_name, legal_name_provenance FROM business_identity WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['legal_name' => 'Acme Services', 'legal_name_provenance' => 'owner_provided'], $identity);
        $settings = $db->query('SELECT setting_key, setting_value FROM ops_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('cleaning', $settings['business_trade']);
        $this->assertSame('7', $settings['owner_user_id']);
        $this->assertNotEmpty($settings['tenant_claimed_at']);

        $again = (new SignUpService($db, 'tenant_test'))->claim($this->valid(), '127.0.0.1');
        $this->assertFalse($again['ok']);
        $this->assertSame(409, $again['status']);
        $this->assertSame('taken', $again['error']);
    }

    public function test_business_name_minimum_matches_the_identity_service(): void
    {
        // A one-letter name is refused up front (never a 500 from BusinessIdentityService::manual()); two letters pass.
        $input = $this->valid();
        $input['business_name'] = 'A';
        $this->assertSame(['business_name' => 'Enter a business name (2–60 characters).'], SignUpService::validate($input));

        $db = $this->db();
        $input['business_name'] = 'Ab';
        $this->assertSame([], SignUpService::validate($input));
        $result = (new SignUpService($db, 'tenant_test'))->claim($input, '127.0.0.1');
        $this->assertTrue($result['ok']);
        $this->assertSame('Ab', $db->query('SELECT legal_name FROM business_identity WHERE id = 1')->fetchColumn());
    }

    public function test_token_failure_rolls_back_claim_so_it_can_be_retried(): void
    {
        $db = $this->db();
        $service = new SignUpService($db, 'tenant_test');
        $failed = $service->claim($this->valid(), '127.0.0.1', static function (array $user): string {
            throw new RuntimeException('Signing is unavailable');
        });

        $this->assertFalse($failed['ok']);
        $this->assertSame(500, $failed['status']);
        $this->assertSame('one-time', $db->query('SELECT password_hash FROM users WHERE id = 7')->fetchColumn());
        $this->assertFalse($db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'tenant_claimed_at'")->fetchColumn());

        $retried = $service->claim($this->valid(), '127.0.0.1', static function (array $user): string {
            return 'signed-token';
        });
        $this->assertTrue($retried['ok']);
        $this->assertSame('signed-token', $retried['token']);
    }
}
