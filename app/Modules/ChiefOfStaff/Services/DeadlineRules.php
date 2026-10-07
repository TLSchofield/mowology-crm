<?php
/**
 * DeadlineRules — when the business's own obligations fall due (pure, unit tested).
 *
 * Rule strings (charlie_deadlines.rule, migration 1171):
 *   annual:MM-DD       every year on that day          annual:06-30
 *   annual:MM-last     last day of that month          annual:02-last  (T4s: Feb 28 / 29)
 *   dates:MM-DD,…      several days a year             dates:01-31,04-30,07-31,10-31
 *   monthly:DD|last    every month                     monthly:15
 *   every:Nm           every N months from an anchor   every:6m  (+ anchor_date)
 *   once:YYYY-MM-DD    a single date
 *   ''                 not set up yet (Tim fills it in) → no due date
 * A day past the end of a short month falls on its last day (monthly:31 → Feb 28).
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
class DeadlineRules
{
    /** Overdue or this close → priority 1 (the one thing). */
    public const URGENT_DAYS = 3;
    /** Never snooze past this many days before the due date. */
    public const MAX_SNOOZE_DAYS = 7;

    /** Is this a rule we understand (empty = "needs setup", which is allowed)? */
    public static function valid(string $rule, ?string $anchor = null): bool
    {
        $rule = trim($rule);
        if ($rule === '') return true;
        return self::nextDue($rule, '2000-01-01', $anchor) !== null;
    }

    /** The first due date on or after $from (Y-m-d), or null. */
    public static function nextDue(string $rule, string $from, ?string $anchor = null): ?string
    {
        $rule = trim($rule);
        if ($rule === '' || !preg_match('/^(annual|dates|monthly|every|once):(.+)$/', $rule, $m)) return null;
        [$type, $arg] = [$m[1], trim($m[2])];
        $fromTs = strtotime($from);
        if ($fromTs === false) return null;
        $y = (int)date('Y', $fromTs);

        switch ($type) {
            case 'once':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg) || !checkdate((int)substr($arg, 5, 2), (int)substr($arg, 8, 2), (int)substr($arg, 0, 4))) return null;
                return $arg >= $from ? $arg : null;

            case 'annual':
            case 'dates':
                $days = $type === 'annual' ? [$arg] : array_map('trim', explode(',', $arg));
                $best = null;
                foreach ($days as $md) {
                    if (!preg_match('/^(\d{2})-(\d{2}|last)$/', $md, $p)) return null;
                    $mon = (int)$p[1];
                    if ($mon < 1 || $mon > 12) return null;
                    if ($p[2] !== 'last' && ((int)$p[2] < 1 || (int)$p[2] > 31)) return null;
                    foreach ([$y, $y + 1] as $yy) {
                        $d = self::day($yy, $mon, $p[2]);
                        if ($d >= $from) { $best = $best === null ? $d : min($best, $d); break; }
                    }
                }
                return $best;

            case 'monthly':
                if (!preg_match('/^(\d{1,2}|last)$/', $arg) || ($arg !== 'last' && ((int)$arg < 1 || (int)$arg > 31))) return null;
                $mon = (int)date('n', $fromTs);
                for ($i = 0; $i < 2; $i++) {
                    $d = self::day($y, $mon, $arg);
                    if ($d >= $from) return $d;
                    if (++$mon > 12) { $mon = 1; $y++; }
                }
                return null;

            case 'every':
                if (!preg_match('/^(\d{1,2})m$/', $arg, $p) || (int)$p[1] < 1 || !$anchor || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) return null;
                $n = (int)$p[1];
                $ay = (int)substr($anchor, 0, 4);
                $am = (int)substr($anchor, 5, 2);
                $ad = (int)substr($anchor, 8, 2);
                for ($k = 0; $k < 1200; $k++) {
                    $mm = $am - 1 + $k * $n;
                    $d = self::day($ay + intdiv($mm, 12), $mm % 12 + 1, (string)$ad);
                    if ($d >= $from) return $d;
                }
                return null;
        }
        return null;
    }

    /** Y-m-d for a year, month and day (or 'last'), clamped to the month's last day. */
    public static function day(int $y, int $m, string $d): string
    {
        $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        $dd = $d === 'last' ? $last : min($last, (int)$d);
        return sprintf('%04d-%02d-%02d', $y, $m, $dd);
    }

    public static function daysUntil(string $today, string $due): int
    {
        return (int)round((strtotime($due) - strtotime($today)) / 86400);
    }

    /**
     * 1 = overdue or within 3 days · 2 = inside the reminder window · 3 = later (calendar page only).
     */
    public static function priority(string $today, string $due, int $leadDays): int
    {
        $d = self::daysUntil($today, $due);
        if ($d <= self::URGENT_DAYS) return 1;
        if ($d <= $leadDays) return 2;
        return 3;
    }

    /** Snooze N days, but never closer than … to the due date: returns the date it comes back. */
    public static function snoozeUntil(string $today, string $due, int $days): string
    {
        $days = max(1, min(self::MAX_SNOOZE_DAYS, $days));
        $want = date('Y-m-d', strtotime($today . " +{$days} days"));
        $cap = date('Y-m-d', strtotime($due . ' -1 day'));
        return max($today, min($want, $cap));
    }

    /** Plain words for a due date relative to today. */
    public static function when(string $today, string $due): string
    {
        $d = self::daysUntil($today, $due);
        if ($d < -1) return 'overdue since ' . date('M j', strtotime($due));
        if ($d === -1) return 'was due yesterday';
        if ($d === 0) return 'due today';
        if ($d === 1) return 'due tomorrow';
        if ($d <= 14) return 'due in ' . $d . ' days (' . date('D M j', strtotime($due)) . ')';
        return 'due ' . date('M j', strtotime($due));
    }

    /** Build a rule string from the calendar page's plain inputs (no raw rule strings in the UI). */
    public static function fromForm(array $f): string
    {
        $type = (string)($f['repeat'] ?? '');
        $md = static function ($v) {
            $v = trim((string)$v);
            if (preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $v, $m)) return $m[1] . '-' . $m[2];
            return preg_match('/^\d{2}-(\d{2}|last)$/', $v) ? $v : '';
        };
        switch ($type) {
            case 'annual':  $x = $md($f['date'] ?? ''); return $x ? 'annual:' . $x : '';
            case 'dates':
                $parts = array_values(array_filter(array_map($md, preg_split('/[\s,]+/', (string)($f['dates'] ?? '')))));
                return $parts ? 'dates:' . implode(',', $parts) : '';
            case 'monthly': $d = trim((string)($f['day'] ?? '')); return preg_match('/^(\d{1,2}|last)$/', $d) ? 'monthly:' . $d : '';
            case 'every':   $n = (int)($f['months'] ?? 0); return $n >= 1 && $n <= 60 ? 'every:' . $n . 'm' : '';
            case 'once':    $d = trim((string)($f['date'] ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? 'once:' . $d : '';
        }
        return '';
    }

    /** Plain words for a rule (calendar page). */
    public static function describe(string $rule): string
    {
        if (!preg_match('/^(annual|dates|monthly|every|once):(.+)$/', trim($rule), $m)) return 'Date not set yet';
        $md = static fn($s) => preg_match('/^(\d{2})-(\d{2}|last)$/', $s, $p)
            ? ($p[2] === 'last' ? 'last day of ' . date('F', mktime(0, 0, 0, (int)$p[1], 1)) : date('M j', mktime(0, 0, 0, (int)$p[1], (int)$p[2])))
            : $s;
        switch ($m[1]) {
            case 'annual':  return 'Every year, ' . $md($m[2]);
            case 'dates':   return 'Every year: ' . implode(', ', array_map($md, explode(',', $m[2])));
            case 'monthly': return $m[2] === 'last' ? 'Last day of every month' : 'Every month on the ' . self::ordinal((int)$m[2]);
            case 'every':   return 'Every ' . (int)$m[2] . ' months';
            case 'once':    return 'Once, ' . date('M j, Y', strtotime($m[2]));
        }
        return '';
    }

    public static function ordinal(int $n): string
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
    }
}
