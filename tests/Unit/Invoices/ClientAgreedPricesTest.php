<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ClientAgreedPrices — the agreed price the invoice "Add from services" picker offers first.
 * Fixture mirrors production 2026-11-01: SUSAN LIM, 4175 West 10th Avenue (property 39),
 * PLN-2026-0081 "BI-WEEKLY MOWING", service "14 Day Lawn Cut Service", $48.00 per visit, no contract.
 */
class ClientAgreedPricesTest extends TestCase
{
    private PDO $db;
    private const TODAY = '2026-11-01';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, plan_number TEXT, property_id INT, company_id INT, contract_id INT,
                   title TEXT, service_type TEXT, pricing_model TEXT DEFAULT 'per_visit', price_per_visit REAL, monthly_flat_price REAL,
                   seasonal_price REAL, status TEXT DEFAULT 'active', plan_start_date TEXT, plan_end_date TEXT, season_start TEXT, season_end TEXT)");
        $db->exec("CREATE TABLE plan_line_items (id INTEGER PRIMARY KEY, plan_id INT, service_type TEXT, description TEXT,
                   quantity REAL, unit_type TEXT, unit_price REAL, sort_order INT DEFAULT 0)");
        $db->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, contract_number TEXT, status TEXT)");
        $this->db = $db;
        $db->exec("INSERT INTO job_plans (id, plan_number, property_id, title, service_type, price_per_visit, plan_start_date)
                   VALUES (96, 'PLN-2026-0081', 39, 'BI-WEEKLY MOWING', '14 Day Lawn Cut Service', 48.00, '2026-05-21')");
        $db->exec("INSERT INTO plan_line_items (plan_id, service_type, description, quantity, unit_type, unit_price)
                   VALUES (96, '14 Day Lawn Cut Service', '', 1, 'visit', 48.00)");
    }

    private function svc(): ClientAgreedPrices { return new ClientAgreedPrices($this->db, self::TODAY); }

    public function testSusanLimGetsHerAgreedPrice(): void
    {
        $r = $this->svc()->forProperty(39);
        $this->assertCount(1, $r);
        $this->assertSame('14 Day Lawn Cut Service', $r[0]['name']);
        $this->assertSame(48.0, $r[0]['unit_price']);
        $this->assertSame('visit', $r[0]['unit_type']);
        $this->assertSame('BI-WEEKLY MOWING · PLN-2026-0081', $r[0]['label']);
    }

    public function testNoPlanMeansNoAgreedPrices(): void
    {
        $this->assertSame([], $this->svc()->forProperty(40));
        $this->assertSame([], $this->svc()->forProperty(0));
    }

    public function testPausedEndedAndFuturePlansAreNotOffered(): void
    {
        $this->db->exec("UPDATE job_plans SET status = 'paused'");
        $this->assertSame([], $this->svc()->forProperty(39));
        $this->db->exec("UPDATE job_plans SET status = 'active', plan_end_date = '2026-10-31'");
        $this->assertSame([], $this->svc()->forProperty(39));
        $this->db->exec("UPDATE job_plans SET plan_end_date = NULL, plan_start_date = '2026-11-02'");
        $this->assertSame([], $this->svc()->forProperty(39));
    }

    public function testTwoPlansForTheSameServiceStaySeparateAndLabelled(): void
    {
        $this->db->exec("INSERT INTO job_plans (id, plan_number, property_id, title, service_type, price_per_visit, plan_start_date, season_start, season_end)
                         VALUES (97, 'PLN-2026-0090', 39, 'FALL MOWING', '14 Day Lawn Cut Service', 55.00, '2026-05-21', '09-01', '11-30')");
        $r = $this->svc()->forProperty(39);
        $this->assertCount(2, $r);
        $this->assertSame([48.0, 55.0], array_column($r, 'unit_price'));
        $this->assertSame('FALL MOWING · PLN-2026-0090 · 09-01 to 11-30', $r[1]['label']);
    }

    public function testMonthlyAndSeasonalUseTheirOwnPrice(): void
    {
        $this->db->exec("INSERT INTO job_plans (id, plan_number, property_id, title, service_type, pricing_model, monthly_flat_price, plan_start_date)
                         VALUES (98, 'PLN-2026-0091', 39, 'Gardens', 'Garden Care', 'monthly_flat', 300.00, '2026-01-01')");
        $db = $this->svc()->forProperty(39);
        $m = array_values(array_filter($db, fn($x) => $x['name'] === 'Garden Care'))[0];
        $this->assertSame(300.0, $m['unit_price']);
        $this->assertSame('month', $m['unit_type']);
    }

    public function testZeroPriceRowsAreNotOffered(): void
    {
        $this->db->exec("UPDATE plan_line_items SET unit_price = 0");
        $this->db->exec("UPDATE job_plans SET price_per_visit = 0");
        $this->assertSame([], $this->svc()->forProperty(39));
    }

    public function testPayerFilterNeverLeaksAnotherPayersPlan(): void
    {
        $this->db->exec("UPDATE job_plans SET company_id = 7");
        $this->assertCount(1, $this->svc()->forProperty(39, 7));
        $this->assertSame([], $this->svc()->forProperty(39, 8));
        $this->assertSame([], $this->svc()->forProperty(39), 'individual invoice must not see a company plan');
        $this->db->exec("UPDATE job_plans SET company_id = NULL");
        $this->assertCount(1, $this->svc()->forProperty(39));
        $this->assertSame([], $this->svc()->forProperty(39, 7), 'company invoice must not see an individual plan');
    }

    public function testContractNumberShownOnlyForActiveContract(): void
    {
        $this->db->exec("INSERT INTO contracts VALUES (5, 'CTR-2026-0016', 'active')");
        $this->db->exec("UPDATE job_plans SET contract_id = 5");
        $r = $this->svc()->forProperty(39);
        $this->assertSame('CTR-2026-0016', $r[0]['contract_number']);
        $this->db->exec("UPDATE contracts SET status = 'cancelled'");
        $this->assertNull($this->svc()->forProperty(39)[0]['contract_number']);
    }
}
