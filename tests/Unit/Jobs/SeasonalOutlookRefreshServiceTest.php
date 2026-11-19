<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SeasonalOutlookRefreshService — pure parsers and selectors (no network, no DB).
 *
 * The behaviours worth pinning are the ones that would silently produce a
 * plausible-but-wrong card: a mis-parsed ONI row, an analog set that includes the
 * current winter, the off-by-one that maps Nov/Dec to the wrong season year, and
 * a missing observation being counted as a dry day.
 */
class SeasonalOutlookRefreshServiceTest extends TestCase
{
    private const ONI_SAMPLE = <<<'TXT'
 SEAS  YR   TOTAL   ANOM
  DJF 1998  28.85   2.22
  MJJ 1997  28.60   1.13
  JJA 1997  28.70   1.60
  DJF 2016  29.05   2.50
  MJJ 2015  28.55   1.19
  DJF 2024  28.38   1.84
  MJJ 2023  28.30   0.73
  MJJ 2026  29.02   1.39
  AMJ 2026  28.74   0.95
  MAM 2026  28.09   0.46
garbage line that must be ignored
TXT;

    // ── ONI parsing ──────────────────────────────────────────────────────

    public function testParseOniReadsTheAnomalyColumnNotTheTotal(): void
    {
        $oni = SeasonalOutlookRefreshService::parseOniAscii(self::ONI_SAMPLE);
        $this->assertSame(2.22, $oni['DJF'][1998], 'must take ANOM (2.22), not TOTAL (28.85)');
        $this->assertSame(1.39, $oni['MJJ'][2026]);
    }

    public function testParseOniSkipsMalformedLines(): void
    {
        $oni = SeasonalOutlookRefreshService::parseOniAscii(self::ONI_SAMPLE);
        $flat = array_merge(...array_values(array_map('array_values', $oni)));
        $this->assertCount(10, $flat, 'header and garbage line must not become data');
    }

    public function testParseOniOfEmptyInputIsEmptyNotFatal(): void
    {
        $this->assertSame([], SeasonalOutlookRefreshService::parseOniAscii(''));
    }

    /** "Latest" must be resolved by calendar position, not by file order. */
    public function testLatestOniSeasonIgnoresFileOrder(): void
    {
        $latest = SeasonalOutlookRefreshService::latestOniSeason(
            SeasonalOutlookRefreshService::parseOniAscii(self::ONI_SAMPLE)
        );
        $this->assertSame('MJJ', $latest['seas']);
        $this->assertSame(2026, $latest['year']);
        $this->assertSame(1.39, $latest['value']);
    }

    public function testLatestOniSeasonOfNothingIsNull(): void
    {
        $this->assertNull(SeasonalOutlookRefreshService::latestOniSeason([]));
    }

    // ── ENSO classification ──────────────────────────────────────────────

    public function testClassifyEnsoBands(): void
    {
        $this->assertSame('neutral',    SeasonalOutlookRefreshService::classifyEnso(0.3)['code']);
        $this->assertSame('neutral',    SeasonalOutlookRefreshService::classifyEnso(-0.4)['code']);
        $this->assertSame('weak_el_nino',        SeasonalOutlookRefreshService::classifyEnso(0.7)['code']);
        $this->assertSame('moderate_el_nino',    SeasonalOutlookRefreshService::classifyEnso(1.39)['code']);
        $this->assertSame('strong_el_nino',      SeasonalOutlookRefreshService::classifyEnso(1.6)['code']);
        $this->assertSame('very_strong_el_nino', SeasonalOutlookRefreshService::classifyEnso(2.5)['code']);
    }

    public function testClassifyEnsoHandlesTheNegativePhase(): void
    {
        $la = SeasonalOutlookRefreshService::classifyEnso(-1.7);
        $this->assertSame('la_nina', $la['phase']);
        $this->assertSame('strong_la_nina', $la['code']);
        $this->assertStringContainsString('La Niña', $la['label']);
    }

    // ── trend ────────────────────────────────────────────────────────────

    public function testEnsoTrendDetectsStrengthening(): void
    {
        $oni = ['MAM' => [2026 => 0.46], 'AMJ' => [2026 => 0.95], 'MJJ' => [2026 => 1.39]];
        $this->assertSame('strengthening', SeasonalOutlookRefreshService::ensoTrend($oni, 'MJJ', 2026));
    }

    public function testEnsoTrendDetectsWeakening(): void
    {
        $oni = ['MAM' => [2026 => 1.39], 'AMJ' => [2026 => 0.95], 'MJJ' => [2026 => 0.46]];
        $this->assertSame('weakening', SeasonalOutlookRefreshService::ensoTrend($oni, 'MJJ', 2026));
    }

    /** Strengthening means moving AWAY from neutral, which is downward in La Niña. */
    public function testEnsoTrendStrengtheningWorksInTheNegativePhase(): void
    {
        $oni = ['MAM' => [2026 => -0.4], 'AMJ' => [2026 => -0.9], 'MJJ' => [2026 => -1.5]];
        $this->assertSame('strengthening', SeasonalOutlookRefreshService::ensoTrend($oni, 'MJJ', 2026));
    }

    public function testEnsoTrendIsSteadyWhenFlatOrIncomplete(): void
    {
        $flat = ['MAM' => [2026 => 1.30], 'AMJ' => [2026 => 1.35], 'MJJ' => [2026 => 1.39]];
        $this->assertSame('steady', SeasonalOutlookRefreshService::ensoTrend($flat, 'MJJ', 2026));
        $this->assertSame('steady', SeasonalOutlookRefreshService::ensoTrend([], 'MJJ', 2026));
    }

    /** DJF must look back across the year boundary into the prior year's OND/NDJ. */
    public function testEnsoTrendWrapsAcrossTheYearBoundary(): void
    {
        $oni = ['OND' => [2025 => 0.4], 'NDJ' => [2025 => 0.9], 'DJF' => [2026 => 1.5]];
        $this->assertSame('strengthening', SeasonalOutlookRefreshService::ensoTrend($oni, 'DJF', 2026));
    }

    // ── analog selection ─────────────────────────────────────────────────

    private function oni(): array
    {
        return SeasonalOutlookRefreshService::parseOniAscii(self::ONI_SAMPLE);
    }

    public function testAnalogsAreRankedByClosenessAtTheSameCalendarPoint(): void
    {
        $pick = SeasonalOutlookRefreshService::selectAnalogWinters(
            $this->oni(), 'MJJ', 2026, [1998, 2016, 2024], 3
        );
        // MJJ 2026 = 1.39. MJJ 2015 = 1.19 (winter 2016) is nearest, then
        // MJJ 1997 = 1.13 (winter 1998), then MJJ 2023 = 0.73 (winter 2024).
        $this->assertSame([2016, 1998, 2024], $pick['winters']);
    }

    /** MJJ leads into the FOLLOWING winter — the off-by-one that breaks hand-made lists. */
    public function testAnalogYearOffsetMapsMidYearSeasonsToTheNextWinter(): void
    {
        $pick = SeasonalOutlookRefreshService::selectAnalogWinters(
            $this->oni(), 'MJJ', 2026, [2016], 1
        );
        $this->assertSame(2016, $pick['ranked'][0]['winter']);
        $this->assertSame(1.19, $pick['ranked'][0]['oni'], 'winter 2016 must read MJJ 2015');
    }

    public function testCurrentWinterIsNeverItsOwnAnalog(): void
    {
        $oni = $this->oni() + [];
        $oni['MJJ'][2026] = 1.39;
        $pick = SeasonalOutlookRefreshService::selectAnalogWinters($oni, 'MJJ', 2026, [2027, 2016], 5);
        $this->assertNotContains(2027, $pick['winters']);
    }

    public function testAnalogSelectionIsEmptyWhenTheCurrentSeasonIsMissing(): void
    {
        $pick = SeasonalOutlookRefreshService::selectAnalogWinters($this->oni(), 'SON', 2026, [2016]);
        $this->assertSame([], $pick['winters']);
    }

    public function testAnalogSelectionSkipsWintersWithNoOniRecord(): void
    {
        $pick = SeasonalOutlookRefreshService::selectAnalogWinters(
            $this->oni(), 'MJJ', 2026, [2016, 1901], 5
        );
        $this->assertSame([2016], $pick['winters'], '1901 has no ONI record and must be dropped');
    }

    // ── ECCC CSV parsing ─────────────────────────────────────────────────

    private function csv(): string
    {
        return "\xEF\xBB\xBF\"Date/Time\",\"Max Temp (°C)\",\"Min Temp (°C)\",\"Total Snow (cm)\"\n"
             . "\"2026-11-01\",\"8.0\",\"-1.5\",\"0.0\"\n"
             . "\"2026-11-02\",\"3.0\",\"3.5\",\"4.2\"\n"
             . "\"2026-11-03\",\"-2.0\",\"-5.0\",\"1.0\"\n"
             . "\"2026-11-04\",\"\",\"\",\"\"\n"
             . "\"not-a-date\",\"1\",\"1\",\"1\"\n";
    }

    public function testParseEcccStripsTheBomAndReadsRows(): void
    {
        $rows = SeasonalOutlookRefreshService::parseEcccDaily($this->csv());
        $this->assertCount(4, $rows, 'the non-date row must be dropped');
        $this->assertSame(-1.5, $rows['2026-11-01']['min']);
        $this->assertSame(4.2, $rows['2026-11-02']['snow']);
    }

    /** A blank cell is a MISSING observation, not a zero — conflating them invents dry days. */
    public function testParseEcccTreatsBlanksAsNullNotZero(): void
    {
        $rows = SeasonalOutlookRefreshService::parseEcccDaily($this->csv());
        $this->assertNull($rows['2026-11-04']['min']);
        $this->assertNull($rows['2026-11-04']['snow']);
    }

    public function testParseEcccReturnsNothingWhenTheDateColumnIsAbsent(): void
    {
        $this->assertSame([], SeasonalOutlookRefreshService::parseEcccDaily("\"Oops\",\"Wrong\"\n\"a\",\"b\"\n"));
        $this->assertSame([], SeasonalOutlookRefreshService::parseEcccDaily(''));
    }

    // ── aggregation ──────────────────────────────────────────────────────

    public function testAggregateAppliesEachThresholdCorrectly(): void
    {
        $agg = SeasonalOutlookRefreshService::aggregateSeasons(
            SeasonalOutlookRefreshService::parseEcccDaily($this->csv())
        );
        $nov = $agg[2027][11];
        $this->assertSame(2, $nov['frost'],        'min <= 0 on Nov 1 and Nov 3');
        $this->assertSame(3, $nov['ground_frost'], 'min <= 4 also catches Nov 2 at +3.5');
        $this->assertSame(1, $nov['ice'],          'max <= 0 only on Nov 3');
        $this->assertSame(2, $nov['snow_days'],    'snow >= 0.2 on Nov 2 and Nov 3');
        $this->assertSame(1, $nov['snow_days_2cm']);
        $this->assertSame(1, $nov['missing']);
    }

    /** November belongs to the NEXT year's winter label; January to its own. */
    public function testWinterLabellingSplitsAtTheYearBoundary(): void
    {
        $agg = SeasonalOutlookRefreshService::aggregateSeasons([
            '2026-11-15' => ['min' => -1.0, 'max' => 5.0, 'snow' => 0.0],
            '2027-01-15' => ['min' => -1.0, 'max' => 5.0, 'snow' => 0.0],
        ]);
        $this->assertArrayHasKey(11, $agg[2027]);
        $this->assertArrayHasKey(1, $agg[2027]);
        $this->assertCount(1, $agg, 'both dates belong to the same winter');
    }

    public function testAggregateIgnoresOutOfSeasonMonths(): void
    {
        $agg = SeasonalOutlookRefreshService::aggregateSeasons([
            '2026-07-15' => ['min' => 12.0, 'max' => 25.0, 'snow' => 0.0],
        ]);
        $this->assertSame([], $agg);
    }

    // ── means and blending ───────────────────────────────────────────────

    private function seasons(): array
    {
        $mk = fn($f, $g, $s, $cm, $miss = 0) => [
            'frost' => $f, 'ground_frost' => $g, 'ice' => 0, 'snow_days' => $s,
            'snow_days_2cm' => 0, 'snow_cm' => $cm, 'missing' => $miss, 'observed' => 30,
        ];
        return [
            2020 => [11 => $mk(4, 10, 1, 5.0)],
            2021 => [11 => $mk(8, 20, 3, 15.0)],
            2022 => [11 => $mk(99, 99, 99, 99.0, 20)], // too many gaps — must be excluded
        ];
    }

    public function testMeanExcludesMonthsWithTooManyMissingObservations(): void
    {
        $mean = SeasonalOutlookRefreshService::meanOverWinters($this->seasons(), [2020, 2021, 2022]);
        $this->assertSame(6.0, $mean[11]['frost'], 'the gappy 2022 must not drag the mean');
        $this->assertSame(15.0, $mean[11]['ground_frost']);
        $this->assertSame(2, $mean[11]['n']);
    }

    public function testMeanOfNoUsableWintersOmitsTheMonthEntirely(): void
    {
        $this->assertSame([], SeasonalOutlookRefreshService::meanOverWinters($this->seasons(), [2022]));
    }

    public function testBlendAppliesTheWeighting(): void
    {
        $analog = [11 => ['frost' => 10.0, 'ground_frost' => 20.0, 'snow_days' => 0.0, 'snow_days_2cm' => 0.0, 'snow_cm' => 0.0]];
        $climo  = [11 => ['frost' => 0.0,  'ground_frost' => 0.0,  'snow_days' => 10.0, 'snow_days_2cm' => 0.0, 'snow_cm' => 0.0]];
        $out = SeasonalOutlookRefreshService::blend($analog, $climo, 0.6);
        $this->assertSame(6.0, $out[11]['frost']);
        $this->assertSame(12.0, $out[11]['ground_frost']);
        $this->assertSame(4.0, $out[11]['snow_days']);
    }

    public function testBlendSkipsMonthsMissingFromEitherSide(): void
    {
        $one = [11 => ['frost' => 1.0, 'ground_frost' => 1.0, 'snow_days' => 1.0, 'snow_days_2cm' => 1.0, 'snow_cm' => 1.0]];
        $this->assertSame([], SeasonalOutlookRefreshService::blend($one, []));
    }

    // ── winter labelling ─────────────────────────────────────────────────

    public function testWinterLabelForDatesEitherSideOfTheSplit(): void
    {
        $this->assertSame(2027, SeasonalOutlookRefreshService::winterLabelFor('2026-08-31'));
        $this->assertSame(2027, SeasonalOutlookRefreshService::winterLabelFor('2026-12-25'));
        $this->assertSame(2027, SeasonalOutlookRefreshService::winterLabelFor('2027-03-01'));
        $this->assertSame(2026, SeasonalOutlookRefreshService::winterLabelFor('2026-06-30'));
    }

    // ── failure containment ──────────────────────────────────────────────

    public function testWritesAreNoOpsWithoutADatabase(): void
    {
        $svc = new SeasonalOutlookRefreshService(null);
        $this->assertNull($svc->readSetting('anything'));
        $this->assertFalse($svc->writeSetting('k', ['a' => 1], 'desc'));
    }
}
