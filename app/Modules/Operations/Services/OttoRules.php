<?php
/**
 * OttoRules — the pure decisions behind Otto, the operations head (unit tested).
 *
 * Nothing here touches the database. OpsDeskService gathers the facts, these rules turn
 * them into suggestions and words, OttoActionService applies what the owner clicks.
 *
 * No namespace / no autoloader in production: require_once, then call statically.
 */
class OttoRules
{
    /** A phone on the clock that has sent nothing for this long is "quiet" (Tim, 2026-10-05). */
    public const SILENT_MINUTES = 15;
    /** How far back Otto looks for timesheet gaps and visits with no time (Tim, 2026-10-05). */
    public const GAP_DAYS = 14;
    /** A suggested time the owner moved by no more than this still counts as "right". */
    public const CLOSE_ENOUGH_MINUTES = 5;
    /** Weather: decisions within this many rain points count as "like this one". */
    public const RAIN_NEAR = 15;
    /** Weather: how many like-this decisions before Otto leans one way. */
    public const LEAN_MIN = 3;
    /** Weather: share of like-this decisions that must agree. */
    public const LEAN_SHARE = 0.75;
    /** Shift length cap for a suggested clock-out. */
    public const MAX_SHIFT_HOURS = 14;

    // ─────────────────────────────────────────────────────────────────────────
    // Weather
    // ─────────────────────────────────────────────────────────────────────────

    /** Worst rain chance (%) in the visit window the weather guard saved, or null. */
    public static function rainChance(?string $snapshotJson): ?int
    {
        $s = $snapshotJson ? json_decode($snapshotJson, true) : null;
        if (!is_array($s)) return null;
        $best = null;
        foreach ((array)($s['hourly_window'] ?? []) as $h) {
            if (is_array($h) && isset($h['precip_chance_pct']) && is_numeric($h['precip_chance_pct'])) {
                $best = max($best ?? 0, (int)$h['precip_chance_pct']);
            }
        }
        if ($best === null && isset($s['worst_hour']['precip_chance_pct']) && is_numeric($s['worst_hour']['precip_chance_pct'])) {
            $best = (int)$s['worst_hour']['precip_chance_pct'];
        }
        return $best === null ? null : max(0, min(100, $best));
    }

    /**
     * Which way the owner usually goes for rain like this, from his past calls on this
     * service type: 'keep', 'move', or null while Otto doesn't know yet.
     * @param array{keep?: int[], move?: int[]} $lesson rain % of each past decision
     */
    public static function weatherLean(array $lesson, ?int $rain): ?string
    {
        if ($rain === null) return null;
        $near = static fn(array $list) => count(array_filter($list, fn($r) => abs((int)$r - $rain) <= self::RAIN_NEAR));
        $keep = $near((array)($lesson['keep'] ?? []));
        $move = $near((array)($lesson['move'] ?? []));
        $n = $keep + $move;
        if ($n < self::LEAN_MIN) return null;
        if ($keep / $n >= self::LEAN_SHARE) return 'keep';
        if ($move / $n >= self::LEAN_SHARE) return 'move';
        return null;
    }

    /** Add one decision to a weather lesson, newest last, capped. */
    public static function addWeatherDecision(array $lesson, string $choice, ?int $rain, int $cap = 30): array
    {
        $lesson += ['keep' => [], 'move' => []];
        if ($rain === null || !in_array($choice, ['keep', 'move'], true)) return $lesson;
        $lesson[$choice][] = $rain;
        $lesson[$choice] = array_slice($lesson[$choice], -$cap);
        return $lesson;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Time
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When a shift nobody closed probably ended: the later of the last job timer that
     * really stopped and the last GPS fix taken AT a job — never the auto clock-out time
     * and never a fix from the drive home. Null when there is nothing to go on.
     * @return array{0: int, 1: string}|null [timestamp, 'timer'|'gps']
     */
    public static function suggestClockOut(int $clockInTs, ?int $lastTimerEndTs, ?int $lastSiteFixTs): ?array
    {
        $cap = $clockInTs + self::MAX_SHIFT_HOURS * 3600;
        $ok = static fn(?int $t) => $t !== null && $t > $clockInTs && $t <= $cap;
        $timer = $ok($lastTimerEndTs) ? $lastTimerEndTs : null;
        $gps = $ok($lastSiteFixTs) ? $lastSiteFixTs : null;
        if ($timer === null && $gps === null) return null;
        if ($gps !== null && ($timer === null || $gps > $timer)) return [self::roundTo5($gps), 'gps'];
        return [self::roundTo5($timer), 'timer'];
    }

    /**
     * How long a visit took: time on site from GPS when it is believable, else what the
     * owner set for this plan before, else the plan's estimate.
     * @return array{0: int, 1: string}|null [minutes, 'gps'|'learned'|'estimate']
     */
    public static function guessMinutes(?int $dwellMinutes, array $learned, ?int $estimate): ?array
    {
        if ($dwellMinutes !== null && $dwellMinutes >= 5 && $dwellMinutes <= 600) {
            return [self::roundUp5($dwellMinutes), 'gps'];
        }
        $m = self::median($learned);
        if ($m !== null && $m > 0) return [(int)$m, 'learned'];
        if ($estimate !== null && $estimate > 0) return [(int)$estimate, 'estimate'];
        return null;
    }

    /** Accepted (Otto was right) when the owner kept his number within a few minutes. */
    public static function keptOrEdited(int $suggested, int $chosen, int $slack = self::CLOSE_ENOUGH_MINUTES): string
    {
        return abs($suggested - $chosen) <= $slack ? 'accepted' : 'edited';
    }

    public static function median(array $xs): ?float
    {
        $xs = array_values(array_filter(array_map('floatval', $xs), fn($x) => $x > 0));
        if (!$xs) return null;
        sort($xs);
        $n = count($xs);
        return $n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }

    private static function roundTo5(int $ts): int
    {
        return (int)(round($ts / 300) * 300);
    }

    private static function roundUp5(int $m): int
    {
        return (int)(ceil($m / 5) * 5);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Quiet phones
    // ─────────────────────────────────────────────────────────────────────────

    /** Minutes to wait before flagging this person's phone (a lesson can stretch it). */
    public static function silentWait(?array $lesson): int
    {
        $w = (int)($lesson['wait_minutes'] ?? 0);
        return $w > self::SILENT_MINUTES ? $w : self::SILENT_MINUTES;
    }

    /** Ask "wait longer for X?" once the owner has said "it's fine" the last 3 times. */
    public static function shouldAskSilentPattern(array $choicesNewestFirst): bool
    {
        $last = array_slice($choicesNewestFirst, 0, 3);
        return count($last) === 3 && count(array_filter($last, fn($c) => $c === 'fine')) === 3;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Words and order
    // ─────────────────────────────────────────────────────────────────────────

    /** Most urgent first; within a priority, the order they were found. */
    public static function sortItems(array $items): array
    {
        $i = 0;
        foreach ($items as &$it) $it['_n'] = $i++;
        unset($it);
        usort($items, fn($a, $b) => [(int)$a['priority'], $a['_n']] <=> [(int)$b['priority'], $b['_n']]);
        return array_map(function ($it) { unset($it['_n']); return $it; }, $items);
    }

    /**
     * What Otto says first. Short, plain, one idea per sentence.
     * @param array $s today: stops, done, crews, on_clock; weather: flagged; gaps: count; silent: names
     */
    public static function headline(array $s, string $name): string
    {
        $hi = 'Hey' . ($name !== '' ? ' ' . $name : '') . ' — ';
        $stops = (int)($s['stops'] ?? 0);
        $crews = (int)($s['crews'] ?? 0);
        $done = (int)($s['done'] ?? 0);
        if ($stops === 0) {
            $out = 'no stops on the schedule today.';
        } else {
            $out = ($crews > 0 ? self::plural($crews, 'crew') . ', ' : '') . self::plural($stops, 'stop') . ' today';
            $out .= $done > 0 ? ", {$done} done." : '.';
        }
        $parts = [$out];
        $silent = array_values((array)($s['silent'] ?? []));
        if (count($silent) === 1) $parts[] = $silent[0] . "'s phone has gone quiet.";
        elseif (count($silent) > 1) $parts[] = count($silent) . ' phones have gone quiet.';
        $w = (int)($s['weather'] ?? 0);
        if ($w > 0) $parts[] = 'The weather puts ' . self::plural($w, 'visit') . ' in doubt.';
        $g = (int)($s['gaps'] ?? 0);
        if ($g > 0) $parts[] = self::plural($g, 'timesheet gap') . ' to close.';
        if ($w === 0 && $g === 0 && !$silent && $stops > 0) $parts[] = 'Nothing needs you right now.';
        return $hi . implode(' ', $parts);
    }

    public static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }

    /** "today", "tomorrow", or "Thu Oct 8". */
    public static function dayWord(string $date, string $today): string
    {
        if ($date === $today) return 'today';
        if ($date === date('Y-m-d', strtotime($today . ' +1 day'))) return 'tomorrow';
        return date('D M j', strtotime($date));
    }

    /** % of Otto's calls the owner kept, from decision statuses (dismissals of quiet phones aside). */
    public static function rightFirstTime(array $statuses): ?int
    {
        $judged = array_values(array_filter($statuses, fn($s) => in_array($s, ['accepted', 'edited'], true)));
        if (count($judged) < 3) return null;
        $ok = count(array_filter($judged, fn($s) => $s === 'accepted'));
        return (int)round($ok / count($judged) * 100);
    }
}
