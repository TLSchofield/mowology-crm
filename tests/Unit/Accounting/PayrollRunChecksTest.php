<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The payroll-prep anomaly rules on Wave runs and clock punches: who, when, both numbers, impact. */
final class PayrollRunChecksTest extends TestCase
{
    private function emp(string $name, float $wages, array $paydays, ?int $uid = 1): array
    {
        return ['name' => $name, 'user_id' => $uid, 'totals' => ['wages' => $wages],
                'paydays' => array_map(fn($p) => ['payday' => $p[0], 'regular' => $p[1], 'overtime' => $p[2] ?? 0, 'double_time' => $p[3] ?? 0], $paydays)];
    }
    private function mkRun(string $month, array $emps): array
    {
        return ['month' => $month, 'totals' => ['wages' => array_sum(array_map(fn($e) => $e['totals']['wages'], $emps))], 'employees' => $emps];
    }

    public function testFirstRunOnlyPunchRulesFire(): void
    {
        $run = $this->mkRun('2026-10', [$this->emp('Ann Lee', 2000, [['2026-10-15', 80, 12]])]);
        $shifts = [1 => [['clock_in' => '2026-10-07 05:10:00', 'clock_out' => '2026-10-08 00:20:00', 'total_minutes' => 1150]]];
        $f = PayrollRunChecks::check($run, [], $shifts, [1 => 25.0]);
        $this->assertSame(['shift', 'day'], array_column($f, 'kind'));   // one 19 h punch is both a long shift and a >16 h day
        $this->assertStringContainsString('Ann Lee: a 19.2 h shift on Wed Oct 7 (5:10 am to 12:20 am) — real long day, or a clock-out they forgot?', $f[0]['text']);
        $this->assertSame(280.0, $f[0]['impact']);     // (19.2 − 8) × 25
    }

    public function testOvertimeAgainstTheirOwnPatternAndNoneBefore(): void
    {
        $prior = [$this->mkRun('2026-08', [$this->emp('Ann Lee', 1800, [['2026-08-15', 80, 2], ['2026-08-29', 80, 2]])]),
                  $this->mkRun('2026-09', [$this->emp('Ann Lee', 1800, [['2026-09-15', 80, 2], ['2026-09-29', 80, 2]])])];
        $run = $this->mkRun('2026-10', [$this->emp('Ann Lee', 1900, [['2026-10-15', 80, 3], ['2026-10-29', 80, 3]])]);   // 3/payday vs avg 2 → 150% → flag
        $f = PayrollRunChecks::check($run, $prior, [], [1 => 20.0]);
        $ot = array_values(array_filter($f, fn($x) => $x['kind'] === 'overtime'));
        $this->assertCount(1, $ot);
        $this->assertStringContainsString('3 h overtime per payday in 2026-10 against their usual 2 h (last 4 paydays) — about $180.00 at time-and-a-half', $ot[0]['text']);
        $run = $this->mkRun('2026-10', [$this->emp('Ann Lee', 1900, [['2026-10-15', 80, 2.4], ['2026-10-29', 80, 2.4]])]);   // 120%: inside the band
        $this->assertSame([], array_filter(PayrollRunChecks::check($run, $prior, []), fn($x) => $x['kind'] === 'overtime'));
        $prior2 = [$this->mkRun('2026-09', [$this->emp('Bo Kim', 1600, [['2026-09-15', 80, 0]], 2)])];
        $run = $this->mkRun('2026-10', [$this->emp('Bo Kim', 1700, [['2026-10-15', 80, 1]], 2)]);
        $f = PayrollRunChecks::check($run, $prior2, []);
        $this->assertStringContainsString('Bo Kim: 1 h overtime in 2026-10; their last 1 payday had none — rate unknown', $f[0]['text']);
    }

    public function testWagesRateZeroHoursNewAndMissingAgainstThePriorRun(): void
    {
        $prior = [$this->mkRun('2026-09', [$this->emp('Ann Lee', 2000, [['2026-09-15', 80]]), $this->emp('Cy Fox', 1500, [['2026-09-15', 60]], 3)])];
        $run = $this->mkRun('2026-10', [$this->emp('Ann Lee', 2400, [['2026-10-15', 80]]),      // +20% a payday on the same 80 h: implied rate 25 → 30
                                      $this->emp('Dee Ng', 900, [['2026-10-15', 40]], 4)]);    // new; Cy missing
        $kinds = array_count_values(array_column(PayrollRunChecks::check($run, $prior, []), 'kind'));
        ksort($kinds);
        $this->assertSame(['missing_person' => 1, 'new_person' => 1, 'rate_changed' => 1, 'wages_moved' => 1], $kinds);
        $texts = implode("\n", array_column(PayrollRunChecks::check($run, $prior, []), 'text'));
        $this->assertStringContainsString('Ann Lee: $2,400.00 a payday in 2026-10 against $2,000.00 in 2026-09 (+20%), and the hours don\'t explain it', $texts);
        $this->assertStringContainsString('pay works out to $30.00/h in 2026-10 against their usual $25.00/h', $texts);
        // Hours explain it: 2 paydays × 80 h for 4000 against 1 payday × 80 h for 2000 → same per payday, no flag; 120 h for 3000 → rate unchanged, wages_moved suppressed
        $f = PayrollRunChecks::check($this->mkRun('2026-10', [$this->emp('Ann Lee', 3000, [['2026-10-15', 120]])]), $prior, []);
        $this->assertSame([], array_values(array_filter($f, fn($x) => in_array($x['kind'], ['wages_moved', 'rate_changed'], true))));
        // Stat pay nudges the rate 4%: inside the 5% band
        $f = PayrollRunChecks::check($this->mkRun('2026-10', [$this->emp('Ann Lee', 2080, [['2026-10-15', 80]])]), $prior, []);
        $this->assertSame([], array_values(array_filter($f, fn($x) => $x['kind'] === 'rate_changed')));
        $this->assertStringContainsString('Cy Fox was paid in 2026-09 and is not on this run', $texts);
        // run total per payday 3300 vs 3500 = −6%: no run flag
        $this->assertArrayNotHasKey('run_moved', $kinds);
        $run2 = $this->mkRun('2026-10', [$this->emp('Ann Lee', 2000, [['2026-10-15', 0]])]);
        $f = PayrollRunChecks::check($run2, $prior, []);
        $this->assertSame('no_hours', $f[0]['kind']);
        $this->assertSame('zero_hours', $f[1]['kind']);                                       // unknown hours first
        $this->assertStringContainsString('no hours in 2026-10 after 80 h in 2026-09', $f[1]['text']);
    }

    public function testOvernightPunchesSplitByDayAndAccentedNamesMatch(): void
    {
        // 9 pm – 7 am (10 h) + an 8 h day shift the next day: no single day reaches 16 h, and both days count toward a streak
        $entries = [['clock_in' => '2026-10-01 21:00:00', 'clock_out' => '2026-10-02 07:00:00', 'total_minutes' => 600],
                    ['clock_in' => '2026-10-02 09:00:00', 'clock_out' => '2026-10-02 17:00:00', 'total_minutes' => 480]];
        $this->assertSame([], PayrollRunChecks::punchFlags('Ann Lee', $entries, null));
        $prior = [$this->mkRun('2026-09', [$this->emp('JOSÉ  Álvarez', 2000, [['2026-09-15', 80]], 5)])];
        $run = $this->mkRun('2026-10', [$this->emp('José Álvarez', 2000, [['2026-10-15', 80]], 5)]);
        $kinds = array_column(PayrollRunChecks::check($run, $prior, []), 'kind');
        $this->assertNotContains('new_person', $kinds);
        $this->assertNotContains('missing_person', $kinds);
    }

    public function testStreakSurvivesTheClockChange(): void
    {
        // Nov 1 2026 is the fall-back Sunday in BC: seven calendar days across it are still seven days straight
        $entries = [];
        foreach (['2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04'] as $d) $entries[] = ['clock_in' => "$d 08:00:00", 'clock_out' => "$d 12:00:00", 'total_minutes' => 240];
        $old = date_default_timezone_get(); date_default_timezone_set('America/Vancouver');
        try { $kinds = array_column(PayrollRunChecks::punchFlags('Ann Lee', $entries, null), 'kind'); } finally { date_default_timezone_set($old); }
        $this->assertSame(['streak'], $kinds);
    }

    public function testPunchFlagsAreCappedPerPerson(): void
    {
        $entries = [];
        foreach (range(1, 6) as $d) $entries[] = ['clock_in' => sprintf('2026-10-%02d 07:00:00', $d), 'clock_out' => sprintf('2026-10-%02d 23:30:00', $d), 'total_minutes' => 990];
        $run = $this->mkRun('2026-10', [$this->emp('Ann Lee', 2000, [['2026-10-15', 80]])]);
        $f = PayrollRunChecks::check($run, [], [1 => $entries]);
        $this->assertSame(['shift', 'shift', 'shift', 'punch_more'], array_column($f, 'kind'));
        $this->assertStringContainsString('9 more clock punches worth a look', $f[3]['text']);   // 6 shifts + 6 days − 3 shown
    }

    public function testDayOverSixteenAndSevenDaysStraight(): void
    {
        $entries = [];
        foreach (range(1, 7) as $d) $entries[] = ['clock_in' => sprintf('2026-10-%02d 07:00:00', $d), 'clock_out' => sprintf('2026-10-%02d 15:00:00', $d), 'total_minutes' => 480];
        $entries[] = ['clock_in' => '2026-10-03 15:30:00', 'clock_out' => '2026-10-04 00:30:00', 'total_minutes' => 540];   // day 3 → 8 + 8.5 = 16.5 h (the half hour after midnight is day 4's), no single shift > 12
        $f = PayrollRunChecks::punchFlags('Ann Lee', $entries, null);
        $kinds = array_column($f, 'kind');
        $this->assertSame(['day', 'streak'], $kinds);
        $this->assertStringContainsString('16.5 h clocked on Sat Oct 3', $f[0]['text']);
        $this->assertStringContainsString('7 days straight from Thu Oct 1', $f[1]['text']);
        // a gap breaks the streak (day 5 off; day 4 still has the overnight spill, so it must be a later day)
        unset($entries[4]);
        $this->assertSame(['day'], array_column(PayrollRunChecks::punchFlags('Ann Lee', array_values($entries), null), 'kind'));
    }
}
