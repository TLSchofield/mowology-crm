<?php
declare(strict_types=1);

/**
 * VisitTimerStop — close the job timers nobody stopped on a visit that is being rolled over,
 * moved or skipped without being finished (auto_rollover cron, Otto's missed-visit review).
 *
 * Same rules as the orphan-timer cron (2026-10-12 review: the rollover used to close at NOW(),
 * uncapped and unflagged, so a timer left running overnight paid the whole night):
 *   minutes capped at the owner's shift (else 12 h) — TimeclockRules::cappedTimerMinutes();
 *   end_time = start + those minutes; auto_stopped + flagged_anomaly set for the office to review;
 *   each owner's week recalculated (approved weeks are left alone by recalculateTimesheetTotals()).
 */
final class VisitTimerStop
{
    /** @return int timers closed */
    public static function stopForVisit(PDO $db, int $visitId, string $by = 'rollover cron'): int
    {
        require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';
        require_once APP_ROOT . '/Modules/Team/Services/TimeclockRules.php';
        require_once APP_ROOT . '/Modules/Team/Services/TimeEntryGuard.php';

        $stmt = $db->prepare("
            SELECT jte.id, jte.user_id, jte.start_time,
                   COALESCE(tce.total_minutes,
                       CASE WHEN tce.status = 'active' THEN TIMESTAMPDIFF(MINUTE, tce.clock_in, NOW()) ELSE NULL END
                   ) AS shift_max_minutes,
                   TIMESTAMPDIFF(MINUTE, jte.start_time, NOW()) AS raw_minutes
            FROM job_time_entries jte
            LEFT JOIN time_clock_entries tce ON tce.id = jte.clock_entry_id
            WHERE jte.visit_id = ? AND jte.status = 'active' AND jte.end_time IS NULL
        ");
        $stmt->execute([$visitId]);
        $upd = $db->prepare("
            UPDATE job_time_entries
            SET end_time         = DATE_ADD(start_time, INTERVAL ? MINUTE),
                duration_minutes = ?,
                status           = 'completed',
                auto_stopped     = 1,
                flagged_anomaly  = 1,
                notes            = CONCAT(COALESCE(notes, ''), ?)
            WHERE id = ? AND status = 'active' AND end_time IS NULL
        ");
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $raw    = (int)$t['raw_minutes'];
            $shift  = $t['shift_max_minutes'] !== null ? (int)$t['shift_max_minutes'] : null;
            $capped = TimeclockRules::cappedTimerMinutes($raw, $shift, 12);
            $note   = ' [Auto-stopped by ' . $by . ' — nobody stopped it; check the time]'
                    . ($capped < $raw ? ' [auto-capped: ' . $raw . ' min → ' . $capped . ' min]' : '');
            $upd->execute([$capped, $capped, $note, (int)$t['id']]);
            TimeEntryGuard::recalcWeeks((int)$t['user_id'], [(string)$t['start_time']]);
            $n++;
        }
        return $n;
    }
}
