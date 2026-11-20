<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tests the shared server calculator. */
final class ToolCalculatorTest extends TestCase
{
    /** Verifies every shared fixture produces a finite result. */
    public function testEveryFixtureProducesExpectedValidity(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/tools-calculator.json'), true);
        $this->assertCount(24, $cases);
        $calculator = new ToolCalculator();
        foreach ($cases as $case) {
            $output = $calculator->calculate($case['tool'], $case['inputs']);
            $this->assertSame($case['tool'], $output['tool']);
            $this->assertSame(1, $output['version']);
            array_walk_recursive($output['result'], function ($value): void {
                if (is_float($value)) {
                    $this->assertTrue(is_finite($value));
                }
            });
            // The expected answers were worked out independently (not by this class); calc.js is held to the same ones.
            foreach ($case['expected'] as $key => $value) {
                if ($value === null) {
                    $this->assertNull($output['result'][$key], $case['tool'] . ' ' . $key);
                } else {
                    $this->assertEqualsWithDelta($value, $output['result'][$key], 0.00001, $case['tool'] . ' ' . $key);
                }
            }
        }
    }

    /** Verifies crew size accepts whole values in range and rejects fractions. */
    public function testMowPriceCrewValidation(): void
    {
        $calculator = new ToolCalculator();
        $output = $calculator->calculate('mow-price', [
            'onsite' => 30,
            'crew' => 50,
            'drive' => 10,
            'cost_paid' => 30,
            'margin' => 20,
        ]);
        $this->assertSame(50.0, $output['inputs']['crew']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid crew');
        $calculator->calculate('mow-price', [
            'onsite' => 30,
            'crew' => 1.5,
            'drive' => 10,
            'cost_paid' => 30,
            'margin' => 20,
        ]);
    }

    /** Verifies known formulas and optional labour rate handling. */
    public function testKnownResultsAndOptionalRate(): void
    {
        $calculator = new ToolCalculator();
        $markup = $calculator->calculate('markup-margin', ['mode' => 'markup', 'percent' => 30, 'cost' => 400]);
        $this->assertEqualsWithDelta(0.230769, $markup['result']['margin'], 0.000001);
        $this->assertEqualsWithDelta(520, $markup['result']['price'], 0.001);
        $breakEven = $calculator->calculate('break-even', ['rate' => 50, 'cost_per_billed_hour' => 50]);
        $this->assertNull($breakEven['result']['hours']);
        $labour = $calculator->calculate('labour-cost', [
            'wage' => 24,
            'vacation' => 4,
            'stat' => 4.4,
            'cpp' => 5.95,
            'ei' => 2.282,
            'wcb' => 3.14,
            'billable' => 75,
            'truck' => 6,
        ]);
        $this->assertNull($labour['result']['rate']);
    }
}
