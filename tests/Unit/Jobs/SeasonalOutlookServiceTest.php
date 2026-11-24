<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SeasonalOutlookService — pure helpers only (no DB).
 *
 * The behaviours worth locking down are the ones a reader of the card relies on
 * without thinking: that the outlook disappears when out of season, that months
 * already past are not offered as "ahead", that a stale outlook is flagged
 * rather than hidden, and that the shipped numbers are internally consistent.
 */
class SeasonalOutlookServiceTest extends TestCase
{
    private function outlook(): array
    {
        return SeasonalOutlookService::bundledOutlook();
    }

    // ── validity window ──────────────────────────────────────────────────

    public function testIsActiveInsideWindow(): void
    {
        $this->assertTrue(SeasonalOutlookService::isActive($this->outlook(), '2026-12-15'));
    }

    public function testIsActiveOnBoundaryDates(): void
    {
        $o = $this->outlook();
        $this->assertTrue(SeasonalOutlookService::isActive($o, $o['valid_from']));
        $this->assertTrue(SeasonalOutlookService::isActive($o, $o['valid_to']));
    }

    public function testIsNotActiveBeforeOrAfterWindow(): void
    {
        $o = $this->outlook();
        $this->assertFalse(SeasonalOutlookService::isActive($o, '2026-07-01'));
        $this->assertFalse(SeasonalOutlookService::isActive($o, '2027-04-01'));
    }

    public function testMissingWindowIsNeverActive(): void
    {
        $this->assertFalse(SeasonalOutlookService::isActive(['months' => []], '2026-12-15'));
    }

    // ── staleness ────────────────────────────────────────────────────────

    public function testStaleOnlyAfterReviewDate(): void
    {
        $o = $this->outlook();
        $this->assertFalse(SeasonalOutlookService::isStale($o, '2026-11-14'));
        $this->assertFalse(SeasonalOutlookService::isStale($o, $o['review_by']), 'review day itself is not yet overdue');
        $this->assertTrue(SeasonalOutlookService::isStale($o, '2026-11-16'));
    }

    public function testOutlookWithoutReviewDateIsNeverStale(): void
    {
        $o = $this->outlook();
        unset($o['review_by']);
        $this->assertFalse(SeasonalOutlookService::isStale($o, '2030-01-01'));
    }

    // ── month selection ──────────────────────────────────────────────────

    public function testAllFiveMonthsAheadBeforeSeasonStarts(): void
    {
        $rows = SeasonalOutlookService::upcomingMonths($this->outlook(), '2026-09-15');
        $this->assertCount(5, $rows);
        $this->assertSame(['November', 'December', 'January', 'February', 'March'],
            array_column($rows, 'name'));
    }

    public function testPastMonthsAreDropped(): void
    {
        $rows = SeasonalOutlookService::upcomingMonths($this->outlook(), '2027-01-20');
        $this->assertSame(['January', 'February', 'March'], array_column($rows, 'name'));
    }

    public function testCurrentMonthIsIncludedAndFlagged(): void
    {
        $rows = SeasonalOutlookService::upcomingMonths($this->outlook(), '2026-12-31');
        $this->assertSame('December', $rows[0]['name']);
        $this->assertTrue($rows[0]['is_current']);
        $this->assertFalse($rows[1]['is_current']);
    }

    /** Nov/Dec belong to the year BEFORE valid_to's year — the classic off-by-a-year trap. */
    public function testNovemberAndDecemberResolveToTheSeasonStartYear(): void
    {
        $rows = SeasonalOutlookService::upcomingMonths($this->outlook(), '2026-09-15');
        $byName = array_column($rows, 'ym', 'name');
        $this->assertSame('2026-11', $byName['November']);
        $this->assertSame('2026-12', $byName['December']);
        $this->assertSame('2027-01', $byName['January']);
        $this->assertSame('2027-03', $byName['March']);
    }

    public function testNoMonthsRemainAfterSeasonEnds(): void
    {
        $this->assertSame([], SeasonalOutlookService::upcomingMonths($this->outlook(), '2027-04-05'));
    }

    // ── totals & anomalies ───────────────────────────────────────────────

    public function testTotalsSumEveryTrackedMetric(): void
    {
        $t = SeasonalOutlookService::totals([
            ['frost' => 1.0, 'snow_days' => 2.0, 'snow_days_2cm' => 0.5, 'snow_cm' => 4.0,
             'normal_frost' => 3.0, 'normal_snow_days' => 1.0, 'normal_snow_cm' => 2.0],
            ['frost' => 2.5, 'snow_days' => 0.5, 'snow_days_2cm' => 0.5, 'snow_cm' => 1.0,
             'normal_frost' => 1.0, 'normal_snow_days' => 1.0, 'normal_snow_cm' => 2.0],
        ]);
        $this->assertSame(3.5, $t['frost']);
        $this->assertSame(2.5, $t['snow_days']);
        $this->assertSame(1.0, $t['snow_days_2cm']);
        $this->assertSame(5.0, $t['snow_cm']);
        $this->assertSame(4.0, $t['normal_frost']);
    }

    public function testTotalsOfNothingAreZeroNotNull(): void
    {
        $this->assertSame(0.0, SeasonalOutlookService::totals([])['frost']);
    }

    public function testAnomalyPctSignAndMagnitude(): void
    {
        $this->assertSame(-50, SeasonalOutlookService::anomalyPct(5.0, 10.0));
        $this->assertSame(25, SeasonalOutlookService::anomalyPct(10.0, 8.0));
        $this->assertSame(0, SeasonalOutlookService::anomalyPct(10.0, 10.0));
    }

    /** A zero baseline must not render as "+100%" — there is nothing to compare to. */
    public function testAnomalyPctIsNullAgainstAZeroBaseline(): void
    {
        $this->assertNull(SeasonalOutlookService::anomalyPct(3.0, 0.0));
        $this->assertSame('no baseline', SeasonalOutlookService::anomalyLabel(3.0, 0.0));
    }

    public function testAnomalyLabelCallsSmallDeviationsNearNormal(): void
    {
        $this->assertSame('near normal', SeasonalOutlookService::anomalyLabel(10.5, 10.0));
        $this->assertSame('50% below normal', SeasonalOutlookService::anomalyLabel(5.0, 10.0));
        $this->assertSame('30% above normal', SeasonalOutlookService::anomalyLabel(13.0, 10.0));
    }

    // ── peak risk ────────────────────────────────────────────────────────

    public function testPeakRiskMonthIsTheHighestSnowDayMonth(): void
    {
        $rows = SeasonalOutlookService::upcomingMonths($this->outlook(), '2026-09-15');
        $this->assertSame('January', SeasonalOutlookService::peakRiskMonth($rows)['name']);
    }

    public function testPeakRiskTiesBreakToTheEarlierMonth(): void
    {
        $peak = SeasonalOutlookService::peakRiskMonth([
            ['name' => 'December', 'snow_days' => 2.0],
            ['name' => 'January',  'snow_days' => 2.0],
        ]);
        $this->assertSame('December', $peak['name']);
    }

    public function testPeakRiskOfNothingIsNull(): void
    {
        $this->assertNull(SeasonalOutlookService::peakRiskMonth([]));
    }

    // ── shipped data integrity ───────────────────────────────────────────

    public function testBundledOutlookCoversEverySeasonMonthWithEveryMetric(): void
    {
        $months = $this->outlook()['months'];
        $this->assertSame(SeasonalOutlookService::SEASON_MONTHS, array_keys($months));
        foreach ($months as $m => $row) {
            foreach (['name', 'frost', 'snow_days', 'snow_days_2cm', 'snow_cm',
                      'normal_frost', 'normal_snow_days', 'normal_snow_cm'] as $k) {
                $this->assertArrayHasKey($k, $row, "month {$m} is missing {$k}");
            }
        }
    }

    /** Days>=2cm is a subset of snow days, and neither can exceed the days in a month. */
    public function testBundledMonthlyFiguresArePhysicallyCoherent(): void
    {
        foreach ($this->outlook()['months'] as $m => $row) {
            $this->assertLessThanOrEqual($row['snow_days'], $row['snow_days_2cm'],
                "month {$m}: days >= 2cm cannot exceed total snow days");
            $this->assertLessThanOrEqual(31, $row['frost'], "month {$m}: impossible frost count");
            $this->assertGreaterThanOrEqual(0, $row['snow_cm'], "month {$m}: negative snowfall");
        }
    }

    /** The whole point of the card is the anomaly, so the season must actually show one. */
    public function testBundledSeasonProjectsLessSnowThanNormal(): void
    {
        $t = SeasonalOutlookService::totals(array_values($this->outlook()['months']));
        $this->assertLessThan($t['normal_snow_cm'], $t['snow_cm']);
        $this->assertLessThan($t['normal_snow_days'], $t['snow_days']);
    }

    /** Mild winter, but frost is NOT projected away — that is the operational caveat. */
    public function testBundledSeasonStillProjectsSubstantialFrost(): void
    {
        $t = SeasonalOutlookService::totals(array_values($this->outlook()['months']));
        $this->assertGreaterThan(25, $t['frost']);
    }

    public function testReviewDateFallsInsideTheValidityWindow(): void
    {
        $o = $this->outlook();
        $this->assertGreaterThanOrEqual($o['valid_from'], $o['review_by']);
        $this->assertLessThanOrEqual($o['valid_to'], $o['review_by']);
    }

    // ── override loading (no PDO) ────────────────────────────────────────

    public function testOverrideIsNullWithoutADatabase(): void
    {
        $this->assertNull((new SeasonalOutlookService())->loadOverride());
    }

    public function testActiveOutlookFallsBackToBundledWithoutADatabase(): void
    {
        $active = (new SeasonalOutlookService())->activeOutlook('2026-12-15');
        $this->assertNotNull($active);
        $this->assertSame('2026-27', $active['season']);
        $this->assertArrayHasKey('months_ahead', $active);
        $this->assertArrayHasKey('totals', $active);
        $this->assertTrue($active['is_partial'], 'mid-December, November is behind us');
    }

    public function testActiveOutlookIsNullOutOfSeason(): void
    {
        $this->assertNull((new SeasonalOutlookService())->activeOutlook('2027-06-01'));
    }
}
