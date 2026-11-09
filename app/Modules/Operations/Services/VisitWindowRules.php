<?php
/**
 * VisitWindowRules — when does the crew really finish, against the end time booked for that weekday?
 *
 * Pure functions only (unit tested). VisitWindowService loads the rows and applies changes.
 *
 * A finish time is the later of the job timers' end and the last GPS fix on the property, with two guards:
 *   - auto-stopped timers don't count (the night cron stopped them, nobody finished then);
 *   - GPS is ignored when another visit was done at the property that day (it can't say which visit it belongs to) or
 *     when it covers under GPS_TRUST of the recorded time (a phone that dropped off site reads as an early finish).
 * A visit recorded by hand with no usable end counts as its start plus the recorded time (shown as "about").
 * Otto asks only with MIN_SAMPLES finishes for that weekday, using the last SAMPLES. With a full set the latest one
 * is allowed to be an exception (4 of 5); with fewer, every one has to fit.
 */
class VisitWindowRules
{
    public const SAMPLES      = 5;
    public const MIN_SAMPLES  = 3;
    public const ROUND_MIN    = 30;
    public const MIN_EARLIER  = 30;
    public const GPS_TRUST    = 0.7;
    public const DAYS = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays'];

    /** Minutes after midnight from "HH:MM[:SS]". */
    public static function minutes(string $time): int
    {
        $p = explode(':', $time);
        return (int)$p[0] * 60 + (int)($p[1] ?? 0);
    }

    public static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    /** "8:30" / "3:00" — the clock as people say it. */
    public static function clock(int $minutes): string
    {
        $h = intdiv($minutes, 60) % 12;
        return ($h === 0 ? 12 : $h) . ':' . sprintf('%02d', $minutes % 60);
    }

    public static function dayOfWeek(string $date): int
    {
        return (int)date('w', (int)strtotime($date));
    }

    /**
     * One visit's finish, or null when nothing can be trusted.
     * @param array{date:string, entries:list<array{start:?string, end:?string, auto_stopped:bool}>, gps_first:?string,
     *              gps_last:?string, gps_minutes:?int, actual:?int, shared_day:bool} $v
     * @return ?array{finish:int, estimated:bool}
     */
    public static function finish(array $v): ?array
    {
        $actual = (int)($v['actual'] ?? 0);
        $first = $last = null;
        $n = 0;
        foreach ($v['entries'] as $e) {
            if (!empty($e['auto_stopped']) || empty($e['end']) || substr((string)$e['end'], 0, 10) !== $v['date']) continue;
            $n++;
            $last = max($last ?? 0, self::minutes(substr((string)$e['end'], 11)));
            if (!empty($e['start'])) $first = min($first ?? 1440, self::minutes(substr((string)$e['start'], 11)));
        }
        // Timers edited to a total they don't span (446 min recorded on timers 70 min apart) don't say when anyone left.
        $timerOk = $last !== null && ($actual <= 0 || $first === null || ($last - $first) >= self::GPS_TRUST * $actual / max(1, $n));
        if ($v['shared_day']) return $timerOk ? ['finish' => $last, 'estimated' => false] : null;

        $gpsLast = $v['gps_last'] ?? null;
        $gpsOk = $gpsLast && substr((string)$gpsLast, 0, 10) === $v['date']
              && ($actual <= 0 || (int)($v['gps_minutes'] ?? 0) >= self::GPS_TRUST * $actual);
        if ($timerOk || $gpsOk) {
            return ['finish' => max($timerOk ? $last : 0, $gpsOk ? self::minutes(substr((string)$gpsLast, 11)) : 0), 'estimated' => false];
        }
        // Recorded by hand with no end: when they started plus the time recorded. Person-minutes can only make this late.
        $gpsFirst = $v['gps_first'] ?? null;
        $start = $gpsFirst && substr((string)$gpsFirst, 0, 10) === $v['date'] ? self::minutes(substr((string)$gpsFirst, 11)) : $first;
        if ($actual > 0 && $start !== null && $start + $actual < 1440) return ['finish' => $start + $actual, 'estimated' => true];
        return null;
    }

    /**
     * The end time to propose for one weekday, or null when the booked end is about right or there is too little to go on.
     * @param list<array{date:string, finish:int, estimated?:bool}> $finishes newest first
     * @return ?array{start:int, end:int, planned_end:int, fit:int, samples:list<array{date:string, finish:int}>}
     */
    public static function propose(array $finishes, int $start, int $plannedEnd): ?array
    {
        $used = array_slice($finishes, 0, self::SAMPLES);
        $n = count($used);
        if ($n < self::MIN_SAMPLES) return null;
        $times = array_column($used, 'finish');
        sort($times);
        $target = $n >= self::SAMPLES ? $times[$n - 2] : $times[$n - 1];
        $end = (int)(ceil($target / self::ROUND_MIN) * self::ROUND_MIN);
        if ($end > $plannedEnd - self::MIN_EARLIER || $end <= $start) return null;
        return ['start' => $start, 'end' => $end, 'planned_end' => $plannedEnd,
                'fit' => count(array_filter($times, fn($t) => $t <= $end)), 'samples' => $used];
    }

    /** "Oakridge Gardens, Mondays: book 8:30–3:00 instead of 8:30–4:00?" */
    public static function line(string $place, int $dow, array $p): string
    {
        return $place . ', ' . self::DAYS[$dow] . ': book ' . self::clock($p['start']) . '–' . self::clock($p['end'])
             . ' instead of ' . self::clock($p['start']) . '–' . self::clock($p['planned_end']) . '?';
    }

    /** "Finished by 3:00 on 3 of the last 3." */
    public static function detail(array $p): string
    {
        return 'Finished by ' . self::clock($p['end']) . ' on ' . $p['fit'] . ' of the last ' . count($p['samples']) . '.';
    }

    /** "Mon Nov 2: finished 2:30". */
    public static function answers(array $p): array
    {
        return array_map(static function (array $s): string {
            return date('D M j', (int)strtotime($s['date'])) . ': finished ' . (!empty($s['estimated']) ? 'about ' : '') . self::clock($s['finish']);
        }, $p['samples']);
    }
}
