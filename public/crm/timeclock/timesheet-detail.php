<?php
/**
 * Timesheet Detail — Single employee's weekly breakdown
 * Shows daily clock entries + job time entries with approve/reject.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();

$timesheetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$timesheetId) {
    header('Location: /crm/timeclock/timesheets.php');
    exit;
}

// Fetch timesheet
$stmt = $db->prepare("
    SELECT ts.*, u.full_name as employee_name, u.email as employee_email,
           r.full_name as reviewer_name
    FROM timesheets ts
    JOIN users u ON ts.user_id = u.id
    LEFT JOIN users r ON ts.reviewed_by = r.id
    WHERE ts.id = ?
");
$stmt->execute([$timesheetId]);
$ts = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ts) {
    header('Location: /crm/timeclock/timesheets.php');
    exit;
}

// Only the employee themselves or admin/manager can view
if ($ts['user_id'] != $user['id'] && !in_array($user['role'], ['admin', 'manager'])) {
    header('Location: /crm/timeclock/my-schedule.php');
    exit;
}

$canApprove = in_array($user['role'], ['admin', 'manager']) && $ts['user_id'] != $user['id'];

// Get clock entries for this week
$clockEntries = getClockEntriesForRange($ts['user_id'], $ts['week_start'], $ts['week_end']);

// Get job time entries for this week
$jobEntries = getJobTimeEntriesForRange($ts['user_id'], $ts['week_start'], $ts['week_end']);

// Group by day
$days = [];
$currentDate = new DateTime($ts['week_start']);
for ($i = 0; $i < 7; $i++) {
    $dateStr = $currentDate->format('Y-m-d');
    $days[$dateStr] = [
        'date' => $dateStr,
        'day_name' => $currentDate->format('l'),
        'display' => $currentDate->format('M j'),
        'clock_entries' => [],
        'job_entries' => [],
        'total_shift_min' => 0,
        'total_job_min' => 0,
    ];
    $currentDate->modify('+1 day');
}

foreach ($clockEntries as $ce) {
    $day = date('Y-m-d', strtotime($ce['clock_in']));
    if (isset($days[$day])) {
        $days[$day]['clock_entries'][] = $ce;
        $days[$day]['total_shift_min'] += (int)($ce['total_minutes'] ?? 0);
    }
}

foreach ($jobEntries as $je) {
    $day = date('Y-m-d', strtotime($je['start_time']));
    if (isset($days[$day])) {
        $days[$day]['job_entries'][] = $je;
        $days[$day]['total_job_min'] += (int)($je['duration_minutes'] ?? 0);
    }
}

$pageTitle = 'Timesheet — ' . htmlspecialchars($ts['employee_name']);
$activePage = 'timeclock';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-1"><?php echo htmlspecialchars($ts['employee_name']); ?></h1>
        <p class="text-muted mb-0">
            Week of <?php echo date('M j', strtotime($ts['week_start'])); ?> &ndash;
            <?php echo date('M j, Y', strtotime($ts['week_end'])); ?>
        </p>
    </div>
    <div>
        <a href="/crm/timeclock/timesheets.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Back
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-6 col-md-3 mb-2">
        <div class="mw-schedule-stat" style="width:100%;">
            <div class="mw-schedule-stat-value"><?php echo formatMinutesAsHours((int)$ts['total_shift_minutes']); ?></div>
            <div class="mw-schedule-stat-label">Total Shift</div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
        <div class="mw-schedule-stat" style="width:100%;">
            <div class="mw-schedule-stat-value"><?php echo formatMinutesAsHours((int)$ts['total_job_minutes']); ?></div>
            <div class="mw-schedule-stat-label">On Jobs</div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
        <div class="mw-schedule-stat" style="width:100%;">
            <div class="mw-schedule-stat-value"><?php echo formatMinutesAsHours((int)$ts['total_travel_minutes']); ?></div>
            <div class="mw-schedule-stat-label">Travel/Other</div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
        <div class="mw-schedule-stat" style="width:100%;">
            <div class="mw-schedule-stat-value">
                <span class="mw-ts-status mw-ts-status-<?php echo htmlspecialchars($ts['status']); ?>" style="font-size:1rem;">
                    <?php echo ucfirst(htmlspecialchars($ts['status'])); ?>
                </span>
            </div>
            <div class="mw-schedule-stat-label">Status</div>
        </div>
    </div>
</div>

<!-- Daily Breakdown -->
<?php foreach ($days as $day): ?>
<div class="mw-ts-day-row">
    <div class="mw-ts-day-header">
        <span class="mw-ts-day-name">
            <?php echo htmlspecialchars($day['day_name']); ?>,
            <?php echo htmlspecialchars($day['display']); ?>
        </span>
        <span class="mw-ts-day-hours">
            <?php
            $dayShift = $day['total_shift_min'];
            $dayJob = $day['total_job_min'];
            if ($dayShift > 0):
            ?>
                <?php echo formatMinutesAsHours($dayShift); ?> shift
                <?php if ($dayJob > 0): ?>
                    / <?php echo formatMinutesAsHours($dayJob); ?> on jobs
                <?php endif; ?>
            <?php else: ?>
                <span style="color: #aaa;">No hours</span>
            <?php endif; ?>
        </span>
    </div>

    <?php if (!empty($day['clock_entries'])): ?>
        <?php foreach ($day['clock_entries'] as $ce): ?>
        <div class="mw-ts-entry">
            <span class="mw-ts-entry-time">
                <i data-feather="log-in" style="width:12px;height:12px;color:var(--mw-green);"></i>
                <?php echo date('g:i A', strtotime($ce['clock_in'])); ?>
                &rarr;
                <?php echo $ce['clock_out'] ? date('g:i A', strtotime($ce['clock_out'])) : '<em>active</em>'; ?>
            </span>
            <span class="mw-ts-entry-job">Shift</span>
            <span class="mw-ts-entry-duration">
                <?php echo formatMinutesAsHours((int)($ce['total_minutes'] ?? 0)); ?>
            </span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($day['job_entries'])): ?>
        <?php foreach ($day['job_entries'] as $je): ?>
        <div class="mw-ts-entry">
            <span class="mw-ts-entry-time">
                <i data-feather="briefcase" style="width:12px;height:12px;color:var(--mw-orange);"></i>
                <?php echo date('g:i A', strtotime($je['start_time'])); ?>
                &rarr;
                <?php echo $je['end_time'] ? date('g:i A', strtotime($je['end_time'])) : '<em>active</em>'; ?>
            </span>
            <span class="mw-ts-entry-job">
                <?php echo htmlspecialchars($je['job_number'] . ' — ' . ($je['job_title'] ?? '')); ?>
                <br><small class="text-muted"><?php echo htmlspecialchars($je['property_address'] ?? ''); ?></small>
            </span>
            <span class="mw-ts-entry-duration">
                <?php echo formatMinutesAsHours((int)($je['duration_minutes'] ?? 0)); ?>
            </span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($day['clock_entries']) && empty($day['job_entries'])): ?>
        <div class="mw-ts-entry" style="color: #bbb;">
            <span>No activity recorded</span>
        </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- Reviewer Notes -->
<?php if ($ts['reviewer_notes']): ?>
<div class="card mt-3">
    <div class="card-body">
        <strong>Reviewer Notes:</strong>
        <p class="mb-0"><?php echo htmlspecialchars($ts['reviewer_notes']); ?></p>
        <small class="text-muted">
            By <?php echo htmlspecialchars($ts['reviewer_name'] ?? 'Unknown'); ?>
            on <?php echo $ts['reviewed_at'] ? date('M j, Y g:i A', strtotime($ts['reviewed_at'])) : ''; ?>
        </small>
    </div>
</div>
<?php endif; ?>

<!-- Approve/Reject Actions (admin/manager only) -->
<?php if ($canApprove && in_array($ts['status'], ['pending', 'submitted'])): ?>
<div class="mw-ts-action-bar">
    <form method="post" action="/crm/api/timesheets.php" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; width:100%;">
        <input type="hidden" name="timesheet_id" value="<?php echo (int)$ts['id']; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">

        <div style="flex:1; min-width:200px;">
            <label class="small text-muted">Notes (optional)</label>
            <textarea name="notes" class="form-control form-control-sm" rows="2"
                      placeholder="Optional reviewer notes..."></textarea>
        </div>

        <button type="submit" name="action" value="approve" class="mw-ts-approve-btn">
            <i data-feather="check" style="width:14px;height:14px;"></i> Approve
        </button>
        <button type="submit" name="action" value="reject" class="mw-ts-reject-btn">
            <i data-feather="x" style="width:14px;height:14px;"></i> Reject
        </button>
    </form>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
