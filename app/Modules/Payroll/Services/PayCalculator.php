<?php
/**
 * PayCalculator — approved hours → gross pay, line by line, in cents (Penny Payroll step 2, 2026-10-21).
 *
 * PURE: no database. The caller gives the period's paid minutes per day (approved time only, breaks already
 * taken off), the days of the same Sunday week that fall before the period (already paid; they only count
 * toward that week's 40 h), the 30-day wage history the stat-holiday rule needs, and the employee's rate,
 * hire date and vacation method. Rules come from app/Modules/Payroll/Data/esa-<PROV>.php.
 *
 * BC (ESA, read on gov.bc.ca 2026-10-21):
 *  - daily: over 8 h 1.5×, over 12 h 2×; weekly: over 40 h 1.5×, counting only the first 8 h of each day; the
 *    week runs Sunday–Saturday, so a period that starts mid-week counts the earlier days of that week. Weekly
 *    overtime falls on the day the 40 h is passed (the hours after it), never re-priced days already at 1.5×+.
 *  - a statutory holiday: eligible after 30 days employed and wages earned on 15 of the 30 days before; pay =
 *    those 30 days' wages (overtime excluded) ÷ days wages were earned. Working it: 1.5× (2× over 12 h) plus
 *    that pay. Its hours count toward the week; the greater rate applies, never both. Not eligible: the hours
 *    are paid as an ordinary day.
 *  - vacation pay: 4% of all wages (overtime and stat pay included), 6% on wages from the 5th anniversary;
 *    paid on each cheque (written agreement) or accrued as a liability.
 *  - every day's rate checked against the minimum wage in force that day.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PayCalculator
{
    /**
     * Earnings codes: what each line counts toward. vacationable = earns vacation pay; T4 box 14 for all of these.
     * The deduction step (3) uses taxable / pensionable / insurable; the ROE uses insurable.
     */
    public const CODES = [
        'REG'    => ['label' => 'Regular',                       'taxable' => true, 'pensionable' => true, 'insurable' => true, 'vacationable' => true,  'overtime' => false],
        'OT15'   => ['label' => 'Overtime 1.5×',                 'taxable' => true, 'pensionable' => true, 'insurable' => true, 'vacationable' => true,  'overtime' => true],
        'OT20'   => ['label' => 'Overtime 2×',                   'taxable' => true, 'pensionable' => true, 'insurable' => true, 'vacationable' => true,  'overtime' => true],
        'STAT'   => ['label' => 'Statutory holiday pay',         'taxable' => true, 'pensionable' => true, 'insurable' => true, 'vacationable' => true,  'overtime' => false],
        'STATW15'=> ['label' => 'Worked a statutory holiday 1.5×', 'taxable' => true, 'pensionable' => true, 'insurable' => true, 'vacationable' => true, 'overtime' => true],
        'STATW20'=> ['label' => 'Worked a statutory holiday 2×', 'taxable' => true, 'pensionable' => true, 'insurable' => true, 'vacationable' => true,  'overtime' => true],
        'VAC'    => ['label' => 'Vacation pay',                  'taxable' => true, 'pensionable' => true, 'insurable' => true, 'vacationable' => false, 'overtime' => false],
    ];

    private array $esa;

    public function __construct(string $province = 'BC', ?array $esa = null)
    {
        $province = strtoupper($province);
        if ($esa === null) {
            $file = dirname(__DIR__) . '/Data/esa-' . $province . '.php';
            if (!preg_match('/^[A-Z]{2}$/', $province) || !is_file($file)) throw new RuntimeException("No employment-standards rules for {$province} yet.");
            $esa = require $file;
        }
        $this->esa = $esa;
    }

    /**
     * @param array{period_start:string, period_end:string, rate_cents:int, days:array<string,int>,
     *              prior_days?:array<string,int>, hire_date?:?string, vacation_mode?:string,
     *              history?:array<string,array{wages_cents:int, earned:bool}>} $in
     * @return array{lines:list<array>, gross_cents:int, vacation_accrued_cents:int, vacationable_cents:int,
     *               minutes:array<string,int>, problems:list<string>}
     */
    public function calculate(array $in): array
    {
        $start = (string)$in['period_start']; $end = (string)$in['period_end'];
        foreach ([$start, $end] as $d) if (!self::isDate($d)) throw new InvalidArgumentException('Dates must be YYYY-MM-DD.');
        if ($end < $start) throw new InvalidArgumentException('The period ends before it starts.');
        $rate = (int)$in['rate_cents'];
        if ($rate <= 0) throw new InvalidArgumentException('An hourly rate is needed.');
        $hire = isset($in['hire_date']) && self::isDate((string)$in['hire_date']) ? (string)$in['hire_date'] : null;
        $mode = ($in['vacation_mode'] ?? 'accrue') === 'each_cheque' ? 'each_cheque' : 'accrue';
        $ot = $this->esa['overtime']; $stat = $this->esa['statutory_holidays'];
        $holidays = array_flip($stat['dates']);
        $problems = [];

        // Every day we know about, in order: the prior days of the first week, then the period.
        $all = [];
        foreach ((array)($in['prior_days'] ?? []) as $d => $m) {
            if (!self::isDate($d)) continue;
            if ($d >= $start || self::weekStart($d) !== self::weekStart($start)) throw new InvalidArgumentException("Prior day {$d} is not in the period's first week before the period.");
            $all[$d] = ['m' => max(0, (int)$m), 'in' => false];
        }
        foreach ((array)$in['days'] as $d => $m) {
            if (!self::isDate($d) || $d < $start || $d > $end) throw new InvalidArgumentException("Day {$d} is outside the period.");
            $all[$d] = ['m' => max(0, (int)$m), 'in' => true];
        }
        // Holidays in the period with no hours still need a day (an average day's pay).
        foreach ($stat['dates'] as $h) if ($h >= $start && $h <= $end && !isset($all[$h])) $all[$h] = ['m' => 0, 'in' => true];
        ksort($all);

        $history = (array)($in['history'] ?? []);
        $lines = []; $cum = []; $minutes = ['REG' => 0, 'OT15' => 0, 'OT20' => 0, 'STATW15' => 0, 'STATW20' => 0];
        $nonOtByDay = [];   // in-period wages without overtime, for later holidays' look-back
        $earnedByDay = [];  // in-period days with any wages (a day all past the weekly 40 still counts — Codex 2026-10-23)
        foreach ($all as $d => $day) {
            $m = $day['m'];
            $wk = self::weekStart($d);
            $before = $cum[$wk] ?? 0;
            $cum[$wk] = $before + min($m, $ot['daily_regular_minutes']);
            if (!$day['in']) continue;

            // 1) never below the minimum wage in force that day: pay at least it, and say so (Codex 2026-10-23).
            $min = $this->minimumWage($d);
            $dayRate = $rate;
            if ($min !== null && $rate < $min && $m > 0) {
                $dayRate = $min;
                $problems[] = self::nice($d) . ': $' . number_format($rate / 100, 2) . '/h is below the BC minimum wage — paid at $' . number_format($min / 100, 2) . '/h.';
            }
            $isStat = isset($holidays[$d]);
            $avg = null;
            if ($isStat) {
                $avg = $this->averageDay($d, $hire, $history, $nonOtByDay, $earnedByDay, $stat, $why);
                if ($avg === null) {
                    $problems[] = self::nice($d) . ": not eligible for statutory holiday pay — {$why}.";
                    $isStat = false;
                }
            }
            if ($isStat) {
                $w15 = min($m, $ot['daily_time_and_half_minutes']); $w20 = max(0, $m - $ot['daily_time_and_half_minutes']);
                if ($w15) $lines[] = $this->line('STATW15', $d, $w15, $dayRate, $stat['worked_rate'], self::nice($d) . ': worked a statutory holiday — ' . self::h($w15) . ' at 1.5×');
                if ($w20) $lines[] = $this->line('STATW20', $d, $w20, $dayRate, $stat['worked_rate_over_12'], self::nice($d) . ': ' . self::h($w20) . ' over 12 h on a statutory holiday at 2×');
                $minutes['STATW15'] += $w15; $minutes['STATW20'] += $w20;
                $lines[] = ['code' => 'STAT', 'date' => $d, 'minutes' => 0, 'rate_cents' => $rate, 'multiplier' => 0.0, 'amount_cents' => $avg['cents'],
                            'explain' => self::nice($d) . ": statutory holiday pay — an average day's pay: $" . number_format($avg['wages'] / 100, 2) . " earned in the 30 days before (overtime excluded) ÷ {$avg['days']} days = $" . number_format($avg['cents'] / 100, 2)];
                $nonOtByDay[$d] = $avg['cents'];
                $earnedByDay[$d] = true;
                continue;
            }
            if ($m === 0) continue;

            // Daily split, then the weekly 40 h on the regular part (only the first 8 h of a day ever count).
            $reg = min($m, $ot['daily_regular_minutes']);
            $o15 = min(max(0, $m - $ot['daily_regular_minutes']), $ot['daily_time_and_half_minutes'] - $ot['daily_regular_minutes']);
            $o20 = max(0, $m - $ot['daily_time_and_half_minutes']);
            $cap = $ot['weekly_regular_minutes'];
            $weekly = min($reg, max(0, $cum[$wk] - $cap) - max(0, $before - $cap));
            $reg -= $weekly;
            if ($reg) $lines[] = $this->line('REG', $d, $reg, $dayRate, 1.0, self::nice($d) . ': ' . self::h($reg) . ' regular');
            if ($o15 || $weekly) {
                $parts = [];
                if ($o15) $parts[] = self::h($o15) . ' over 8 h in the day';
                if ($weekly) $parts[] = self::h($weekly) . ' past 40 h in the week (Sunday–Saturday)';
                $lines[] = $this->line('OT15', $d, $o15 + $weekly, $dayRate, 1.5, self::nice($d) . ': ' . implode(' + ', $parts) . ' at 1.5×');
            }
            if ($o20) $lines[] = $this->line('OT20', $d, $o20, $dayRate, 2.0, self::nice($d) . ': ' . self::h($o20) . ' over 12 h in the day at 2×');
            $minutes['REG'] += $reg; $minutes['OT15'] += $o15 + $weekly; $minutes['OT20'] += $o20;
            $nonOtByDay[$d] = (int)round($reg * $dayRate / 60);
            $earnedByDay[$d] = true;
        }

        // Vacation pay on everything earned, 6% on wages from the 5th anniversary.
        $vacationable = 0; $vac = 0.0;
        foreach ($lines as $l) {
            if (!self::CODES[$l['code']]['vacationable']) continue;
            $vacationable += $l['amount_cents'];
            $vac += $l['amount_cents'] * $this->vacationRate($l['date'], $hire);
        }
        $vacCents = (int)round($vac);
        $accrued = 0;
        if ($vacCents > 0) {
            if ($mode === 'each_cheque') {
                $lines[] = ['code' => 'VAC', 'date' => $end, 'minutes' => 0, 'rate_cents' => 0, 'multiplier' => 0.0, 'amount_cents' => $vacCents,
                            'explain' => 'Vacation pay ' . self::vacationWords($hire, $end, $this->esa) . ' of $' . number_format($vacationable / 100, 2) . ', paid on this cheque (written agreement)'];
            } else {
                $accrued = $vacCents;
            }
        }
        $gross = array_sum(array_column($lines, 'amount_cents'));
        return ['lines' => $lines, 'gross_cents' => (int)$gross, 'vacation_accrued_cents' => $accrued,
                'vacationable_cents' => $vacationable, 'minutes' => $minutes, 'problems' => $problems];
    }

    /** An average day's pay for a holiday, or null with $why (not eligible). */
    private function averageDay(string $h, ?string $hire, array $history, array $inPeriod, array $earnedIn, array $stat, ?string &$why): ?array
    {
        $why = '';
        $from = date('Y-m-d', strtotime($h . ' -' . $stat['lookback_days'] . ' days'));
        if ($hire === null || $hire > date('Y-m-d', strtotime($h . ' -' . $stat['min_employed_days'] . ' days'))) {
            $why = 'employed less than 30 days';
            return null;
        }
        $wages = 0; $days = 0;
        for ($d = $from; $d < $h; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (isset($earnedIn[$d]) || isset($inPeriod[$d])) { $c = (int)($inPeriod[$d] ?? 0); $earned = !empty($earnedIn[$d]); }
            else { $c = (int)($history[$d]['wages_cents'] ?? 0); $earned = !empty($history[$d]['earned']); }
            $wages += $c;
            if ($earned) $days++;
        }
        if ($days < $stat['min_days_earned']) { $why = "wages earned on {$days} of the 30 days before (15 needed)"; return null; }
        return ['cents' => (int)round($wages / $days), 'wages' => $wages, 'days' => $days];
    }

    private function vacationRate(string $date, ?string $hire): float
    {
        $v = $this->esa['vacation'];
        if ($hire !== null && $date >= date('Y-m-d', strtotime($hire . ' +' . $v['rate_after_years'] . ' years'))) return (float)$v['rate_after'];
        return (float)$v['rate'];
    }

    private static function vacationWords(?string $hire, string $end, array $esa): string
    {
        $v = $esa['vacation'];
        $five = $hire !== null && $end >= date('Y-m-d', strtotime($hire . ' +' . $v['rate_after_years'] . ' years'));
        return $five ? '(6% from the 5th anniversary)' : '(4%)';
    }

    private function minimumWage(string $date): ?int
    {
        $c = null;
        foreach ($this->esa['minimum_wage'] as $k => $row) {
            if (!is_int($k)) continue;
            if ($date >= $row['from']) $c = (int)$row['cents'];
        }
        return $c;
    }

    private function line(string $code, string $date, int $minutes, int $rate, float $mult, string $explain): array
    {
        return ['code' => $code, 'date' => $date, 'minutes' => $minutes, 'rate_cents' => $rate, 'multiplier' => $mult,
                'amount_cents' => (int)round($minutes * $rate * $mult / 60), 'explain' => $explain];
    }

    /** The Sunday that starts $d's overtime week. */
    public static function weekStart(string $d): string
    {
        $ts = strtotime($d);
        return date('Y-m-d', strtotime('-' . (int)date('w', $ts) . ' days', $ts));
    }

    private static function isDate(string $d): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);   // 2026-02-31 is refused, not rolled into March
    }

    private static function nice(string $d): string
    {
        return date('D M j', strtotime($d));
    }

    private static function h(int $m): string
    {
        $h = intdiv($m, 60); $r = $m % 60;
        return $r ? ($h ? "{$h} h {$r} min" : "{$r} min") : "{$h} h";
    }
}
