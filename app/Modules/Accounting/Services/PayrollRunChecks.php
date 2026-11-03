<?php
declare(strict_types=1);

/**
 * PayrollRunChecks — what a careful bookkeeper would ask before booking a Wave payroll month.
 *
 * Harvested from Anthropic's small-business plugin (payroll-prep anomaly rules, 2026-11-03) and fitted to what the
 * Wave "Wages & taxes" report and the CRM time clock actually hold. Every flag names who, when, both numbers and the
 * dollar impact where it is known ("unknown" otherwise); flags are grouped by person, unknown hours first. Nothing
 * here fills a punch, changes a figure or blocks booking — it is read on the payroll import page.
 *
 *   OT above the person's own pattern   OT this run > 125% of their average over the last 4 paydays on file, or any OT
 *                                       for someone whose last paydays had none (history = imported runs only)
 *   Wages moved                         a person's wages, or the run total, moved more than 10% from the prior run
 *   Rate looks changed                  implied rate (wages ÷ weighted hours) moved more than 2% from the prior run
 *   Zero hours / missing / new          zero Wave hours for someone on the prior run; on the prior run but not this one; new
 *   Shift over 12 h                     any single clock entry — fires on a first run with nothing to compare against
 *   Day over 16 h                       clocked minutes in one calendar day (almost always a punch error)
 *   Seven days straight                 clocked time on 7 consecutive days inside the run's period
 *   Hours against schedule              not checked: the CRM has no per-person scheduled-hours figure yet
 *
 * Pure and static. The import service gathers the inputs.
 */
final class PayrollRunChecks
{
    public const OT_FACTOR = 1.25;
    public const OT_HISTORY_PAYDAYS = 4;
    public const WAGES_MOVE = 0.10;
    public const RATE_MOVE = 0.02;
    public const SHIFT_HOURS = 12.0;
    public const DAY_HOURS = 16.0;
    public const STREAK_DAYS = 7;

    /**
     * @param array $run       loaded run: month, employees[] {name, user_id, totals{wages}, paydays[] {payday, regular, overtime, double_time}}
     * @param array $priorRuns earlier loaded runs, oldest first (same shape); [] on a first run
     * @param array $shifts    user_id => clock entries {clock_in, clock_out, total_minutes} inside the run's period
     * @param array $rates     user_id => hourly rate (users.hourly_rate), for dollar impact
     * @return array<int,array{person:string, kind:string, text:string, impact:?float}>
     */
    public static function check(array $run, array $priorRuns, array $shifts, array $rates = []): array
    {
        $flags = [];
        $prior = $priorRuns ? end($priorRuns) : null;
        $priorBy = [];
        if ($prior) foreach ($prior['employees'] as $e) $priorBy[self::key($e['name'])] = $e;
        $historyOt = [];   // person key => OT hours per payday, oldest first, across all prior runs
        foreach ($priorRuns as $pr) foreach ($pr['employees'] as $e) foreach ($e['paydays'] as $p) $historyOt[self::key($e['name'])][] = (float)$p['overtime'];

        $seen = [];
        foreach ($run['employees'] as $e) {
            $k = self::key($e['name']); $seen[$k] = true;
            $name = (string)$e['name'];
            $uid = $e['user_id'] ?? null;
            $rate = $uid !== null && isset($rates[$uid]) ? (float)$rates[$uid] : null;
            $wages = (float)($e['totals']['wages'] ?? 0);
            $reg = array_sum(array_column($e['paydays'], 'regular'));
            $ot = array_sum(array_column($e['paydays'], 'overtime'));
            $dt = array_sum(array_column($e['paydays'], 'double_time'));
            $weighted = $reg + 1.5 * $ot + 2 * $dt;
            $hours = $reg + $ot + $dt;
            $month = (string)($run['month'] ?? '');

            // Unknown hours first: a person on the report with no hours at all
            if ($hours <= 0 && $wages > 0) {
                $flags[] = ['person' => $name, 'kind' => 'no_hours', 'impact' => $wages,
                    'text' => "$name: $" . number_format($wages, 2) . " of wages in $month with no hours on the report — salary, a correction, or a report gap?"];
            }
            // OT against the person's own pattern
            $hist = $historyOt[$k] ?? [];
            if ($ot > 0 && $hist) {
                $last = array_slice($hist, -self::OT_HISTORY_PAYDAYS);
                $avg = array_sum($last) / count($last);
                $perPayday = count($e['paydays']) ? $ot / count($e['paydays']) : $ot;
                $impact = $rate !== null ? round($ot * $rate * 1.5, 2) : null;
                if ($avg <= 0) {
                    $flags[] = ['person' => $name, 'kind' => 'overtime', 'impact' => $impact,
                        'text' => "$name: " . self::h($ot) . " h overtime in $month; their last " . count($last) . " payday" . (count($last) === 1 ? '' : 's') . " had none" . self::money($impact, ' at time-and-a-half')];
                } elseif ($perPayday > self::OT_FACTOR * $avg) {
                    $flags[] = ['person' => $name, 'kind' => 'overtime', 'impact' => $impact,
                        'text' => "$name: " . self::h($perPayday) . " h overtime per payday in $month against their usual " . self::h($avg) . " h (last " . count($last) . " paydays)" . self::money($impact, ' at time-and-a-half')];
                }
            }
            // Wages and implied rate against the prior run
            if (isset($priorBy[$k])) {
                $pe = $priorBy[$k];
                $pw = (float)($pe['totals']['wages'] ?? 0);
                if ($pw > 0 && abs($wages - $pw) / $pw > self::WAGES_MOVE) {
                    $flags[] = ['person' => $name, 'kind' => 'wages_moved', 'impact' => round($wages - $pw, 2),
                        'text' => "$name: wages $" . number_format($wages, 2) . " in $month against $" . number_format($pw, 2) . " in " . ($prior['month'] ?? 'the prior run') . " (" . self::pct(($wages - $pw) / $pw) . ")"];
                }
                $pReg = array_sum(array_column($pe['paydays'], 'regular')); $pOt = array_sum(array_column($pe['paydays'], 'overtime')); $pDt = array_sum(array_column($pe['paydays'], 'double_time'));
                $pWeighted = $pReg + 1.5 * $pOt + 2 * $pDt;
                if ($weighted > 0 && $pWeighted > 0) {
                    $r1 = $wages / $weighted; $r0 = $pw / $pWeighted;
                    if ($r0 > 0 && abs($r1 - $r0) / $r0 > self::RATE_MOVE) {
                        $flags[] = ['person' => $name, 'kind' => 'rate_changed', 'impact' => round(($r1 - $r0) * $weighted, 2),
                            'text' => "$name: pay works out to $" . number_format($r1, 2) . "/h in $month against $" . number_format($r0, 2) . "/h before — a rate change, or vacation pay in the wages?"];
                    }
                }
                if ($hours <= 0 && ($pReg + $pOt + $pDt) > 0) {
                    $flags[] = ['person' => $name, 'kind' => 'zero_hours', 'impact' => null,
                        'text' => "$name: no hours in $month after " . self::h($pReg + $pOt + $pDt) . " h in " . ($prior['month'] ?? 'the prior run') . " — a missing timesheet, or did they leave?"];
                }
            } elseif ($prior) {
                $flags[] = ['person' => $name, 'kind' => 'new_person', 'impact' => $wages,
                    'text' => "$name is new on this run ($" . number_format($wages, 2) . ") — not on " . ($prior['month'] ?? 'the prior run')];
            }
            // Clock punches inside the period
            foreach (self::punchFlags($name, $shifts[$uid] ?? [], $rate) as $f) $flags[] = $f;
        }
        if ($prior) {
            foreach ($prior['employees'] as $pe) {
                if (isset($seen[self::key($pe['name'])])) continue;
                $flags[] = ['person' => (string)$pe['name'], 'kind' => 'missing_person', 'impact' => null,
                    'text' => $pe['name'] . " was paid in " . ($prior['month'] ?? 'the prior run') . " and is not on this run — left, or a missed timesheet?"];
            }
            $tot = (float)($run['totals']['wages'] ?? 0); $ptot = (float)($prior['totals']['wages'] ?? 0);
            if ($ptot > 0 && $tot > 0 && abs($tot - $ptot) / $ptot > self::WAGES_MOVE) {
                $flags[] = ['person' => '', 'kind' => 'run_moved', 'impact' => round($tot - $ptot, 2),
                    'text' => "The run's gross is $" . number_format($tot, 2) . " against $" . number_format($ptot, 2) . " in " . ($prior['month'] ?? 'the prior run') . " (" . self::pct(($tot - $ptot) / $ptot) . ")"];
            }
        }
        // Group by person, unknown-hours flags first, run-level last
        $order = ['no_hours' => 0, 'zero_hours' => 0, 'missing_person' => 1, 'shift' => 2, 'day' => 2, 'streak' => 2, 'overtime' => 3, 'wages_moved' => 4, 'rate_changed' => 4, 'new_person' => 5, 'run_moved' => 9];
        usort($flags, static function (array $a, array $b) use ($order): int {
            $pa = $a['person'] === '' ? "\xff" : $a['person']; $pb = $b['person'] === '' ? "\xff" : $b['person'];
            return strcmp($pa, $pb) ?: (($order[$a['kind']] ?? 8) <=> ($order[$b['kind']] ?? 8));
        });
        return $flags;
    }

    /** Pure: the three punch rules for one person's clock entries. */
    public static function punchFlags(string $name, array $entries, ?float $rate): array
    {
        $flags = [];
        $byDay = [];
        foreach ($entries as $c) {
            $in = (string)$c['clock_in'];
            $min = $c['total_minutes'] !== null && $c['total_minutes'] !== '' ? (int)$c['total_minutes']
                 : (!empty($c['clock_out']) ? (int)round((strtotime((string)$c['clock_out']) - strtotime($in)) / 60) : 0);
            $day = substr($in, 0, 10);
            $byDay[$day] = ($byDay[$day] ?? 0) + max(0, $min);
            if ($min / 60 > self::SHIFT_HOURS) {
                $hrs = round($min / 60, 1);
                $flags[] = ['person' => $name, 'kind' => 'shift', 'impact' => $rate !== null ? round(($hrs - 8) * $rate, 2) : null,
                    'text' => "$name: a " . $hrs . " h shift on " . date('D M j', strtotime($in)) . " (" . date('g:i a', strtotime($in))
                            . (!empty($c['clock_out']) ? ' to ' . date('g:i a', strtotime((string)$c['clock_out'])) : '') . ") — real long day, or a clock-out they forgot?"];
            }
        }
        foreach ($byDay as $day => $min) {
            if ($min / 60 > self::DAY_HOURS) {
                $flags[] = ['person' => $name, 'kind' => 'day', 'impact' => null,
                    'text' => "$name: " . round($min / 60, 1) . " h clocked on " . date('D M j', strtotime($day)) . " across their entries — almost always a punch error"];
            }
        }
        ksort($byDay);
        $streak = 0; $prev = null; $start = null;
        foreach (array_keys($byDay) as $day) {
            if ($byDay[$day] <= 0) continue;
            if ($prev !== null && (strtotime($day) - strtotime($prev)) === 86400) { $streak++; } else { $streak = 1; $start = $day; }
            $prev = $day;
            if ($streak === self::STREAK_DAYS) {
                $flags[] = ['person' => $name, 'kind' => 'streak', 'impact' => null,
                    'text' => "$name: clocked in " . self::STREAK_DAYS . " days straight from " . date('D M j', strtotime((string)$start)) . " — fatigue, and check the week's overtime"];
            }
        }
        return $flags;
    }

    private static function key(string $name): string { return strtolower(preg_replace('/\s+/', ' ', trim($name))); }
    private static function h(float $v): string { return rtrim(rtrim(number_format($v, 2), '0'), '.'); }
    private static function pct(float $v): string { return ($v > 0 ? '+' : '') . round($v * 100) . '%'; }
    private static function money(?float $v, string $suffix = ''): string { return $v === null ? ' — rate unknown' : ' — about $' . number_format(abs($v), 2) . $suffix; }
}
