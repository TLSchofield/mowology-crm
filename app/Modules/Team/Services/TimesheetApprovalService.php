<?php
/**
 * TimesheetApprovalService — the ONE way a week's timesheet is approved.
 *
 * Codex review 2026-10-14: payroll-summary.php's "approve all" ran a bare UPDATE that skipped the
 * three checks the single approval (Team/Api/timesheets.php) makes. Both now come here:
 *   1. nobody approves their own week;
 *   2. TimeEntryGuard::approveRefusal — no shift or job timer still running in that week;
 *   3. the totals are recalculated just before they are locked.
 * The status update only moves a week not yet approved, so a second click changes nothing.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */

require_once __DIR__ . '/TimeEntryGuard.php';

class TimesheetApprovalService
{
    /** A rejected week may be approved after it is fixed, as the single approval always allowed. */
    public const APPROVABLE = ['submitted', 'pending', 'rejected'];

    /** @var callable(int $userId, string $weekStart): void */
    private $recalc;

    public function __construct(private PDO $db, ?callable $recalc = null)
    {
        $this->recalc = $recalc ?? function (int $userId, string $weekStart): void {
            if (!function_exists('recalculateTimesheetTotals')) {
                require_once __DIR__ . '/TimeclockFunctions.php';
            }
            recalculateTimesheetTotals($userId, $weekStart);
        };
    }

    /** Why this reviewer can't approve this week, or null. Pure. */
    public static function selfRefusal(int $timesheetUserId, int $reviewerId): ?string
    {
        return $timesheetUserId === $reviewerId ? 'You cannot approve your own timesheet.' : null;
    }

    /**
     * Approve one week. Returns null on success, or the reason it was refused.
     */
    public function approve(int $timesheetId, int $reviewerId, ?string $notes = null): ?string
    {
        // Hold the crew member's clock and job-timer locks (the ones clockIn/startJobTimer take) so a
        // timer can't start between the open-timer check and the approval (Codex review 2026-10-14).
        $s = $this->db->prepare("SELECT user_id FROM timesheets WHERE id = ?");
        $s->execute([$timesheetId]);
        $uid = (int)$s->fetchColumn();
        if ($uid > 0 && function_exists('withTimeclockUserLock')) {
            return withTimeclockUserLock('clock', $uid, fn() =>
                withTimeclockUserLock('job', $uid, fn() => $this->approveLocked($timesheetId, $reviewerId, $notes)));
        }
        return $this->approveLocked($timesheetId, $reviewerId, $notes);
    }

    private function approveLocked(int $timesheetId, int $reviewerId, ?string $notes): ?string
    {
        $s = $this->db->prepare("SELECT id, user_id, week_start, status FROM timesheets WHERE id = ?");
        $s->execute([$timesheetId]);
        $ts = $s->fetch(PDO::FETCH_ASSOC);
        if (!$ts) return 'Timesheet not found.';
        if (!in_array($ts['status'], self::APPROVABLE, true)) return 'Timesheet is already ' . $ts['status'] . '.';
        if ($why = self::selfRefusal((int)$ts['user_id'], $reviewerId)) return $why;
        if ($why = TimeEntryGuard::approveRefusal($this->db, (int)$ts['user_id'], (string)$ts['week_start'])) return $why;

        ($this->recalc)((int)$ts['user_id'], (string)$ts['week_start']);

        $u = $this->db->prepare("UPDATE timesheets SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), reviewer_notes = ?
                                 WHERE id = ? AND status IN ('submitted', 'pending', 'rejected')");
        $u->execute([$reviewerId, $notes ?: null, $timesheetId]);
        return $u->rowCount() === 1 ? null : 'Timesheet changed while approving — refresh and try again.';
    }

    /**
     * Approve several weeks. @return array{approved:int, refused:array<int,string>} refused = timesheet id => reason
     */
    public function approveMany(array $timesheetIds, int $reviewerId): array
    {
        $approved = 0; $refused = [];
        foreach (array_unique(array_map('intval', $timesheetIds)) as $id) {
            if ($id <= 0) continue;
            $why = $this->approve($id, $reviewerId);
            if ($why === null) $approved++; else $refused[$id] = $why;
        }
        return ['approved' => $approved, 'refused' => $refused];
    }
}
