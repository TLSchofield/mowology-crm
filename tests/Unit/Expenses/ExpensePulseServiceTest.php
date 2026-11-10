<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** ExpensePulseService::shape — six months, gaps filled, this month marked, the quarter's GST summed. */
class ExpensePulseServiceTest extends TestCase
{
    public function testSixMonthsWithGapsAndQuarterGst(): void
    {
        $now = strtotime('2026-11-10 08:00:00');
        $p = ExpensePulseService::shape([
            ['ym' => '2026-06', 'total' => '100.00', 'gst' => '5.00'],
            ['ym' => '2026-10', 'total' => '400.50', 'gst' => '20.02'],
            ['ym' => '2026-11', 'total' => '50.00', 'gst' => '2.50'],
        ], $now);
        $this->assertSame(['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'], array_column($p['months'], 'label'));
        $this->assertSame(0.0, $p['months'][1]['total'], 'a month with no receipts is drawn as zero');
        $this->assertTrue($p['months'][5]['now']);
        $this->assertFalse($p['months'][4]['now']);
        $this->assertSame(400.5, $p['max']);
        $this->assertSame(22.52, $p['quarter_gst'], 'Q4 = Oct + Nov GST');
        $this->assertSame('Q4 2026', $p['quarter_label']);
        $this->assertSame(50.0, $p['this_month']);
    }

    public function testEmptyIsHonest(): void
    {
        $p = ExpensePulseService::shape([], strtotime('2026-02-03'));
        $this->assertCount(6, $p['months']);
        $this->assertSame(0.0, $p['max']);
        $this->assertSame(0.0, $p['quarter_gst']);
        $this->assertSame('Q1 2026', $p['quarter_label']);
    }
}
