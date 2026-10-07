<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * per_yard_area — mulch sold only in whole cubic yards (Tim, 2026-10-06):
 * $175/yd, 2-yd minimum, 3 in deep, so 1 yd covers 108 sq ft.
 *
 * JS/PHP PARITY: mwYardsFromArea() in public/crm/quotes/create.php and
 * public/crm/quote-workflow.php must give these same answers.
 */
class QuoteCalculatorYardsTest extends TestCase
{
    private function mulchRule(array $over = []): array
    {
        return $over + [
            'id' => 8, 'product_id' => 5, 'pricing_model' => 'per_yard_area', 'price_per_unit' => 175,
            'minimum_price' => 0, 'included_units' => 0, 'depth_inches' => 3, 'min_units' => 2,
            'default_frequency' => 'one_off', 'group_key' => 'garden_bed', 'group_label' => 'Garden beds', 'unit' => 'sqft',
        ];
    }

    private function product(): array
    {
        return ['id' => 5, 'name' => 'Black Composted Bark Mulch', 'base_price' => 175, 'description' => ''];
    }

    public static function workedExamples(): array
    {
        return [
            '0 sq ft → minimum'   => [0, 2, 350.0],
            '150 sq ft'           => [150, 2, 350.0],
            '216 sq ft exactly 2' => [216, 2, 350.0],
            '217 sq ft'           => [217, 3, 525.0],
            '300 sq ft'           => [300, 3, 525.0],
            '432 sq ft'           => [432, 4, 700.0],
            '1000 sq ft'          => [1000, 10, 1750.0],
        ];
    }

    /** @dataProvider workedExamples */
    public function test_worked_examples(float $sqft, int $yards, float $price): void
    {
        $this->assertSame($yards, QuoteCalculator::yardsFromArea($sqft, 3, 2));
        $line = calculateLineItemFromRule($this->mulchRule(), $sqft, $this->product());
        $this->assertSame($yards, $line['quantity']);
        $this->assertSame('yd', $line['unit_type']);
        $this->assertSame(175.0, $line['unit_price']);
        $this->assertSame($price, $line['line_total']);
    }

    public function test_one_yard_covers_108_sqft_at_3_inches(): void
    {
        $this->assertEqualsWithDelta(108.0, QuoteCalculator::sqftPerYard(3), 1e-9);
        $this->assertEqualsWithDelta(162.0, QuoteCalculator::sqftPerYard(2), 1e-9);
    }

    public function test_exact_multiples_never_round_up(): void
    {
        foreach ([1, 2, 3, 5, 7, 10, 25, 100] as $n) {
            $this->assertSame($n, QuoteCalculator::yardsFromArea($n * 108.0, 3, 0), "{$n} yd");
            $this->assertSame($n + 1, QuoteCalculator::yardsFromArea($n * 108.0 + 0.01, 3, 0), "{$n} yd + a hair");
        }
        // 2 in deep: 162 sq ft per yard; 1.5 in: 216.
        $this->assertSame(2, QuoteCalculator::yardsFromArea(324, 2, 0));
        $this->assertSame(2, QuoteCalculator::yardsFromArea(432, 1.5, 0));
    }

    public function test_bad_inputs_fall_back_safely(): void
    {
        $this->assertSame(2, QuoteCalculator::yardsFromArea(-50, 3, 2));     // negative area = none
        $this->assertSame(4, QuoteCalculator::yardsFromArea(432, 0, 2));     // no depth = 3 in
        $this->assertSame(0, QuoteCalculator::yardsFromArea(0, 3, -1));      // negative minimum = none
    }

    public function test_line_description_and_snapshot(): void
    {
        $line = calculateLineItemFromRule($this->mulchRule(), 432, $this->product(), ['Front beds', 'Side bed']);
        $this->assertSame('4 yd · 432 sq ft at 3 in', $line['description']);
        $this->assertSame(0, $line['minimum_applied']);
        $this->assertSame(432.0, $line['units_used']);
        $snap = json_decode($line['pricing_snapshot'], true);
        $this->assertSame('per_yard_area', $snap['pricing_model']);
        $this->assertSame(4, $snap['yards']);
        $this->assertSame(2, $snap['min_units']);
        $this->assertEquals(3, $snap['depth_inches']);
        $this->assertEquals(108, $snap['sqft_per_yard']);
        $this->assertSame('4 yd · 432 sq ft at 3 in', $snap['area_description']);

        $withDesc = calculateLineItemFromRule($this->mulchRule(), 1000, ['description' => 'Spread by hand'] + $this->product());
        $this->assertSame('Spread by hand — 10 yd · 1,000 sq ft at 3 in', $withDesc['description']);
    }

    public function test_minimum_flag_only_when_the_minimum_lifted_the_yards(): void
    {
        $this->assertSame(1, calculateLineItemFromRule($this->mulchRule(), 100, $this->product())['minimum_applied']); // 1 yd → 2
        $this->assertSame(1, calculateLineItemFromRule($this->mulchRule(), 0, $this->product())['minimum_applied']);
        $this->assertSame(0, calculateLineItemFromRule($this->mulchRule(), 150, $this->product())['minimum_applied']); // 2 yd anyway
        $this->assertSame(0, calculateLineItemFromRule($this->mulchRule(), 216, $this->product())['minimum_applied']);
    }

    public function test_missing_columns_default_to_3_in_and_2_yd(): void
    {
        // Before migration 1189 runs, the rule row has no depth_inches / min_units.
        $rule = $this->mulchRule();
        unset($rule['depth_inches'], $rule['min_units']);
        $line = calculateLineItemFromRule($rule, 100, $this->product());
        $this->assertSame(2, $line['quantity']);
        $this->assertSame(350.0, $line['line_total']);
        $this->assertSame(['depth_inches' => 3.0, 'min_units' => 2], QuoteCalculator::yardRuleParams(['min_units' => '']));
        $this->assertSame(['depth_inches' => 2.5, 'min_units' => 0], QuoteCalculator::yardRuleParams(['depth_inches' => '2.5', 'min_units' => '0']));
    }

    public function test_other_models_are_unchanged(): void
    {
        $rule = ['id' => 7, 'pricing_model' => 'per_sqft', 'price_per_unit' => 0.03, 'minimum_price' => 150,
                 'included_units' => 0, 'group_key' => 'lawn_area', 'unit' => 'sqft'];
        $line = calculateLineItemFromRule($rule, 10000, ['id' => 3, 'name' => 'Fall Clean-up', 'base_price' => 0]);
        $this->assertSame(1, $line['quantity']);
        $this->assertSame('each', $line['unit_type']);
        $this->assertSame(300.0, $line['unit_price']);
        $this->assertSame(300.0, $line['line_total']);
    }
}
