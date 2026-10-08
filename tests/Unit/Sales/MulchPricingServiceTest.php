<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam's mulch price per yard: material from receipts, haul from Otto's trips (or an estimate),
 * spreading labour, at the target margin. GST is never in it.
 */
final class FakeMulchPricing extends MulchPricingService
{
    public array $lines = [];
    public array $runs = [];
    public array $places = [];
    public array $facts = [];
    public array $props = [];
    public array $productRows = [];
    public array $set = [];
    public array $card = ['hourly_cost' => 40.0, 'target_margin' => 0.35, 'target_margin_pct' => 35.0, 'kmh' => 40.0, 'detour' => 1.4, 'depot' => null, 'flags' => []];
    public array $km = ['value' => 0.70, 'real' => false];

    public function __construct()
    {
        $this->db = new PDO('sqlite::memory:');
    }

    public function settings(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $d) {
            $v = $this->set[$k] ?? $d;
            $out[$k] = ['value' => (float)$v, 'present' => true, 'seeded' => abs($v - $d) < 0.0001];
        }
        return $out;
    }
    public function rateCard(): array { return $this->card; }
    public function perKm(): array { return $this->km; }
    public function property(int $id): ?array { return $this->props[$id] ?? null; }
    public function propertiesInPostcode(string $prefix): array
    {
        return array_values(array_filter($this->props, fn($p) => strpos(str_replace(' ', '', strtoupper((string)$p['postal_code'])), $prefix) === 0));
    }
    public function propertiesByAddress(string $addr): array
    {
        return array_values(array_filter($this->props, fn($p) => stripos((string)$p['address'], $addr) === 0));
    }
    public function products(): array { return $this->productRows; }
    public function receiptLines(string $family, array $productIds): array
    {
        return array_values(array_filter($this->lines, fn($l) => in_array((int)($l['product_id'] ?? 0), $productIds, true) || self::family((string)$l['name']) === $family));
    }
    public function supplierPlaces(): array { return $this->places; }
    public function runsFrom(int $placeId, array $propertyIds): array
    {
        return array_values(array_filter($this->runs, fn($r) => (int)$r['place_id'] === $placeId
            && (in_array((int)$r['from_property_id'], $propertyIds, true) || in_array((int)$r['return_property_id'], $propertyIds, true))));
    }
    public function fact(string $key): ?array { return $this->facts[$key] ?? null; }
    public function quotedBefore(string $family): array { return []; }
}

class MulchPricingServiceTest extends TestCase
{
    private const OAKRIDGE = 73;
    private const LARCH = 441;
    private const LAWNBOY = 3;

    private static function line(int $exp, string $date, string $name, float $qty, ?float $unit, float $total, string $vendor = 'LAWNBOY ENTERPRISES', array $extra = []): array
    {
        return $extra + ['id' => $exp * 10, 'expense_id' => $exp, 'product_id' => 11, 'name' => $name, 'quantity' => $qty, 'unit_price' => $unit,
                         'line_total' => $total, 'expense_date' => $date, 'vendor' => $vendor, 'tax_amount' => 0, 'receipt_total' => 0, 'lines_sum' => 0];
    }

    private function fake(): FakeMulchPricing
    {
        $f = new FakeMulchPricing();
        $f->productRows = [
            ['id' => 11, 'name' => 'Black Composted Bark Mulch', 'sku' => 'CBM', 'base_cost' => 40.00, 'base_price' => 0],
            ['id' => 19, 'name' => 'OVER-SEED', 'sku' => '', 'base_cost' => 0, 'base_price' => 0],
            ['id' => 30, 'name' => 'Garden Soil (per yard)', 'sku' => '', 'base_cost' => 35.00, 'base_price' => 0],
        ];
        $f->props = [
            self::OAKRIDGE => ['id' => self::OAKRIDGE, 'property_name' => 'Oakridge Gardens', 'address' => '6015 Tisdall St', 'postal_code' => 'V5Z 3N1',
                               'latitude' => 49.2290, 'longitude' => -123.1260, 'city' => 'Vancouver'],
            self::LARCH => ['id' => self::LARCH, 'property_name' => '', 'address' => '2448 Larch St', 'postal_code' => 'V6K 3P1',
                            'latitude' => 49.2640, 'longitude' => -123.1600, 'city' => 'Vancouver'],
        ];
        $f->places = [
            ['id' => self::LAWNBOY, 'name' => 'Lawn Boy', 'kind' => 'supplier', 'lat' => 49.2090, 'lng' => -123.1160, 'address' => '8655 Cambie St', 'vendor_match' => null],
            ['id' => 4, 'name' => 'Southlands Nursery', 'kind' => 'supplier', 'lat' => 49.2240, 'lng' => -123.1900, 'address' => null, 'vendor_match' => 'southlands'],
        ];
        $f->lines = [
            self::line(413, '2026-10-07', 'Black Composted Bark Mulch', 2, 40.00, 80.00),
            self::line(412, '2026-10-07', 'CBM Black Mulch 2 yd', 1, 90.00, 90.00),         // qty from the name → $45/yd
            self::line(380, '2026-09-20', 'Black Composted Bark Mulch', 3, 38.00, 114.00),
            self::line(300, '2026-08-02', 'Bark mulch bag 2 cu ft', 4, 8.00, 32.00, 'HOME DEPOT'),   // bagged → left out
        ];
        return $f;
    }

    // ── Pure ────────────────────────────────────────────────────────────────

    public function test_family_reads_lines_products_and_questions(): void
    {
        $this->assertSame('mulch', MulchPricingService::family('Black Composted Bark Mulch'));
        $this->assertSame('mulch', MulchPricingService::family('CBM'));
        $this->assertSame('soil', MulchPricingService::family('Garden soil top-up'));
        $this->assertSame('compost', MulchPricingService::family('Mushroom compost'));
        $this->assertNull(MulchPricingService::family('Weekly mowing'));
        $this->assertSame('mulch', MulchPricingService::family('Mulching the front beds'));
    }

    public function test_material_is_the_median_of_recent_receipt_lines(): void
    {
        $m = MulchPricingService::material($this->fake()->lines, 40.0, 6);
        $this->assertTrue($m['real']);
        $this->assertSame('receipts', $m['source']);
        $this->assertSame(3, $m['n']);
        $this->assertSame(40.0, $m['per_yard']);                 // median of 40, 45, 38
        $this->assertSame([40.0, 45.0, 38.0], array_column($m['evidence'], 'per_yard'));
        $this->assertSame(2.0, $m['evidence'][1]['qty']);         // "2 yd" in the name
        $this->assertCount(1, $m['excluded']);
        $this->assertSame('LAWNBOY ENTERPRISES', $m['usual_vendor']);
    }

    public function test_material_takes_only_the_newest_n(): void
    {
        $m = MulchPricingService::material($this->fake()->lines, null, 2);
        $this->assertSame(2, $m['n']);
        $this->assertSame(42.5, $m['per_yard']);                 // median of 40 and 45
    }

    public function test_material_falls_back_to_the_product_cost_and_says_so(): void
    {
        $m = MulchPricingService::material([], 40.0);
        $this->assertFalse($m['real']);
        $this->assertSame('product cost', $m['source']);
        $this->assertSame(40.0, $m['per_yard']);
        $this->assertNull(MulchPricingService::material([], null)['per_yard']);
    }

    public function test_gst_inside_a_line_is_taken_out(): void
    {
        // One line that is the whole receipt total, $84 with $4 GST, 2 yd → $40/yd before tax.
        $l = self::line(500, '2026-10-01', 'Black Mulch', 2, null, 84.00, 'LAWNBOY', ['tax_amount' => 4.00, 'receipt_total' => 84.00, 'lines_sum' => 84.00]);
        $u = MulchPricingService::unitCost($l);
        $this->assertTrue($u['gst_removed']);
        $this->assertSame(40.0, $u['unit']);
        // Lines that add up to the pre-tax amount are left alone.
        $l2 = self::line(501, '2026-10-01', 'Black Mulch', 2, 40.00, 80.00, 'LAWNBOY', ['tax_amount' => 4.00, 'receipt_total' => 84.00, 'lines_sum' => 80.00]);
        $this->assertFalse(MulchPricingService::unitCost($l2)['gst_removed']);
        $this->assertSame(40.0, MulchPricingService::unitCost($l2)['unit']);
    }

    public function test_haul_from_real_trips_uses_the_medians(): void
    {
        $k = ['hourly' => 40.0, 'crew' => 1, 'per_km' => 0.70, 'kmh' => 40, 'detour' => 1.4, 'load_min' => 15, 'yards_per_load' => 2];
        $runs = [
            ['id' => 2, 'run_date' => '2026-10-07', 'drive_min' => 18, 'onsite_min' => 9, 'km' => 4.5],
            ['id' => 9, 'run_date' => '2026-09-20', 'drive_min' => 22, 'onsite_min' => 11, 'km' => 5.5],
            ['id' => 7, 'run_date' => '2026-09-01', 'drive_min' => 20, 'onsite_min' => 10, 'km' => 5.0],
        ];
        $h = MulchPricingService::haul($runs, 2.5, null, null, $k);
        $this->assertSame('trips', $h['tier']);
        $this->assertTrue($h['real']);
        $this->assertSame(30.0, $h['minutes']);                  // median of 27, 33, 30
        $this->assertSame(5.0, $h['km']);
        $this->assertSame(20.0, $h['labour']);                   // 30 min × $40/h × 1
        $this->assertSame(3.5, $h['truck']);                     // 5 km × $0.70
        $this->assertSame(23.5, $h['per_load']);
        $this->assertSame(11.75, $h['per_yard']);                // ÷ 2 yd per load
    }

    public function test_haul_without_trips_is_a_flagged_straight_line_estimate(): void
    {
        $k = ['hourly' => 40.0, 'crew' => 1, 'per_km' => 0.70, 'kmh' => 40, 'detour' => 1.4, 'load_min' => 15, 'yards_per_load' => 2];
        $h = MulchPricingService::haul([], 5.0, null, null, $k);
        $this->assertSame('estimate', $h['tier']);
        $this->assertFalse($h['real']);
        $this->assertSame(14.0, $h['km']);                       // 5 × 2 × 1.4
        $this->assertSame(36.0, $h['minutes']);                  // 14 km at 40 km/h = 21 min + 15 loading
        $this->assertStringStartsWith('ESTIMATE', $h['note']);
        // Otto's measured time on site at that supplier replaces the loading default.
        $h2 = MulchPricingService::haul([], 5.0, ['sample_n' => 4, 'median_onsite_min' => 9.0], null, $k);
        $this->assertSame(30.0, $h2['minutes']);
        $this->assertTrue($h2['load_real']);
        // No pins at all: Otto's typical supply run, real but not this address.
        $h3 = MulchPricingService::haul([], null, null, ['sample_n' => 5, 'median_round_trip_min' => 27, 'median_km' => 4.5], $k);
        $this->assertSame('typical', $h3['tier']);
        $this->assertSame(27.0, $h3['minutes']);
        // Nothing: no haul.
        $this->assertNull(MulchPricingService::haul([], null, null, null, $k)['per_yard']);
    }

    public function test_margin_math_and_minimum(): void
    {
        $p = MulchPricingService::price(40.0, 11.75, 30.0, 0.0, 23.5, 0.0, 0.35);
        $this->assertSame(81.75, $p['cost_per_yard']);
        $this->assertSame(126.0, $p['sell_per_yard']);           // 81.75 / 0.65 = 125.77 → up to the dollar
        $this->assertSame(35.1, $p['margin_pct']);
        $this->assertSame(145.0, $p['minimum_charge']);          // (40 + 23.5 + 30) / 0.65 = 143.85 → $145
        $this->assertSame(200.0, MulchPricingService::price(40.0, 11.75, 30.0, 0.0, 23.5, 0.0, 0.35, 200.0)['minimum_charge']);
        $this->expectException(InvalidArgumentException::class);
        MulchPricingService::price(40.0, 0, 0, 0, 0, 0, 0.95);
    }

    public function test_postcode_and_intent(): void
    {
        $this->assertSame(['full' => 'V6K3P1', 'fsa' => 'V6K'], MulchPricingService::postcode('v6k 3p1'));
        $this->assertSame(['full' => null, 'fsa' => 'V6K'], MulchPricingService::postcode('mulch in V6K'));
        $i = MulchPricingService::intent('What should I charge for mulch at 2448 Larch St?');
        $this->assertSame('mulch', $i['family']);
        $this->assertSame('2448 Larch St', $i['address']);
        $this->assertSame('V6K', MulchPricingService::intent('mulch price for V6K')['postcode']['fsa']);
        $this->assertNull(MulchPricingService::intent('how much mulch do we have'));      // a stock question, not pricing
        $this->assertNull(MulchPricingService::intent('what should I charge for mowing'));
    }

    // ── The breakdown, with fakes ───────────────────────────────────────────

    public function test_breakdown_from_real_trips_at_the_property(): void
    {
        $f = $this->fake();
        $f->runs = [
            ['id' => 2, 'place_id' => self::LAWNBOY, 'from_property_id' => self::OAKRIDGE, 'return_property_id' => self::OAKRIDGE, 'run_date' => '2026-10-07', 'drive_min' => 18, 'onsite_min' => 9, 'km' => 4.5],
            ['id' => 8, 'place_id' => self::LAWNBOY, 'from_property_id' => 99, 'return_property_id' => 99, 'run_date' => '2026-10-02', 'drive_min' => 60, 'onsite_min' => 9, 'km' => 30],
        ];
        $b = $f->breakdown(['property_id' => self::OAKRIDGE, 'text' => 'Black mulch install']);
        $this->assertSame('Lawn Boy', $b['supplier']['name']);                 // "LAWNBOY ENTERPRISES" receipts ↔ the "Lawn Boy" place
        $this->assertSame('trips', $b['haul']['tier']);
        $this->assertSame(1, $b['haul']['n']);                                // the run from another property is not used
        $this->assertSame(27.0, $b['haul']['minutes']);
        // material 40 + haul (27/60×40 = 18 + 4.5×0.7 = 3.15 → 21.15 / 2 = 10.58) + labour 45/60×40 = 30
        $this->assertSame(10.58, $b['haul']['per_yard']);
        $this->assertSame(80.58, $b['cost_per_yard']);
        $this->assertSame(124.0, $b['sell_per_yard']);                        // 80.58 / 0.65 = 123.97
        $this->assertSame('V5Z', $b['location']['label']);
        $this->assertStringContainsString("Sam's bark mulch price for V5Z: \$124/yd + GST", $b['headline']);
        $this->assertStringContainsString('material $40 + haul $11 + labour $30', $b['headline']);
        $labels = array_column(array_filter($b['inputs'], fn($i) => !$i['real']), 'label');
        $this->assertContains('Spreading minutes per yard', $labels);         // defaults are flagged
        $this->assertContains('Truck $/km', $labels);
        $this->assertNotContains('Material / yd', $labels);
        $this->assertNotContains('Haul round trip (min)', $labels);
    }

    public function test_breakdown_without_trips_estimates_and_flags_it(): void
    {
        $f = $this->fake();
        $b = $f->breakdown(['property_id' => self::LARCH, 'family' => 'mulch']);
        $this->assertSame('estimate', $b['haul']['tier']);
        $this->assertContains('Haul round trip (min)', MulchPricingService::hint($b)['estimated']);
        $this->assertSame('V6K', MulchPricingService::hint($b)['where']);
        $this->assertTrue(MulchPricingService::hint($b)['includes_pickup']);
    }

    public function test_gst_is_never_in_the_per_yard_price(): void
    {
        $f = $this->fake();
        // The same slips, but captured as tax-in lines: the price must not move.
        $f->lines = array_map(fn($l) => $l['vendor'] === 'HOME DEPOT' ? $l : array_merge($l, [
            'line_total' => round($l['line_total'] * 1.05, 2), 'unit_price' => null,
            'tax_amount' => round($l['line_total'] * 0.05, 2), 'receipt_total' => round($l['line_total'] * 1.05, 2), 'lines_sum' => round($l['line_total'] * 1.05, 2),
        ]), $this->fake()->lines);
        $taxIn = $f->breakdown(['property_id' => self::LARCH]);
        $clean = $this->fake()->breakdown(['property_id' => self::LARCH]);
        $this->assertSame($clean['material']['per_yard'], $taxIn['material']['per_yard']);
        $this->assertSame($clean['sell_per_yard'], $taxIn['sell_per_yard']);
        $this->assertSame(3, $taxIn['material']['gst_removed']);
        $this->assertStringContainsString('+ GST', $clean['headline']);
        // sell = cost / (1 − margin) exactly, with no 5% anywhere
        $this->assertSame((float)ceil(round($clean['cost_per_yard'] / 0.65, 2)), $clean['sell_per_yard']);
    }

    public function test_postcode_uses_the_centre_of_our_properties_there(): void
    {
        $b = $this->fake()->breakdown(['postcode' => 'V6K']);
        $this->assertSame('postcode', $b['location']['kind']);
        $this->assertSame([self::LARCH], $b['location']['property_ids']);
        $this->assertNotNull($b['sell_per_yard']);
        $none = $this->fake()->breakdown(['postcode' => 'V7X']);
        $this->assertSame('none', $none['haul']['tier']);               // no pin, no supply-run fact
        $this->assertStringContainsString('no properties of ours in V7X', $none['location']['basis']);
        $this->assertContains('No haul: no supplier pin / address pin and no supply-run fact yet', $none['flags']);
    }

    public function test_ask_answers_with_sources(): void
    {
        $a = $this->fake()->answer('what should I charge for mulch at 2448 Larch');
        $this->assertSame('sam', $a['head']);
        $this->assertStringContainsString('for V6K', $a['answer']);
        $this->assertStringContainsString('Minimum $', $a['answer']);
        $this->assertStringContainsString('ESTIMATE', $a['answer']);
        $this->assertNull($this->fake()->answer('how many visits today'));
    }

    public function test_ask_charlie_routes_a_mulch_price_question_to_sam(): void
    {
        require_once __DIR__ . '/../../../app/Modules/ChiefOfStaff/Services/CharlieFactAnswerer.php';
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $a = (new CharlieFactAnswerer($db, '2026-10-07'))->answer('What should I charge for mulch at V6K?');
        $this->assertSame('sam', $a['head']);
        $this->assertStringContainsString("Sam can't price bark mulch for V6K yet", $a['answer']);   // empty database: says so, no guess
    }
}
