<?php
declare(strict_types=1);

/**
 * TimeclockRules — pure rules for clock entries and job timers (2026-10-12 review).
 *
 * Global-namespace, no autoloader: require_once this file, then call statically. No DB.
 * The DB side (approved weeks, overlaps, which weeks to recalculate) is TimeEntryGuard.
 */
final class TimeclockRules
{
    public const TZ = 'America/Vancouver';

    /**
     * An open clock entry is stale (left on by mistake) once it has run auto_clock_out_hours —
     * the same threshold as the auto_clockout cron. Never by calendar date: an overnight
     * snow/salt shift crosses midnight legitimately.
     */
    public static function isStaleEntry(int $elapsedSeconds, int $autoHours): bool
    {
        return $elapsedSeconds >= max(1, $autoHours) * 3600;
    }

    /** Monday (Y-m-d) of the week a local datetime/date falls in. Sunday belongs to the week before. */
    public static function weekStart(string $when): string
    {
        $ts = strtotime($when);
        if ($ts === false) $ts = time();
        return date('Y-m-d', strtotime('monday this week', $ts));
    }

    /** Distinct week starts for a list of datetimes (nulls/blanks skipped). */
    public static function weekStarts(array $whens): array
    {
        $out = [];
        foreach ($whens as $w) {
            if ($w === null || $w === '') continue;
            $out[self::weekStart((string)$w)] = true;
        }
        return array_keys($out);
    }

    /**
     * Real elapsed minutes between two LOCAL wall-clock datetimes (America/Vancouver).
     * MySQL TIMESTAMPDIFF on DATETIME counts wall-clock time: a shift 00:00→06:00 on the
     * fall-back night (2026-11-01) is 7 real hours, not 6; spring-forward (2026-03-08) the reverse.
     */
    public static function minutesBetween(string $start, string $end): int
    {
        $tz = new DateTimeZone(self::TZ);
        $a = new DateTimeImmutable($start, $tz);
        $b = new DateTimeImmutable($end, $tz);
        return (int)floor(($b->getTimestamp() - $a->getTimestamp()) / 60);
    }

    /** Wall-clock minutes (what TIMESTAMPDIFF gives) — for comparing with minutesBetween(). */
    public static function wallMinutes(string $start, string $end): int
    {
        $utc = new DateTimeZone('UTC');
        $a = new DateTimeImmutable($start, $utc);
        $b = new DateTimeImmutable($end, $utc);
        return (int)floor(($b->getTimestamp() - $a->getTimestamp()) / 60);
    }

    /** Does [start, end] cross a daylight-saving change in Vancouver? */
    public static function spansDstChange(string $start, string $end): bool
    {
        $tz = new DateTimeZone(self::TZ);
        return (new DateTimeImmutable($start, $tz))->getOffset() !== (new DateTimeImmutable($end, $tz))->getOffset();
    }

    /**
     * Do two intervals overlap? A null end means "still running" (open-ended).
     * Touching ends (one ends 12:00, the next starts 12:00) do not overlap.
     */
    public static function intervalsOverlap(string $aStart, ?string $aEnd, string $bStart, ?string $bEnd): bool
    {
        $as = strtotime($aStart);
        $bs = strtotime($bStart);
        $ae = ($aEnd === null || $aEnd === '') ? PHP_INT_MAX : strtotime($aEnd);
        $be = ($bEnd === null || $bEnd === '') ? PHP_INT_MAX : strtotime($bEnd);
        return $as < $be && $bs < $ae;
    }

    /**
     * May this person change someone's time? Admins and managers may change other people's;
     * only an admin may change their OWN (a manager editing their own hours is unreviewed pay).
     */
    public static function canEditTimeOf(string $actorRole, int $actorId, int $ownerId): bool
    {
        if ($actorRole === 'admin') return true;
        if ($actorRole === 'manager') return $actorId !== $ownerId;
        return false;
    }

    /** The message every edit path returns for a week that is already approved. */
    public static function approvedWeekMessage(string $weekStart): string
    {
        return 'The timesheet for the week of ' . date('M j', strtotime($weekStart))
            . ' is approved. Reopen it on the timesheet page first (with a note), then change the time.';
    }
}
