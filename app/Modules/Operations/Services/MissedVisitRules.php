<?php
declare(strict_types=1);

/**
 * MissedVisitRules — Otto's pick for a visit that was not done (the end-of-day missed-visit
 * review, migration 1349). PURE: no database, no clock. MissedVisitReviewService gathers the
 * facts (plan, next visit, the days ahead) and stores what this returns.
 *
 * The picks:
 *   tomorrow — tomorrow is a working day for the crew, the plan has nothing then, the city allows
 *              the work, it isn't forecast wet, and it fits the crew's route (stops nearby) — or,
 *              with no route data that day, it is simply the crew's next working day.
 *   date     — the first later day that fits (route first, then dry), before the plan's next visit.
 *   skip     — the plan's next visit is close (≤ skipWithinDays(): 3 days, or half the plan's
 *              frequency when that is longer), so doing this one too would double the work.
 *              NEVER for a contract visit, a prepaid visit, or a one-off job (skipBlock()).
 *   null     — nothing fits: "you decide". Cancel is never suggested, only offered.
 */
final class MissedVisitRules
{
    public const SKIP_WITHIN_DAYS = 3;    // "the next one is within 3 days" → skip
    public const WET_MM = 2.0;            // a day forecast at ≥ 2 mm is wet
    public const LOOKAHEAD_DAYS = 10;     // how far "another day" looks when the plan has no next visit
    public const ROUTE_KM = 3.0;          // a stop within 3 km on that day = it fits the route

    /** Skip/cancel reasons offered on every screen. */
    public const REASONS = [
        'weather' => 'Weather',
        'client'  => 'Client asked',
        'access'  => 'No access',
        'other'   => 'Other',
    ];

    /** The plan's frequency in days, or null for a one-off / unknown. */
    public static function freqDays(?string $pattern, $interval, ?string $unit, ?string $daysOfWeek = null): ?int
    {
        $n = max(1, (int)($interval ?: 1));
        $unit = strtolower((string)$unit);
        $pattern = strtolower((string)$pattern);
        if ($unit === '' && $pattern === '') return null;
        if ($unit === '') {
            $unit = ['weekly' => 'weeks', 'biweekly' => 'weeks', 'monthly' => 'months', 'yearly' => 'years'][$pattern] ?? 'weeks';
            if ($pattern === 'biweekly') $n = max(2, $n);
        }
        $days = ['days' => 1, 'weeks' => 7, 'months' => 30, 'years' => 365][$unit] ?? 7;
        $freq = $n * $days;
        // Several weekdays a week ("1,3,5"): the gap between them, roughly.
        $dows = array_filter(array_map('trim', explode(',', (string)$daysOfWeek)), fn($d) => $d !== '');
        if ($unit === 'weeks' && count($dows) > 1) {
            $freq = max(1, intdiv($freq, count($dows)));
        }
        return $freq;
    }

    /** Within how many days of the next visit Otto suggests skipping instead of moving. */
    public static function skipWithinDays(?int $freqDays): int
    {
        return max(self::SKIP_WITHIN_DAYS, $freqDays ? intdiv($freqDays, 2) : 0);
    }

    /**
     * Why skipping is never suggested for this visit, or null when it may be.
     * $v: is_recurring, contract_number (an ACTIVE contract's number, else null), is_prepaid_bundle.
     */
    public static function skipBlock(array $v): ?string
    {
        if (empty($v['is_recurring'])) return 'One-off job: the client is owed this visit.';
        if (!empty($v['contract_number'])) return 'Contract visit (' . $v['contract_number'] . '): owed under the contract.';
        if (!empty($v['is_prepaid_bundle'])) return 'Prepaid: the client already paid for this visit.';
        return null;
    }

    /** Whole days from $a to $b (Y-m-d). */
    public static function daysBetween(string $a, string $b): int
    {
        return (int)round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400);
    }

    /** "Wed Oct 14". */
    public static function dayLabel(string $date): string
    {
        return date('D M j', strtotime($date . ' 12:00:00'));
    }

    /**
     * Why this day can't take the visit, or null when it can.
     * $d: rest (Sunday / BC holiday), crew_works (?bool: null = no schedule on file), plan_visit
     * (the plan already has a live visit that day), bylaw_ok.
     */
    public static function dayBlock(array $d, bool $everyDay): ?string
    {
        if (!$everyDay && !empty($d['rest'])) return 'Sunday or holiday';
        if (!$everyDay && ($d['crew_works'] ?? null) === false) return 'crew day off';
        if (!empty($d['plan_visit'])) return 'already has a visit';
        if (array_key_exists('bylaw_ok', $d) && $d['bylaw_ok'] === false) return 'city hours';
        return null;
    }

    /**
     * Otto's pick.
     *
     * @param array  $v     visit facts: is_recurring, contract_number, is_prepaid_bundle, freq_days,
     *                      next_visit (Y-m-d|null — the plan's next live visit after this one),
     *                      done_since (Y-m-d|null — a completed visit of the plan after this one was missed),
     *                      every_day (snow/salt: Sundays and holidays are fine), crew_name
     * @param string $today the review day (Y-m-d)
     * @param array  $days  date => [rest, crew_works, plan_visit, bylaw_ok, wet (?bool), wet_mm, route (?bool), route_n]
     *                      for the days after $today, ascending
     * @return array{pick: ?string, date: ?string, reason: string, skip_allowed: bool, skip_block: ?string}
     */
    public static function pick(array $v, string $today, array $days): array
    {
        $block = self::skipBlock($v);
        $out = ['pick' => null, 'date' => null, 'reason' => '', 'skip_allowed' => $block === null, 'skip_block' => $block];
        $next = $v['next_visit'] ?? null;
        $gap = $next ? self::daysBetween($today, (string)$next) : null;
        $within = self::skipWithinDays(isset($v['freq_days']) ? (int)$v['freq_days'] : null);
        $crew = trim((string)($v['crew_name'] ?? '')) ?: 'the crew';
        $everyDay = !empty($v['every_day']);

        // 1. The plan was already done again since this visit was missed: skip (never for contracts).
        $doneSince = $v['done_since'] ?? null;
        if ($block === null && $doneSince) {
            $out['pick'] = 'skip';
            $out['reason'] = 'Done since, on ' . self::dayLabel((string)$doneSince) . ': doing this one too would double up.';
            return $out;
        }

        // 2. The next regular visit is close: skip, so the work isn't done twice.
        if ($block === null && $gap !== null && $gap <= $within) {
            $out['pick'] = 'skip';
            $out['reason'] = 'Next visit is ' . self::dayLabel((string)$next)
                . ($gap <= 0 ? ' (today)' : ' (in ' . $gap . ' day' . ($gap === 1 ? '' : 's') . ')')
                . ': doing this one too would double up.';
            return $out;
        }

        // Days it may go to: never on or past the next visit (that would double up too).
        ksort($days);
        $usable = [];
        $whyNot = [];
        foreach ($days as $date => $d) {
            if ($next && $date >= $next) break;
            $b = self::dayBlock($d, $everyDay);
            if ($b === null) $usable[$date] = $d; else $whyNot[$date] = $b;
        }
        $tomorrow = date('Y-m-d', strtotime($today . ' 12:00:00 +1 day'));

        // 3. Tomorrow, when it fits.
        if (isset($usable[$tomorrow])) {
            $d = $usable[$tomorrow];
            if (($d['wet'] ?? null) !== true && ($d['route'] ?? null) !== false) {
                $out['pick'] = 'tomorrow';
                $out['date'] = $tomorrow;
                $out['reason'] = ($d['route'] ?? null) === true
                    ? 'Tomorrow ' . $crew . ' is already working nearby (' . (int)($d['route_n'] ?? 1) . ' stop' . ((int)($d['route_n'] ?? 1) === 1 ? '' : 's') . ' within ' . (int)self::ROUTE_KM . ' km)'
                    : 'Tomorrow is ' . $crew . "'s next working day";
                $out['reason'] .= ($d['wet'] ?? null) === false ? ' and it looks dry.' : '.';
                return $out;
            }
        }

        // 4. Another day: on the route and dry; then dry; then any day that fits.
        $why = '';
        if (isset($usable[$tomorrow])) {
            $t = $usable[$tomorrow];
            $why = ($t['wet'] ?? null) === true
                ? 'Tomorrow looks wet' . (isset($t['wet_mm']) ? ' (' . rtrim(rtrim(number_format((float)$t['wet_mm'], 1), '0'), '.') . ' mm)' : '') . '. '
                : 'Tomorrow ' . $crew . ' is working elsewhere. ';
        } elseif (isset($whyNot[$tomorrow])) {
            $why = 'Tomorrow: ' . $whyNot[$tomorrow] . '. ';
        }
        $passes = [
            fn($d) => ($d['route'] ?? null) === true && ($d['wet'] ?? null) !== true,
            fn($d) => ($d['wet'] ?? null) !== true && ($d['route'] ?? null) !== false,
            fn($d) => ($d['wet'] ?? null) !== true,
            fn($d) => true,
        ];
        foreach ($passes as $ok) {
            foreach ($usable as $date => $d) {
                if (!$ok($d)) continue;
                $out['pick'] = $date === $tomorrow ? 'tomorrow' : 'date';
                $out['date'] = $date;
                if ($date === $tomorrow) {
                    $out['reason'] = 'Tomorrow: ' . (($d['wet'] ?? null) === true
                        ? 'forecast wet, but no better day before the next visit.'
                        : $crew . ' is working elsewhere, but it is the first free day' . (($d['wet'] ?? null) === false ? ' that looks dry.' : '.'));
                    return $out;
                }
                $fits = ($d['route'] ?? null) === true ? $crew . ' works nearby' : (($d['route'] ?? null) === null ? $crew . ' is working' : 'a free day');
                $wet = ($d['wet'] ?? null) === true ? ' (forecast wet, but nothing better before the next visit)'
                     : (($d['wet'] ?? null) === false ? ' and it looks dry' : '');
                $out['reason'] = $why . self::dayLabel($date) . ' fits: ' . $fits . $wet . '.';
                return $out;
            }
        }

        // 5. Nothing fits.
        $until = $next ? ' before the next visit on ' . self::dayLabel((string)$next) : ' in the next ' . count($days) . ' days';
        if ($block === null) {
            $out['pick'] = 'skip';
            $out['reason'] = 'No working day fits' . $until . '.';
            return $out;
        }
        $out['reason'] = 'No day fits' . $until . '. ' . $block . ' You decide.';
        return $out;
    }

    /**
     * Should the day's review open now? PURE.
     * $hhmm local time "H:i"; $clockedToday people who clocked in today; $onClock still clocked in;
     * $minsSinceLastOut minutes since the last clock-out today (null = none).
     * Returns 'last_clock_out' | 'six_pm' | null.
     */
    public static function openTrigger(string $hhmm, int $clockedToday, int $onClock, ?int $minsSinceLastOut, string $sixPm = '18:00'): ?string
    {
        if ($hhmm >= $sixPm) return 'six_pm';
        // Everyone who worked today has clocked out, and has stayed out for 20 minutes (not a lunch
        // clock-out) — and not before noon.
        if ($clockedToday > 0 && $onClock === 0 && $minsSinceLastOut !== null && $minsSinceLastOut >= 20 && $hhmm >= '12:00') {
            return 'last_clock_out';
        }
        return null;
    }

    /**
     * What the nightly auto_rollover does with one past-due visit. PURE.
     * Before 2026-10-13 it skipped silently when the plan had ended, after max_rollover_days, or when
     * the next day already had a visit — contract visits included (CTR-0002/0004/0005/0007… on
     * Oct 5–11). Now a protected visit (binding contract, prepaid) is held: left open, not moved,
     * and the next missed-visit review shows it to a person.
     *
     * $v: plan_end_date, rollover_count, contract_binds (bool), is_prepaid_bundle
     * @return array{action: string, why: string}  action: roll | skip | hold
     */
    public static function rolloverAction(array $v, string $nextDay, int $maxRollover, bool $nextDayTaken): array
    {
        $protected = !empty($v['contract_binds']) || !empty($v['is_prepaid_bundle']);
        $why = null;
        if (!empty($v['plan_end_date']) && $nextDay > (string)$v['plan_end_date']) $why = 'plan ended on ' . $v['plan_end_date'];
        elseif ((int)($v['rollover_count'] ?? 0) >= $maxRollover) $why = 'exceeded max rollover of ' . $maxRollover . ' days';
        elseif ($nextDayTaken) $why = 'next occurrence already scheduled for ' . $nextDay;
        if ($why === null) return ['action' => 'roll', 'why' => ''];
        if ($protected) return ['action' => 'hold', 'why' => $why . '; ' . (!empty($v['contract_binds']) ? 'contract' : 'prepaid') . ' visit left open for Otto\'s review'];
        return ['action' => 'skip', 'why' => $why];
    }

    /** The answer as a comparable value: "skip", "cancel", "move:2026-10-14"; null = none. */
    public static function answerKey(?string $decisionOrPick, ?string $date): ?string
    {
        $k = strtolower((string)$decisionOrPick);
        if ($k === '') return null;
        if ($k === 'tomorrow' || $k === 'date' || $k === 'move') return 'move:' . (string)$date;
        return $k;
    }

    /** One line for the screens: "Tomorrow (Wed Oct 14)", "Skip", "You decide". */
    public static function pickLabel(?string $pick, ?string $date): string
    {
        if ($pick === 'tomorrow') return 'Tomorrow' . ($date ? ' (' . self::dayLabel($date) . ')' : '');
        if ($pick === 'date') return $date ? self::dayLabel($date) : 'Another day';
        if ($pick === 'skip') return 'Skip this one';
        return 'You decide';
    }
}
