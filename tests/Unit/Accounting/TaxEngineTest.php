<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for TaxEngine calculation methods.
 *
 * Mocks PDO so getRates() returns known BC tax rates (GST 5%, PST 7%)
 * without touching the database.
 *
 * Covers:
 *  - calculateFromSubtotal(): GST only, PST only, both, neither
 *  - calculateFromGross(): back-calculation from tax-inclusive total
 *  - currentQuarter(): pure date logic
 *  - getQuarterRange(): via reflection
 */
class TaxEngineTest extends TestCase
{
    private TaxEngine $engine;

    protected function setUp(): void
    {
        $this->engine = $this->engineWithRates(0.05, 0.07);
    }

    /**
     * Build a TaxEngine whose getRates() returns the given GST/PST rates.
     */
    private function engineWithRates(float $gst, float $pst): TaxEngine
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn([
            ['code' => 'GST', 'rate' => (string)$gst],
            ['code' => 'PST', 'rate' => (string)$pst],
        ]);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->willReturn($mockStmt);

        return new TaxEngine($mockPdo);
    }

    // ── calculateFromSubtotal() ───────────────────────────────────────────────

    /** @test */
    public function calculateFromSubtotal_gst_only(): void
    {
        $result = $this->engine->calculateFromSubtotal(100.00, true, false);

        $this->assertEqualsWithDelta(100.00, $result['subtotal'],    0.001);
        $this->assertEqualsWithDelta(5.00,   $result['gst_amount'],  0.001);
        $this->assertEqualsWithDelta(0.00,   $result['pst_amount'],  0.001);
        $this->assertEqualsWithDelta(5.00,   $result['total_tax'],   0.001);
        $this->assertEqualsWithDelta(105.00, $result['grand_total'], 0.001);
    }

    /** @test */
    public function calculateFromSubtotal_pst_only(): void
    {
        $result = $this->engine->calculateFromSubtotal(100.00, false, true);

        $this->assertEqualsWithDelta(0.00,   $result['gst_amount'],  0.001);
        $this->assertEqualsWithDelta(7.00,   $result['pst_amount'],  0.001);
        $this->assertEqualsWithDelta(107.00, $result['grand_total'], 0.001);
    }

    /** @test */
    public function calculateFromSubtotal_gst_and_pst(): void
    {
        $result = $this->engine->calculateFromSubtotal(100.00, true, true);

        $this->assertEqualsWithDelta(5.00,   $result['gst_amount'],  0.001);
        $this->assertEqualsWithDelta(7.00,   $result['pst_amount'],  0.001);
        $this->assertEqualsWithDelta(12.00,  $result['total_tax'],   0.001);
        $this->assertEqualsWithDelta(112.00, $result['grand_total'], 0.001);
    }

    /** @test */
    public function calculateFromSubtotal_no_tax(): void
    {
        $result = $this->engine->calculateFromSubtotal(100.00, false, false);

        $this->assertEqualsWithDelta(0.00,   $result['gst_amount'],  0.001);
        $this->assertEqualsWithDelta(0.00,   $result['pst_amount'],  0.001);
        $this->assertEqualsWithDelta(100.00, $result['grand_total'], 0.001);
    }

    /** @test */
    public function calculateFromSubtotal_zero_subtotal(): void
    {
        $result = $this->engine->calculateFromSubtotal(0.00, true, true);

        $this->assertEqualsWithDelta(0.00, $result['gst_amount'],  0.001);
        $this->assertEqualsWithDelta(0.00, $result['grand_total'], 0.001);
    }

    /** @test */
    public function calculateFromSubtotal_result_has_rate_keys(): void
    {
        $result = $this->engine->calculateFromSubtotal(100.00, true, true);
        $this->assertArrayHasKey('gst_rate', $result);
        $this->assertArrayHasKey('pst_rate', $result);
        $this->assertEqualsWithDelta(0.05, $result['gst_rate'], 0.001);
        $this->assertEqualsWithDelta(0.07, $result['pst_rate'], 0.001);
    }

    /** @test */
    public function calculateFromSubtotal_rounds_to_two_decimals(): void
    {
        // $99.99 × 5% = $4.9995 → rounds to $5.00
        $result = $this->engine->calculateFromSubtotal(99.99, true, false);
        $this->assertSame(5.00, $result['gst_amount']);
    }

    // ── calculateFromGross() (back-calculation) ───────────────────────────────

    /** @test */
    public function calculateFromGross_gst_only(): void
    {
        // $105 tax-inclusive at 5% GST → subtotal = $100
        $result = $this->engine->calculateFromGross(105.00, true, false);

        $this->assertEqualsWithDelta(100.00, $result['subtotal'],    0.01);
        $this->assertEqualsWithDelta(5.00,   $result['gst_amount'],  0.01);
        $this->assertEqualsWithDelta(0.00,   $result['pst_amount'],  0.001);
        $this->assertEqualsWithDelta(105.00, $result['grand_total'], 0.001);
    }

    /** @test */
    public function calculateFromGross_gst_and_pst(): void
    {
        // $112 at 5% GST + 7% PST → subtotal = $100
        $result = $this->engine->calculateFromGross(112.00, true, true);

        $this->assertEqualsWithDelta(100.00, $result['subtotal'], 0.01);
        $this->assertEqualsWithDelta(112.00, $result['grand_total'], 0.001);
    }

    /** @test */
    public function calculateFromGross_no_tax_returns_gross_as_subtotal(): void
    {
        $result = $this->engine->calculateFromGross(100.00, false, false);
        $this->assertEqualsWithDelta(100.00, $result['subtotal'], 0.001);
        $this->assertEqualsWithDelta(0.00,   $result['gst_amount'], 0.001);
    }

    // ── currentQuarter() ─────────────────────────────────────────────────────

    /** @test */
    public function currentQuarter_returns_value_between_1_and_4(): void
    {
        $q = $this->engine->currentQuarter();
        $this->assertGreaterThanOrEqual(1, $q);
        $this->assertLessThanOrEqual(4, $q);
    }

    /** @test */
    public function currentQuarter_matches_current_month(): void
    {
        $month = (int)date('n');
        $expected = (int)ceil($month / 3);
        $this->assertSame($expected, $this->engine->currentQuarter());
    }

    // ── getQuarterRange() (private) ───────────────────────────────────────────

    private function getQuarterRange(int $year, int $quarter): array
    {
        $m = new \ReflectionMethod(TaxEngine::class, 'getQuarterRange');
        return $m->invoke($this->engine, $year, $quarter);
    }

    /** @test */
    public function getQuarterRange_q1(): void
    {
        [$from, $to] = $this->getQuarterRange(2026, 1);
        $this->assertSame('2026-01-01', $from);
        $this->assertSame('2026-03-31', $to);
    }

    /** @test */
    public function getQuarterRange_q2(): void
    {
        [$from, $to] = $this->getQuarterRange(2026, 2);
        $this->assertSame('2026-04-01', $from);
        $this->assertSame('2026-06-30', $to);
    }

    /** @test */
    public function getQuarterRange_q3(): void
    {
        [$from, $to] = $this->getQuarterRange(2026, 3);
        $this->assertSame('2026-07-01', $from);
        $this->assertSame('2026-09-30', $to);
    }

    /** @test */
    public function getQuarterRange_q4(): void
    {
        [$from, $to] = $this->getQuarterRange(2026, 4);
        $this->assertSame('2026-10-01', $from);
        $this->assertSame('2026-12-31', $to);
    }

    /** @test */
    public function getQuarterRange_full_year_when_quarter_zero(): void
    {
        [$from, $to] = $this->getQuarterRange(2026, 0);
        $this->assertSame('2026-01-01', $from);
        $this->assertSame('2026-12-31', $to);
    }
}
