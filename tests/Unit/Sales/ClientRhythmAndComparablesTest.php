<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClientRhythmAndComparablesTest extends TestCase
{
    private const TODAY = '2026-11-03';

    public function testQuietAndGoneAgainstTheClientsOwnRhythm(): void
    {
        $clients = [
            'co1' => ['name' => 'Weekly Strata', 'purchases' => ['2026-08-01', '2026-08-08', '2026-08-15', '2026-08-22', '2026-08-29', '2026-09-05'], 'spend_12m' => 9000],   // gap 7, 59 days since → 8.4× gone
            'co2' => ['name' => 'Monthly Ann', 'purchases' => ['2026-06-01', '2026-07-02', '2026-08-01', '2026-09-03'], 'spend_12m' => 1200],                                  // gap 31, 61 days → 1.97× not quiet (needs > 2)
            'co3' => ['name' => 'Monthly Bo', 'purchases' => ['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-15'], 'spend_12m' => 2400],                                   // gap 31, 80 days → 2.6× quiet
            'co4' => ['name' => 'Two visits', 'purchases' => ['2026-05-01', '2026-06-01'], 'spend_12m' => 500],                                                                  // no rhythm
            'co5' => ['name' => 'Snow Sam', 'purchases' => ['2025-12-01', '2026-01-02', '2026-02-01', '2026-10-20'], 'spend_12m' => 3000],                                      // the 261-day break is the season; 14 days since → fine
        ];
        $r = ClientRhythm::find($clients, self::TODAY);
        $this->assertSame(['co1', 'co3'], array_column($r, 'key'));          // by spend
        $this->assertSame(['gone', 'quiet'], array_column($r, 'state'));
        $this->assertSame('usually every 7 days, now 59 days since Sep 5 (8.4×)', $r[0]['reason']);
        $this->assertSame([], ClientRhythm::find($clients, self::TODAY, false));   // off-season: nobody is quiet
    }

    public function testFirstWinBackDoesNotPitch(): void
    {
        $m = ClientRhythm::firstMessage(['first_name' => 'Ann', 'last' => '2026-08-15'], 'Tim');
        $this->assertSame('Checking in', $m['subject']);
        $this->assertStringContainsString('No pitch', $m['body']);
        $this->assertStringNotContainsString('%', $m['body']);
        $this->assertSame(10, $m['next_touch_days']);
    }

    public function testComparablesPriceShowsArithmeticAndFlagsTheRateCard(): void
    {
        $acc = [
            ['quote_number' => 'QUO-1', 'who' => 'A', 'accepted_at' => '2026-05-10', 'size' => 120, 'amount' => 600],   // 5.00/min
            ['quote_number' => 'QUO-2', 'who' => 'B', 'accepted_at' => '2026-06-10', 'size' => 90, 'amount' => 495],    // 5.50
            ['quote_number' => 'QUO-3', 'who' => 'C', 'accepted_at' => '2026-07-10', 'size' => 400, 'amount' => 1200],  // 3.00 — far in size, dropped
            ['quote_number' => 'QUO-4', 'who' => 'D', 'accepted_at' => '2026-08-10', 'size' => 100, 'amount' => 520],   // 5.20
        ];
        $s = ComparablePricing::suggest($acc, 110, 450.0, 'min');
        $this->assertSame(['QUO-4', 'QUO-1', 'QUO-2'], array_column($s['comparables'], 'quote_number'));   // 10 off (newest first), 10 off, 20 off
        $this->assertSame(5.2, $s['unit_price']);
        $this->assertSame(572.0, $s['suggested']);
        $this->assertStringContainsString('QUO-4 (D, Aug 2026): $520 for 100 min = $5.20/min', $s['arithmetic']);
        $this->assertStringContainsString('Median $5.20/min × 110 min = $572.00.', $s['arithmetic']);
        $this->assertStringContainsString('Comparables land +27% off the rate card ($450.00)', $s['flag']);
        $this->assertNull(ComparablePricing::suggest($acc, 110, 540.0, 'min')['flag']);   // +6%: inside tolerance
        $fb = ComparablePricing::suggest([$acc[0]], 110, 450.0, 'min');
        $this->assertSame('rate_card', $fb['basis']);
        $this->assertSame(450.0, $fb['suggested']);
        $this->assertStringContainsString('fewer than 2 accepted jobs like it', $fb['arithmetic']);
        $none = ComparablePricing::suggest([], 0, null, 'min');
        $this->assertNull($none['suggested']);
        $this->assertStringContainsString('nothing is invented', $none['arithmetic']);
    }
}
