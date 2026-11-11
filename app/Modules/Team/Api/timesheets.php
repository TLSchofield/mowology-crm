<?php
/**
 * Timesheets API — Approve / Reject / Recalculate
 * Handles form POST from timesheet-detail.php
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();

// Only admin/manager can approve/reject
if (!in_array($user['role'], ['admin', 'manager'])) {
    header('Location: /crm/timeclock/my-schedule.php');
    exit;
}

$action = $_POST['action'] ?? '';
$timesheetId = (int)($_POST['timesheet_id'] ?? 0);
$notes = $_POST['notes'] ?? '';
$csrfToken = $_POST['csrf_token'] ?? '';

// Validate CSRF
if (!verifyCSRFToken($csrfToken)) {
    $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
    header('Location: /crm/timeclock/timesheets.php');
    exit;
}

if (!$timesheetId || !in_array($action, ['approve', 'reject', 'reopen'])) {
    $_SESSION['flash_error'] = 'Invalid request.';
    header('Location: /crm/timeclock/timesheets.php');
    exit;
}

$db = getDB();

// Verify timesheet exists
$stmt = $db->prepare("SELECT id, user_id, status FROM timesheets WHERE id = ?");
$stmt->execute([$timesheetId]);
$ts = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ts) {
    $_SESSION['flash_error'] = 'Timesheet not found.';
    header('Location: /crm/timeclock/timesheets.php');
    exit;
}

// Can't approve your own timesheet
if ($ts['user_id'] == $user['id']) {
    $_SESSION['flash_error'] = 'You cannot approve your own timesheet.';
    header("Location: /crm/timeclock/timesheet-detail.php?id={$timesheetId}");
    exit;
}

// Reopen an approved week so its time can be corrected (edits to an approved week are refused —
// TimeEntryGuard, 2026-10-12). Back to 'submitted' with the reason appended to the review notes.
if ($action === 'reopen') {
    if ($ts['status'] !== 'approved' || trim((string)$notes) === '') {
        $_SESSION['flash_error'] = 'Only an approved timesheet can be reopened, and it needs a reason.';
        header("Location: /crm/timeclock/timesheet-detail.php?id={$timesheetId}");
        exit;
    }
    $db->prepare("
        UPDATE timesheets
        SET status = 'submitted',
            reviewer_notes = TRIM(CONCAT(COALESCE(reviewer_notes, ''), '\n', ?))
        WHERE id = ? AND status = 'approved'
    ")->execute(['Reopened ' . date('M j') . ' by ' . ($user['full_name'] ?? ('#' . $user['id'])) . ': ' . trim((string)$notes), $timesheetId]);
    if (function_exists('logActivity')) {
        logActivity($user['id'], null, 'Reopened approved timesheet #' . $timesheetId, trim((string)$notes));
    }
    $_SESSION['flash_success'] = 'Timesheet reopened. Change the time, then approve it again.';
    header("Location: /crm/timeclock/timesheet-detail.php?id={$timesheetId}");
    exit;
}

// Approving goes through the one approval (self, open timers, totals) — TimesheetApprovalService.
if ($action === 'approve') {
    require_once APP_ROOT . '/Modules/Team/Services/TimesheetApprovalService.php';
    $blocked = (new TimesheetApprovalService($db))->approve($timesheetId, (int)$user['id'], $notes ?: null);
    if ($blocked !== null) {
        $_SESSION['flash_error'] = $blocked;
    } else {
        $_SESSION['flash_success'] = 'Timesheet approved successfully.';
    }
    header("Location: /crm/timeclock/timesheet-detail.php?id={$timesheetId}");
    exit;
}

// Update status
$newStatus = 'rejected';

$stmt = $db->prepare("
    UPDATE timesheets
    SET status = ?,
        reviewed_by = ?,
        reviewed_at = NOW(),
        reviewer_notes = ?
    WHERE id = ?
");
$stmt->execute([$newStatus, $user['id'], $notes ?: null, $timesheetId]);

$_SESSION['flash_success'] = 'Timesheet ' . $newStatus . ' successfully.';
header("Location: /crm/timeclock/timesheet-detail.php?id={$timesheetId}");
exit;
