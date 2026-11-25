<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/CostFactsFixture.php';

/**
 * Otto's shared cost facts: medians of one-man runs, written once by the cron, read by every head.
 */
class CostFactsServiceTest extends TestCase
{
    private function rows(): array
    {
        return array_merge(CostFactsFixture::runs(), CostFactsFixture::moreDumpRuns());
    }

    public function test_dump_fact_uses_one_man_priced_runs_and_adds_the_median_dump_fee(): void
    {
        $f = CostFactsService::build($this->rows())['run:dump:any'];
        // Oakridge 26.47, Oct 1 34.66, Oct 3 25.46 (two-man and no-rate runs left out)
        $this->assertSame(3, $f['sample_n']);
        $this->assertSame(26.47, $f['median_trip_cost']);
        $this->assertSame(29.0, $f['median_receipt']);      // $27 and $31; the unfiled Oct 3 receipt is not a $0 fee
        $this->assertSame(2, $f['receipt_n']);
        $this->assertSame(55.47, $f['median_cost']);
        $this->assertSame(57.86, $f['avg_cost']);
        $this->assertSame(12.0, $f['median_onsite_min']);
        $this->assertSame(32.0, $f['median_round_trip_min']);
        $this->assertSame(8.0, $f['median_km']);
        $this->assertSame('2026-10-07', $f['last_run_date']);
        $this->assertNull($f['place_id']);
    }

    public function test_supplier_fact_charges_the_trip_only_because_materials_are_billed_on_their_own_line(): void
    {
        $f = CostFactsService::build($this->rows())['run:supplier:place:3'];
        $this->assertSame(1, $f['sample_n']);
        $this->assertSame(22.3, $f['median_trip_cost']);   // Lawn Boy: 19.15 labour + 3.15 truck
        $this->assertSame(141.75, $f['median_receipt']);
        $this->assertSame(22.3, $f['median_cost']);
        $this->assertSame('Supply run — Lawn Boy', $f['label']);
    }

    public function test_keys_per_place_and_any(): void
    {
        $this->assertSame(['run:dump:any', 'run:dump:place:1', 'run:supplier:any', 'run:supplier:place:3'],
            array_keys(CostFactsService::build($this->rows())));
        $this->assertSame([], CostFactsService::build([]));
    }

    public function test_median(): void
    {
        $this->assertSame(12.0, CostFactsService::median([30, 12, 9]));
        $this->assertSame(10.5, CostFactsService::median([9, 12, 30, 6]));
        $this->assertNull(CostFactsService::median([]));
    }

    public function test_refresh_upserts_idempotently_and_get_reads_one_row(): void
    {
        $db = CostFactsFixture::db();
        foreach (CostFactsFixture::moreDumpRuns() as $r) CostFactsFixture::insertRun($db, $r);
        $svc = new CostFactsService($db);
        $this->assertTrue($svc->ready());
        $this->assertSame(['facts' => 4, 'removed' => 0], $svc->refresh());
        $this->assertSame(['facts' => 4, 'removed' => 0], $svc->refresh());
        $this->assertSame(4, (int)$db->query("SELECT COUNT(*) FROM ops_cost_facts")->fetchColumn());

        $f = $svc->get('run:dump:any');
        $this->assertSame(3, $f['sample_n']);
        $this->assertSame(55.47, $f['median_cost']);
        $this->assertNull($svc->get('run:fuel:any'));
        $this->assertSame(['run:dump:any', 'run:dump:place:1'], array_column($svc->forKinds(['dump']), 'fact_key'));
        $this->assertSame([], $svc->forKinds(['fuel']));

        // Runs disappear (a stop was renamed) → their facts go
        $db->exec("DELETE FROM ops_trip_runs WHERE kind = 'supplier'");
        $this->assertSame(['facts' => 2, 'removed' => 2], $svc->refresh());
        $this->assertNull($svc->get('run:supplier:any'));
    }

    public function test_readers_degrade_before_the_migration(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $svc = new CostFactsService($db);
        $this->assertFalse($svc->ready());
        $this->assertNull($svc->get('run:dump:any'));
        $this->assertSame([], $svc->forKinds(['dump']));
    }
}
