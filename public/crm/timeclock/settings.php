<?php
/**
 * Time Clock Settings — Admin-only configuration page
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('settings.edit');

$db = getDB();

// Handle form submission
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Build enabled_roles from checkboxes
        $roles = [];
        if (!empty($_POST['role_admin'])) $roles[] = 'admin';
        if (!empty($_POST['role_manager'])) $roles[] = 'manager';
        if (!empty($_POST['role_user'])) $roles[] = 'user';
        $enabledRoles = implode(',', $roles) ?: 'user';

        $settings = [
            'enabled_roles' => $enabledRoles,
            'gps_proximity_meters' => max(50, min(500, (int)($_POST['gps_proximity_meters'] ?? 150))),
            'auto_clock_out_hours' => max(1, min(24, (int)($_POST['auto_clock_out_hours'] ?? 12))),
            'require_gps_for_clock_in' => !empty($_POST['require_gps_for_clock_in']) ? '1' : '0',
            'require_gps_for_job_start' => !empty($_POST['require_gps_for_job_start']) ? '1' : '0',
        ];

        $stmt = $db->prepare("
            INSERT INTO time_clock_settings (setting_key, setting_value, updated_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
        ");

        foreach ($settings as $key => $value) {
            $stmt->execute([$key, (string)$value, $user['id']]);
        }

        $saved = true;
    }
}

// Load current settings
$enabledRolesStr = getTimeClockSetting('enabled_roles', 'admin,manager,user');
$enabledRoles = array_map('trim', explode(',', $enabledRolesStr));
$gpsProximity = (int)getTimeClockSetting('gps_proximity_meters', '150');
$autoClockOut = (int)getTimeClockSetting('auto_clock_out_hours', '12');
$requireGpsClock = getTimeClockSetting('require_gps_for_clock_in', '0');
$requireGpsJob = getTimeClockSetting('require_gps_for_job_start', '1');

$pageTitle = 'Time Clock Settings';
$activePage = 'timeclock';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Time Clock Settings</h1>
    <a href="/crm/timeclock/timesheets.php" class="btn btn-sm btn-outline-secondary">
        <i data-feather="file-text" style="width:14px;height:14px;"></i> View Timesheets
    </a>
</div>

<?php if ($saved): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Settings saved successfully.
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">

    <!-- Enabled Roles -->
    <div class="mw-tcs-section">
        <h3><i data-feather="users" style="width:16px;height:16px;"></i> Enabled Roles</h3>
        <p class="text-muted small">Choose which user roles can use the time clock system.</p>

        <div class="mw-tcs-row">
            <div class="mw-tcs-label">
                Admin
                <small>Full system administrators</small>
            </div>
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="roleAdmin" name="role_admin" value="1"
                    <?php echo in_array('admin', $enabledRoles) ? 'checked' : ''; ?>>
                <label class="custom-control-label" for="roleAdmin"></label>
            </div>
        </div>

        <div class="mw-tcs-row">
            <div class="mw-tcs-label">
                Manager
                <small>Team leads and supervisors</small>
            </div>
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="roleManager" name="role_manager" value="1"
                    <?php echo in_array('manager', $enabledRoles) ? 'checked' : ''; ?>>
                <label class="custom-control-label" for="roleManager"></label>
            </div>
        </div>

        <div class="mw-tcs-row">
            <div class="mw-tcs-label">
                User (Field Crew)
                <small>Standard employees and field workers</small>
            </div>
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="roleUser" name="role_user" value="1"
                    <?php echo in_array('user', $enabledRoles) ? 'checked' : ''; ?>>
                <label class="custom-control-label" for="roleUser"></label>
            </div>
        </div>
    </div>

    <!-- GPS Settings -->
    <div class="mw-tcs-section">
        <h3><i data-feather="map-pin" style="width:16px;height:16px;"></i> GPS & Location</h3>

        <div class="mw-tcs-row">
            <div class="mw-tcs-label">
                GPS proximity radius
                <small>How close (meters) to a property before auto-start triggers</small>
            </div>
            <div>
                <input type="number" name="gps_proximity_meters" class="form-control form-control-sm"
                       style="width: 100px;" min="50" max="500" step="10"
                       value="<?php echo $gpsProximity; ?>">
                <small class="text-muted">meters (50–500)</small>
            </div>
        </div>

        <div class="mw-tcs-row">
            <div class="mw-tcs-label">
                Require GPS for clock-in
                <small>Employee must share location to clock in</small>
            </div>
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="requireGpsClock"
                       name="require_gps_for_clock_in" value="1"
                    <?php echo $requireGpsClock === '1' ? 'checked' : ''; ?>>
                <label class="custom-control-label" for="requireGpsClock"></label>
            </div>
        </div>

        <div class="mw-tcs-row">
            <div class="mw-tcs-label">
                Require GPS for job start
                <small>Timer won't start unless GPS is available</small>
            </div>
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="requireGpsJob"
                       name="require_gps_for_job_start" value="1"
                    <?php echo $requireGpsJob === '1' ? 'checked' : ''; ?>>
                <label class="custom-control-label" for="requireGpsJob"></label>
            </div>
        </div>
    </div>

    <!-- Safety Settings -->
    <div class="mw-tcs-section">
        <h3><i data-feather="shield" style="width:16px;height:16px;"></i> Safety & Limits</h3>

        <div class="mw-tcs-row">
            <div class="mw-tcs-label">
                Auto clock-out after
                <small>Safety net: auto-end shift if employee forgets to clock out</small>
            </div>
            <div>
                <input type="number" name="auto_clock_out_hours" class="form-control form-control-sm"
                       style="width: 80px;" min="1" max="24"
                       value="<?php echo $autoClockOut; ?>">
                <small class="text-muted">hours (1–24)</small>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-lg" style="background: var(--mw-green); color: #fff; margin-top: 12px;">
        <i data-feather="save" style="width:16px;height:16px;"></i> Save Settings
    </button>
</form>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
