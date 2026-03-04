<?php
/**
 * Team / Employee Management — Admin page for managing employees
 * List all employees, create new ones, edit roles/rates, deactivate.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('team.view');

$db = getDB();

// Get all employees (including inactive)
$showInactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';
$whereClause = $showInactive ? '' : 'WHERE u.is_active = 1';

$stmt = $db->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM time_clock_entries WHERE user_id = u.id AND status = 'active' AND clock_out IS NULL) as is_clocked_in,
           (SELECT COALESCE(SUM(total_minutes), 0) FROM time_clock_entries
            WHERE user_id = u.id
              AND DATE(clock_in) BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND CURDATE()
              AND status IN ('completed', 'edited')
           ) as week_minutes,
           (SELECT COUNT(*) FROM job_visits WHERE assigned_crew_id = u.id AND status IN ('scheduled', 'in_progress')) as active_jobs
    FROM users u
    {$whereClause}
    ORDER BY u.is_active DESC, u.full_name ASC
");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Role badge colors
$roleBadges = [
    'admin' => 'mw-ts-status-approved',
    'manager' => 'mw-ts-status-submitted',
    'user' => 'mw-ts-status-pending',
];

$pageTitle = 'Team';
$activePage = 'team';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Team</h1>
        <p class="text-muted mb-0"><?php echo count($employees); ?> employee<?php echo count($employees) !== 1 ? 's' : ''; ?></p>
    </div>
    <div class="d-flex" style="gap: 8px;">
        <a href="/crm/timeclock/crew-map.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="map-pin" style="width:14px;height:14px;"></i> Crew Map
        </a>
        <a href="/crm/timeclock/timesheets.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="file-text" style="width:14px;height:14px;"></i> Timesheets
        </a>
        <?php if ($user['role'] === 'admin'): ?>
        <button class="btn btn-sm" style="background: var(--mw-green); color:#fff;" onclick="openAddModal()">
            <i data-feather="user-plus" style="width:14px;height:14px;"></i> Add Employee
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Toggle inactive -->
<div class="mb-3">
    <?php if ($showInactive): ?>
        <a href="?" class="btn btn-sm btn-outline-secondary">Hide Inactive</a>
    <?php else: ?>
        <a href="?show_inactive=1" class="btn btn-sm btn-outline-secondary">Show Inactive</a>
    <?php endif; ?>
</div>

<!-- Employee Cards -->
<div class="row">
    <?php foreach ($employees as $emp): ?>
    <div class="col-12 col-md-6 col-lg-4 mb-3">
        <div class="mw-emp-card <?php echo !$emp['is_active'] ? 'mw-emp-inactive' : ''; ?>">
            <!-- Status dot -->
            <?php if ($emp['is_clocked_in']): ?>
                <span class="mw-emp-status-dot mw-emp-online" title="Clocked in"></span>
            <?php endif; ?>

            <div class="mw-emp-card-header">
                <div class="mw-emp-avatar">
                    <?php echo strtoupper(substr($emp['full_name'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="mw-emp-info">
                    <h5 class="mw-emp-name"><?php echo htmlspecialchars($emp['full_name'] ?? 'Unknown'); ?></h5>
                    <span class="mw-ts-status <?php echo $roleBadges[$emp['role']] ?? ''; ?>">
                        <?php echo ucfirst(htmlspecialchars($emp['role'])); ?>
                    </span>
                    <?php if (($emp['device_type'] ?? 'personal') === 'truck'): ?>
                        <span class="mw-ts-status" style="background: var(--mw-orange); color: #fff;">
                            <i data-feather="truck" style="width:11px;height:11px;margin-right:2px;"></i> Truck
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($emp['is_driver'])): ?>
                        <span class="mw-ts-status" style="background: var(--mw-forest); color: #7FD858;">
                            <i data-feather="smartphone" style="width:11px;height:11px;margin-right:2px;"></i> Driver Portal
                        </span>
                    <?php endif; ?>
                    <?php if (!$emp['is_active']): ?>
                        <span class="mw-ts-status mw-ts-status-rejected">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mw-emp-details">
                <div class="mw-emp-detail-row">
                    <i data-feather="mail" style="width:13px;height:13px;"></i>
                    <span><?php echo htmlspecialchars($emp['email']); ?></span>
                </div>
                <?php if (!empty($emp['phone'])): ?>
                <div class="mw-emp-detail-row">
                    <i data-feather="phone" style="width:13px;height:13px;"></i>
                    <span><?php echo htmlspecialchars($emp['phone']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($emp['hourly_rate'])): ?>
                <div class="mw-emp-detail-row">
                    <i data-feather="dollar-sign" style="width:13px;height:13px;"></i>
                    <span>$<?php echo number_format((float)$emp['hourly_rate'], 2); ?>/hr</span>
                </div>
                <?php endif; ?>
            </div>

            <div class="mw-emp-stats-row">
                <div class="mw-emp-mini-stat">
                    <span class="mw-emp-mini-val"><?php echo (int)$emp['active_jobs']; ?></span>
                    <span class="mw-emp-mini-label">Active Jobs</span>
                </div>
                <div class="mw-emp-mini-stat">
                    <span class="mw-emp-mini-val"><?php echo formatMinutesAsHours((int)$emp['week_minutes']); ?></span>
                    <span class="mw-emp-mini-label">This Week</span>
                </div>
                <div class="mw-emp-mini-stat">
                    <span class="mw-emp-mini-val"><?php echo $emp['last_login'] ? date('M j', strtotime($emp['last_login'])) : '&mdash;'; ?></span>
                    <span class="mw-emp-mini-label">Last Login</span>
                </div>
            </div>

            <div class="mw-emp-actions">
                <?php if ($user['role'] === 'admin' && $emp['is_active']): ?>
                    <?php if ($emp['is_clocked_in']): ?>
                    <button class="btn btn-sm btn-outline-danger" onclick="adminClockOut(<?php echo (int)$emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['full_name'] ?? 'User'), ENT_QUOTES); ?>')"
                            title="Clock Out <?php echo htmlspecialchars($emp['full_name'] ?? ''); ?>">
                        <i data-feather="log-out" style="width:13px;height:13px;"></i> Clock Out
                    </button>
                    <?php else: ?>
                    <button class="btn btn-sm" style="background:var(--mw-green);color:#fff;border:none;" onclick="adminClockIn(<?php echo (int)$emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['full_name'] ?? 'User'), ENT_QUOTES); ?>')"
                            title="Clock In <?php echo htmlspecialchars($emp['full_name'] ?? ''); ?>">
                        <i data-feather="log-in" style="width:13px;height:13px;"></i> Clock In
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($user['role'] === 'admin'): ?>
                <div class="mw-tracking-toggle" title="<?php echo !empty($emp['is_driver']) ? 'Driver Portal ON' : 'Driver Portal OFF'; ?>">
                    <label class="mw-toggle-switch">
                        <input type="checkbox" <?php echo !empty($emp['is_driver']) ? 'checked' : ''; ?>
                               onchange="toggleDriverPortal(<?php echo (int)$emp['id']; ?>, this.checked)">
                        <span class="mw-toggle-slider"></span>
                    </label>
                    <i data-feather="smartphone" style="width:13px;height:13px;" class="<?php echo !empty($emp['is_driver']) ? 'text-success' : 'text-muted'; ?>"></i>
                </div>
                <div class="mw-tracking-toggle" title="<?php echo !empty($emp['receive_weather_sms']) ? 'Weather SMS alerts ON' : 'Weather SMS alerts OFF'; ?>">
                    <label class="mw-toggle-switch">
                        <input type="checkbox" <?php echo !empty($emp['receive_weather_sms']) ? 'checked' : ''; ?>
                               onchange="toggleWeatherSms(<?php echo (int)$emp['id']; ?>, this.checked)">
                        <span class="mw-toggle-slider"></span>
                    </label>
                    <i data-feather="cloud-snow" style="width:13px;height:13px;" class="<?php echo !empty($emp['receive_weather_sms']) ? 'text-info' : 'text-muted'; ?>"></i>
                </div>
                <div class="mw-tracking-toggle" title="<?php echo $emp['location_tracking_enabled'] ? 'Location tracking ON' : 'Location tracking OFF'; ?>">
                    <label class="mw-toggle-switch">
                        <input type="checkbox" <?php echo $emp['location_tracking_enabled'] ? 'checked' : ''; ?>
                               onchange="toggleTracking(<?php echo (int)$emp['id']; ?>, this.checked)"
                               id="trackToggle_<?php echo (int)$emp['id']; ?>">
                        <span class="mw-toggle-slider"></span>
                    </label>
                    <i data-feather="navigation" style="width:13px;height:13px;" class="<?php echo $emp['location_tracking_enabled'] ? 'text-success' : 'text-muted'; ?>"></i>
                    <select class="mw-ping-rate-select" id="pingRate_<?php echo (int)$emp['id']; ?>"
                            onchange="changePingRate(<?php echo (int)$emp['id']; ?>, this.value)"
                            title="GPS ping rate"
                            style="<?php echo $emp['location_tracking_enabled'] ? '' : 'display:none;'; ?>">
                        <option value="low"<?php echo ($emp['location_ping_rate'] ?? 'high') === 'low' ? ' selected' : ''; ?>>Low</option>
                        <option value="medium"<?php echo ($emp['location_ping_rate'] ?? 'high') === 'medium' ? ' selected' : ''; ?>>Med</option>
                        <option value="high"<?php echo ($emp['location_ping_rate'] ?? 'high') === 'high' ? ' selected' : ''; ?>>High</option>
                    </select>
                </div>
                <button class="btn btn-sm btn-outline-secondary" onclick="openEditModal(<?php echo (int)$emp['id']; ?>)"
                        title="Edit">
                    <i data-feather="edit-2" style="width:13px;height:13px;"></i>
                </button>
                <?php endif; ?>
                <a href="/crm/timeclock/timesheets.php?user_id=<?php echo (int)$emp['id']; ?>"
                   class="btn btn-sm btn-outline-secondary" title="View Timesheets">
                    <i data-feather="clock" style="width:13px;height:13px;"></i>
                </a>
                <a href="/crm/team/profile.php?id=<?php echo (int)$emp['id']; ?>"
                   class="btn btn-sm btn-outline-secondary" title="HR Profile">
                    <i data-feather="user" style="width:13px;height:13px;"></i>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add/Edit Employee Modal -->
<div class="modal fade" id="employeeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--mw-forest); color: #fff;">
                <h5 class="modal-title" id="modalTitle">Add Employee</h5>
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;">
                    <span>&times;</span>
                </button>
            </div>
            <form id="employeeForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="empId" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">

                    <div class="form-group">
                        <label>Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" id="empName" required>
                    </div>

                    <div class="form-group">
                        <label>Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" id="empEmail" required>
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="tel" class="form-control" name="phone" id="empPhone">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>Role <span class="text-danger">*</span></label>
                                <select class="form-control" name="role" id="empRole" required>
                                    <option value="user">User (Field Crew)</option>
                                    <option value="manager">Manager</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Device Type</label>
                        <select class="form-control" name="device_type" id="empDeviceType">
                            <option value="personal">Personal (Phone) — GPS only during jobs</option>
                            <option value="truck">Truck (Tablet) — GPS tracks continuously</option>
                        </select>
                        <small class="form-text text-muted">Truck devices track GPS whenever the app is open, regardless of clock-in status.</small>
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>Hourly Rate ($)</label>
                                <input type="number" class="form-control" name="hourly_rate" id="empRate"
                                       step="0.01" min="0" placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>Hire Date</label>
                                <input type="date" class="form-control" name="hire_date" id="empHireDate">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Emergency Contact</label>
                        <input type="text" class="form-control" name="emergency_contact" id="empEmergency"
                               placeholder="Name — Phone">
                    </div>

                    <!-- Password (only for new employees) -->
                    <div id="passwordSection">
                        <div class="form-group">
                            <label>Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="password" id="empPassword"
                                   minlength="8" placeholder="Min. 8 characters">
                            <small class="form-text text-muted">Employee will use this to log in.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea class="form-control" name="notes" id="empNotes" rows="2"></textarea>
                    </div>

                    <!-- Active toggle (only for edit mode) -->
                    <div id="activeSection" style="display:none;">
                        <div class="custom-control custom-switch mt-2">
                            <input type="checkbox" class="custom-control-input" id="empActive" name="is_active" value="1" checked>
                            <label class="custom-control-label" for="empActive">Active Employee</label>
                        </div>
                    </div>

                    <!-- Location Tracking -->
                    <div class="mt-3 pt-3" style="border-top:1px solid #eee;">
                        <label class="d-block mb-2"><strong>Location Tracking</strong></label>
                        <div class="form-group">
                            <label>GPS Ping Rate</label>
                            <select class="form-control" name="location_ping_rate" id="empPingRate">
                                <option value="low">Low (every 10 minutes)</option>
                                <option value="medium">Medium (every 2 minutes)</option>
                                <option value="high" selected>High (every 30 seconds)</option>
                            </select>
                            <small class="form-text text-muted">How often the device sends GPS position when tracking is enabled.</small>
                        </div>
                    </div>

                    <!-- Notifications -->
                    <div class="mt-3 pt-3" style="border-top:1px solid #eee;">
                        <label class="d-block mb-2"><strong>Notifications</strong></label>
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="empWeatherSms" name="receive_weather_sms" value="1" checked>
                            <label class="custom-control-label" for="empWeatherSms">
                                Salt/Snow weather SMS alerts
                            </label>
                        </div>
                        <small class="form-text text-muted">When enabled, this person gets a text when weather causes a visit to be rescheduled. Requires a phone number.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background: var(--mw-green); color: #fff;" id="saveBtn">
                        Save Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Alert container -->
<div id="alertContainer" style="position:fixed; top: 80px; right: 20px; z-index: 10000; max-width: 400px;"></div>

<script>
(function() {
    'use strict';

    var form = document.getElementById('employeeForm');
    var modal = document.getElementById('employeeModal');

    // Admin clock-in for another user
    window.adminClockIn = function(empId, empName) {
        if (!confirm('Clock in ' + empName + '?')) return;
        fetch('/crm/api/time-clock.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'clock_in', user_id: empId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showAlert(empName + ' clocked in', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showAlert(data.error || 'Failed to clock in', 'danger');
            }
        })
        .catch(function() { showAlert('Network error', 'danger'); });
    };

    // Admin clock-out for another user
    window.adminClockOut = function(empId, empName) {
        if (!confirm('Clock out ' + empName + '?')) return;
        fetch('/crm/api/time-clock.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'clock_out', user_id: empId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showAlert(empName + ' clocked out — ' + (data.total_formatted || ''), 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showAlert(data.error || 'Failed to clock out', 'danger');
            }
        })
        .catch(function() { showAlert('Network error', 'danger'); });
    };

    // Toggle driver portal access
    window.toggleDriverPortal = function(empId, enabled) {
        fetch('/crm/api/employees.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update',
                id: empId,
                is_driver: enabled ? 1 : 0,
                csrf_token: document.querySelector('input[name="csrf_token"]').value
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showAlert('Driver Portal ' + (enabled ? 'enabled' : 'disabled'), 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showAlert(data.error || 'Failed to update', 'danger');
                setTimeout(function() { location.reload(); }, 500);
            }
        })
        .catch(function() {
            showAlert('Network error', 'danger');
            setTimeout(function() { location.reload(); }, 500);
        });
    };

    // Toggle weather SMS alerts
    window.toggleWeatherSms = function(empId, enabled) {
        fetch('/crm/api/employees.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update',
                id: empId,
                receive_weather_sms: enabled ? 1 : 0,
                csrf_token: document.querySelector('input[name="csrf_token"]').value
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showAlert('Weather SMS alerts ' + (enabled ? 'enabled' : 'disabled'), 'success');
            } else {
                showAlert(data.error || 'Failed to update', 'danger');
                setTimeout(function() { location.reload(); }, 500);
            }
        })
        .catch(function() {
            showAlert('Network error', 'danger');
            setTimeout(function() { location.reload(); }, 500);
        });
    };

    // Toggle location tracking
    window.toggleTracking = function(empId, enabled) {
        // Show/hide ping rate select
        var rateSelect = document.getElementById('pingRate_' + empId);
        if (rateSelect) rateSelect.style.display = enabled ? '' : 'none';

        fetch('/crm/api/employees.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update',
                id: empId,
                location_tracking_enabled: enabled ? 1 : 0,
                csrf_token: document.querySelector('input[name="csrf_token"]').value
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showAlert('Location tracking ' + (enabled ? 'enabled' : 'disabled'), 'success');
            } else {
                showAlert(data.error || 'Failed to update tracking', 'danger');
                setTimeout(function() { location.reload(); }, 500);
            }
        })
        .catch(function() {
            showAlert('Network error', 'danger');
            setTimeout(function() { location.reload(); }, 500);
        });
    };

    // Change GPS ping rate
    window.changePingRate = function(empId, rate) {
        var labels = { low: 'Low (10 min)', medium: 'Medium (2 min)', high: 'High (30 sec)' };
        fetch('/crm/api/employees.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update',
                id: empId,
                location_ping_rate: rate,
                csrf_token: document.querySelector('input[name="csrf_token"]').value
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showAlert('Ping rate set to ' + (labels[rate] || rate), 'success');
            } else {
                showAlert(data.error || 'Failed to update ping rate', 'danger');
                setTimeout(function() { location.reload(); }, 500);
            }
        })
        .catch(function() {
            showAlert('Network error', 'danger');
            setTimeout(function() { location.reload(); }, 500);
        });
    };

    // Open Add Modal
    window.openAddModal = function() {
        document.getElementById('modalTitle').textContent = 'Add Employee';
        form.reset();
        document.getElementById('empId').value = '';
        document.getElementById('passwordSection').style.display = '';
        document.getElementById('activeSection').style.display = 'none';
        document.getElementById('empPassword').required = true;
        $(modal).modal('show');
    };

    // Open Edit Modal — fetch employee data first
    window.openEditModal = function(id) {
        fetch('/crm/api/employees.php?action=get&id=' + id, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { showAlert(data.error || 'Failed to load employee', 'danger'); return; }
                var emp = data.employee;
                document.getElementById('modalTitle').textContent = 'Edit Employee';
                document.getElementById('empId').value = emp.id;
                document.getElementById('empName').value = emp.full_name || '';
                document.getElementById('empEmail').value = emp.email || '';
                document.getElementById('empPhone').value = emp.phone || '';
                document.getElementById('empRole').value = emp.role || 'user';
                document.getElementById('empRate').value = emp.hourly_rate || '';
                document.getElementById('empHireDate').value = emp.hire_date || '';
                document.getElementById('empEmergency').value = emp.emergency_contact || '';
                document.getElementById('empNotes').value = emp.notes || '';
                document.getElementById('empActive').checked = emp.is_active == 1;
                document.getElementById('empWeatherSms').checked = emp.receive_weather_sms != 0;
                document.getElementById('empDeviceType').value = emp.device_type || 'personal';
                document.getElementById('empPingRate').value = emp.location_ping_rate || 'high';

                // Hide password for edit, show active toggle
                document.getElementById('passwordSection').style.display = 'none';
                document.getElementById('activeSection').style.display = '';
                document.getElementById('empPassword').required = false;

                $(modal).modal('show');
            })
            .catch(function() { showAlert('Network error loading employee', 'danger'); });
    };

    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        var id = document.getElementById('empId').value;
        var action = id ? 'update' : 'create';
        var body = {};

        // Gather form data
        var formData = new FormData(form);
        formData.forEach(function(value, key) { body[key] = value; });
        body.action = action;
        if (!id) delete body.id;

        // Checkbox handling
        body.receive_weather_sms = document.getElementById('empWeatherSms').checked ? '1' : '0';
        if (id) {
            body.is_active = document.getElementById('empActive').checked ? '1' : '0';
        }

        document.getElementById('saveBtn').disabled = true;

        fetch('/crm/api/employees.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('saveBtn').disabled = false;
            if (data.success) {
                $(modal).modal('hide');
                showAlert(data.message || 'Saved!', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showAlert(data.error || 'Save failed', 'danger');
            }
        })
        .catch(function() {
            document.getElementById('saveBtn').disabled = false;
            showAlert('Network error', 'danger');
        });
    });

    function showAlert(msg, type) {
        var container = document.getElementById('alertContainer');
        var el = document.createElement('div');
        el.className = 'alert alert-' + type + ' alert-dismissible fade show';
        el.innerHTML = msg + '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>';
        container.appendChild(el);
        setTimeout(function() { el.remove(); }, 4000);
    }
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
