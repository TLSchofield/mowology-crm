<?php
declare(strict_types=1);

/** Pure five-field cron schedule matching. */
final class CronSchedule
{
    public static function matches(string $expr, int $ts): bool
    {
        $fields = preg_split('/\s+/', trim($expr));
        if (!$fields || count($fields) !== 5) return false;

        $values = [(int)date('i', $ts), (int)date('G', $ts), (int)date('j', $ts),
                   (int)date('n', $ts), (int)date('w', $ts)];
        $limits = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        $matched = [];
        foreach ($fields as $i => $field) {
            $matched[$i] = self::fieldMatches($field, $values[$i], $limits[$i][0], $limits[$i][1], $i === 4);
            if ($matched[$i] === null) return false;
        }

        if (!$matched[0] || !$matched[1] || !$matched[3]) return false;
        // Cron treats restricted day-of-month and day-of-week as alternatives. (Tim, 2026-11-04)
        if ($fields[2] !== '*' && $fields[4] !== '*') return $matched[2] || $matched[4];
        return $matched[2] && $matched[4];
    }

    public static function dueBetween(string $expr, int $fromExclusive, int $toInclusive): bool
    {
        if ($toInclusive <= $fromExclusive) return false;
        $fromExclusive = max($fromExclusive, $toInclusive - 3600);
        for ($ts = intdiv($toInclusive, 60) * 60; $ts > $fromExclusive; $ts -= 60) {
            if (self::matches($expr, $ts)) return true;
        }
        return false;
    }

    /** @return bool|null Null means invalid. */
    private static function fieldMatches(string $field, int $value, int $min, int $max, bool $dayOfWeek): ?bool
    {
        if ($field === '') return null;
        $any = false;
        foreach (explode(',', $field) as $part) {
            if ($part === '') return null;
            $step = 1;
            if (strpos($part, '/') !== false) {
                if (substr_count($part, '/') !== 1) return null;
                [$part, $stepText] = explode('/', $part, 2);
                if (!preg_match('/^[1-9][0-9]*$/', $stepText)) return null;
                $step = (int)$stepText;
            }

            if ($part === '*') {
                $start = $min;
                $end = $max;
            } elseif (preg_match('/^([0-9]+)-([0-9]+)$/', $part, $m)) {
                $start = (int)$m[1];
                $end = (int)$m[2];
                if ($start < $min || $end > $max || $start > $end) return null;
            } elseif (preg_match('/^[0-9]+$/', $part) && $step === 1) {
                $start = (int)$part;
                $end = $start;
                if ($start < $min || $start > $max) return null;
            } else {
                return null;
            }

            for ($candidate = $start; $candidate <= $end; $candidate += $step) {
                $compare = $dayOfWeek && $candidate === 7 ? 0 : $candidate;
                if ($compare === $value) $any = true;
            }
        }
        return $any;
    }
}
