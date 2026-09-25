<?php
/**
 * Crew Work Schedule Editor — Admin/Manager sets recurring weekly shift for an employee.
 * Employee sees their schedule on my-schedule.php.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('timer.override');

$db = getDB();

$empId = (int)($_GET['user_id'] ?? 0);
if (!$empId) {
    header('Location: /crm/team/');
    exit;
}

// Load employee
$stmt = $db->prepare("SELECT id, full_name, email, hourly_rate, is_active FROM users WHERE id = ?");
$stmt->execute([$empId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    header('Location: /crm/team/');
    exit;
}

// Load existing schedule rows
$stmt = $db->prepare("
    SELECT day_of_week, start_time, end_time, notes
    FROM crew_work_schedules
    WHERE user_id = ?
    ORDER BY day_of_week ASC
");
$stmt->execute([$empId]);
$scheduleRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Key by day_of_week for easy lookup
$schedule = [];
foreach ($scheduleRows as $row) {
    $schedule[(int)$row['day_of_week']] = $row;
}

$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    0 => 'Sunday',
];

$pageTitle = 'Work Schedule — ' . htmlspecialchars($emp['full_name']);
$activePage = 'team';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">
            <a href="/crm/team/profile.php?id=<?php echo (int)$empId; ?>" class="text-muted" style="text-decoration:none;">
                <?php echo htmlspecialchars($emp['full_name']); ?>
            </a>
            <span class="text-muted mx-1">/</span>
            Work Schedule
        </h1>
        <p class="text-muted mb-0">Set the recurring weekly shift this employee should follow.</p>
    </div>
    <div class="d-flex" style="gap: 8px;">
        <a href="/crm/team/profile.php?id=<?php echo (int)$empId; ?>" class="btn btn-sm btn-outline-secondary">
            <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Back to Profile
        </a>
    </div>
</div>

<!-- Flash message area -->
<div id="mwScheduleFlash"></div>

<div class="row">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0">
                    <i data-feather="calendar" style="width:16px;height:16px;margin-right:6px;"></i>
                    Weekly Schedule
                </h5>
                <span class="text-muted" style="font-size:0.85rem;">Changes save automatically</span>
            </div>
            <div class="card-body p-0">
                <form id="scheduleForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int)$empId; ?>">

                    <table class="mw-crew-sched-table">
                        <thead>
                            <tr>
                                <th style="width:130px;">Day</th>
                                <th style="width:50px;">Working</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Duration</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($days as $dow => $dayName):
                            $row = $schedule[$dow] ?? null;
                            $working = $row !== null;
                            $startVal = $row ? substr($row['start_time'], 0, 5) : '08:00';
                            $endVal   = $row ? substr($row['end_time'], 0, 5) : '16:00';
                            $notesVal = $row['notes'] ?? '';
                        ?>
                        <tr class="mw-crew-sched-row <?php echo $working ? 'mw-crew-sched-active' : 'mw-crew-sched-off'; ?>"
                            id="row-<?php echo $dow; ?>">
                            <td class="mw-crew-sched-day">
                                <strong><?php echo $dayName; ?></strong>
                            </td>
                            <td class="text-center">
                                <div class="mw-crew-sched-toggle">
                                    <input type="checkbox" id="working-<?php echo $dow; ?>"
                                           name="days[<?php echo $dow; ?>][working]"
                                           value="1"
                                           <?php echo $working ? 'checked' : ''; ?>
                                           onchange="toggleDay(<?php echo $dow; ?>, this.checked)">
                                    <label for="working-<?php echo $dow; ?>"></label>
                                </div>
                            </td>
                            <td>
                                <input type="time" class="form-control form-control-sm mw-crew-sched-time"
                                       id="start-<?php echo $dow; ?>"
                                       name="days[<?php echo $dow; ?>][start]"
                                       value="<?php echo htmlspecialchars($startVal); ?>"
                                       <?php echo !$working ? 'disabled' : ''; ?>
                                       onchange="updateDuration(<?php echo $dow; ?>)">
                            </td>
                            <td>
                                <input type="time" class="form-control form-control-sm mw-crew-sched-time"
                                       id="end-<?php echo $dow; ?>"
                                       name="days[<?php echo $dow; ?>][end]"
                                       value="<?php echo htmlspecialchars($endVal); ?>"
                                       <?php echo !$working ? 'disabled' : ''; ?>
                                       onchange="updateDuration(<?php echo $dow; ?>)">
                            </td>
                            <td class="mw-crew-sched-duration" id="dur-<?php echo $dow; ?>">
                                <?php if ($working): ?>
                                    <?php
                                    $startMin = (int)substr($startVal,0,2)*60 + (int)substr($startVal,3,2);
                                    $endMin   = (int)substr($endVal,0,2)*60 + (int)substr($endVal,3,2);
                                    $durMin   = $endMin - $startMin;
                                    if ($durMin > 0) {
                                        $h = floor($durMin/60);
                                        $m = $durMin % 60;
                                        echo $m ? "{$h}h {$m}m" : "{$h}h";
                                    } else {
                                        echo '<span class="text-danger">Invalid</span>';
                                    }
                                    ?>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       id="notes-<?php echo $dow; ?>"
                                       name="days[<?php echo $dow; ?>][notes]"
                                       value="<?php echo htmlspecialchars($notesVal); ?>"
                                       placeholder="Optional note..."
                                       maxlength="100"
                                       <?php echo !$working ? 'disabled' : ''; ?>>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="p-3 d-flex align-items-center" style="gap: 12px; border-top: 1px solid var(--mw-light);">
                        <button type="submit" class="btn btn-sm" style="background:var(--mw-green);color:#fff;min-width:120px;" id="saveBtn">
                            <i data-feather="save" style="width:14px;height:14px;"></i> Save Schedule
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSchedule()">
                            <i data-feather="trash-2" style="width:14px;height:14px;"></i> Clear All
                        </button>
                        <span id="saveStatus" class="text-muted" style="font-size:0.85rem;"></span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Schedule Summary -->
    <div class="col-12 col-lg-4 mt-3 mt-lg-0">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i data-feather="info" style="width:16px;height:16px;margin-right:6px;"></i>
                    Schedule Summary
                </h5>
            </div>
            <div class="card-body" id="scheduleSummary">
                <?php
                $weeklyHours = 0;
                $workingDays = [];
                foreach ($days as $dow => $dayName) {
                    if (isset($schedule[$dow])) {
                        $s = strtotime($schedule[$dow]['start_time']);
                        $e = strtotime($schedule[$dow]['end_time']);
                        $hrs = ($e - $s) / 3600;
                        $weeklyHours += $hrs;
                        $workingDays[] = substr($dayName, 0, 3) . ' ' . date('g:ia', $s) . '–' . date('g:ia', $e);
                    }
                }
                ?>
                <?php if (empty($schedule)): ?>
                    <p class="text-muted mb-0" style="font-size:0.9rem;">No schedule set. Check days above to define working hours.</p>
                <?php else: ?>
                    <div class="mw-crew-sched-summary">
                        <?php foreach ($workingDays as $line): ?>
                            <div class="mw-crew-sched-summary-row"><?php echo htmlspecialchars($line); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 pt-3" style="border-top:1px solid var(--mw-light);">
                        <strong style="font-size:1.1rem; color:var(--mw-green);"><?php echo number_format($weeklyHours, 1); ?>h</strong>
                        <span class="text-muted"> / week</span>
                        <?php if ($emp['hourly_rate'] > 0): ?>
                            <div class="text-muted mt-1" style="font-size:0.85rem;">
                                Est. weekly pay: $<?php echo number_format($weeklyHours * (float)$emp['hourly_rate'], 2); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Employee info card -->
        <div class="card mt-3">
            <div class="card-body p-3">
                <div class="d-flex align-items-center" style="gap:12px;">
                    <div class="mw-emp-avatar" style="width:40px;height:40px;font-size:1.1rem;flex-shrink:0;">
                        <?php echo strtoupper(substr($emp['full_name'],0,1)); ?>
                    </div>
                    <div>
                        <strong><?php echo htmlspecialchars($emp['full_name']); ?></strong>
                        <div class="text-muted" style="font-size:0.85rem;"><?php echo htmlspecialchars($emp['email']); ?></div>
                        <?php if ($emp['hourly_rate'] > 0): ?>
                            <div class="text-muted" style="font-size:0.85rem;">$<?php echo number_format((float)$emp['hourly_rate'], 2); ?>/hr</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleDay(dow, working) {
    const row = document.getElementById('row-' + dow);
    const startEl = document.getElementById('start-' + dow);
    const endEl   = document.getElementById('end-' + dow);
    const notesEl = document.getElementById('notes-' + dow);
    const durEl   = document.getElementById('dur-' + dow);

    row.className = 'mw-crew-sched-row ' + (working ? 'mw-crew-sched-active' : 'mw-crew-sched-off');
    startEl.disabled = !working;
    endEl.disabled   = !working;
    notesEl.disabled = !working;

    if (!working) {
        durEl.innerHTML = '<span class="text-muted">&mdash;</span>';
    } else {
        updateDuration(dow);
    }
}

function updateDuration(dow) {
    const startVal = document.getElementById('start-' + dow).value;
    const endVal   = document.getElementById('end-' + dow).value;
    const durEl    = document.getElementById('dur-' + dow);

    if (!startVal || !endVal) { durEl.innerHTML = '<span class="text-muted">&mdash;</span>'; return; }

    const [sh, sm] = startVal.split(':').map(Number);
    const [eh, em] = endVal.split(':').map(Number);
    const totalMin = (eh * 60 + em) - (sh * 60 + sm);

    if (totalMin <= 0) {
        durEl.innerHTML = '<span class="text-danger">Invalid</span>';
    } else {
        const h = Math.floor(totalMin / 60);
        const m = totalMin % 60;
        durEl.textContent = m ? h + 'h ' + m + 'm' : h + 'h';
    }
}

function clearSchedule() {
    if (!confirm('Clear all scheduled days for this employee?')) return;
    document.querySelectorAll('.mw-crew-sched-row input[type="checkbox"]').forEach(cb => {
        cb.checked = false;
        toggleDay(parseInt(cb.id.replace('working-', '')), false);
    });
}

document.getElementById('scheduleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveBtn');
    const status = document.getElementById('saveStatus');
    btn.disabled = true;
    btn.textContent = 'Saving…';
    status.textContent = '';

    const data = { days: {}, user_id: <?php echo (int)$empId; ?> };
    data.csrf_token = document.querySelector('[name="csrf_token"]').value;

    const dayKeys = [0,1,2,3,4,5,6];
    dayKeys.forEach(dow => {
        const cb = document.getElementById('working-' + dow);
        if (cb && cb.checked) {
            data.days[dow] = {
                start: document.getElementById('start-' + dow).value,
                end:   document.getElementById('end-' + dow).value,
                notes: document.getElementById('notes-' + dow).value
            };
        }
    });

    fetch('/crm/api/crew-schedule-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i data-feather="save" style="width:14px;height:14px;"></i> Save Schedule';
        if (typeof feather !== 'undefined') feather.replace();

        if (res.success) {
            status.innerHTML = '<span style="color:var(--mw-green);"><i data-feather="check" style="width:13px;height:13px;"></i> Saved</span>';
            if (typeof feather !== 'undefined') feather.replace();
            showFlash('Schedule saved successfully.', 'success');
        } else {
            showFlash('Error: ' + (res.error || 'Unknown error'), 'danger');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i data-feather="save" style="width:14px;height:14px;"></i> Save Schedule';
        showFlash('Network error — please try again.', 'danger');
    });
});

function showFlash(msg, type) {
    const el = document.getElementById('mwScheduleFlash');
    el.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible" role="alert">' +
        msg + '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>';
    setTimeout(() => { if (el.firstChild) el.removeChild(el.firstChild); }, 4000);
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
