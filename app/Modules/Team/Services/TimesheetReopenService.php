<?php
declare(strict_types=1);

/**
 * TimesheetReopenService — reopen an approved week so its hours can be changed (Tim, 2026-10-23: "adjustable always").
 *
 * An approved timesheet is what was approved and paid, so TimeEntryGuard refuses to change it silently. The week view
 * makes the honest path one step: say why, the week goes back to "waiting for approval" with the reason kept in the
 * review notes and the activity log, the hours are changed, and it is approved again. Same rule as the form on the
 * single-person page (timesheets.php `reopen`): admin or manager, never your own week, a reason is required.
 *
 * Global namespace, no autoloader: require_once the file.
 */
class TimesheetReopenService
{
    public const MIN_REASON = 3;

    /**
     * @param array{id: int|string, role?: string, full_name?: string} $actor
     * @return ?string null when reopened (or already open); otherwise the reason it was refused
     */
    public static function reopen(PDO $db, int $timesheetId, array $actor, string $reason): ?string
    {
        if (!in_array((string)($actor['role'] ?? ''), ['admin', 'manager'], true)) return 'Only an admin or manager can reopen a timesheet.';
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) return 'Say why the approved week is being changed.';

        $s = $db->prepare("SELECT id, user_id, status FROM timesheets WHERE id = ?");
        $s->execute([$timesheetId]);
        $ts = $s->fetch(PDO::FETCH_ASSOC);
        if (!$ts) return 'That timesheet was not found.';
        if ((int)$ts['user_id'] === (int)$actor['id']) return 'You cannot reopen your own timesheet.';
        if ($ts['status'] !== 'approved') return null;   // already open: nothing to do

        $who = (string)($actor['full_name'] ?? ('#' . $actor['id']));
        $note = 'Reopened ' . date('M j') . ' by ' . $who . ': ' . $reason;
        $u = $db->prepare("
            UPDATE timesheets
            SET status = 'submitted',
                reviewer_notes = TRIM(CONCAT(COALESCE(reviewer_notes, ''), '\n', ?))
            WHERE id = ? AND status = 'approved'
        ");
        $u->execute([$note, $timesheetId]);
        // Only the request that really reopened it writes the audit line (two at once: one wins), and a failure to
        // write the log must not make a reopen that happened look like it did not.
        if ($u->rowCount() > 0 && function_exists('logActivity')) {
            try { logActivity((int)$actor['id'], null, 'Reopened approved timesheet #' . $timesheetId, $reason); }
            catch (Throwable $e) { error_log('[timesheet-reopen] activity log: ' . $e->getMessage()); }
        }
        return null;
    }
}
