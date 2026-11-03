<?php
declare(strict_types=1);

/**
 * WeekLoadRules — the next seven days against crew capacity (operations plugin, capacity-plan:
 * "Target 80%. 100% means no room for surprises."), 2026-11-03.
 *
 *   capacity   = crew × HOURS_PER_DAY × 60 crew-minutes a day
 *   load       = Σ planned minutes × crew size of the day's scheduled visits
 *   > OVER     over-booked · FULL..OVER full, no room for rain · < LIGHT light · otherwise nothing said
 *   sanity     a day over INSANE× capacity, or one plan at ≥ HOURS_PER_DAY × crew, is "a plan length looks
 *              wrong", never a crew problem (2026-11-05 was planned at 6× capacity: one plan).
 * Pure and static.
 */
final class WeekLoadRules
{
    public const HOURS_PER_DAY = 8;
    public const OVER = 1.00;
    public const FULL = 0.80;
    public const LIGHT = 0.40;
    public const INSANE = 3.0;
    public const DAYS = 7;

    public static function capacityMinutes(int $crew): int
    {
        return max(0, $crew) * self::HOURS_PER_DAY * 60;
    }

    /**
     * @param array $days   date => {minutes: int, visits: int, biggest: {plan_id, title, minutes}|null}
     * @param int   $crew   people available a day
     * @return array per day: {date, share: ?float, state: over|full|light|ok|suspect|n/a, minutes, visits, note}
     */
    public static function days(array $days, int $crew): array
    {
        $cap = self::capacityMinutes($crew);
        $out = [];
        foreach ($days as $date => $d) {
            $min = (int)($d['minutes'] ?? 0);
            $big = $d['biggest'] ?? null;
            $row = ['date' => (string)$date, 'minutes' => $min, 'visits' => (int)($d['visits'] ?? 0), 'share' => null, 'state' => 'n/a', 'note' => ''];
            if ($cap <= 0) { $row['note'] = 'no crew count'; $out[] = $row; continue; }
            $share = $min / $cap;
            $row['share'] = round($share, 2);
            $bigMin = (int)($big['minutes'] ?? 0);
            if ($share > self::INSANE || $bigMin >= self::HOURS_PER_DAY * 60 * max(1, $crew)) {
                $row['state'] = 'suspect';
                $row['note'] = 'a plan length looks wrong' . ($big ? ' (plan #' . (int)$big['plan_id'] . ($big['title'] !== '' ? ' ' . $big['title'] : '') . ': ' . $bigMin . ' min)' : '');
            } elseif ($share > self::OVER) {
                $row['state'] = 'over'; $row['note'] = 'over-booked';
            } elseif ($share >= self::FULL) {
                $row['state'] = 'full'; $row['note'] = 'full — no room for rain';
            } elseif ($share < self::LIGHT && $row['visits'] > 0) {
                $row['state'] = 'light'; $row['note'] = 'light';
            } else {
                $row['state'] = 'ok';
            }
            $out[] = $row;
        }
        return $out;
    }

    /** One brief item for the week, or null when every day is ok. */
    public static function briefItem(array $rows, int $crew, string $today): ?array
    {
        $said = array_values(array_filter($rows, static fn($r) => in_array($r['state'], ['over', 'full', 'light', 'suspect'], true)));
        if (!$said) return null;
        $parts = [];
        foreach ($said as $r) {
            $day = date('D', strtotime($r['date']));
            $parts[] = $day . ' ' . ($r['share'] !== null ? (int)round($r['share'] * 100) . '%' : '') . ' (' . $r['note'] . ')';
        }
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $urgent = false;
        foreach ($said as $r) if ($r['date'] === $tomorrow && in_array($r['state'], ['over', 'suspect'], true)) $urgent = true;
        $suspect = (bool)array_filter($said, static fn($r) => $r['state'] === 'suspect');
        return [
            'key'      => 'otto:week-load:' . $today,
            'kind'     => 'week_load',
            'text'     => 'The week against ' . $crew . ' crew × ' . self::HOURS_PER_DAY . ' h: ' . implode(', ', $parts) . '.'
                        . ($suspect ? ' Fix the plan length before reading the rest.' : ' 80% is full; 100% leaves nothing for rain or a breakdown.'),
            'url'      => '/crm/jobs/schedule.php',
            'priority' => $urgent ? 1 : 3,
            'value'    => null,
            'since'    => $today,
        ];
    }
}
