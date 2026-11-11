<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/CostFactsFixture.php';

/**
 * Sam's "Material pickup" / "Disposal run" suggestions, priced from Otto's shared cost facts.
 */
class TripLineSuggesterTest extends TestCase
{
    public function test_mulch_needs_a_pickup_and_cleanup_needs_a_disposal_run(): void
    {
        $this->assertSame(['disposal_run' => 'Fall cleanup', 'material_pickup' => 'Black mulch install'],
            TripLineSuggester::needs(CostFactsFixture::quoteLines()));
        $this->assertSame(['material_pickup' => 'Garden soil top-up'],
            TripLineSuggester::needs([['service_type' => 'Garden soil top-up', 'description' => '3 yd']]));
        $this->assertSame(['disposal_run' => 'Hedge trim'],
            TripLineSuggester::needs([['service_type' => 'Hedge trim', 'description' => 'Green waste hauled away']]));
    }

    public function test_nothing_for_plain_mowing_and_nothing_twice(): void
    {
        $this->assertSame([], TripLineSuggester::needs([['service_type' => 'Weekly mowing', 'description' => 'Mow, trim, blow']]));
        $lines = array_merge(CostFactsFixture::quoteLines(), [['service_type' => 'Material pickup', 'description' => '']]);
        $this->assertSame(['disposal_run' => 'Fall cleanup'], TripLineSuggester::needs($lines));
        $lines[] = ['service_type' => 'Disposal run', 'description' => ''];
        $this->assertSame([], TripLineSuggester::needs($lines));
    }

    public function test_round_up_to_the_next_five_dollars(): void
    {
        $this->assertSame(60.0, TripLineSuggester::roundUp5(55.47));
        $this->assertSame(55.0, TripLineSuggester::roundUp5(55.0));
        $this->assertSame(25.0, TripLineSuggester::roundUp5(22.3));
    }

    public function test_priced_only_from_three_runs(): void
    {
        $fact = ['sample_n' => 3, 'median_cost' => 55.47, 'median_round_trip_min' => 32, 'median_km' => 8.0, 'median_receipt' => 29.0];
        $s = TripLineSuggester::suggestion('disposal_run', 'Fall cleanup', $fact);
        $this->assertTrue($s['ready']);
        $this->assertSame(60.0, $s['price']);
        $this->assertSame('Disposal run $60 + GST', $s['text']);
        $this->assertSame('Median of 3 one-man dump runs: 32 min, 8 km, dump fee $29 → $55.47', $s['basis']);

        $few = TripLineSuggester::suggestion('material_pickup', 'Black mulch install', ['sample_n' => 1, 'median_cost' => 22.3] + $fact);
        $this->assertFalse($few['ready']);
        $this->assertNull($few['price']);
        $this->assertSame('Material pickup — not enough runs yet (1/3)', $few['text']);

        $none = TripLineSuggester::suggestion('material_pickup', 'Black mulch install', null);
        $this->assertSame('Material pickup — not enough runs yet (0/3)', $none['text']);
    }

    public function test_for_quote_reads_the_facts_the_cron_wrote(): void
    {
        $db = CostFactsFixture::db();
        foreach (CostFactsFixture::moreDumpRuns() as $r) CostFactsFixture::insertRun($db, $r);
        (new CostFactsService($db))->refresh();
        $out = (new TripLineSuggester($db))->forQuote(CostFactsFixture::OAKRIDGE_QUOTE);
        $by = array_column($out, null, 'key');
        $this->assertSame(60.0, $by['disposal_run']['price']);
        $this->assertSame(1, $by['material_pickup']['sample_n']);
        $this->assertNull($by['material_pickup']['price']);
        $this->assertSame([], (new TripLineSuggester($db))->forQuote(999));
    }
}
