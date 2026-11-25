<?php
/**
 * WeatherGuardSummary — the one-line Last-run message for the weather guard cron.
 *
 * The guard's results array counts moves under 'auto_moved' and visits needing a
 * decision under 'action_list'. The cron used to read 'rescheduled' / 'flagged',
 * keys that were never set, so every run logged "0 rescheduled, 0 flagged".
 *
 * No namespace / no autoloader in production: require_once, then call statically.
 */
class WeatherGuardSummary
{
    public static function line(array $results): string
    {
        $moved   = (int)($results['auto_moved'] ?? 0);
        $flagged = (int)($results['action_list'] ?? 0);
        $checked = (int)($results['total_visits'] ?? 0);
        return "{$moved} rescheduled, {$flagged} flagged, {$checked} visits checked";
    }
}
