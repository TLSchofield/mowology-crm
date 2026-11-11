<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's pick for a missed visit (MissedVisitRules) — pure.
 * Review day in these tests: Tue 2026-10-13. Wed 14 … Sun 18 (rest day) … Mon 19.
 */
final class MissedVisitRulesTest extends TestCase
{
    private const TODAY = '2026-10-13';

    /** Plain working days Wed 14 → Mon 19 (Sun 18 is a rest day). */
    private function days(array $over = []): array
    {
        $out = [];
        foreach (['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19'] as $d) {
            $out[$d] = ['rest' => $d === '2026-10-18', 'crew_works' => null, 'plan_visit' => false, 'bylaw_ok' => true, 'wet' => null, 'route' => null, 'route_n' => 0];
        }
        foreach ($over as $d => $o) $out[$d] = $o + $out[$d];
        return $out;
    }

    private function weekly(array $over = []): array
    {
        return $over + ['is_recurring' => true, 'contract_number' => null, 'is_prepaid_bundle' => false,
                        'freq_days' => 7, 'next_visit' => '2026-10-20', 'done_since' => null, 'every_day' => false, 'crew_name' => 'Nigel'];
    }

    public function testTomorrowWhenItIsTheCrewsNextWorkingDay(): void
    {
        $p = MissedVisitRules::pick($this->weekly(), self::TODAY, $this->days());
        $this->assertSame('tomorrow', $p['pick']);
        $this->assertSame('2026-10-14', $p['date']);
        $this->assertStringContainsString("Nigel's next working day", $p['reason']);
    }

    public function testTomorrowOnTheRouteSaysSo(): void
    {
        $p = MissedVisitRules::pick($this->weekly(), self::TODAY, $this->days(['2026-10-14' => ['route' => true, 'route_n' => 2, 'wet' => false]]));
        $this->assertSame('tomorrow', $p['pick']);
        $this->assertStringContainsString('2 stops within 3 km', $p['reason']);
        $this->assertStringContainsString('dry', $p['reason']);
    }

    public function testWetTomorrowGoesToTheFirstDryRouteDay(): void
    {
        $p = MissedVisitRules::pick($this->weekly(), self::TODAY, $this->days([
            '2026-10-14' => ['wet' => true, 'wet_mm' => 6.4],
            '2026-10-15' => ['wet' => false, 'route' => false],
            '2026-10-16' => ['wet' => false, 'route' => true, 'route_n' => 1],
        ]));
        $this->assertSame('date', $p['pick']);
        $this->assertSame('2026-10-16', $p['date']);
        $this->assertStringContainsString('Tomorrow looks wet (6.4 mm)', $p['reason']);
    }

    public function testAFarDayWithNoStopsYetNeverBeatsANearerDryDay(): void
    {
        // Thu: crew working elsewhere, dry. Mon: nothing scheduled yet (route unknown). → Thu.
        $p = MissedVisitRules::pick($this->weekly(['next_visit' => '2026-10-21']), self::TODAY, $this->days([
            '2026-10-14' => ['wet' => true, 'wet_mm' => 8],
            '2026-10-15' => ['wet' => false, 'route' => false],
            '2026-10-16' => ['wet' => false, 'route' => false],
            '2026-10-19' => ['wet' => null, 'route' => null],
        ]));
        $this->assertSame('2026-10-15', $p['date']);
    }

    public function testNeverOnARestDayOrACrewDayOff(): void
    {
        // Missed on Fri 16, next visit Fri 23: Sat is the crew's day off, Sun is a rest day → Mon.
        $days = [
            '2026-10-17' => ['rest' => false, 'crew_works' => false, 'plan_visit' => false, 'bylaw_ok' => true],
            '2026-10-18' => ['rest' => true, 'crew_works' => false, 'plan_visit' => false, 'bylaw_ok' => true],
            '2026-10-19' => ['rest' => false, 'crew_works' => true, 'plan_visit' => false, 'bylaw_ok' => true],
        ];
        $p = MissedVisitRules::pick($this->weekly(['next_visit' => '2026-10-23']), '2026-10-16', $days);
        $this->assertSame('date', $p['pick']);
        $this->assertSame('2026-10-19', $p['date']);
    }

    public function testSnowRoutesMayLandOnSundays(): void
    {
        $days = ['2026-10-18' => ['rest' => true, 'crew_works' => false, 'plan_visit' => false, 'bylaw_ok' => true]];
        $p = MissedVisitRules::pick($this->weekly(['every_day' => true, 'next_visit' => null]), '2026-10-17', $days);
        $this->assertSame('tomorrow', $p['pick']);
    }

    public function testBylawHoursBlockADay(): void
    {
        $p = MissedVisitRules::pick($this->weekly(), self::TODAY, $this->days(['2026-10-14' => ['bylaw_ok' => false]]));
        $this->assertSame('2026-10-15', $p['date']);
        $this->assertStringContainsString('city hours', $p['reason']);
    }

    // ── Skip: only when doing it would double up, never for contracts / prepaid / one-offs ──

    public function testSkipWhenTheNextVisitIsWithinThreeDays(): void
    {
        $p = MissedVisitRules::pick($this->weekly(['next_visit' => '2026-10-16']), self::TODAY, $this->days());
        $this->assertSame('skip', $p['pick']);
        $this->assertStringContainsString('in 3 days', $p['reason']);
        $this->assertStringContainsString('double up', $p['reason']);
    }

    public function testHalfTheFrequencyCountsForLongerPlans(): void
    {
        // Every 2 weeks: next visit in 6 days is within half (7) → skip; weekly with 6 days → move.
        $this->assertSame(7, MissedVisitRules::skipWithinDays(14));
        $this->assertSame(3, MissedVisitRules::skipWithinDays(7));
        $p = MissedVisitRules::pick($this->weekly(['freq_days' => 14, 'next_visit' => '2026-10-19']), self::TODAY, $this->days());
        $this->assertSame('skip', $p['pick']);
        $p = MissedVisitRules::pick($this->weekly(['freq_days' => 7, 'next_visit' => '2026-10-19']), self::TODAY, $this->days());
        $this->assertSame('tomorrow', $p['pick']);
    }

    public function testAContractVisitIsNeverSkipped(): void
    {
        $v = $this->weekly(['contract_number' => 'CTR-2026-0014', 'next_visit' => '2026-10-15']);
        $p = MissedVisitRules::pick($v, self::TODAY, $this->days());
        $this->assertNotSame('skip', $p['pick']);
        $this->assertSame('tomorrow', $p['pick']);
        $this->assertFalse($p['skip_allowed']);
        $this->assertStringContainsString('CTR-2026-0014', (string)$p['skip_block']);
    }

    public function testAContractVisitWithNoFreeDayIsLeftToThePerson(): void
    {
        // Next visit tomorrow and the contract forbids a skip → no pick, "you decide". Never skip.
        $v = $this->weekly(['contract_number' => 'CTR-2026-0014', 'next_visit' => '2026-10-14']);
        $p = MissedVisitRules::pick($v, self::TODAY, $this->days());
        $this->assertNull($p['pick']);
        $this->assertStringContainsString('You decide', $p['reason']);
    }

    public function testPrepaidAndOneOffAreNeverSkipped(): void
    {
        foreach ([['is_prepaid_bundle' => true], ['is_recurring' => false]] as $o) {
            $p = MissedVisitRules::pick($this->weekly($o + ['next_visit' => '2026-10-15']), self::TODAY, $this->days());
            $this->assertNotSame('skip', $p['pick']);
            $this->assertFalse($p['skip_allowed']);
        }
        $this->assertStringContainsString('One-off', (string)MissedVisitRules::skipBlock(['is_recurring' => false]));
    }

    public function testDoneSinceMeansSkipButNotForContracts(): void
    {
        $p = MissedVisitRules::pick($this->weekly(['done_since' => '2026-10-13', 'next_visit' => '2026-10-20']), self::TODAY, $this->days());
        $this->assertSame('skip', $p['pick']);
        $p = MissedVisitRules::pick($this->weekly(['done_since' => '2026-10-13', 'contract_number' => 'CTR-1']), self::TODAY, $this->days());
        $this->assertSame('tomorrow', $p['pick']);
    }

    public function testNeverMovesOntoOrPastTheNextVisit(): void
    {
        // Next visit Mon 19 (6 days, weekly → not a skip). Wed–Sat are all blocked → no day before Mon → skip.
        $blocked = ['plan_visit' => true];
        $p = MissedVisitRules::pick($this->weekly(['next_visit' => '2026-10-19']), self::TODAY, $this->days([
            '2026-10-14' => $blocked, '2026-10-15' => $blocked, '2026-10-16' => $blocked, '2026-10-17' => $blocked,
        ]));
        $this->assertSame('skip', $p['pick']);
        $this->assertStringContainsString('No working day fits', $p['reason']);
    }

    public function testCancelIsNeverSuggested(): void
    {
        $cases = [
            $this->weekly(), $this->weekly(['next_visit' => '2026-10-14']),
            $this->weekly(['contract_number' => 'C', 'next_visit' => '2026-10-14']), $this->weekly(['is_recurring' => false, 'next_visit' => null]),
        ];
        foreach ($cases as $v) {
            $this->assertContains(MissedVisitRules::pick($v, self::TODAY, $this->days())['pick'], ['tomorrow', 'date', 'skip', null]);
        }
    }

    // ── Frequency ──

    public function testFrequency(): void
    {
        $this->assertSame(7, MissedVisitRules::freqDays('weekly', 1, 'weeks'));
        $this->assertSame(14, MissedVisitRules::freqDays('weekly', 2, 'weeks'));
        $this->assertSame(14, MissedVisitRules::freqDays('biweekly', 2, 'weeks'));
        $this->assertSame(14, MissedVisitRules::freqDays('biweekly', 1, null));
        $this->assertSame(1, MissedVisitRules::freqDays(null, 1, 'days'));
        $this->assertSame(30, MissedVisitRules::freqDays('monthly', 1, 'months'));
        $this->assertSame(2, MissedVisitRules::freqDays('weekly', 1, 'weeks', '1,3,5'));
        $this->assertNull(MissedVisitRules::freqDays(null, null, null));
    }

    // ── When the review opens ──

    public function testOpensAtSixOrAfterTheLastClockOut(): void
    {
        $this->assertSame('six_pm', MissedVisitRules::openTrigger('18:00', 3, 2, null));
        $this->assertSame('last_clock_out', MissedVisitRules::openTrigger('16:40', 3, 0, 25));
        $this->assertNull(MissedVisitRules::openTrigger('16:40', 3, 0, 10), 'out for 10 min — maybe lunch');
        $this->assertNull(MissedVisitRules::openTrigger('11:30', 3, 0, 60), 'not before noon');
        $this->assertNull(MissedVisitRules::openTrigger('15:00', 3, 1, 60), 'someone is still on');
        $this->assertNull(MissedVisitRules::openTrigger('15:00', 0, 0, null), 'nobody worked — wait for 18:00');
    }

    // ── auto_rollover never silently skips a contract visit ──

    public function testRolloverHoldsContractAndPrepaidVisitsInsteadOfSkipping(): void
    {
        $base = ['plan_end_date' => null, 'rollover_count' => 3, 'contract_binds' => false, 'is_prepaid_bundle' => false];
        $this->assertSame('skip', MissedVisitRules::rolloverAction($base, '2026-10-14', 3, false)['action']);
        $this->assertSame('hold', MissedVisitRules::rolloverAction(['contract_binds' => true] + $base, '2026-10-14', 3, false)['action']);
        $this->assertSame('hold', MissedVisitRules::rolloverAction(['is_prepaid_bundle' => true] + $base, '2026-10-14', 3, false)['action']);
        $this->assertSame('hold', MissedVisitRules::rolloverAction(['contract_binds' => true, 'rollover_count' => 0] + $base, '2026-10-14', 3, true)['action']);
        $this->assertSame('roll', MissedVisitRules::rolloverAction(['contract_binds' => true, 'rollover_count' => 1] + $base, '2026-10-14', 3, false)['action']);
        $this->assertSame('roll', MissedVisitRules::rolloverAction(['rollover_count' => 1] + $base, '2026-10-14', 3, false)['action']);
        $ended = MissedVisitRules::rolloverAction(['plan_end_date' => '2026-10-13', 'rollover_count' => 0] + $base, '2026-10-14', 3, false);
        $this->assertSame('skip', $ended['action']);
        $this->assertStringContainsString('plan ended', $ended['why']);
    }

    public function testAnswerKeysCompareMovesByDate(): void
    {
        $this->assertSame(MissedVisitRules::answerKey('tomorrow', '2026-10-14'), MissedVisitRules::answerKey('move', '2026-10-14'));
        $this->assertNotSame(MissedVisitRules::answerKey('tomorrow', '2026-10-14'), MissedVisitRules::answerKey('move', '2026-10-15'));
        $this->assertSame('skip', MissedVisitRules::answerKey('skip', null));
        $this->assertNull(MissedVisitRules::answerKey(null, null));
    }
}
