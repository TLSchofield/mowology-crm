<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Signed contracts keep a version before an edit; new contracts default to a 5% renewal
 * increase (Fable review 2026-10-12).
 */
class ContractSignedEditTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $db = class_exists('Pdo\\Sqlite') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $now = function () { return date('Y-m-d H:i:s'); };
        if (method_exists($db, 'createFunction')) $db->createFunction('NOW', $now, 0); else $db->sqliteCreateFunction('NOW', $now, 0);
        $db->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, title TEXT, billing_cycle TEXT, billing_amount REAL,
            invoice_timing TEXT, start_date TEXT, end_date TEXT, renewal_date TEXT, auto_renew INTEGER,
            renewal_increase_pct REAL, notes TEXT, contact_id INTEGER, signature_status TEXT,
            current_version INTEGER DEFAULT 0, updated_at TEXT)");
        $db->exec("CREATE TABLE contract_versions (id INTEGER PRIMARY KEY, contract_id INTEGER, version_number INTEGER,
            changed_by INTEGER, change_reason TEXT, title TEXT, billing_cycle TEXT, billing_amount REAL, invoice_timing TEXT,
            start_date TEXT, end_date TEXT, renewal_date TEXT, auto_renew INTEGER, renewal_increase_pct REAL,
            notes TEXT, signature_status TEXT)");
        $db->exec("INSERT INTO contracts (id, title, billing_cycle, billing_amount, start_date, auto_renew, renewal_increase_pct, notes, contact_id, signature_status)
                   VALUES (1, 'Lawn care', 'monthly', 400.00, '2026-04-01', 1, 5.00, NULL, 7, 'signed'),
                          (2, 'Lawn care', 'monthly', 400.00, '2026-04-01', 1, 5.00, NULL, 7, 'unsigned')");
        $this->db = $db;
    }

    private function edit(array $over = []): array
    {
        return array_merge([
            'title' => 'Lawn care', 'billing_cycle' => 'monthly', 'billing_amount' => 400.0,
            'start_date' => '2026-04-01', 'end_date' => null, 'renewal_date' => null, 'auto_renew' => 1,
            'renewal_increase_pct' => 5.0, 'notes' => '', 'contact_id' => null,
        ], $over);
    }

    private function versions(int $id): array
    {
        $s = $this->db->prepare("SELECT * FROM contract_versions WHERE contract_id = ?");
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function test_signed_contract_price_change_keeps_the_signed_terms_as_a_version(): void
    {
        $svc = new ContractService($this->db);
        $this->assertTrue($svc->snapshotSignedBeforeEdit(1, $this->edit(['billing_amount' => 450.0]), 3));
        $v = $this->versions(1);
        $this->assertCount(1, $v);
        $this->assertEquals(400.0, (float)$v[0]['billing_amount']);
        $this->assertSame('signed', $v[0]['signature_status']);
        $this->assertStringContainsString('billing_amount', $v[0]['change_reason']);
    }

    public function test_unchanged_or_unsigned_contracts_write_no_version(): void
    {
        $svc = new ContractService($this->db);
        $this->assertFalse($svc->snapshotSignedBeforeEdit(1, $this->edit(), 3));
        $this->assertFalse($svc->snapshotSignedBeforeEdit(2, $this->edit(['billing_amount' => 999.0]), 3));
        $this->assertSame([], $this->versions(1));
        $this->assertSame([], $this->versions(2));
    }

    public function test_changed_fields_ignore_formatting_and_absent_contact(): void
    {
        $cur = ['title' => 'A', 'billing_amount' => '400.00', 'renewal_increase_pct' => null, 'auto_renew' => '1', 'contact_id' => 7, 'notes' => null];
        $this->assertSame([], ContractService::changedSignedFields($cur, ['title' => ' A ', 'billing_amount' => 400, 'renewal_increase_pct' => 0.0, 'auto_renew' => 1, 'contact_id' => null, 'notes' => '']));
        $this->assertSame(['contact_id'], ContractService::changedSignedFields($cur, ['contact_id' => 8]));
        $this->assertSame(['billing_amount'], ContractService::changedSignedFields($cur, ['billing_amount' => '400.01']));
    }

    public function test_renewal_increase_defaults_to_five_percent_or_the_ops_setting(): void
    {
        $this->assertSame(5.0, ContractService::defaultRenewalIncreasePct($this->db)); // no ops_settings table
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT)");
        $this->assertSame(5.0, ContractService::defaultRenewalIncreasePct($this->db));
        $this->db->exec("INSERT INTO ops_settings VALUES ('contract_renewal_increase_pct', '3.5')");
        $this->assertSame(3.5, ContractService::defaultRenewalIncreasePct($this->db));
        $this->assertSame(50.0, ContractService::clampRenewalIncreasePct(80));
        $this->assertSame(0.0, ContractService::clampRenewalIncreasePct(-2));
    }
}
