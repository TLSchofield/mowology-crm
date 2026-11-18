<?php
/**
 * StopCardText — what a stop card says at the top, the same on the web/Android card and the iPhone
 * (StopCardView.swift). Tim, 2026-10-14: "iOS looks better" — the iPhone leads with the PROPERTY
 * ("BRANDON LODGE"), the web led with the client ("RON HARVIE") for the same address.
 *
 * Pure (unit tested). No namespace / no autoloader in production: require_once.
 */
class StopCardText
{
    /**
     * The card's headline: property name, else company, else contact — skipping any that only repeats the
     * address (a property often has no name of its own but is named by its street address).
     */
    public static function headline(array $stop): string
    {
        $addr = self::norm((string)($stop['property_address'] ?? ''));
        foreach (['property_name', 'company_name', 'contact_name'] as $k) {
            $v = trim((string)($stop[$k] ?? ''));
            if ($v !== '' && self::norm($v) !== $addr) return $v;
        }
        return '';
    }

    /** Who to show under the headline as the client: the next name after the headline's. */
    public static function client(array $stop): string
    {
        $head = self::headline($stop);
        foreach (['company_name', 'contact_name'] as $k) {
            $v = trim((string)($stop[$k] ?? ''));
            if ($v !== '' && $v !== $head) return $v;
        }
        return '';
    }

    /** "09:00" from estimated arrival (or the first visit's start), as the iPhone shows it; '' when none. */
    public static function arrival(array $stop): string
    {
        $t = $stop['estimated_arrival'] ?? ($stop['visits'][0]['scheduled_time_start'] ?? null);
        if (!$t) return '';
        $ts = strtotime((string)$t);
        return $ts === false ? '' : date('H:i', $ts);
    }

    /** "1 visit" / "3 visits". */
    public static function visits(array $stop): string
    {
        $n = count($stop['visits'] ?? []);
        return $n . ' visit' . ($n === 1 ? '' : 's');
    }

    /** Crew on the stop, as a short line ("Nigel C., Tim S."); '' when none. */
    public static function crew(array $stop): string
    {
        $names = $stop['crew_names'] ?? [];
        if (is_string($names)) $names = array_filter(array_map('trim', explode(',', $names)));
        $short = [];
        foreach ((array)$names as $n) {
            $parts = preg_split('/\s+/', trim((string)$n));
            if (!$parts || $parts[0] === '') continue;
            $short[] = $parts[0] . (count($parts) > 1 ? ' ' . mb_substr(end($parts), 0, 1) . '.' : '');
        }
        return implode(', ', $short);
    }

    private static function norm(string $s): string
    {
        return strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', ' ', $s)));
    }
}
