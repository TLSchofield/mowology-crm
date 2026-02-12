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

// Only admin/manager can access
if (!in_array($user['role'], ['admin', 'manager'])) {
    header('Location: /crm/dashboard_appstack.php');
    exit;
}

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
           (SELECT COUNT(*) FROM jobs WHERE assigned_to = u.id AND status IN ('scheduled', 'in_progress')) as active_jobs
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
                <?php if ($user['role'] === 'admin'): ?>
                <div class="mw-tracking-toggle" title="<?php echo $emp['location_tracking_enabled'] ? 'Location tracking ON' : 'Location tracking OFF'; ?>">
                    <label class="mw-toggle-switch">
                        <input type="checkbox" <?php echo $emp['location_tracking_enabled'] ? 'checked' : ''; ?>
                               onchange="toggleTracking(<?php echo (int)$emp['id']; ?>, this.checked)">
                        <span class="mw-toggle-slider"></span>
                    </label>
                    <i data-feather="navigation" style="width:13px;height:13px;" class="<?php echo $emp['location_tracking_enabled'] ? 'text-success' : 'text-muted'; ?>"></i>
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

    // Toggle location tracking
    window.toggleTracking = function(empId, enabled) {
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
                // Revert checkbox
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

        // is_active checkbox handling
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
