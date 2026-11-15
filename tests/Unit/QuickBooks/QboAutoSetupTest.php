<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QboAutoSetupTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE qbo_connections (site_id INT, status TEXT, refresh_token_enc TEXT, environment TEXT, realm_id TEXT, company_name TEXT, country TEXT, connected_at TEXT, refresh_expires_at TEXT, last_pull_at TEXT, last_error TEXT)");
        $db->exec("INSERT INTO qbo_connections VALUES (1, 'connected', 'token', 'production', 'realm', 'Test Co', 'CA', NULL, NULL, NULL, NULL)");
        $db->exec("CREATE TABLE qbo_remote_items (site_id INT, kind TEXT, qbo_id TEXT, name TEXT, detail TEXT, active INT)");
        $db->exec("CREATE TABLE qbo_mappings (site_id INT, kind TEXT, ittai_key TEXT, qbo_id TEXT, qbo_name TEXT, set_by INT)");
        $db->exec("CREATE TABLE chart_of_accounts (code TEXT, name TEXT, type TEXT, is_active INT)");
        $db->exec("INSERT INTO chart_of_accounts VALUES ('4000', 'Service Revenue', 'revenue', 1), ('6100', 'Fuel', 'expense', 1)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE business_settings (id INT, timezone TEXT)");
        $db->exec("INSERT INTO business_settings VALUES (1, 'America/Vancouver')");
        $db->exec("CREATE TABLE qbo_links (site_id INT, realm_id TEXT, status TEXT, qbo_id TEXT, entity_type TEXT)");
        return $db;
    }

    private function seedRemote(PDO $db, bool $twoBanks = false, bool $twoGst = false): void
    {
        $rows = [
            ['account', 'a1', 'Service Revenue', 'Income'],
            ['account', 'a2', 'Fuel', 'Expense'],
            ['account', 'b1', 'Operating', 'Bank'],
            ['account', 'c1', 'Business Card', 'Credit Card'],
            ['tax_code', 't1', 'GST', 'GST 5%'],
            ['tax_code', 't2', 'Exempt', ''],
            ['tax_code', 't3', 'Out of Scope', ''],
        ];
        if ($twoBanks) $rows[] = ['account', 'b2', 'Savings', 'Bank'];
        if ($twoGst) $rows[] = ['tax_code', 't4', 'GST on sales', ''];
        $s = $db->prepare('INSERT INTO qbo_remote_items VALUES (1, ?, ?, ?, ?, 1)');
        foreach ($rows as $row) $s->execute($row);
    }

    private function service(PDO $db, bool $default = false): QboAutoSetupService
    {
        $conn = new QboConnectionService($db, 1, null, null, null, fn() => ['status' => 500, 'body' => '']);
        return new QboAutoSetupService($conn, fn() => new DateTimeImmutable('2026-11-16 07:30:00+00:00'), $default);
    }

    public function test_unique_matches_are_saved_automatically_and_sending_starts_today(): void
    {
        $db = $this->db(); $this->seedRemote($db);
        $r = $this->service($db)->run();
        $this->assertCount(7, $r['mapped']);
        $this->assertSame([], $r['needs']);
        $this->assertTrue($r['sending']);
        $settings = $db->query('SELECT setting_key, setting_value FROM ops_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('2026-11-15', $settings['qbo_auto_from'], 'UTC evening is still today in the business timezone');
        $this->assertSame('1', $settings['qbo_auto_send']);
        $this->assertSame('1', $settings['qbo_auto_receipts']);
        $this->assertSame('1', $settings['qbo_live_sends']);
        $this->assertSame('2026-11-15', $settings['qbo_autosetup_done']);
        $this->assertSame(7, (int)$db->query('SELECT COUNT(*) FROM qbo_mappings WHERE set_by IS NULL')->fetchColumn());
    }

    public function test_ambiguous_bank_and_tax_code_are_left_for_the_owner(): void
    {
        $db = $this->db(); $this->seedRemote($db, true, true);
        $r = $this->service($db)->autoMap();
        $keys = array_column($r['needs'], 'key');
        $this->assertContains('1010', $keys);
        $this->assertContains('gst', $keys);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM qbo_mappings WHERE ittai_key IN ('1010', 'gst')")->fetchColumn());
    }

    public function test_owner_mapping_is_not_overwritten_and_needs_only_contains_unmapped_keys(): void
    {
        $db = $this->db(); $this->seedRemote($db, true);
        $db->exec("INSERT INTO qbo_mappings VALUES (1, 'account', '6100', 'owner-id', 'Owner choice', 42)");
        $r = $this->service($db)->autoMap();
        $this->assertSame('owner-id', $db->query("SELECT qbo_id FROM qbo_mappings WHERE ittai_key = '6100'")->fetchColumn());
        $this->assertNotContains('6100', array_column($r['needs'], 'key'));
        $this->assertContains('1010', array_column($r['needs'], 'key'));
    }

    public function test_one_remote_account_cannot_be_claimed_by_two_ittai_accounts(): void
    {
        $db = $this->db();
        $db->exec("UPDATE chart_of_accounts SET name = 'Fuel', type = 'expense'");
        $db->exec("INSERT INTO qbo_remote_items VALUES (1, 'account', 'a2', 'Fuel', 'Expense', 1)");
        $r = $this->service($db)->autoMap();
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM qbo_mappings WHERE kind = 'account'")->fetchColumn());
        $this->assertCount(1, array_filter($r['needs'], fn($n) => $n['kind'] === 'account'));
    }

    public function test_default_tenant_writes_nothing(): void
    {
        $db = $this->db(); $this->seedRemote($db);
        $r = $this->service($db, true)->run();
        $this->assertSame([], $r['mapped']);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM qbo_mappings')->fetchColumn());
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM ops_settings')->fetchColumn());
    }

    public function test_second_run_is_idempotent_and_an_owner_who_turned_auto_off_stays_off(): void
    {
        $db = $this->db(); $this->seedRemote($db);
        $svc = $this->service($db);
        $svc->run();
        $count = (int)$db->query('SELECT COUNT(*) FROM qbo_mappings')->fetchColumn();
        $again = $svc->run();
        $this->assertSame([], $again['mapped']);
        $this->assertFalse($again['sending']);
        $this->assertSame($count, (int)$db->query('SELECT COUNT(*) FROM qbo_mappings')->fetchColumn());

        $db2 = $this->db(); $this->seedRemote($db2);
        $db2->exec("INSERT INTO ops_settings VALUES ('qbo_auto_from', '2026-10-01', ''), ('qbo_auto_send', '0', '')");
        $this->assertFalse($this->service($db2)->enableSending());
        $this->assertSame('0', $db2->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'qbo_auto_send'")->fetchColumn());
        $this->assertSame(0, (int)$db2->query("SELECT COUNT(*) FROM ops_settings WHERE setting_key = 'qbo_live_sends'")->fetchColumn());
    }
}
