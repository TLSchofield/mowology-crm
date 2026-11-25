<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's pricing of overhead runs and Otto's baseline.
 */
class TripCostServiceTest extends TestCase
{
    public function test_cost_is_driver_time_with_burden_plus_km_plus_receipts(): void
    {
        // 24 min driving + 12 on site = 0.6 h × $28 × 1.15 = 19.32; 9.5 km × 0.70 = 6.65; dump fee 21.50
        $c = TripCostService::cost(24, 12, 9.5, 28.0, 15, 1, 0.70, 21.50);
        $this->assertSame(19.32, $c['labour']);
        $this->assertSame(6.65, $c['truck']);
        $this->assertSame(47.47, $c['total']);
        $this->assertFalse($c['rate_missing']);
    }

    public function test_two_people_double_the_labour_and_a_missing_rate_leaves_it_out(): void
    {
        $this->assertSame(38.64, TripCostService::cost(24, 12, 0, 28.0, 15, 2, 0.70, 0)['labour']);
        $c = TripCostService::cost(24, 12, 10, null, 15, 1, 0.70, 0);
        $this->assertNull($c['labour']);
        $this->assertTrue($c['rate_missing']);
        $this->assertSame(7.0, $c['total']);
    }

    public function test_median_odd_even_and_empty(): void
    {
        $this->assertSame(12.0, TripCostService::median([30, 12, 9]));
        $this->assertSame(10.5, TripCostService::median([9, 12, 30, 6]));
        $this->assertNull(TripCostService::median([]));
    }

    public function test_baseline_groups_by_place_and_prices_only_rows_with_a_rate(): void
    {
        $rows = [
            ['place_id' => 1, 'name' => 'Vancouver Transfer Station', 'kind' => 'dump', 'drive_min' => 24, 'onsite_min' => 12, 'km' => 9.5, 'total' => 47.47, 'rate_missing' => 0],
            ['place_id' => 1, 'name' => 'Vancouver Transfer Station', 'kind' => 'dump', 'drive_min' => 20, 'onsite_min' => 18, 'km' => 8.5, 'total' => 40.00, 'rate_missing' => 0],
            ['place_id' => 1, 'name' => 'Vancouver Transfer Station', 'kind' => 'dump', 'drive_min' => 22, 'onsite_min' => 9, 'km' => 9.0, 'total' => 6.30, 'rate_missing' => 1],
            ['place_id' => 3, 'name' => 'Lawnboy', 'kind' => 'supplier', 'drive_min' => 28, 'onsite_min' => 20, 'km' => 11, 'total' => null, 'rate_missing' => 1],
        ];
        $b = TripCostService::aggregate($rows);
        $this->assertSame(['dump', 'supplier'], array_column($b, 'kind'));
        $dump = $b[0];
        $this->assertSame(3, $dump['runs']);
        $this->assertSame(12.0, $dump['onsite_median']);
        $this->assertSame(13.0, $dump['onsite_avg']);
        $this->assertSame(35.0, $dump['round_trip_avg']);
        $this->assertSame(9.0, $dump['km_avg']);
        $this->assertSame(43.74, $dump['cost_avg']);
        $this->assertSame(2, $dump['priced']);
        $this->assertNull($b[1]['cost_avg']);

        $this->assertSame('Dump run (Vancouver Transfer Station), one man: 3 runs, avg 13 min there, 35 min round trip, 9 km, $44',
            TripCostService::baselineLine($dump));
        $this->assertStringEndsWith('cost needs a pay rate', TripCostService::baselineLine($b[1]));
    }

    public function test_receipt_vendor_matching(): void
    {
        $words = TripCostService::vendorWords(['name' => 'Vancouver Transfer Station', 'vendor_match' => 'transfer station|City of Vancouver']);
        $this->assertSame(['transfer station', 'city of vancouver'], $words);
        $this->assertTrue(TripCostService::vendorMatches('CITY OF VANCOUVER - LANDFILL', $words));
        $this->assertFalse(TripCostService::vendorMatches('Shell Canada', $words));
        $this->assertSame(['lawnboy'], TripCostService::vendorWords(['name' => 'Lawnboy', 'vendor_match' => null]));
    }

    public function test_penny_line_and_minutes(): void
    {
        $this->assertSame('Dump run 7 Oct — no dump receipt yet', TripCostService::missingLine(['kind' => 'dump', 'run_date' => '2026-10-07', 'name' => 'Vancouver Transfer Station']));
        $this->assertSame('Supply run 7 Oct — no receipt from Lawnboy yet', TripCostService::missingLine(['kind' => 'supplier', 'run_date' => '2026-10-07', 'name' => 'Lawnboy']));
        $this->assertSame('35 min', TripCostService::mins(35.2));
        $this->assertSame('1 h 05', TripCostService::mins(65));
    }
}
