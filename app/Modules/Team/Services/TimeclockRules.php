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
     * MySQL TIMESTAMPDIFF on DATETIME counts wall-clock time: a shift 00:00→06:00 on a fall-back
     * night (2025-11-02) is 7 real hours, not 6; spring-forward (2026-03-08) the reverse. Follows
     * the zone data: production's tzdata (checked 2026-10-12) has BC on permanent UTC-7 ("MST")
     * from 2026-11-01, so that night has no change; a host with older tzdata would see one.
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

    /**
     * May a week's stored totals be recalculated? Never an approved week: its totals are what was
     * approved and paid — a cron closing a late entry must not rewrite them (2026-10-12 review).
     */
    public static function mayRecalculateWeek(?string $timesheetStatus): bool
    {
        return $timesheetStatus !== 'approved';
    }

    /**
     * Why a week can't be approved yet, or null. A shift or job timer still running in the week
     * would change its hours after approval (2026-10-12 review).
     */
    public static function approveBlockedMessage(int $openShifts, int $openTimers): ?string
    {
        if ($openShifts <= 0 && $openTimers <= 0) return null;
        $what = [];
        if ($openShifts > 0) $what[] = $openShifts === 1 ? 'a shift still clocked in' : "{$openShifts} shifts still clocked in";
        if ($openTimers > 0) $what[] = $openTimers === 1 ? 'a job timer still running' : "{$openTimers} job timers still running";
        return 'This week has ' . implode(' and ', $what) . '. Clock out / stop the timer first, then approve.';
    }

    /**
     * Minutes to record for a job timer a cron closes (nobody stopped it): never more than the
     * person's shift that day, and never more than $capHours when there is no shift to go by.
     * Shared by the orphan-timer cron and the rollover cron (2026-10-12 review).
     */
    public static function cappedTimerMinutes(int $rawMinutes, ?int $shiftMinutes, int $capHours = 12): int
    {
        $raw = max(0, $rawMinutes);
        if ($shiftMinutes !== null && $shiftMinutes > 0) return min($raw, $shiftMinutes);
        return min($raw, $capHours * 60);
    }

    /** The message every edit path returns for a week that is already approved. */
    public static function approvedWeekMessage(string $weekStart): string
    {
        return 'The timesheet for the week of ' . date('M j', strtotime($weekStart))
            . ' is approved. Reopen it on the timesheet page first (with a note), then change the time.';
    }
}
