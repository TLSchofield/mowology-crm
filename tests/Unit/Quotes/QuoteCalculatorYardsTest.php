<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/QuoteCalculator.php';

/**
 * Worked examples for per-yard (mulch, soil, gravel) maths. The JS twins mwYardsFromArea() in
 * quotes/create.php and quote-workflow.php must give the same answers. 1 cu yd = 324 sq ft x in.
 */
class QuoteCalculatorYardsTest extends TestCase
{
    public function test_a_yard_covers_108_sqft_at_three_inches(): void
    {
        $this->assertEqualsWithDelta(108.0, QuoteCalculator::sqftPerYard(3.0), 0.0001);
        $this->assertEqualsWithDelta(54.0, QuoteCalculator::sqftPerYard(6.0), 0.0001);
    }

    public function test_exact_multiples_do_not_round_up(): void
    {
        $this->assertSame(2, QuoteCalculator::yardsFromArea(216.0, 3.0, 0));   // not 3 from float noise
        $this->assertSame(4, QuoteCalculator::yardsFromArea(432.0, 3.0, 0));
    }

    public function test_partial_yards_round_up(): void
    {
        $this->assertSame(1, QuoteCalculator::yardsFromArea(1.0, 3.0, 0));
        $this->assertSame(3, QuoteCalculator::yardsFromArea(217.0, 3.0, 0));
    }

    public function test_minimum_yards_is_a_floor(): void
    {
        $this->assertSame(2, QuoteCalculator::yardsFromArea(50.0, 3.0, 2));
        $this->assertSame(4, QuoteCalculator::yardsFromArea(432.0, 3.0, 2));
        $this->assertSame(0, QuoteCalculator::yardsFromArea(0.0, 3.0, 0));
    }

    public function test_depth_scales_the_yards(): void
    {
        $this->assertSame(4, QuoteCalculator::yardsFromArea(216.0, 6.0, 0));
        $this->assertSame(1, QuoteCalculator::yardsFromArea(216.0, 1.5, 0));
    }

    public function test_bad_depth_falls_back_to_three_inches(): void
    {
        $this->assertSame(QuoteCalculator::yardsFromArea(432.0, 3.0, 0), QuoteCalculator::yardsFromArea(432.0, 0.0, 0));
        $this->assertSame(QuoteCalculator::yardsFromArea(432.0, 3.0, 0), QuoteCalculator::yardsFromArea(432.0, -2.0, 0));
    }

    public function test_rule_params_default_to_three_inches_and_two_yards(): void
    {
        $this->assertSame(['depth_inches' => 3.0, 'min_units' => 2], QuoteCalculator::yardRuleParams([]));
        $this->assertSame(['depth_inches' => 3.0, 'min_units' => 2], QuoteCalculator::yardRuleParams(['depth_inches' => null, 'min_units' => '']));
        $this->assertSame(['depth_inches' => 4.0, 'min_units' => 0], QuoteCalculator::yardRuleParams(['depth_inches' => '4', 'min_units' => 0]));
    }

    public function test_description_says_how_the_yards_were_reached(): void
    {
        $this->assertSame('4 yd · 432 sq ft at 3 in', QuoteCalculator::yardsDescription(4, 432.0, 3.0));
        $this->assertSame('2 yd · 1,000 sq ft at 1.5 in', QuoteCalculator::yardsDescription(2, 1000.0, 1.5));
    }
}
