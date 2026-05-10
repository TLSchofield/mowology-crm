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

    // Void buttons
    document.querySelectorAll('.js-void-entry').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Void this clock entry? This cannot be undone.')) return;
            apiCall({action: 'void', entry_id: parseInt(btn.dataset.id, 10), csrf_token: CSRF},
                function(data) {
                    // Mark entry as voided in DOM
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
