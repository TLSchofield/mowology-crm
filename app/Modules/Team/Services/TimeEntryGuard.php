<?php
declare(strict_types=1);

require_once __DIR__ . '/TimeclockRules.php';

/**
 * TimeEntryGuard — the one check every path that CHANGES recorded time goes through
 * (2026-10-12 review): manual add/edit/void of clock entries, job-timer corrections,
 * Otto's clock-out/timer fixes.
 *
 *   lockedWeek()   an approved timesheet is never edited silently — refuse; reopen it first
 *   overlapping()  a manual entry may not overlap another of the same person's entries
 *   recalcWeeks()  recalculate EVERY week an entry touched (its own, and the one it moved from)
 *
 * Global-namespace, no autoloader. Needs timeclock-functions (recalculateTimesheetTotals) loaded
 * for recalcWeeks().
 */
final class TimeEntryGuard
{
    /** The approved week (Y-m-d Monday) among the given datetimes, or null if all are open. */
    public static function lockedWeek(PDO $db, int $userId, array $whens): ?string
    {
        $weeks = TimeclockRules::weekStarts($whens);
        if (!$weeks) return null;
        $in = implode(',', array_fill(0, count($weeks), '?'));
        $s = $db->prepare("SELECT week_start FROM timesheets WHERE user_id = ? AND status = 'approved' AND week_start IN ($in) ORDER BY week_start LIMIT 1");
        $s->execute(array_merge([$userId], $weeks));
        $w = $s->fetchColumn();
        return $w ? (string)$w : null;
    }

    /** Message to refuse with, or null when the change may go ahead. */
    public static function refusal(PDO $db, int $userId, array $whens): ?string
    {
        $w = self::lockedWeek($db, $userId, $whens);
        return $w === null ? null : TimeclockRules::approvedWeekMessage($w);
    }

    /**
     * First of the person's live clock entries that overlaps [start, end] (end null = open),
     * ignoring $exceptId. Returns the row or null.
     */
    public static function overlapping(PDO $db, int $userId, string $start, ?string $end, int $exceptId = 0): ?array
    {
        // Narrow in SQL (entries starting within 2 days either side), decide in PHP (open ends).
        $s = $db->prepare("
            SELECT id, clock_in, clock_out, status
            FROM time_clock_entries
            WHERE user_id = ? AND id <> ? AND status IN ('active', 'completed', 'edited')
              AND clock_in < DATE_ADD(?, INTERVAL 2 DAY)
              AND (clock_out IS NULL OR clock_out > DATE_SUB(?, INTERVAL 2 DAY))
            ORDER BY clock_in
        ");
        $s->execute([$userId, $exceptId, $end ?? $start, $start]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (TimeclockRules::intervalsOverlap($start, $end, (string)$r['clock_in'], $r['clock_out'] !== null ? (string)$r['clock_out'] : null)) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Message refusing approval while a shift or job timer is still open in the week (Monday
     * $weekStart), or null when the week may be approved.
     */
    public static function approveRefusal(PDO $db, int $userId, string $weekStart): ?string
    {
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $c = $db->prepare("SELECT COUNT(*) FROM time_clock_entries
                           WHERE user_id = ? AND status = 'active' AND clock_out IS NULL
                             AND DATE(clock_in) BETWEEN ? AND ?");
        $c->execute([$userId, $weekStart, $weekEnd]);
        $t = $db->prepare("SELECT COUNT(*) FROM job_time_entries
                           WHERE user_id = ? AND status = 'active' AND end_time IS NULL
                             AND DATE(start_time) BETWEEN ? AND ?");
        $t->execute([$userId, $weekStart, $weekEnd]);
        return TimeclockRules::approveBlockedMessage((int)$c->fetchColumn(), (int)$t->fetchColumn());
    }

    /** Recalculate (creating if needed) every week the given datetimes fall in. */
    public static function recalcWeeks(int $userId, array $whens): void
    {
        foreach (TimeclockRules::weekStarts($whens) as $monday) {
            ensureTimesheetExists($userId, $monday);
        }
    }

    /**
     * Real elapsed minutes for a stored clock entry / job timer when it spans a DST change;
     * null when wall-clock minutes are already right. Callers write it back.
     */
    public static function dstMinutes(?string $start, ?string $end): ?int
    {
        if (!$start || !$end) return null;
        if (!TimeclockRules::spansDstChange($start, $end)) return null;
        return max(0, TimeclockRules::minutesBetween($start, $end));
    }

    /** After a SQL TIMESTAMPDIFF write: fix the minutes of one row if it spans a DST change. */
    public static function fixDst(PDO $db, string $table, int $id): void
    {
        if (!in_array($table, ['job_time_entries', 'time_clock_entries'], true)) return;
        [$startCol, $endCol, $minCol] = $table === 'job_time_entries'
            ? ['start_time', 'end_time', 'duration_minutes']
            : ['clock_in', 'clock_out', 'total_minutes'];
        $s = $db->prepare("SELECT $startCol AS s, $endCol AS e FROM $table WHERE id = ?");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $m = $r ? self::dstMinutes($r['s'], $r['e']) : null;
        if ($m === null) return;
        $db->prepare("UPDATE $table SET $minCol = ?, notes = CONCAT(COALESCE(notes, ''), ' [minutes corrected for the daylight-saving change]') WHERE id = ?")
           ->execute([$m, $id]);
    }
}
