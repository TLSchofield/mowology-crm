<?php
/**
 * Timesheets — Admin/Manager weekly overview of all employee hours
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('timer.override');

$db = getDB();

// Filters
$filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterWeek = isset($_GET['week']) ? $_GET['week'] : '';

// Build query
$where = [];
$params = [];

if ($filterUser) {
    $where[] = 'ts.user_id = ?';
    $params[] = $filterUser;
}

if ($filterStatus && in_array($filterStatus, ['pending', 'submitted', 'approved', 'rejected'])) {
    $where[] = 'ts.status = ?';
    $params[] = $filterStatus;
}

if ($filterWeek && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterWeek)) {
    $where[] = 'ts.week_start = ?';
    $params[] = $filterWeek;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT ts.*, u.full_name as employee_name, u.email as employee_email,
           r.full_name as reviewer_name
    FROM timesheets ts
    JOIN users u ON ts.user_id = u.id
    LEFT JOIN users r ON ts.reviewed_by = r.id
    {$whereClause}
    ORDER BY ts.week_start DESC, u.full_name ASC
    LIMIT 100
");
$stmt->execute($params);
$timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get staff for filter dropdown
$staff = getStaffMembers();

$pageTitle = 'Timesheets';
$activePage = 'timeclock';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Timesheets</h1>
    <a href="/crm/timeclock/my-schedule.php" class="btn btn-sm btn-outline-secondary">
        <i data-feather="arrow-left" style="width:14px;height:14px;"></i> My Schedule
    </a>
</div>

<!-- Filters -->
<div class="mw-ts-filters">
    <form method="get" class="d-flex flex-wrap" style="gap: 10px;">
        <select name="user_id" class="form-control form-control-sm" style="width:auto;">
            <option value="">All Employees</option>
            <?php foreach ($staff as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>"
                    <?php echo $filterUser == $s['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['full_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="form-control form-control-sm" style="width:auto;">
            <option value="">All Statuses</option>
            <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="submitted" <?php echo $filterStatus === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
            <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
        </select>

        <input type="date" name="week" value="<?php echo htmlspecialchars($filterWeek); ?>"
               class="form-control form-control-sm" style="width:auto;"
               placeholder="Week of...">

        <button type="submit" class="btn btn-sm" style="background: var(--mw-green); color: #fff;">Filter</button>
        <a href="?<?php echo ''; ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
    </form>
</div>

<!-- Timesheets Table -->
<?php if (empty($timesheets)): ?>
    <div class="mw-schedule-empty">
        <i data-feather="file-text"></i>
        <h3>No timesheets found</h3>
        <p>Timesheets are generated automatically when employees clock out.</p>
    </div>
<?php else: ?>
<div class="table-responsive">
    <table class="mw-ts-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Week</th>
                <th>Shift Hours</th>
                <th>Job Hours</th>
                <th>Travel/Other</th>
                <th>Status</th>
                <th>Reviewed By</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($timesheets as $ts): ?>
            <tr onclick="window.location='/crm/timeclock/timesheet-detail.php?id=<?php echo (int)$ts['id']; ?>'"
                style="cursor: pointer;">
                <td>
                    <strong><?php echo htmlspecialchars($ts['employee_name']); ?></strong>
                </td>
                <td>
                    <?php echo date('M j', strtotime($ts['week_start'])); ?> &ndash;
                    <?php echo date('M j, Y', strtotime($ts['week_end'])); ?>
                </td>
                <td><?php echo formatMinutesAsHours((int)$ts['total_shift_minutes']); ?></td>
                <td><?php echo formatMinutesAsHours((int)$ts['total_job_minutes']); ?></td>
                <td><?php echo formatMinutesAsHours((int)$ts['total_travel_minutes']); ?></td>
                <td>
                    <span class="mw-ts-status mw-ts-status-<?php echo htmlspecialchars($ts['status']); ?>">
                        <?php echo ucfirst(htmlspecialchars($ts['status'])); ?>
                    </span>
                </td>
                <td>
                    <?php echo $ts['reviewer_name'] ? htmlspecialchars($ts['reviewer_name']) : '&mdash;'; ?>
                </td>
                <td>
                    <a href="/crm/timeclock/timesheet-detail.php?id=<?php echo (int)$ts['id']; ?>"
                       class="btn btn-sm btn-outline-secondary">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
