<?php
declare(strict_types=1);

/**
 * ClockWeekRules — last week's time clock, one line per person, asked never fixed.
 *
 * Harvested from Anthropic's small-business plugin (payroll-prep anomaly rules), 2026-11-03, on top of
 * Penny's PayrollRunChecks::punchFlags() (shift > 12 h, day > 16 h, 7 days straight):
 *   overlap     two rows of one person that share time — never merged, Otto asks (a duplicate punch pair);
 *   zero-length a shift under ZERO_MINUTES — a double tap;
 *   open        a shift from a past day still open — "hours unknown" (Otto already proposes the clock-out);
 *   week-high   week hours above OT_FACTOR (125%) of the person's own trailing average, both numbers shown;
 *   zero-hours  nothing clocked by someone who clocked in REGULAR_WEEKS of the last HISTORY_WEEKS weeks.
 * Flags are capped at PayrollRunChecks::PUNCH_CAP per person. Dollars only when a rate is known.
 * Pure and static: OttoHarvestService gathers the rows, this class reasons about them.
 */
require_once dirname(__DIR__, 2) . '/Accounting/Services/PayrollRunChecks.php';

final class ClockWeekRules
{
    public const ZERO_MINUTES = 5;
    public const HISTORY_WEEKS = 4;
    public const REGULAR_WEEKS = 3;
    public const SHOW_UNTIL_WEEKDAY = 3;   // Monday=1 … Wednesday=3: last week's review is shown Mon–Wed, then dropped
    public const MIN_HISTORY_HOURS = 8.0;  // a trailing average under this is not a baseline for the 125% rule

    /** The Monday of the last completed week (Mon–Sun) before $today. */
    public static function lastWeekMonday(string $today): string
    {
        $t = strtotime($today);
        $dow = (int)date('N', $t);                      // 1 = Monday
        return date('Y-m-d', strtotime($today . ' -' . ($dow - 1 + 7) . ' days'));
    }

    public static function shownToday(string $today): bool
    {
        return (int)date('N', strtotime($today)) <= self::SHOW_UNTIL_WEEKDAY;
    }

    /** Minutes of one entry (closed ones only). */
    private static function minutes(array $c): ?int
    {
        if (empty($c['clock_out'])) return null;
        if (isset($c['total_minutes']) && $c['total_minutes'] !== null && $c['total_minutes'] !== '') return (int)$c['total_minutes'];
        return (int)round((strtotime((string)$c['clock_out']) - strtotime((string)$c['clock_in'])) / 60);
    }

    /**
     * Hours of a list of entries. An open entry is "hours unknown" and counts nothing (Codex 2026-11-03: a punch
     * left open three weeks ago is not hundreds of hours), unless $countOpen asks for its hours up to $now.
     */
    public static function hours(array $entries, string $now, bool $countOpen = false): float
    {
        $m = 0;
        foreach ($entries as $c) {
            $min = self::minutes($c);
            if ($min === null) { if ($countOpen) $m += max(0, (int)round((strtotime($now) - strtotime((string)$c['clock_in'])) / 60)); continue; }
            $m += $min;
        }
        return round($m / 60, 1);
    }

    /**
     * Overlapping pairs among one person's entries (newest first is fine): [[a, b], …] by id.
     */
    public static function overlaps(array $entries, string $now): array
    {
        $rows = array_values($entries);
        usort($rows, static fn($a, $b) => strcmp((string)$a['clock_in'], (string)$b['clock_in']));
        $out = [];
        for ($i = 0; $i < count($rows); $i++) {
            $aIn = strtotime((string)$rows[$i]['clock_in']);
            $aOut = !empty($rows[$i]['clock_out']) ? strtotime((string)$rows[$i]['clock_out']) : strtotime($now);
            for ($j = $i + 1; $j < count($rows); $j++) {
                $bIn = strtotime((string)$rows[$j]['clock_in']);
                if ($bIn >= $aOut) break;
                $bOut = !empty($rows[$j]['clock_out']) ? strtotime((string)$rows[$j]['clock_out']) : strtotime($now);
                if ($bOut > $aIn) $out[] = [(int)$rows[$i]['id'], (int)$rows[$j]['id']];
            }
        }
        return $out;
    }

    /**
     * One person's review of last week.
     * @param array  $week     entries with clock_in in the week {id, clock_in, clock_out, total_minutes}
     * @param array  $history  hours per earlier week, newest first, up to HISTORY_WEEKS (float[])
     * @param ?float $rate     hourly rate when known
     * @return array flags [{kind, text, impact}]
     */
    public static function person(string $name, array $week, array $history, ?float $rate, string $weekMonday, string $now, array $neighbours = []): array
    {
        $flags = [];
        $weekLabel = 'the week of ' . date('M j', strtotime($weekMonday));
        $hrs = self::hours($week, $now);   // closed entries only; an open one is named below as "hours unknown"

        if (!$week) {
            $regular = count(array_filter($history, static fn($h) => $h > 0));
            if (count($history) >= self::REGULAR_WEEKS && $regular >= self::REGULAR_WEEKS) {
                $flags[] = ['kind' => 'zero_hours', 'impact' => null,
                            'text' => "{$name}: nothing clocked in {$weekLabel}, after {$regular} of the last " . count($history) . ' weeks with hours — away, or a phone that stopped clocking?'];
            }
            return $flags;
        }

        foreach ($week as $c) {
            if (empty($c['clock_out']) && substr((string)$c['clock_in'], 0, 10) < substr($now, 0, 10)) {
                $flags[] = ['kind' => 'open', 'impact' => null,
                            'text' => "{$name}: clocked in " . date('D M j, g:i a', strtotime((string)$c['clock_in'])) . ' and never out — hours unknown until the clock-out is set.'];
            }
            $min = self::minutes($c);
            if ($min !== null && $min < self::ZERO_MINUTES) {
                $flags[] = ['kind' => 'zero_length', 'impact' => null,
                            'text' => "{$name}: a {$min}-minute shift on " . date('D M j, g:i a', strtotime((string)$c['clock_in'])) . ' — a double tap? Delete it or it counts as a day worked.'];
            }
        }
        // Overlaps are looked for across the week's edges too (a Sunday-night shift against Monday's), but only pairs
        // that touch this week are reported (Codex 2026-11-03).
        $weekIds = array_map(static fn($c) => (int)$c['id'], $week);
        $all = array_merge($week, $neighbours);
        foreach (self::overlaps($all, $now) as [$a, $b]) {
            if (!in_array($a, $weekIds, true) && !in_array($b, $weekIds, true)) continue;
            $ra = null; $rb = null;
            foreach ($all as $c) { if ((int)$c['id'] === $a) $ra = $c; if ((int)$c['id'] === $b) $rb = $c; }
            $same = $ra && $rb && (string)$ra['clock_in'] === (string)$rb['clock_in'];
            $flags[] = ['kind' => 'overlap', 'impact' => ($rate !== null && $rb && self::minutes($rb) !== null) ? round(self::minutes($rb) / 60 * $rate, 2) : null,
                        'text' => "{$name}: two shifts " . ($same ? 'with the same start' : 'that overlap') . ' on ' . date('D M j', strtotime((string)($ra['clock_in'] ?? $now)))
                                . " (#{$a} and #{$b}) — " . ($same ? 'a duplicate punch; one of them should go' : 'they cannot both be right') . '.'];
        }

        // Penny's three punch rules (shift > 12 h, day > 16 h, 7 days straight), same wording.
        foreach (PayrollRunChecks::punchFlags($name, array_map(static function ($c) {
            return ['clock_in' => (string)$c['clock_in'], 'clock_out' => $c['clock_out'] ?? null, 'total_minutes' => $c['total_minutes'] ?? null];
        }, $week), $rate) as $f) {
            $flags[] = ['kind' => $f['kind'], 'impact' => $f['impact'], 'text' => $f['text']];
        }

        $base = array_values(array_filter($history, static fn($h) => $h > 0));
        if (count($base) >= 2) {
            $avg = array_sum($base) / count($base);
            if ($avg >= self::MIN_HISTORY_HOURS && $hrs > PayrollRunChecks::OT_FACTOR * $avg) {
                $over = $hrs - $avg;
                $flags[] = ['kind' => 'week_high', 'impact' => $rate !== null ? round($over * $rate, 2) : null,
                            'text' => "{$name}: {$hrs} h in {$weekLabel} against their usual " . round($avg, 1) . ' h (' . count($base) . '-week average) — '
                                    . round($hrs / $avg * 100) . '%' . ($rate !== null ? ', about $' . number_format($over * $rate, 2) . ' more' : ', cost unknown without a rate') . '.'];
            }
        }

        if (count($flags) > PayrollRunChecks::PUNCH_CAP) {
            $more = count($flags) - PayrollRunChecks::PUNCH_CAP;
            $flags = array_slice($flags, 0, PayrollRunChecks::PUNCH_CAP);
            $flags[] = ['kind' => 'more', 'impact' => null, 'text' => "{$name}: and {$more} more on the timesheet."];
        }
        return $flags;
    }

    /** Brief items: one per person with flags. */
    public static function briefItems(array $perPerson, string $weekMonday, string $today): array
    {
        $out = [];
        foreach ($perPerson as $p) {
            if (empty($p['flags'])) continue;
            $texts = array_map(static fn($f) => $f['text'], $p['flags']);
            $kinds = array_column($p['flags'], 'kind');
            $hard = (bool)array_intersect($kinds, ['overlap', 'open', 'day', 'zero_hours']);
            $out[] = [
                'key'      => 'otto:clock-week:' . (int)$p['user_id'] . ':' . $weekMonday,
                'kind'     => 'clock_week',
                'text'     => 'Last week\'s clock — ' . implode(' ', $texts),
                'url'      => '/crm/timeclock/timesheet-detail.php?user_id=' . (int)$p['user_id'] . '&week=' . $weekMonday,
                'priority' => $hard ? 2 : 3,
                'value'    => array_sum(array_map(static fn($f) => (float)($f['impact'] ?? 0), $p['flags'])) ?: null,
                'since'    => $today,
            ];
        }
        return $out;
    }
}
