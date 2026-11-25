<?php
declare(strict_types=1);

/**
 * ClientRhythm — which clients have gone quiet, measured against THEIR OWN rhythm, not a calendar.
 * Harvested from Anthropic's small-business plugin (review-reputation churn signals + reactivate, 2026-11-03):
 *   normal gap   the median days between a client's purchases (3+ purchases needed; fewer = no rhythm to measure)
 *   quiet        days since the last purchase > QUIET_FACTOR × their normal gap
 *   gone         > GONE_FACTOR × their normal gap
 * Seasonal clients are judged inside their own season: a gap that spans the off-season (SEASON_BREAK_DAYS or more
 * between two purchases in their history) is ignored when the median is taken, and a client whose last purchase was
 * at season's end is not "quiet" until the season is back (the caller passes $inSeason).
 * Ranked by what the relationship was worth (12-month spend). Win-back: three touches at days 0 / +10 / +28, the first
 * never pitches; stop on any reply. Pure and static — Sam drafts, Tim sends.
 */
final class ClientRhythm
{
    public const MIN_PURCHASES = 3;
    public const QUIET_FACTOR = 2.0;
    public const GONE_FACTOR = 3.0;
    public const SEASON_BREAK_DAYS = 100;
    public const WINBACK_DAYS = [0, 10, 28];

    /**
     * @param array $clients key => {name, purchases: ['Y-m-d', …] (any order), spend_12m: float, email?, first_name?}
     * @return array rows {key, name, state (quiet|gone), last, days_since, normal_gap, n, spend_12m, factor, reason}, ranked by spend
     */
    public static function find(array $clients, string $today, bool $inSeason = true): array
    {
        $out = [];
        $t = strtotime($today);
        foreach ($clients as $key => $c) {
            $dates = array_values(array_unique(array_filter(array_map(static fn($d) => substr((string)$d, 0, 10), (array)($c['purchases'] ?? [])))));
            sort($dates);
            if (count($dates) < self::MIN_PURCHASES) continue;
            $gaps = [];
            for ($i = 1; $i < count($dates); $i++) {
                $g = (int)round((strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400);
                if ($g > 0 && $g < self::SEASON_BREAK_DAYS) $gaps[] = $g;   // an off-season break is not their rhythm
            }
            if (count($gaps) < 2) continue;
            sort($gaps);
            $n = count($gaps);
            $median = $n % 2 ? (float)$gaps[intdiv($n, 2)] : ($gaps[$n / 2 - 1] + $gaps[$n / 2]) / 2;
            if ($median <= 0) continue;
            $last = end($dates);
            $since = (int)floor(($t - strtotime($last)) / 86400);
            $factor = $since / $median;
            if ($factor <= self::QUIET_FACTOR) continue;
            if (!$inSeason) continue;   // off-season silence is the season, not the client
            $state = $factor > self::GONE_FACTOR ? 'gone' : 'quiet';
            $out[] = ['key' => (string)$key, 'name' => (string)($c['name'] ?? $key), 'state' => $state, 'last' => $last, 'days_since' => $since,
                      'normal_gap' => round($median), 'n' => count($dates), 'spend_12m' => round((float)($c['spend_12m'] ?? 0), 2), 'factor' => round($factor, 1),
                      'email' => (string)($c['email'] ?? ''), 'first_name' => (string)($c['first_name'] ?? ''),
                      'reason' => 'usually every ' . round($median) . ' days, now ' . $since . ' days since ' . date('M j', strtotime($last)) . ' (' . round($factor, 1) . '×)'];
        }
        usort($out, static fn($a, $b) => ($b['spend_12m'] <=> $a['spend_12m']) ?: ($b['factor'] <=> $a['factor']));
        return $out;
    }

    /** The first win-back message: no pitch, no offer — a check-in that makes replying easy. Later touches are the owner's call. */
    public static function firstMessage(array $row, string $signoff): array
    {
        $first = trim((string)($row['first_name'] ?? '')) ?: 'there';
        $subject = 'Checking in';
        $body = "Hi $first,\n\nIt has been a while since we were last out at your place (" . date('F', strtotime((string)$row['last'])) . "). "
              . "No pitch — I just wanted to check everything is still looking the way you want it, and whether anything has changed on your end.\n\n"
              . "If there is anything you'd like us to look at, reply here and I'll sort it.\n\n$signoff";
        return ['subject' => $subject, 'body' => $body, 'next_touch_days' => self::WINBACK_DAYS[1]];
    }
}
