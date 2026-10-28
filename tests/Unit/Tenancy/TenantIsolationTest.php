<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Penny/HeadLessonsTest.php';

/**
 * Cross-tenant leak test (Ittai audit 2026-10-27). head_lessons is the one head table that can hold
 * more than one tenant's rows, so every read and write of it must be scoped by site_id, and the tenant
 * must come from TenantContext rather than a literal 1. Fixture data is invented.
 */
class TenantIsolationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        TenantContext::reset();
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        HeadLessonsTest::schema($this->db);
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
    }

    private function lesson(string $vendor, string $correct): array
    {
        return ['head' => 'penny', 'subject_kind' => 'vendor', 'subject_key' => $vendor, 'field' => 'accounting_category',
                'said' => 'Office', 'correct' => $correct, 'source' => 'owner_answer'];
    }

    public function testNoSettingMeansSiteOne(): void
    {
        $this->assertSame(1, TenantContext::siteId($this->db));
        $this->assertSame(1, TenantContext::siteId(null));
    }

    public function testSettingSelectsTheSite(): void
    {
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('tenant_site_id', '7')");
        $this->assertSame(7, TenantContext::siteId($this->db));
        // Junk never becomes a tenant.
        TenantContext::reset();
        $this->db->exec("UPDATE ops_settings SET setting_value = '-3'");
        $this->assertSame(1, TenantContext::siteId($this->db));
        TenantContext::reset();
        $this->db->exec("UPDATE ops_settings SET setting_value = 'abc'");
        $this->assertSame(1, TenantContext::siteId($this->db));
    }

    public function testLessonsWrittenByOneTenantAreInvisibleToTheOther(): void
    {
        $a = new HeadLessons($this->db, 1);
        $b = new HeadLessons($this->db, 2);
        $a->record($this->lesson('Home Depot', 'Materials'));
        $b->record($this->lesson('Home Depot', 'Equipment'));

        $this->assertSame(['Materials'], array_column($a->forSubject('penny', 'vendor', 'Home Depot'), 'correct'));
        $this->assertSame(['Equipment'], array_column($b->forSubject('penny', 'vendor', 'Home Depot'), 'correct'));
        $this->assertCount(1, $a->recentFor('penny', 30));
        $this->assertCount(1, $b->recentFor('penny', 30));
    }

    public function testDefaultConstructorFollowsTheTenantSetting(): void
    {
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('tenant_site_id', '4')");
        (new HeadLessons($this->db))->record($this->lesson('Lowes', 'Materials'));
        $site = (int)$this->db->query("SELECT site_id FROM head_lessons")->fetchColumn();
        $this->assertSame(4, $site, 'a default-constructed HeadLessons must write the configured tenant, not a literal 1');
    }

    public function testPennyVendorLessonsAreTenantScoped(): void
    {
        (new HeadLessons($this->db, 1))->record($this->lesson('Rona', 'Materials'));
        (new HeadLessons($this->db, 2))->record($this->lesson('Rona', 'Equipment'));
        $one = PennyLessons::forVendor($this->db, 'Rona', 0, null, 8, 1);
        $two = PennyLessons::forVendor($this->db, 'Rona', 0, null, 8, 2);
        $this->assertSame(['Materials'], array_column($one, 'owner_set'));
        $this->assertSame(['Equipment'], array_column($two, 'owner_set'));
        // And with no explicit site it follows the install's tenant (site 1 here).
        $this->assertSame(['Materials'], array_column(PennyLessons::forVendor($this->db, 'Rona'), 'owner_set'));
    }
}
