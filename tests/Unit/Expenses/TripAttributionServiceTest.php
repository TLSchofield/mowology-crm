<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/CostFactsFixture.php';

/**
 * Penny tags Otto's runs to the job that caused them — and never a whole mixed receipt.
 * Fixture: 2026-10-07 Oakridge Gardens (dump fee $27; Lawn Boy mulch for the job + grass seed for the shop).
 */
class TripAttributionServiceTest extends TestCase
{
    private function job(): array
    {
        return ['plan_id' => CostFactsFixture::OAKRIDGE_PLAN, 'visit_id' => CostFactsFixture::OAKRIDGE_VISIT, 'quote_lines' => CostFactsFixture::quoteLines()];
    }

    private function tripRun(int $i): array
    {
        return CostFactsFixture::runs()[$i] + ['property_id' => CostFactsFixture::OAKRIDGE_PROPERTY];
    }

    private function lawnBoy(?int $jobId = null, ?array $lines = null): array
    {
        return ['id' => CostFactsFixture::LAWNBOY_RECEIPT, 'total' => 141.75, 'job_id' => $jobId, 'lines' => $lines ?? CostFactsFixture::lawnBoyLines()];
    }

    public function test_mulch_on_a_mulch_quote_is_the_job_and_grass_seed_is_shop_stock(): void
    {
        [$mulch, $seed] = CostFactsFixture::lawnBoyLines();
        $this->assertTrue(TripAttributionService::isJobLine($mulch, CostFactsFixture::quoteLines()));
        $this->assertFalse(TripAttributionService::isJobLine($seed, CostFactsFixture::quoteLines()));
        // Same product id counts even without a material word
        $this->assertTrue(TripAttributionService::isJobLine(['product_id' => 21, 'name' => 'SKU 4410'], CostFactsFixture::quoteLines()));
        // A Material pickup line on the quote is not a material
        $this->assertFalse(TripAttributionService::isJobLine(['name' => 'Material pickup'], [['service_type' => 'Material pickup']]));
    }

    public function test_dump_fee_and_trip_go_to_the_job(): void
    {
        $res = TripAttributionService::attributeRun($this->tripRun(0), $this->job(), [['id' => 501, 'total' => 27.00, 'job_id' => null, 'lines' => []]]);
        $this->assertSame([501 => 7], $res['tag']);
        $this->assertTrue($res['trip_to_job']);
        $this->assertSame(['receipt', 'trip'], array_column($res['rows'], 'source'));
        $this->assertSame([27.0, 26.47], array_column($res['rows'], 'amount'));
        $this->assertSame([7, 7], array_column($res['rows'], 'job_plan_id'));
        $this->assertSame([70, 70], array_column($res['rows'], 'visit_id'));
    }

    public function test_a_mixed_supplier_receipt_is_split_by_line_and_never_tagged_whole(): void
    {
        $res = TripAttributionService::attributeRun($this->tripRun(1), $this->job(), [$this->lawnBoy()]);
        $this->assertSame([], $res['tag']);                // mixed → expenses.job_id stays NULL
        $this->assertTrue($res['trip_to_job']);           // one job line bought → the trip is Oakridge's
        $this->assertTrue($res['stock']);
        $this->assertFalse($res['assumed']);
        $lines = array_values(array_filter($res['rows'], fn($r) => $r['source'] === 'receipt_line'));
        $this->assertSame([7, null], array_column($lines, 'job_plan_id'));
        $this->assertSame([0, 1], array_column($lines, 'is_stock'));
        $this->assertSame([90.0, 45.0], array_column($lines, 'amount'));
        $this->assertSame([9001, 9002], array_column($lines, 'expense_line_id'));
        $trip = array_values(array_filter($res['rows'], fn($r) => $r['source'] === 'trip'))[0];
        $this->assertSame(7, $trip['job_plan_id']);
        $this->assertSame(22.3, $trip['amount']);
    }

    public function test_a_single_purpose_supplier_receipt_is_tagged_whole_but_never_overwritten(): void
    {
        $mulchOnly = [CostFactsFixture::lawnBoyLines()[0]];
        $res = TripAttributionService::attributeRun($this->tripRun(1), $this->job(), [$this->lawnBoy(null, $mulchOnly)]);
        $this->assertSame([502 => 7], $res['tag']);
        $this->assertFalse($res['stock']);
        $this->assertContains('receipt', array_column($res['rows'], 'source'));

        $already = TripAttributionService::attributeRun($this->tripRun(1), $this->job(), [$this->lawnBoy(99, $mulchOnly)]);
        $this->assertSame([], $already['tag']);
    }

    public function test_an_all_stock_run_is_job_less(): void
    {
        $seedOnly = [CostFactsFixture::lawnBoyLines()[1]];
        $res = TripAttributionService::attributeRun($this->tripRun(1), $this->job(), [$this->lawnBoy(null, $seedOnly)]);
        $this->assertFalse($res['trip_to_job']);
        $this->assertSame([], $res['tag']);
        $this->assertSame([null, null], array_column($res['rows'], 'job_plan_id'));
        $this->assertSame([1, 1], array_column($res['rows'], 'is_stock'));
    }

    public function test_a_receipt_without_line_items_is_left_alone_and_the_trip_follows_the_quote(): void
    {
        $res = TripAttributionService::attributeRun($this->tripRun(1), $this->job(), [$this->lawnBoy(null, [])]);
        $this->assertSame([], $res['tag']);
        $this->assertTrue($res['trip_to_job']);
        $this->assertTrue($res['assumed']);
        $this->assertNull($res['rows'][0]['job_plan_id']);

        $noMaterials = ['quote_lines' => [['service_type' => 'Weekly mowing']]] + $this->job();
        $this->assertFalse(TripAttributionService::attributeRun($this->tripRun(1), $noMaterials, [$this->lawnBoy(null, [])])['trip_to_job']);
    }

    public function test_no_job_that_day_means_nothing_is_tagged(): void
    {
        $res = TripAttributionService::attributeRun($this->tripRun(0), null, [['id' => 501, 'total' => 27.00, 'job_id' => null, 'lines' => []]]);
        $this->assertSame([], $res['tag']);
        $this->assertSame([null, null], array_column($res['rows'], 'job_plan_id'));
    }

    public function test_allowance_and_the_charlie_line(): void
    {
        $this->assertSame(0.0, TripAttributionService::allowance(CostFactsFixture::quoteLines()));
        $this->assertSame(85.0, TripAttributionService::allowance([
            ['service_type' => 'Material pickup', 'line_total' => 25], ['service_type' => 'Disposal run', 'quantity' => 1, 'unit_price' => 60],
            ['service_type' => 'Fall cleanup', 'line_total' => 480],
        ]));
        $this->assertSame('Oakridge Gardens needed 2 runs today ($76), not in the quote — also picked up shop stock.',
            TripAttributionService::overLine('Oakridge Gardens', 2, 75.77, 0, 'today', true));
        $this->assertSame('Oakridge Gardens needed 1 run yesterday ($56), the quote allowed $25.',
            TripAttributionService::overLine('Oakridge Gardens', 1, 55.5, 25, 'yesterday', false));
    }

    public function test_the_oakridge_morning_end_to_end(): void
    {
        $db = CostFactsFixture::db();
        $svc = new TripAttributionService($db, CostFactsFixture::DATE);
        $this->assertTrue($svc->ready());

        $o = TripAttributionService::overheadByJob($svc->build(CostFactsFixture::DATE)['rows'])[7];
        $this->assertSame(75.77, $o['overhead']);   // 26.47 + 22.30 trip + $27 dump fee (mulch is billed, not overhead)
        $this->assertSame(2, $o['runs']);
        $this->assertTrue($o['stock']);

        $this->assertSame(['rows' => 5, 'tagged' => 1], $svc->attribute(CostFactsFixture::DATE));
        $this->assertSame(7, (int)$db->query("SELECT job_id FROM expenses WHERE id = 501")->fetchColumn());
        $this->assertNull($db->query("SELECT job_id FROM expenses WHERE id = 502")->fetchColumn());
        // Re-running rebuilds the date, and never re-tags a receipt that already has a job
        $db->exec("UPDATE expenses SET job_id = 99 WHERE id = 501");
        $this->assertSame(['rows' => 5, 'tagged' => 0], $svc->attribute(CostFactsFixture::DATE));
        $this->assertSame(99, (int)$db->query("SELECT job_id FROM expenses WHERE id = 501")->fetchColumn());
        $this->assertSame(5, (int)$db->query("SELECT COUNT(*) FROM ops_trip_job_costs")->fetchColumn());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM ops_trip_job_costs WHERE is_stock = 1 AND job_plan_id IS NULL")->fetchColumn());

        $this->assertSame([7 => ['trip_overhead' => 48.77, 'trip_runs' => 2]], $svc->overheadForJobs([7, 8]));

        $items = $svc->briefItems();
        $this->assertCount(1, $items);
        $this->assertSame('penny:trip-overhead:2026-10-07:7', $items[0]['key']);
        $this->assertSame('trip_overhead', $items[0]['kind']);
        $this->assertSame(76, $items[0]['value']);
        $this->assertSame('Oakridge Gardens needed 2 runs today ($76), not in the quote — also picked up shop stock.', $items[0]['text']);
        $this->assertSame('/crm/jobs/view.php?id=7', $items[0]['url']);

        // Once the quote carries the trips, Charlie stays quiet
        $db->exec("INSERT INTO quote_line_items (quote_id, service_type, description, quantity, unit_price, line_total) VALUES (12, 'Disposal run', '', 1, 60, 60), (12, 'Material pickup', '', 1, 25, 25)");
        $this->assertSame([], $svc->briefItems());
    }

    public function test_before_the_migration_everything_is_empty(): void
    {
        $svc = new TripAttributionService(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
        $this->assertFalse($svc->ready());
        $this->assertSame([], $svc->briefItems('2026-10-07'));
        $this->assertSame([], $svc->overheadForJobs([7]));
    }
}
