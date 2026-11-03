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
        $run = $this->mkRun('2026-10', [$this->emp('Ann Lee', 2400, [['2026-10-15', 80]]),      // +20% wages, implied rate 25 → 30
                                      $this->emp('Dee Ng', 900, [['2026-10-15', 40]], 4)]);    // new; Cy missing
        $kinds = array_count_values(array_column(PayrollRunChecks::check($run, $prior, []), 'kind'));
        ksort($kinds);
        $this->assertSame(['missing_person' => 1, 'new_person' => 1, 'rate_changed' => 1, 'wages_moved' => 1], $kinds);
        $texts = implode("\n", array_column(PayrollRunChecks::check($run, $prior, []), 'text'));
        $this->assertStringContainsString('Ann Lee: wages $2,400.00 in 2026-10 against $2,000.00 in 2026-09 (+20%)', $texts);
        $this->assertStringContainsString('pay works out to $30.00/h in 2026-10 against $25.00/h before', $texts);
        $this->assertStringContainsString('Cy Fox was paid in 2026-09 and is not on this run', $texts);
        // run total 3300 vs 3500 = −6%: no run flag
        $this->assertArrayNotHasKey('run_moved', $kinds);
        $run2 = $this->mkRun('2026-10', [$this->emp('Ann Lee', 2000, [['2026-10-15', 0]])]);
        $f = PayrollRunChecks::check($run2, $prior, []);
        $this->assertSame('no_hours', $f[0]['kind']);
        $this->assertSame('zero_hours', $f[1]['kind']);                                       // unknown hours first
        $this->assertStringContainsString('no hours in 2026-10 after 80 h in 2026-09', $f[1]['text']);
    }

    public function testDayOverSixteenAndSevenDaysStraight(): void
    {
        $entries = [];
        foreach (range(1, 7) as $d) $entries[] = ['clock_in' => sprintf('2026-10-%02d 07:00:00', $d), 'clock_out' => sprintf('2026-10-%02d 15:00:00', $d), 'total_minutes' => 480];
        $entries[] = ['clock_in' => '2026-10-03 16:00:00', 'clock_out' => '2026-10-04 01:30:00', 'total_minutes' => 570];   // day 3 → 17.5 h total, no single shift > 12
        $f = PayrollRunChecks::punchFlags('Ann Lee', $entries, null);
        $kinds = array_column($f, 'kind');
        $this->assertSame(['day', 'streak'], $kinds);
        $this->assertStringContainsString('17.5 h clocked on Sat Oct 3', $f[0]['text']);
        $this->assertStringContainsString('7 days straight from Thu Oct 1', $f[1]['text']);
        // a gap breaks the streak
        unset($entries[3]);
        $this->assertSame(['day'], array_column(PayrollRunChecks::punchFlags('Ann Lee', array_values($entries), null), 'kind'));
    }
}
