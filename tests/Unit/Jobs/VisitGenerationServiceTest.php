<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure recurrence/holiday math extracted into
 * VisitGenerationService (refactor Phase 2).
 *
 * These three methods (parseDowList, findBumpDate, calculateRecurrenceDates) are
 * pure — no DB, no clock — so they are fully deterministic given explicit dates.
 * They drive the whole schedule and the generate_visits cron, so this is the
 * highest-value behaviour to lock down.
 *
 * generateVisits() / getActiveHolidays() touch the DB and are exercised by the
 * integration suite, not here.
 */
class VisitGenerationServiceTest extends TestCase
{
    // ---- parseDowList -------------------------------------------------------

    public function test_parseDowList_falls_back_to_start_dow_when_empty(): void
    {
        $monday = new DateTime('2026-06-15'); // w = 1
        $this->assertSame([1], VisitGenerationService::parseDowList(null, $monday));
        $this->assertSame([1], VisitGenerationService::parseDowList('', $monday));
    }

    public function test_parseDowList_single_legacy_value(): void
    {
        $this->assertSame([3], VisitGenerationService::parseDowList('3', new DateTime('2026-06-15')));
    }

    public function test_parseDowList_multi_day(): void
    {
        $this->assertSame([1, 3, 5], VisitGenerationService::parseDowList('1,3,5', new DateTime('2026-06-15')));
    }

    public function test_parseDowList_dedupes_and_filters_out_of_range(): void
    {
        // 9 is out of range (0..6) and the duplicate 1 collapses
        $this->assertSame([1, 3], VisitGenerationService::parseDowList('1,1,3,9', new DateTime('2026-06-15')));
    }

    public function test_parseDowList_all_invalid_falls_back(): void
    {
        $wednesday = new DateTime('2026-06-17'); // w = 3
        $this->assertSame([3], VisitGenerationService::parseDowList('7,8', $wednesday));
    }

    // ---- findBumpDate -------------------------------------------------------

    public function test_findBumpDate_returns_prior_weekday(): void
    {
        // Canada Day 2026-07-01 (Wed) → bump to Tue 2026-06-30
        $this->assertSame(
            '2026-06-30',
            VisitGenerationService::findBumpDate('2026-07-01', ['2026-07-01' => 'Canada Day'], [])
        );
    }

    public function test_findBumpDate_skips_weekends(): void
    {
        // Holiday on Mon 2026-07-06 → Sun/Sat skipped → Fri 2026-07-03
        $this->assertSame(
            '2026-07-03',
            VisitGenerationService::findBumpDate('2026-07-06', ['2026-07-06' => 'X'], [])
        );
    }

    public function test_findBumpDate_skips_other_holidays_and_blackouts(): void
    {
        // 07-01 Wed holiday; 06-30 Tue also a holiday; 06-29 Mon blacked out → 06-26 Fri
        $holidays  = ['2026-07-01' => 'A', '2026-06-30' => 'B'];
        $blackouts = ['2026-06-29' => true];
        $this->assertSame(
            '2026-06-26',
            VisitGenerationService::findBumpDate('2026-07-01', $holidays, $blackouts)
        );
    }

    // ---- calculateRecurrenceDates ------------------------------------------

    private function plan(array $overrides = []): array
    {
        return array_merge([
            'recurrence_pattern' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_interval_unit' => 'weeks',
            'recurrence_day_of_week' => '1', // Monday
            'plan_start_date' => '2026-06-01', // Monday
            'blackout_dates' => null,
        ], $overrides);
    }

    public function test_weekly_single_day(): void
    {
        $dates = VisitGenerationService::calculateRecurrenceDates(
            $this->plan(), '2026-06-01', '2026-06-30'
        );
        $this->assertSame(
            ['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22', '2026-06-29'],
            $dates
        );
    }

    public function test_biweekly_every_other_week_from_start(): void
    {
        $dates = VisitGenerationService::calculateRecurrenceDates(
            $this->plan(['recurrence_pattern' => 'biweekly']), '2026-06-01', '2026-06-30'
        );
        $this->assertSame(['2026-06-01', '2026-06-15', '2026-06-29'], $dates);
    }

    public function test_weekly_multi_day(): void
    {
        // Mondays + Wednesdays in the first two weeks of June 2026
        $dates = VisitGenerationService::calculateRecurrenceDates(
            $this->plan(['recurrence_day_of_week' => '1,3']), '2026-06-01', '2026-06-12'
        );
        $this->assertSame(
            ['2026-06-01', '2026-06-03', '2026-06-08', '2026-06-10'],
            $dates
        );
    }

    public function test_blackout_drops_the_occurrence(): void
    {
        $dates = VisitGenerationService::calculateRecurrenceDates(
            $this->plan(['blackout_dates' => json_encode(['2026-06-15'])]),
            '2026-06-01', '2026-06-30'
        );
        $this->assertNotContains('2026-06-15', $dates);
        $this->assertContains('2026-06-08', $dates);
        $this->assertContains('2026-06-22', $dates);
    }

    public function test_holiday_bumps_occurrence_to_prior_working_day(): void
    {
        // A Monday plan; make 2026-06-15 (Mon) a holiday → it should be bumped
        // back to Fri 2026-06-12, and the holiday date itself must not appear.
        $dates = VisitGenerationService::calculateRecurrenceDates(
            $this->plan(), '2026-06-01', '2026-06-30',
            ['2026-06-15' => 'Test Holiday']
        );
        $this->assertNotContains('2026-06-15', $dates);
        $this->assertContains('2026-06-12', $dates);
    }

    public function test_monthly_on_day_of_month(): void
    {
        // Start on the 1st → monthly should hit the 1st of each month in range
        $dates = VisitGenerationService::calculateRecurrenceDates(
            $this->plan(['recurrence_pattern' => 'monthly']),
            '2026-06-01', '2026-08-31'
        );
        $this->assertSame(['2026-06-01', '2026-07-01', '2026-08-01'], $dates);
    }

    public function test_respects_window_bounds(): void
    {
        // from-date after the first occurrence should exclude it
        $dates = VisitGenerationService::calculateRecurrenceDates(
            $this->plan(), '2026-06-09', '2026-06-23'
        );
        $this->assertSame(['2026-06-15', '2026-06-22'], $dates);
    }
}
