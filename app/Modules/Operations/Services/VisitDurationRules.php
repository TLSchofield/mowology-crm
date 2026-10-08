<?php
/**
 * VisitDurationRules — Otto: how long a lawn cut really takes, from the job timers.
 *
 * Pure functions only (unit tested). VisitDurationService loads the rows and applies changes.
 *
 * Per visit (job_time_entries, void excluded):
 *   person-minutes  every person's timer added up (two people for 30 min = 60)
 *   crew-minutes    the time the crew was on site: the union of the timers (two people for 30 min = 30)
 *   The truck login (device_type 'truck') auto-starts timers too (owner decision 2026-10-06: it is the
 *   cost-record backup). It never counts as a person; its timer is used only when no person timed it.
 *
 * Plans are scheduled on crew-minutes (job_plans.estimated_duration_minutes is a slot on the day),
 * so that is what the proposal compares. Person-minutes are shown alongside for costing.
 *
 * Dropped from the median, with the reason kept for the review:
 *   auto_stopped    a timer the system stopped with no sign the crew left (no end GPS on the timer,
 *                   no departure GPS on the visit) — the night cron, not the crew
 *   under_5         less than MIN_MINUTES (a tap and a stop)
 *   over_3x         more than OUTLIER_X × the median of the rest (timer left running)
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class VisitDurationRules
{
    public const SAMPLES          = 8;
    public const MIN_MINUTES      = 5;
    public const OUTLIER_X        = 3.0;
    public const MIN_TO_PROPOSE   = 3;
    public const CONFIDENT_SAMPLES = 5;
    /** (p75 − p25) / median at or under this = a stable spread. */
    public const STABLE_SPREAD    = 0.35;
    public const MIN_CHANGE_MIN   = 5;
    public const MIN_CHANGE_PCT   = 0.15;
    public const ROUND_TO         = 5;
    public const COVERAGE_DAYS    = 60;
    /** Recurring plans whose service or title reads like lawn work. */
    public const LAWN_PATTERN     = '/lawn|mow|grass|turf/i';

    /**
     * One visit's minutes from its timer entries.
     * @param list<array{user_id: int, truck: bool, start: int, end: ?int, duration: ?int, auto_stopped: bool, end_gps: bool}> $entries
     * @param bool $visitDeparture the visit has departure GPS
     * @return array{person_min: int, crew_min: int, people: int, excluded: ?string, truck_only: bool}
     */
    public static function visitMinutes(array $entries, bool $visitDeparture = false): array
    {
        $people = array_values(array_filter($entries, fn($e) => !$e['truck']));
        $use = $people ?: $entries;
        $out = ['person_min' => 0, 'crew_min' => 0, 'people' => 0, 'excluded' => null, 'truck_only' => !$people && $entries];
        if (!$use) return ['excluded' => 'untimed'] + $out;
        $intervals = [];
        $person = 0;
        foreach ($use as $e) {
            $end = $e['end'] ?? ($e['duration'] !== null ? $e['start'] + (int)$e['duration'] * 60 : null);
            if ($end === null || $end <= $e['start']) continue;
            if (!empty($e['auto_stopped']) && empty($e['end_gps']) && !$visitDeparture) $out['excluded'] = 'auto_stopped';
            $person += $e['duration'] !== null ? (int)$e['duration'] : (int)round(($end - $e['start']) / 60);
            $intervals[] = [$e['start'], $end];
        }
        if (!$intervals) return ['excluded' => 'untimed'] + $out;
        $out['person_min'] = $person;
        $out['crew_min'] = (int)round(self::unionSeconds($intervals) / 60);
        $out['people'] = count(array_unique(array_map(fn($e) => (int)$e['user_id'], $use)));
        return $out;
    }

    /** Total seconds covered by [start, end] intervals. */
    public static function unionSeconds(array $intervals): int
    {
        usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        $cur = null;
        foreach ($intervals as [$s, $e]) {
            if ($cur === null) { $cur = [$s, $e]; continue; }
            if ($s <= $cur[1]) { $cur[1] = max($cur[1], $e); continue; }
            $total += $cur[1] - $cur[0];
            $cur = [$s, $e];
        }
        if ($cur !== null) $total += $cur[1] - $cur[0];
        return $total;
    }

    public static function median(array $xs): ?float
    {
        $xs = array_values(array_map('floatval', $xs));
        $n = count($xs);
        if ($n === 0) return null;
        sort($xs);
        return $n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }

    /** Linear-interpolated percentile (0–1). */
    public static function percentile(array $xs, float $p): ?float
    {
        $xs = array_values(array_map('floatval', $xs));
        $n = count($xs);
        if ($n === 0) return null;
        sort($xs);
        $i = ($n - 1) * $p;
        $lo = (int)floor($i);
        $hi = (int)ceil($i);
        return $xs[$lo] + ($xs[$hi] - $xs[$lo]) * ($i - $lo);
    }

    /**
     * The plan's real duration from its visits (most recent first) against what is planned.
     * @param list<array{visit_id: int, date: string, crew_min: int, person_min: int, people: int, excluded: ?string}> $visits
     * @return array{samples: int, used: list<array>, dropped: list<array>, median_crew: ?int, median_person: ?int,
     *   crew_size: ?float, spread: ?float, stable: bool, confident: bool, planned: ?int, proposed: ?int, change: ?int, reason: string}
     */
    public static function summarise(array $visits, ?int $planned): array
    {
        $dropped = [];
        $ok = [];
        foreach ($visits as $v) {
            if ($v['excluded'] === 'untimed') continue;
            if ($v['excluded'] !== null) { $dropped[] = ['visit_id' => $v['visit_id'], 'date' => $v['date'], 'minutes' => $v['crew_min'], 'why' => $v['excluded']]; continue; }
            if ($v['crew_min'] < self::MIN_MINUTES) { $dropped[] = ['visit_id' => $v['visit_id'], 'date' => $v['date'], 'minutes' => $v['crew_min'], 'why' => 'under_5']; continue; }
            $ok[] = $v;
        }
        $ok = array_slice($ok, 0, self::SAMPLES);
        $m0 = self::median(array_column($ok, 'crew_min'));
        $used = [];
        foreach ($ok as $v) {
            // Compare each visit with the median of the OTHERS, so one runaway timer can't hide itself.
            $others = array_column(array_values(array_filter($ok, fn($o) => $o['visit_id'] !== $v['visit_id'])), 'crew_min');
            $ref = self::median($others) ?? $m0;
            if ($ref !== null && count($others) >= 2 && $v['crew_min'] > self::OUTLIER_X * $ref) {
                $dropped[] = ['visit_id' => $v['visit_id'], 'date' => $v['date'], 'minutes' => $v['crew_min'], 'why' => 'over_3x'];
                continue;
            }
            $used[] = $v;
        }
        $crew = array_column($used, 'crew_min');
        $med = self::median($crew);
        $medP = self::median(array_column($used, 'person_min'));
        $spread = $med ? round((self::percentile($crew, .75) - self::percentile($crew, .25)) / $med, 2) : null;
        $n = count($used);
        $stable = $spread !== null && $spread <= self::STABLE_SPREAD;
        $out = [
            'samples' => $n, 'used' => $used, 'dropped' => $dropped,
            'median_crew' => $med === null ? null : (int)round($med), 'median_person' => $medP === null ? null : (int)round($medP),
            'crew_size' => $n ? round(array_sum(array_column($used, 'people')) / $n, 1) : null,
            'spread' => $spread, 'stable' => $stable, 'confident' => $n >= self::CONFIDENT_SAMPLES && $stable,
            'planned' => $planned, 'proposed' => null, 'change' => null, 'reason' => '',
        ];
        if ($n < self::MIN_TO_PROPOSE) { $out['reason'] = 'too_few'; return $out; }
        $proposed = max(self::ROUND_TO, (int)(round($med / self::ROUND_TO) * self::ROUND_TO));
        if ($planned !== null && $planned > 0) {
            $diff = abs($med - $planned);
            if ($diff < max(self::MIN_CHANGE_MIN, self::MIN_CHANGE_PCT * $planned) || $proposed === $planned) { $out['reason'] = 'close_enough'; return $out; }
        }
        $out['proposed'] = $proposed;
        $out['change'] = $planned !== null ? $proposed - $planned : null;
        $out['reason'] = $out['confident'] ? 'confident' : ($n >= self::CONFIDENT_SAMPLES ? 'spread' : 'few');
        return $out;
    }

    /** "2448 Larch St: plan 45 min, real median 32 min over 9 visits → 30 min". */
    public static function line(string $street, array $s): string
    {
        $plan = $s['planned'] ? 'plan ' . $s['planned'] . ' min' : 'no plan length';
        $t = $street . ': ' . $plan . ', real median ' . $s['median_crew'] . ' min over ' . $s['samples'] . ' visit' . ($s['samples'] === 1 ? '' : 's');
        if ($s['proposed'] !== null) $t .= ' → ' . $s['proposed'] . ' min';
        return $t . '.';
    }

    /** "2 people, 64 person-min · spread ±18% · 1 dropped (timer left running)". */
    public static function detail(array $s): string
    {
        $parts = [];
        if ($s['crew_size']) $parts[] = rtrim(rtrim(number_format($s['crew_size'], 1), '0'), '.') . ' ' . ($s['crew_size'] == 1.0 ? 'person' : 'people') . ', ' . $s['median_person'] . ' person-min';
        if ($s['spread'] !== null) $parts[] = 'spread ' . (int)round($s['spread'] * 100) . '%' . ($s['stable'] ? '' : ' (uneven)');
        $why = ['auto_stopped' => 'stopped by the system', 'under_5' => 'under 5 min', 'over_3x' => 'timer left running'];
        $d = array_count_values(array_column($s['dropped'], 'why'));
        foreach ($d as $k => $n) $parts[] = $n . ' dropped (' . ($why[$k] ?? $k) . ')';
        return implode(' · ', $parts);
    }

    public static function isLawn(string $serviceType, string $title = ''): bool
    {
        return (bool)preg_match(self::LAWN_PATTERN, $serviceType . ' ' . $title);
    }
}
