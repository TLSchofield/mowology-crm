<?php
/**
 * Timesheet Detail — Single employee's weekly breakdown
 * Shows daily clock entries + job time entries with approve/reject + pay summary.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';
require_once APP_ROOT . '/Modules/Team/Services/OvertimeCalculator.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();

$timesheetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$timesheetId) {
    header('Location: /crm/timeclock/timesheets.php');
    exit;
}

// Fetch timesheet + employee hourly rate
$stmt = $db->prepare("
    SELECT ts.*, u.full_name as employee_name, u.email as employee_email,
           u.hourly_rate,
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
$canEdit    = in_array($user['role'], ['admin', 'manager']);

// Get clock entries for this week.
// Managers see voided entries too (so they can audit); employees see only active/completed/edited.
if ($canEdit) {
    $ceStmt = $db->prepare("
        SELECT * FROM time_clock_entries
        WHERE user_id = ?
          AND DATE(clock_in) BETWEEN ? AND ?
        ORDER BY clock_in ASC
    ");
    $ceStmt->execute([$ts['user_id'], $ts['week_start'], $ts['week_end']]);
    $clockEntries = $ceStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $clockEntries = getClockEntriesForRange($ts['user_id'], $ts['week_start'], $ts['week_end']);
}

// Get job time entries for this week
$jobEntries = getJobTimeEntriesForRange($ts['user_id'], $ts['week_start'], $ts['week_end']);

// Build 7-day skeleton (Mon–Sun)
$days = [];
$currentDate = new DateTime($ts['week_start']);
for ($i = 0; $i < 7; $i++) {
    $dateStr = $currentDate->format('Y-m-d');
    $days[$dateStr] = [
        'date'          => $dateStr,
        'day_name'      => $currentDate->format('l'),
        'display'       => $currentDate->format('M j'),
        'clock_entries' => [],
        'job_entries'   => [],
        'total_shift_min' => 0,
        'total_job_min'   => 0,
    ];
    $currentDate->modify('+1 day');
}

foreach ($clockEntries as $ce) {
    $day = date('Y-m-d', strtotime($ce['clock_in']));
    if (isset($days[$day])) {
        $days[$day]['clock_entries'][] = $ce;
        // Only count completed/edited entries in the OT calculation (matches recalculateTimesheetTotals)
        if (in_array($ce['status'] ?? '', ['completed', 'edited'])) {
            $days[$day]['total_shift_min'] += (int)($ce['total_minutes'] ?? 0);
        }
    }
}

foreach ($jobEntries as $je) {
    $day = date('Y-m-d', strtotime($je['start_time']));
    if (isset($days[$day])) {
        $days[$day]['job_entries'][] = $je;
        $days[$day]['total_job_min'] += (int)($je['duration_minutes'] ?? 0);
    }
}

// Flag days where job time exceeds shift time — indicates a runaway timer.
foreach ($days as $date => &$day) {
    $day['has_anomaly']      = false;
    $day['anomaly_excess_min'] = 0;
    if ($day['total_job_min'] > $day['total_shift_min'] && $day['total_shift_min'] > 0) {
        $day['has_anomaly']      = true;
        $day['anomaly_excess_min'] = $day['total_job_min'] - $day['total_shift_min'];
    }
}
unset($day);

// Compute pay via OvertimeCalculator
$dailyMinutes = array_map(fn($d) => $d['total_shift_min'], $days);
$hourlyRate   = (float)($ts['hourly_rate'] ?? 0);
$pay          = OvertimeCalculator::calculate($dailyMinutes, $hourlyRate);

$pageTitle = 'Timesheet — ' . htmlspecialchars($ts['employee_name']);
$activePage = 'timeclock';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-1">
            <?php echo htmlspecialchars($ts['employee_name']); ?>
            <span class="mw-ts-status mw-ts-status-<?php echo htmlspecialchars($ts['status']); ?>"
                  style="font-size:0.75rem; vertical-align: middle; margin-left:8px;">
                <?php echo ucfirst(htmlspecialchars($ts['status'])); ?>
            </span>
        </h1>
        <p class="text-muted mb-0">
            Week of <?php echo date('M j', strtotime($ts['week_start'])); ?> &ndash;
            <?php echo date('M j, Y', strtotime($ts['week_end'])); ?>
        </p>
    </div>
    <div class="d-flex" style="gap:8px;">
        <?php if ($canEdit): ?>
        <a href="/crm/timeclock/payroll-summary.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="dollar-sign" style="width:14px;height:14px;"></i> Payroll
        </a>
        <?php endif; ?>
        <a href="/crm/timeclock/timesheets.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Back
        </a>
    </div>
</div>

<!-- Hours summary row -->
<div class="row mb-3">
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
                <?php if ($pay['has_overtime']): ?>
                    <span class="mw-ot-badge" style="font-size:0.85rem;">OT</span>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </div>
            <div class="mw-schedule-stat-label">Overtime</div>
        </div>
    </div>
</div>

<!-- Pay summary row -->
<?php if ($hourlyRate > 0): ?>
<div class="mw-pay-summary mb-4" id="js-pay-summary">
    <div class="mw-pay-card">
        <div class="mw-pay-card-label">Rate</div>
        <div class="mw-pay-card-value"><?php echo OvertimeCalculator::formatPay($hourlyRate); ?>/h</div>
    </div>
    <div class="mw-pay-card">
        <div class="mw-pay-card-label">Regular</div>
        <div class="mw-pay-card-value"><?php echo OvertimeCalculator::minutesToHours($pay['regular_minutes']); ?></div>
        <div class="mw-pay-card-sub"><?php echo OvertimeCalculator::formatPay($pay['regular_pay']); ?></div>
    </div>
    <?php if ($pay['ot_1_5_minutes'] > 0): ?>
    <div class="mw-pay-card mw-pay-card--ot">
        <div class="mw-pay-card-label">OT 1.5&times;</div>
        <div class="mw-pay-card-value"><?php echo OvertimeCalculator::minutesToHours($pay['ot_1_5_minutes']); ?></div>
        <div class="mw-pay-card-sub"><?php echo OvertimeCalculator::formatPay($pay['ot_1_5_pay']); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($pay['ot_2_0_minutes'] > 0): ?>
    <div class="mw-pay-card mw-pay-card--ot">
        <div class="mw-pay-card-label">OT 2&times;</div>
        <div class="mw-pay-card-value"><?php echo OvertimeCalculator::minutesToHours($pay['ot_2_0_minutes']); ?></div>
        <div class="mw-pay-card-sub"><?php echo OvertimeCalculator::formatPay($pay['ot_2_0_pay']); ?></div>
    </div>
    <?php endif; ?>
    <div class="mw-pay-card mw-pay-card--gross">
        <div class="mw-pay-card-label">Gross Pay</div>
        <div class="mw-pay-card-value"><?php echo OvertimeCalculator::formatPay($pay['gross_pay']); ?></div>
        <div class="mw-pay-card-sub">BC overtime rules applied</div>
    </div>
</div>
<?php endif; ?>

<!-- Daily Breakdown -->
<?php foreach ($days as $day):
    $dayPayBreakdown = $pay['daily_breakdown'][$day['date']] ?? null;
    $dayHasOt = $dayPayBreakdown ? $dayPayBreakdown['has_daily_ot'] : false;
    $dayShift = $day['total_shift_min'];
    $dayJob   = $day['total_job_min'];
?>
<div class="mw-ts-day-row<?php echo $dayHasOt ? ' mw-ts-day-row--ot' : ''; ?>">
    <div class="mw-ts-day-header">
        <span class="mw-ts-day-name">
            <?php echo htmlspecialchars($day['day_name']); ?>,
            <?php echo htmlspecialchars($day['display']); ?>
            <?php if ($dayHasOt): ?>
                <span class="mw-ot-badge">OT</span>
            <?php endif; ?>
        </span>
        <span class="mw-ts-day-hours">
            <?php if ($dayShift > 0): ?>
                <?php echo formatMinutesAsHours($dayShift); ?> shift
                <?php if ($dayJob > 0): ?>
                    / <?php echo formatMinutesAsHours($dayJob); ?> on jobs
                <?php endif; ?>
            <?php else: ?>
                <span style="color: #aaa;">No hours</span>
            <?php endif; ?>
        </span>
    </div>

    <?php if (!empty($day['has_anomaly'])): ?>
    <div class="mw-anomaly-alert" role="alert">
        <i data-feather="alert-triangle" style="width:14px;height:14px;margin-right:5px;"></i>
        <strong>Data anomaly:</strong> Job time
        (<?php echo formatMinutesAsHours($day['total_job_min']); ?>) exceeds shift time
        (<?php echo formatMinutesAsHours($day['total_shift_min']); ?>) by
        <?php echo formatMinutesAsHours($day['anomaly_excess_min']); ?>.
        A timer likely ran overnight. Review job entries below — void or correct before approving.
    </div>
    <?php endif; ?>

    <?php if (!empty($day['clock_entries'])): ?>
        <?php foreach ($day['clock_entries'] as $ce):
            $isVoided = ($ce['status'] ?? '') === 'void';
        ?>
        <div class="mw-ts-entry<?php echo $isVoided ? ' mw-entry-voided' : ''; ?>"
             data-entry-id="<?php echo (int)$ce['id']; ?>">
            <span class="mw-ts-entry-time">
                <i data-feather="log-in" style="width:12px;height:12px;color:var(--mw-green);"></i>
                <?php echo date('g:i A', strtotime($ce['clock_in'])); ?>
                &rarr;
                <?php echo $ce['clock_out'] ? date('g:i A', strtotime($ce['clock_out'])) : '<em>active</em>'; ?>
            </span>
            <span class="mw-ts-entry-job">
                Shift<?php echo $isVoided ? ' <span class="text-muted small">(voided)</span>' : ''; ?>
            </span>
            <span class="mw-ts-entry-duration">
                <?php echo $isVoided ? '—' : formatMinutesAsHours((int)($ce['total_minutes'] ?? 0)); ?>
            </span>
            <?php if ($canEdit && !$isVoided): ?>
            <span class="mw-entry-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary js-edit-entry"
                        data-id="<?php echo (int)$ce['id']; ?>"
                        data-clock-in="<?php echo htmlspecialchars($ce['clock_in']); ?>"
                        data-clock-out="<?php echo htmlspecialchars($ce['clock_out'] ?? ''); ?>"
                        data-notes="<?php echo htmlspecialchars($ce['notes'] ?? ''); ?>"
                        title="Edit entry">
                    <i data-feather="edit-2" style="width:11px;height:11px;"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger js-void-entry"
                        data-id="<?php echo (int)$ce['id']; ?>"
                        title="Void entry">
                    <i data-feather="trash-2" style="width:11px;height:11px;"></i>
                </button>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($day['job_entries'])): ?>
        <?php foreach ($day['job_entries'] as $je):
            $jeIsAnomaly  = !empty($je['flagged_anomaly']);
            $jeStartDay   = date('Y-m-d', strtotime($je['start_time']));
            $jeEndDay     = $je['end_time'] ? date('Y-m-d', strtotime($je['end_time'])) : null;
            $jeSpansDay   = $jeEndDay && $jeStartDay !== $jeEndDay;
        ?>
        <div class="mw-ts-entry<?php echo $jeIsAnomaly ? ' mw-entry-anomaly' : ''; ?>"
             data-job-entry-id="<?php echo (int)$je['id']; ?>">
            <span class="mw-ts-entry-time">
                <i data-feather="briefcase" style="width:12px;height:12px;color:var(--mw-orange);"></i>
                <?php echo date('g:i A', strtotime($je['start_time'])); ?>
                &rarr;
                <?php if ($je['end_time']): ?>
                    <?php echo date('g:i A', strtotime($je['end_time'])); ?>
                    <?php if ($jeSpansDay): ?>
                        <span class="badge badge-warning ml-1"
                              title="Timer ended on a different day: <?php echo date('M j', strtotime($je['end_time'])); ?>">
                            <?php echo date('M j', strtotime($je['end_time'])); ?>
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    <em>active</em>
                <?php endif; ?>
            </span>
            <span class="mw-ts-entry-job">
                <?php echo htmlspecialchars($je['job_number'] . ' — ' . ($je['job_title'] ?? '')); ?>
                <?php if ($jeIsAnomaly): ?>
                    <span class="badge badge-warning ml-1" title="Timer was auto-capped or flagged as anomalous">capped</span>
                <?php endif; ?>
                <br><small class="text-muted"><?php echo htmlspecialchars($je['property_address'] ?? ''); ?></small>
            </span>
            <span class="mw-ts-entry-duration">
                <?php echo formatMinutesAsHours((int)($je['duration_minutes'] ?? 0)); ?>
            </span>
            <?php if ($canEdit): ?>
            <span class="mw-entry-actions">
                <button type="button" class="btn btn-sm btn-outline-danger js-void-job-entry"
                        data-id="<?php echo (int)$je['id']; ?>"
                        title="Void this job timer entry">
                    <i data-feather="trash-2" style="width:11px;height:11px;"></i>
                </button>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($day['clock_entries']) && empty($day['job_entries'])): ?>
        <div class="mw-ts-entry" style="color: #bbb;">
            <span>No activity recorded</span>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <div style="padding: 2px 12px 8px;">
        <button type="button" class="mw-day-add-btn js-add-entry"
                data-date="<?php echo htmlspecialchars($day['date']); ?>"
                data-user-id="<?php echo (int)$ts['user_id']; ?>">
            <i data-feather="plus" style="width:12px;height:12px;"></i>
            Add manual entry
        </button>
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

<?php
// Check for any days with data anomalies across the week
$weekHasAnomalies = !empty(array_filter($days, fn($d) => !empty($d['has_anomaly'])));
?>
<?php if ($weekHasAnomalies): ?>
<div class="alert alert-warning d-flex align-items-center mb-2" role="alert"
     style="border-left:4px solid var(--mw-orange);background:#fff8f0;font-size:0.85rem;">
    <i data-feather="alert-triangle" style="width:16px;height:16px;margin-right:8px;flex-shrink:0;"></i>
    <span>
        <strong>Anomalies detected.</strong>
        One or more days show job time exceeding shift time. Review and correct timer entries before approving.
    </span>
</div>
<?php endif; ?>

<div class="mw-ts-action-bar">
    <form id="tsApproveForm" method="post" action="/crm/api/timesheets.php"
          style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; width:100%;">
        <input type="hidden" name="timesheet_id" value="<?php echo (int)$ts['id']; ?>">
        <input type="hidden" name="csrf_token"   value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
        <input type="hidden" name="action"        id="tsFormAction" value="approve">

        <div style="flex:1; min-width:200px;">
            <label class="small text-muted">Notes (optional)</label>
            <textarea name="notes" id="tsReviewerNotes" class="form-control form-control-sm" rows="2"
                      placeholder="Optional reviewer notes..."></textarea>
        </div>

        <!-- Approve: intercepted by JS to run route reconciliation check first -->
        <button type="button" id="tsApproveBtn" class="mw-ts-approve-btn">
            <i data-feather="check" style="width:14px;height:14px;"></i> Approve
        </button>
        <button type="submit" name="action" value="reject" class="mw-ts-reject-btn"
                onclick="document.getElementById('tsFormAction').value='reject';">
            <i data-feather="x" style="width:14px;height:14px;"></i> Reject
        </button>
    </form>
</div>

<!-- Route Reconciliation Check Modal -->
<div class="modal fade" id="reconModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content" style="border-radius:12px; border:none;">
            <div class="modal-header" style="background:var(--mw-green);color:#fff;border-radius:12px 12px 0 0;">
                <h5 class="modal-title">
                    <i data-feather="map" style="width:16px;height:16px;margin-right:6px;"></i>
                    Route Reconciliation Check
                </h5>
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:0.8;">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body" id="reconModalBody">
                <div class="text-center py-3">
                    <div class="spinner-border text-success" role="status" style="width:2rem;height:2rem;"></div>
                    <p class="mt-2 text-muted small">Checking GPS route data for this week&hellip;</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" id="reconProceedBtn" class="btn btn-sm"
                        style="background:var(--mw-green);color:#fff;" disabled>
                    <i data-feather="check" style="width:13px;height:13px;"></i>
                    Approve Anyway
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var weekStart = <?php echo json_encode($ts['week_start']); ?>;
    var weekEnd   = <?php echo json_encode($ts['week_end']); ?>;

    document.getElementById('tsApproveBtn').addEventListener('click', function () {
        document.getElementById('reconModalBody').innerHTML =
            '<div class="text-center py-3">' +
            '<div class="spinner-border text-success" role="status" style="width:2rem;height:2rem;"></div>' +
            '<p class="mt-2 text-muted small">Checking GPS route data for this week&hellip;</p>' +
            '</div>';
        document.getElementById('reconProceedBtn').disabled = true;
        $('#reconModal').modal('show');

        fetch('/crm/api/route-reconciliation.php?start=' + weekStart + '&end=' + weekEnd)
            .then(function(r){ return r.json(); })
            .then(function(data) {
                var conflicts = data.conflicts || [];
                var html = '';

                if (conflicts.length === 0) {
                    html = '<div class="alert alert-success mb-0">' +
                           '<i data-feather="check-circle" style="width:15px;height:15px;margin-right:6px;"></i>' +
                           '<strong>No route conflicts detected.</strong> GPS data matches scheduled visits.' +
                           '</div>';
                    document.getElementById('reconProceedBtn').textContent = 'Approve';
                } else {
                    html = '<p class="text-muted small mb-2">' + conflicts.length +
                           ' conflict(s) detected. Review before approving.</p>';
                    html += '<div class="list-group">';
                    conflicts.forEach(function(c) {
                        var badgeClass = c.severity === 'warning' ? 'badge-warning' : 'badge-info';
                        var icon = c.type === 'truck_at_site_no_clockin' ? 'alert-triangle' : 'info';
                        html += '<div class="list-group-item list-group-item-action py-2 px-3" style="font-size:0.83rem;">' +
                                '<div class="d-flex justify-content-between align-items-start">' +
                                '<strong>' + (c.visit_number || '') + '</strong>' +
                                '<span class="badge ' + badgeClass + ' ml-2">' + c.type.replace(/_/g,' ') + '</span>' +
                                '</div>' +
                                '<div class="text-muted">' + (c.property_address || '') + (c.property_city ? ', ' + c.property_city : '') + '</div>' +
                                '<div>' + (c.message || '') + '</div>' +
                                '</div>';
                    });
                    html += '</div>';
                    document.getElementById('reconProceedBtn').textContent = 'Approve Anyway';
                }

                document.getElementById('reconModalBody').innerHTML = html;
                document.getElementById('reconProceedBtn').disabled = false;

                // Re-init feather icons in modal
                if (window.feather) feather.replace();
            })
            .catch(function() {
                document.getElementById('reconModalBody').innerHTML =
                    '<div class="alert alert-warning mb-0">Could not load route data. You may approve without reconciliation check.</div>';
                document.getElementById('reconProceedBtn').textContent = 'Approve Anyway';
                document.getElementById('reconProceedBtn').disabled = false;
            });
    });

    document.getElementById('reconProceedBtn').addEventListener('click', function () {
        $('#reconModal').modal('hide');
        document.getElementById('tsFormAction').value = 'approve';
        document.getElementById('tsApproveForm').submit();
    });
})();
</script>

<?php endif; ?>

<?php if ($canEdit): ?>
<!-- Entry Edit Modal -->
<div class="modal fade mw-entry-modal" id="entryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="entryModalTitle">Edit Clock Entry</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modalAction" value="edit">
                <input type="hidden" id="modalEntryId" value="">
                <input type="hidden" id="modalUserId" value="<?php echo (int)$ts['user_id']; ?>">

                <div class="form-group">
                    <label class="small font-weight-bold">Clock In</label>
                    <input type="datetime-local" id="modalClockIn" class="form-control form-control-sm" required>
                </div>
                <div class="form-group">
                    <label class="small font-weight-bold">Clock Out <span class="text-muted font-weight-normal">(leave blank if still active)</span></label>
                    <input type="datetime-local" id="modalClockOut" class="form-control form-control-sm">
                </div>
                <div class="form-group">
                    <label class="small font-weight-bold">Notes</label>
                    <textarea id="modalNotes" class="form-control form-control-sm" rows="2"
                              placeholder="Optional notes..."></textarea>
                </div>
                <div id="modalError" class="alert alert-danger d-none" role="alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm js-save-entry"
                        style="background:var(--mw-green);color:#fff;">
                    <i data-feather="save" style="width:13px;height:13px;"></i>
                    <span id="modalSaveLabel">Save Changes</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var CSRF = <?php echo json_encode(generateCSRFToken()); ?>;

    function toLocalDT(mysqlStr) {
        if (!mysqlStr) return '';
        // Convert MySQL datetime to datetime-local input value (browser local)
        var d = new Date(mysqlStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        var pad = function(n){ return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) +
               'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function toMySQLDT(localStr) {
        if (!localStr) return '';
        return localStr.replace('T', ' ') + ':00';
    }

    function showModal(title, action, entryId, clockIn, clockOut, notes, userId) {
        document.getElementById('entryModalTitle').textContent = title;
        document.getElementById('modalAction').value = action;
        document.getElementById('modalEntryId').value = entryId || '';
        document.getElementById('modalUserId').value = userId || document.getElementById('modalUserId').value;
        document.getElementById('modalClockIn').value = toLocalDT(clockIn);
        document.getElementById('modalClockOut').value = toLocalDT(clockOut);
        document.getElementById('modalNotes').value = notes || '';
        document.getElementById('modalSaveLabel').textContent = action === 'add' ? 'Add Entry' : 'Save Changes';
        document.getElementById('modalError').classList.add('d-none');
        $('#entryModal').modal('show');
    }

    // Edit buttons
    document.querySelectorAll('.js-edit-entry').forEach(function(btn) {
        btn.addEventListener('click', function() {
            showModal('Edit Clock Entry', 'edit',
                btn.dataset.id,
                btn.dataset.clockIn,
                btn.dataset.clockOut,
                btn.dataset.notes,
                null
            );
        });
    });

    // Add entry buttons
    document.querySelectorAll('.js-add-entry').forEach(function(btn) {
        btn.addEventListener('click', function() {
            // Default clock-in to 8:00 AM on the day
            var defaultIn = btn.dataset.date + 'T08:00';
            showModal('Add Manual Entry', 'add', '', defaultIn, '', '', btn.dataset.userId);
        });
    });

    // Void clock entry buttons
    document.querySelectorAll('.js-void-entry').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Void this clock entry? This cannot be undone.')) return;
            apiCall({action: 'void', entry_id: parseInt(btn.dataset.id, 10), csrf_token: CSRF},
                function(data) {
                    var row = document.querySelector('.mw-ts-entry[data-entry-id="' + btn.dataset.id + '"]');
                    if (row) {
                        row.classList.add('mw-entry-voided');
                        var actions = row.querySelector('.mw-entry-actions');
                        if (actions) actions.style.display = 'none';
                        var durEl = row.querySelector('.mw-ts-entry-duration');
                        if (durEl) durEl.textContent = '—';
                        var jobEl = row.querySelector('.mw-ts-entry-job');
                        if (jobEl) jobEl.innerHTML = 'Shift <span class="text-muted small">(voided)</span>';
                    }
                }
            );
        });
    });

    // Void job timer entry buttons
    document.querySelectorAll('.js-void-job-entry').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Void this job timer entry? The timesheet will be recalculated. This cannot be undone.')) return;
            fetch('/crm/api/job-time-entry-edit.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'void', entry_id: parseInt(btn.dataset.id, 10), csrf_token: CSRF}),
            })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    alert(data.error || 'Could not void entry.');
                    return;
                }
                // Reload so recalculated totals + anomaly warnings refresh
                window.location.reload();
            })
            .catch(function() { alert('Network error. Please try again.'); });
        });
    });

    // Save modal
    document.querySelector('.js-save-entry').addEventListener('click', function() {
        var action = document.getElementById('modalAction').value;
        var payload = {
            action:     action,
            csrf_token: CSRF,
            clock_in:   toMySQLDT(document.getElementById('modalClockIn').value),
            clock_out:  toMySQLDT(document.getElementById('modalClockOut').value),
            notes:      document.getElementById('modalNotes').value,
        };
        if (action === 'edit') {
            payload.entry_id = parseInt(document.getElementById('modalEntryId').value, 10);
        } else {
            payload.user_id = parseInt(document.getElementById('modalUserId').value, 10);
        }
        apiCall(payload, function() {
            // Reload to reflect new entries + recalculated pay
            window.location.reload();
        });
    });

    function apiCall(payload, onSuccess) {
        var errEl = document.getElementById('modalError');
        if (errEl) errEl.classList.add('d-none');

        fetch('/crm/api/time-entry-edit.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!data.success) {
                if (errEl) {
                    errEl.textContent = data.error || 'An error occurred.';
                    errEl.classList.remove('d-none');
                }
                return;
            }
            $('#entryModal').modal('hide');
            if (onSuccess) onSuccess(data);
        })
        .catch(function(err) {
            if (errEl) {
                errEl.textContent = 'Network error. Please try again.';
                errEl.classList.remove('d-none');
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
