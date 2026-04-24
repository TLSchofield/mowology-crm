<?php
/**
 * Employee HR Profile — Full human-resources view for a single employee.
 *
 * Tabs:
 *   Overview    — name, role, contact, stats (all team.view)
 *   HR Details  — address, DOB, emergency contact (hr.view)
 *   Payroll     — SIN (masked), banking, TD1 (hr.view; edit requires hr.edit)
 *   Install App — send install link via SMS/email
 *
 * Requires: team.view  (admin or manager to open page)
 * Sensitive tab content gated behind hr.view permission
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('team.view');

$db = getDB();

$empId = (int)($_GET['id'] ?? 0);
if (!$empId) {
    header('Location: /crm/team/');
    exit;
}

// ── Load employee ──────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM time_clock_entries WHERE user_id = u.id AND status = 'active' AND clock_out IS NULL) AS is_clocked_in,
           (SELECT COALESCE(SUM(total_minutes), 0)
            FROM time_clock_entries
            WHERE user_id = u.id
              AND DATE(clock_in) BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND CURDATE()
              AND status IN ('completed', 'edited')
           ) AS week_minutes,
           (SELECT COUNT(*) FROM job_visits WHERE assigned_crew_id = u.id AND status IN ('scheduled', 'in_progress')) AS active_jobs,
           (SELECT COALESCE(SUM(total_minutes), 0) FROM time_clock_entries WHERE user_id = u.id AND status IN ('completed', 'edited')) AS total_minutes_all_time
    FROM users u
    WHERE u.id = ?
");
$stmt->execute([$empId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) {
    header('Location: /crm/team/');
    exit;
}

// ── Permissions ────────────────────────────────────────────────────────────
$canHrView  = userHasPermission('hr.view');
$canHrEdit  = userHasPermission('hr.edit');
$canTeamEdit = userHasPermission('team.edit');

// ── Decrypt helper (for display) ───────────────────────────────────────────
function decryptField(?string $encrypted): string {
    if (!$encrypted) return '';
    $key = defined('APP_ENCRYPTION_KEY') ? APP_ENCRYPTION_KEY : '';
    if (!$key) return '(encryption key not set)';
    try {
        $data = base64_decode($encrypted);
        if (strlen($data) < 16) return '';
        $iv         = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    } catch (Throwable $e) {
        return '';
    }
}

// Mask SIN for display: ### ### ###  → show only last 3
function maskSin(string $sin): string {
    $digits = preg_replace('/\D/', '', $sin);
    if (strlen($digits) < 9) return $sin ? '•••••' . substr($digits, -3) : '';
    return '••• ••• ' . substr($digits, -3);
}

// Mask driver's licence: show last 4 chars
function maskDl(string $dl): string {
    $dl = trim($dl);
    if (strlen($dl) <= 4) return $dl ? str_repeat('•', strlen($dl)) : '';
    return str_repeat('•', strlen($dl) - 4) . substr($dl, -4);
}

// Mask bank account: show last 4
function maskAccount(string $acct): string {
    $acct = trim($acct);
    if (strlen($acct) < 4) return $acct ? str_repeat('•', strlen($acct)) : '';
    return str_repeat('•', strlen($acct) - 4) . substr($acct, -4);
}

// ── Recent timesheets ──────────────────────────────────────────────────────
$recentSheets = [];
if ($canHrView || $canTeamEdit) {
    $tsStmt = $db->prepare("
        SELECT DATE(clock_in) AS work_date,
               COALESCE(SUM(total_minutes), 0) AS minutes
        FROM time_clock_entries
        WHERE user_id = ?
          AND status IN ('completed', 'edited')
          AND clock_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(clock_in)
        ORDER BY work_date DESC
        LIMIT 14
    ");
    $tsStmt->execute([$empId]);
    $recentSheets = $tsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$roleBadge = ['admin' => 'danger', 'manager' => 'warning', 'user' => 'success'][$emp['role']] ?? 'secondary';

// ── Load RBAC data for Account & Access tab ────────────────────────────────
$canManageUsers = userHasPermission('users.manage');
$canAccountTab  = $canTeamEdit || $canManageUsers;
$allRoles    = [];
$userRoleIds = [];
if ($canAccountTab) {
    try {
        $rbacCheck = $db->query("SHOW TABLES LIKE 'roles'");
        if ($rbacCheck && $rbacCheck->rowCount() > 0) {
            $allRoles = $db->query("SELECT id, name, description FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $urStmt = $db->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
            $urStmt->execute([$empId]);
            $userRoleIds = array_column($urStmt->fetchAll(PDO::FETCH_ASSOC), 'role_id');
        }
    } catch (Throwable $e) {
        // RBAC tables may not exist yet — degrade gracefully
    }
}

// ── Load training/certification data ──────────────────────────────────────
$pesticideCourseId    = null;
$pesticideCertRecord  = null;
$pesticideExamHistory = [];
$pesticideRequired    = (bool) ($emp['pesticide_training_required'] ?? false);

try {
    $courseRow = $db->query("SELECT id FROM cert_courses WHERE slug = 'bc-pesticide-applicator' LIMIT 1")->fetch();
    if ($courseRow) {
        $pesticideCourseId = (int) $courseRow['id'];

        // Best cert record for this user + course
        $crStmt = $db->prepare("
            SELECT cr.*, ces.score_pct AS session_score, ces.completed_at AS session_completed_at
            FROM cert_records cr
            LEFT JOIN cert_exam_sessions ces ON ces.id = cr.exam_session_id
            WHERE cr.user_id = ? AND cr.course_id = ?
            LIMIT 1
        ");
        $crStmt->execute([$empId, $pesticideCourseId]);
        $pesticideCertRecord = $crStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Last 5 exam attempts
        $histStmt = $db->prepare("
            SELECT id, score_pct, passed, attempt_number, started_at, completed_at, questions_count, correct_count
            FROM cert_exam_sessions
            WHERE user_id = ? AND course_id = ?
            ORDER BY started_at DESC
            LIMIT 5
        ");
        $histStmt->execute([$empId, $pesticideCourseId]);
        $pesticideExamHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // cert tables may not exist yet — degrade gracefully
}

// ── Load truck device users (for driver assignment dropdown) ───────────────
$truckUsers = [];
try {
    $truckUsers = $db->query("
        SELECT id, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))),''), full_name, email) AS display_name
        FROM users
        WHERE device_type = 'truck' AND is_active = 1
        ORDER BY display_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // device_type column may not exist yet — degrade gracefully
}

// ── Build display name from first/last (fall back to full_name) ────────────
$empFirstName = $emp['first_name'] ?? '';
$empLastName  = $emp['last_name'] ?? '';
$empDisplayName = trim($empFirstName . ' ' . $empLastName) ?: ($emp['full_name'] ?? 'Employee');

$pageTitle = h($empDisplayName) . ' — HR Profile';
$activePage = 'team';

// Load Google Maps Places API for address autocomplete
$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
if ($apiKey) {
    $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key='
        . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8')
        . '&libraries=places" defer></script>';
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Back + Header ─────────────────────────────────────────────────── -->
<div class="d-flex align-items-center mb-3">
    <a href="/crm/team/" class="btn btn-sm btn-outline-secondary mr-3">
        <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Team
    </a>
    <div>
        <h1 class="h3 mb-0"><?php echo h($empDisplayName); ?></h1>
        <span class="badge badge-<?php echo $roleBadge; ?> mr-1"><?php echo ucfirst(h($emp['role'])); ?></span>
        <?php if ($emp['is_active']): ?>
            <span class="badge badge-success">Active</span>
        <?php else: ?>
            <span class="badge badge-danger">Inactive</span>
        <?php endif; ?>
        <?php if ($emp['is_clocked_in']): ?>
            <span class="badge badge-info">Clocked In</span>
        <?php endif; ?>
    </div>
    <?php if ($canTeamEdit): ?>
    <div class="ml-auto">
        <button class="btn btn-sm btn-outline-primary" id="btn-edit-overview"
                onclick="document.getElementById('tab-overview').click(); setTimeout(()=>document.getElementById('overview-edit-panel').classList.remove('d-none'),100);">
            <i data-feather="edit-2" style="width:13px;height:13px;"></i> Edit
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Tabs ───────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4" id="hrTabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" id="tab-overview" data-toggle="tab" href="#pane-overview" role="tab">
            <i data-feather="user" style="width:14px;height:14px;"></i> Overview
        </a>
    </li>
    <?php if ($canHrView): ?>
    <li class="nav-item">
        <a class="nav-link" id="tab-hr" data-toggle="tab" href="#pane-hr" role="tab">
            <i data-feather="file-text" style="width:14px;height:14px;"></i> HR Details
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="tab-payroll" data-toggle="tab" href="#pane-payroll" role="tab">
            <i data-feather="credit-card" style="width:14px;height:14px;"></i> Payroll
        </a>
    </li>
    <?php endif; ?>
    <?php if ($canTeamEdit): ?>
    <li class="nav-item">
        <a class="nav-link" id="tab-install" data-toggle="tab" href="#pane-install" role="tab">
            <i data-feather="smartphone" style="width:14px;height:14px;"></i> Install App
        </a>
    </li>
    <?php endif; ?>
    <?php if ($canTeamEdit): ?>
    <li class="nav-item">
        <a class="nav-link" id="tab-training" data-toggle="tab" href="#pane-training" role="tab">
            <i data-feather="award" style="width:14px;height:14px;"></i> Training
            <?php if ($pesticideRequired && !$pesticideCertRecord): ?>
                <span class="badge badge-warning badge-pill ml-1" style="font-size:.65rem;">!</span>
            <?php elseif ($pesticideCertRecord && ($pesticideCertRecord['status'] ?? '') === 'active'): ?>
                <span class="badge badge-success badge-pill ml-1" style="font-size:.65rem;">✓</span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if ($canAccountTab): ?>
    <li class="nav-item">
        <a class="nav-link" id="tab-account" data-toggle="tab" href="#pane-account" role="tab">
            <i data-feather="shield" style="width:14px;height:14px;"></i> Account
        </a>
    </li>
    <?php endif; ?>
</ul>

<div class="tab-content">

<!-- ── Tab: Overview ─────────────────────────────────────────────────── -->
<div class="tab-pane fade show active" id="pane-overview" role="tabpanel">
    <div class="row">

        <!-- Profile card -->
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-body text-center py-4">
                    <div class="mw-hr-avatar mx-auto mb-3">
                        <?php echo strtoupper(substr($empDisplayName, 0, 1)); ?>
                    </div>
                    <h4 class="mb-1"><?php echo h($empDisplayName); ?></h4>
                    <p class="text-muted mb-2"><?php echo h($emp['email']); ?></p>
                    <?php if (!empty($emp['phone'])): ?>
                    <p class="text-muted mb-2"><?php echo h($emp['phone']); ?></p>
                    <?php endif; ?>
                    <hr>
                    <div class="d-flex justify-content-around text-center">
                        <div>
                            <strong><?php echo (int)$emp['active_jobs']; ?></strong>
                            <div class="small text-muted">Active Jobs</div>
                        </div>
                        <div>
                            <strong><?php echo formatMinutesAsHours((int)$emp['week_minutes']); ?></strong>
                            <div class="small text-muted">This Week</div>
                        </div>
                        <div>
                            <strong><?php echo formatMinutesAsHours((int)$emp['total_minutes_all_time']); ?></strong>
                            <div class="small text-muted">All Time</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick actions -->
            <div class="card mt-3">
                <div class="card-header"><h6 class="mb-0">Quick Actions</h6></div>
                <div class="card-body p-3" style="display:flex;flex-direction:column;gap:8px;">
                    <a href="/crm/timeclock/timesheets.php?user_id=<?php echo $empId; ?>" class="btn btn-sm btn-outline-secondary">
                        <i data-feather="clock" style="width:13px;height:13px;"></i> View Timesheets
                    </a>
                    <a href="/crm/timeclock/crew-map.php" class="btn btn-sm btn-outline-secondary">
                        <i data-feather="map-pin" style="width:13px;height:13px;"></i> Crew Map
                    </a>
                    <?php if ($canTeamEdit): ?>
                    <button class="btn btn-sm btn-outline-primary" onclick="document.getElementById('tab-install').click();">
                        <i data-feather="smartphone" style="width:13px;height:13px;"></i> Send Install Link
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Details -->
        <div class="col-md-8 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Employee Details</h6>
                    <?php if ($canTeamEdit): ?>
                    <button class="btn btn-sm btn-outline-primary" onclick="toggleOverviewEdit()">
                        <i data-feather="edit-2" style="width:13px;height:13px;"></i> Edit
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">

                    <!-- View mode -->
                    <div id="overview-view-panel">
                        <div class="row">
                            <div class="col-sm-3 mb-3">
                                <label class="mw-hr-label">First Name</label>
                                <p class="mw-hr-value"><?php echo h($empFirstName ?: '—'); ?></p>
                            </div>
                            <div class="col-sm-3 mb-3">
                                <label class="mw-hr-label">Last Name</label>
                                <p class="mw-hr-value"><?php echo h($empLastName ?: '—'); ?></p>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Email</label>
                                <p class="mw-hr-value"><?php echo h($emp['email']); ?></p>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Phone</label>
                                <p class="mw-hr-value"><?php echo h($emp['phone'] ?? '—'); ?></p>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Role</label>
                                <p class="mw-hr-value"><span class="badge badge-<?php echo $roleBadge; ?>"><?php echo ucfirst(h($emp['role'])); ?></span></p>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Hire Date</label>
                                <p class="mw-hr-value"><?php echo $emp['hire_date'] ? date('F j, Y', strtotime($emp['hire_date'])) : '—'; ?></p>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Hourly Rate</label>
                                <p class="mw-hr-value"><?php echo $emp['hourly_rate'] ? '$' . number_format((float)$emp['hourly_rate'], 2) . '/hr' : '—'; ?></p>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Device Type</label>
                                <p class="mw-hr-value"><?php echo ucfirst(h($emp['device_type'] ?? 'personal')); ?></p>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">GPS Ping Rate</label>
                                <p class="mw-hr-value"><?php echo ucfirst(h($emp['location_ping_rate'] ?? 'high')); ?></p>
                            </div>
                            <?php if (!empty($truckUsers)): ?>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Assigned Truck</label>
                                <p class="mw-hr-value">
                                <?php
                                    $assignedTruck = null;
                                    if (!empty($emp['assigned_truck_user_id'])) {
                                        foreach ($truckUsers as $tu) {
                                            if ((int)$tu['id'] === (int)$emp['assigned_truck_user_id']) {
                                                $assignedTruck = $tu['display_name'];
                                                break;
                                            }
                                        }
                                    }
                                    echo $assignedTruck ? h($assignedTruck) : '<span class="text-muted">—</span>';
                                ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            <div class="col-sm-12 mb-3">
                                <label class="mw-hr-label">Home Location (Clock-Out Reminder)</label>
                                <p class="mw-hr-value">
                                <?php if (!empty($emp['home_lat']) && !empty($emp['home_lng'])): ?>
                                    <?php echo number_format((float)$emp['home_lat'], 5); ?>, <?php echo number_format((float)$emp['home_lng'], 5); ?>
                                    <span class="text-muted small ml-1">(radius: <?php echo (int)($emp['home_radius_meters'] ?? 250); ?>m)</span>
                                <?php else: ?>
                                    <span class="text-muted">Not set — SMS reminder disabled</span>
                                <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-sm-12 mb-3">
                                <label class="mw-hr-label">Emergency Contact</label>
                                <p class="mw-hr-value"><?php echo h($emp['emergency_contact'] ?? '—'); ?></p>
                            </div>
                            <div class="col-sm-12 mb-3">
                                <label class="mw-hr-label">Notes</label>
                                <p class="mw-hr-value"><?php echo h($emp['notes'] ?? '—'); ?></p>
                            </div>
                            <div class="col-sm-12 mb-3">
                                <label class="mw-hr-label">Pre-Shift Quiz</label>
                                <p class="mw-hr-value">
                                <?php if (!empty($emp['quiz_preshift_skip'])): ?>
                                    <span class="badge badge-secondary">Skipped (exempt)</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Required</span>
                                <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-sm-6 mb-2">
                                <label class="mw-hr-label">Last Login</label>
                                <p class="mw-hr-value"><?php echo $emp['last_login'] ? date('M j, Y g:ia', strtotime($emp['last_login'])) : 'Never'; ?></p>
                            </div>
                            <div class="col-sm-6 mb-2">
                                <label class="mw-hr-label">Account Created</label>
                                <p class="mw-hr-value"><?php echo date('M j, Y', strtotime($emp['created_at'])); ?></p>
                            </div>
                        </div>
                    </div>

                    <?php if ($canTeamEdit): ?>
                    <!-- Edit mode (hidden by default) -->
                    <div id="overview-edit-panel" class="d-none">
                        <form id="overview-edit-form">
                            <input type="hidden" name="id" value="<?php echo $empId; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                            <input type="hidden" name="action" value="update">

                            <div class="row">
                                <div class="col-sm-3">
                                    <div class="form-group">
                                        <label>First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="first_name" value="<?php echo h($empFirstName); ?>" required>
                                    </div>
                                </div>
                                <div class="col-sm-3">
                                    <div class="form-group">
                                        <label>Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="last_name" value="<?php echo h($empLastName); ?>" required>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Email <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" name="email" value="<?php echo h($emp['email']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <input type="tel" class="form-control" name="phone" value="<?php echo h($emp['phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Role</label>
                                        <select class="form-control" name="role">
                                            <option value="user"    <?php echo $emp['role'] === 'user'    ? 'selected' : ''; ?>>User (Field Crew)</option>
                                            <option value="manager" <?php echo $emp['role'] === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                            <option value="admin"   <?php echo $emp['role'] === 'admin'   ? 'selected' : ''; ?>>Admin</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Hire Date</label>
                                        <input type="date" class="form-control" name="hire_date" value="<?php echo h($emp['hire_date'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Hourly Rate ($)</label>
                                        <input type="number" class="form-control" name="hourly_rate" step="0.01" min="0"
                                               value="<?php echo $emp['hourly_rate'] !== null ? h((string)$emp['hourly_rate']) : ''; ?>">
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label>Emergency Contact</label>
                                        <input type="text" class="form-control" name="emergency_contact"
                                               value="<?php echo h($emp['emergency_contact'] ?? ''); ?>"
                                               placeholder="Name — Phone">
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label>Notes</label>
                                        <textarea class="form-control" name="notes" rows="3"><?php echo h($emp['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <?php if (!empty($truckUsers)): ?>
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label>Assigned Truck <span class="text-muted">(auto-clocks out when driver clocks out)</span></label>
                                        <select class="form-control" name="assigned_truck_user_id">
                                            <option value="">— None —</option>
                                            <?php foreach ($truckUsers as $tu): ?>
                                            <option value="<?php echo (int)$tu['id']; ?>"
                                                <?php echo (int)($emp['assigned_truck_user_id'] ?? 0) === (int)$tu['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($tu['display_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label>Home Address <span class="text-muted">(for forgot-to-clock-out SMS reminder)</span></label>
                                        <input type="text" class="form-control" id="homeAddressSearch"
                                               placeholder="Start typing to search address…"
                                               value="<?php echo (!empty($emp['home_lat']) && !empty($emp['home_lng'])) ? h(($emp['home_lat'] ?? '') . ', ' . ($emp['home_lng'] ?? '')) : ''; ?>"
                                               autocomplete="off">
                                        <input type="hidden" name="home_lat" id="homeLatInput" value="<?php echo h($emp['home_lat'] ?? ''); ?>">
                                        <input type="hidden" name="home_lng" id="homeLngInput" value="<?php echo h($emp['home_lng'] ?? ''); ?>">
                                        <div class="small text-muted mt-1" id="homeAddressHint">
                                            <?php if (!empty($emp['home_lat']) && !empty($emp['home_lng'])): ?>
                                                Saved: <?php echo number_format((float)$emp['home_lat'], 6); ?>, <?php echo number_format((float)$emp['home_lng'], 6); ?>
                                            <?php else: ?>
                                                No home location saved yet.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Home Geofence Radius (metres)</label>
                                        <input type="number" class="form-control" name="home_radius_meters"
                                               value="<?php echo (int)($emp['home_radius_meters'] ?? 250); ?>"
                                               min="50" max="2000" step="50">
                                        <small class="text-muted">Default 250m. SMS sends when last GPS ping is within this distance of home.</small>
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label>New Password <span class="text-muted">(leave blank to keep current)</span></label>
                                        <input type="password" class="form-control" name="password" minlength="8"
                                               placeholder="Min. 8 characters"
                                               autocomplete="new-password" data-lpignore="true" data-1p-ignore>
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <div class="custom-control custom-switch">
                                            <input type="hidden" name="quiz_preshift_skip" value="0">
                                            <input type="checkbox" class="custom-control-input" id="quiz_preshift_skip"
                                                   name="quiz_preshift_skip" value="1"
                                                   <?php echo !empty($emp['quiz_preshift_skip']) ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="quiz_preshift_skip">
                                                Exempt from pre-shift quiz
                                                <span class="text-muted small d-block">When on, this person skips the quiz even when it's globally enabled.</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex" style="gap:8px;">
                                <button type="submit" class="btn btn-primary btn-sm" id="overview-save-btn">Save Changes</button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleOverviewEdit()">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Recent hours -->
            <?php if (!empty($recentSheets)): ?>
            <div class="card mt-3">
                <div class="card-header"><h6 class="mb-0">Recent Hours (Last 30 Days)</h6></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Date</th><th>Hours</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentSheets as $ts): ?>
                        <tr>
                            <td><?php echo date('M j', strtotime($ts['work_date'])); ?></td>
                            <td><?php echo formatMinutesAsHours((int)$ts['minutes']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div><!-- /overview pane -->

<?php if ($canHrView): ?>

<!-- ── Tab: HR Details ─────────────────────────────────────────────────── -->
<div class="tab-pane fade" id="pane-hr" role="tabpanel">
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Personal & Address Information</h6>
                    <div>
                        <span class="badge badge-warning mr-2">Confidential</span>
                        <?php if ($canHrEdit): ?>
                        <button class="btn btn-sm btn-outline-primary" onclick="toggleHrEdit()">
                            <i data-feather="edit-2" style="width:13px;height:13px;"></i> Edit
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">

                    <!-- View mode -->
                    <div id="hr-view-panel">
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Date of Birth</label>
                                <p class="mw-hr-value"><?php echo !empty($emp['date_of_birth']) ? date('F j, Y', strtotime($emp['date_of_birth'])) : '—'; ?></p>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">SIN</label>
                                <p class="mw-hr-value">
                                    <?php
                                    $sinDecrypted = decryptField($emp['sin_encrypted'] ?? '');
                                    echo $sinDecrypted ? maskSin($sinDecrypted) : '—';
                                    ?>
                                    <?php if ($canHrEdit && $sinDecrypted): ?>
                                    <button class="btn btn-xs btn-link p-0 ml-2 text-muted" onclick="revealSin(this)" data-id="<?php echo $empId; ?>">
                                        <i data-feather="eye" style="width:12px;height:12px;"></i>
                                    </button>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-sm-12 mb-3">
                                <label class="mw-hr-label">Address</label>
                                <p class="mw-hr-value">
                                    <?php
                                    $addrParts = array_filter([
                                        $emp['address'] ?? '',
                                        $emp['city'] ?? '',
                                        trim(($emp['province'] ?? '') . ' ' . ($emp['postal_code'] ?? '')),
                                    ]);
                                    echo $addrParts ? h(implode(', ', $addrParts)) : '—';
                                    ?>
                                </p>
                            </div>
                        </div>

                        <!-- Driver's Licence -->
                        <hr>
                        <p class="mw-hr-label mb-2" style="text-transform:uppercase;letter-spacing:.5px;">Driver's Licence</p>
                        <?php $dlDecrypted = decryptField($emp['dl_number_encrypted'] ?? ''); ?>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Licence Number</label>
                                <p class="mw-hr-value">
                                    <?php echo $dlDecrypted ? h(maskDl($dlDecrypted)) : '—'; ?>
                                    <?php if ($canHrEdit && $dlDecrypted): ?>
                                    <button class="btn btn-xs btn-link p-0 ml-2 text-muted" onclick="revealDl(this)" data-id="<?php echo $empId; ?>">
                                        <i data-feather="eye" style="width:12px;height:12px;"></i>
                                    </button>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-sm-2 mb-3">
                                <label class="mw-hr-label">Class</label>
                                <p class="mw-hr-value"><?php echo h($emp['dl_class'] ?? '—'); ?></p>
                            </div>
                            <div class="col-sm-2 mb-3">
                                <label class="mw-hr-label">Province</label>
                                <p class="mw-hr-value"><?php echo h($emp['dl_province'] ?? '—'); ?></p>
                            </div>
                            <div class="col-sm-4 mb-3">
                                <label class="mw-hr-label">Expiry</label>
                                <p class="mw-hr-value">
                                    <?php
                                    if (!empty($emp['dl_expiry'])) {
                                        $exp = strtotime($emp['dl_expiry']);
                                        $cls = $exp < time() ? 'text-danger font-weight-bold' : ($exp < strtotime('+90 days') ? 'text-warning font-weight-bold' : '');
                                        echo '<span class="' . $cls . '">' . date('M j, Y', $exp) . ($exp < time() ? ' — EXPIRED' : '') . '</span>';
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </p>
                            </div>
                            <div class="col-sm-2 mb-3">
                                <label class="mw-hr-label">Authorized Driver</label>
                                <p class="mw-hr-value">
                                    <?php if (!empty($emp['is_driver'])): ?>
                                    <span class="badge badge-success"><i data-feather="check" style="width:11px;height:11px;"></i> Yes</span>
                                    <?php else: ?>
                                    <span class="badge badge-secondary">No</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <?php if ($canHrEdit): ?>
                    <!-- Edit mode -->
                    <div id="hr-edit-panel" class="d-none">
                        <form id="hr-edit-form">
                            <input type="hidden" name="id" value="<?php echo $empId; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                            <input type="hidden" name="action" value="update_hr">

                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Date of Birth</label>
                                        <input type="date" class="form-control" name="date_of_birth"
                                               value="<?php echo h($emp['date_of_birth'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>SIN</label>
                                        <input type="text" class="form-control" name="sin"
                                               placeholder="Leave blank to keep current"
                                               maxlength="11" autocomplete="off">
                                        <small class="form-text text-muted">Enter 9-digit SIN. Stored encrypted.</small>
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label>Street Address</label>
                                        <input type="text" class="form-control" id="hrAddress" name="address"
                                               value="<?php echo h($emp['address'] ?? ''); ?>"
                                               placeholder="Start typing an address..." autocomplete="off">
                                    </div>
                                </div>
                                <div class="col-sm-5">
                                    <div class="form-group">
                                        <label>City</label>
                                        <input type="text" class="form-control" id="hrCity" name="city"
                                               value="<?php echo h($emp['city'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-sm-3">
                                    <div class="form-group">
                                        <label>Province</label>
                                        <select class="form-control" id="hrProvince" name="province">
                                            <option value="">—</option>
                                            <?php foreach (['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'] as $prov): ?>
                                            <option value="<?php echo $prov; ?>" <?php echo ($emp['province'] ?? '') === $prov ? 'selected' : ''; ?>><?php echo $prov; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label>Postal Code</label>
                                        <input type="text" class="form-control" id="hrPostalCode" name="postal_code"
                                               value="<?php echo h($emp['postal_code'] ?? ''); ?>"
                                               placeholder="V6B 2W9" maxlength="7">
                                    </div>
                                </div>
                            </div>

                            <hr>
                            <p class="font-weight-bold small text-muted mb-3" style="text-transform:uppercase;letter-spacing:.5px;">Driver's Licence</p>
                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Licence Number</label>
                                        <input type="text" class="form-control" name="dl_number"
                                               placeholder="Leave blank to keep current"
                                               maxlength="20" autocomplete="off">
                                        <small class="form-text text-muted">Stored encrypted.</small>
                                    </div>
                                </div>
                                <div class="col-sm-2">
                                    <div class="form-group">
                                        <label>Class</label>
                                        <select class="form-control" name="dl_class">
                                            <option value="">—</option>
                                            <?php foreach (['1','2','3','4','5','6','7','8'] as $cls): ?>
                                            <option value="<?php echo $cls; ?>" <?php echo ($emp['dl_class'] ?? '') === $cls ? 'selected' : ''; ?>><?php echo $cls; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-2">
                                    <div class="form-group">
                                        <label>Province</label>
                                        <select class="form-control" name="dl_province">
                                            <option value="">—</option>
                                            <?php foreach (['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'] as $prov): ?>
                                            <option value="<?php echo $prov; ?>" <?php echo ($emp['dl_province'] ?? '') === $prov ? 'selected' : ''; ?>><?php echo $prov; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label>Expiry Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="dl_expiry"
                                               value="<?php echo h($emp['dl_expiry'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" class="custom-control-input" id="isDriverCheck"
                                                   name="is_driver" value="1"
                                                   <?php echo !empty($emp['is_driver']) ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="isDriverCheck">
                                                Authorized Driver
                                                <small class="text-muted d-block">Enables the pre-trip inspection form and driver portal on clock-in</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex" style="gap:8px;">
                                <button type="submit" class="btn btn-primary btn-sm">Save HR Details</button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleHrEdit()">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark"><h6 class="mb-0">Privacy Notice</h6></div>
                <div class="card-body small text-muted">
                    <p>This tab contains sensitive personal information including SIN and home address.</p>
                    <p>Access is logged and restricted to authorized HR personnel only.</p>
                    <p class="mb-0">All sensitive fields are encrypted at rest using AES-256.</p>
                </div>
            </div>
        </div>
    </div>
</div><!-- /hr pane -->

<!-- ── Tab: Payroll ───────────────────────────────────────────────────── -->
<div class="tab-pane fade" id="pane-payroll" role="tabpanel">
    <div class="row">
        <div class="col-md-8">

            <!-- Banking -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Banking / Direct Deposit</h6>
                    <div>
                        <span class="badge badge-danger mr-2">Sensitive</span>
                        <?php if ($canHrEdit): ?>
                        <button class="btn btn-sm btn-outline-primary" onclick="togglePayrollEdit()">
                            <i data-feather="edit-2" style="width:13px;height:13px;"></i> Edit
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <!-- View mode -->
                    <div id="payroll-view-panel">
                        <div class="row">
                            <div class="col-sm-4 mb-3">
                                <label class="mw-hr-label">Transit (5 digits)</label>
                                <p class="mw-hr-value"><?php echo h($emp['bank_transit'] ?? '—'); ?></p>
                            </div>
                            <div class="col-sm-4 mb-3">
                                <label class="mw-hr-label">Institution (3 digits)</label>
                                <p class="mw-hr-value"><?php echo h($emp['bank_institution'] ?? '—'); ?></p>
                            </div>
                            <div class="col-sm-4 mb-3">
                                <label class="mw-hr-label">Account Number</label>
                                <p class="mw-hr-value">
                                    <?php
                                    $acctDecrypted = decryptField($emp['bank_account_encrypted'] ?? '');
                                    echo $acctDecrypted ? maskAccount($acctDecrypted) : '—';
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <?php if ($canHrEdit): ?>
                    <!-- Edit mode -->
                    <div id="payroll-edit-panel" class="d-none">
                        <form id="payroll-edit-form">
                            <input type="hidden" name="id" value="<?php echo $empId; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                            <input type="hidden" name="action" value="update_payroll">

                            <div class="row">
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label>Transit Number</label>
                                        <input type="text" class="form-control" name="bank_transit"
                                               value="<?php echo h($emp['bank_transit'] ?? ''); ?>"
                                               maxlength="5" placeholder="12345">
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label>Institution Number</label>
                                        <input type="text" class="form-control" name="bank_institution"
                                               value="<?php echo h($emp['bank_institution'] ?? ''); ?>"
                                               maxlength="3" placeholder="004">
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label>Account Number</label>
                                        <input type="text" class="form-control" name="bank_account"
                                               placeholder="Leave blank to keep current"
                                               maxlength="20" autocomplete="off">
                                        <small class="form-text text-muted">Stored encrypted.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex" style="gap:8px;">
                                <button type="submit" class="btn btn-primary btn-sm">Save Banking</button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="togglePayrollEdit()">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TD1 -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Tax Forms (TD1)</h6>
                    <?php if ($canHrEdit): ?>
                    <button class="btn btn-sm btn-outline-primary" onclick="toggleTd1Edit()">
                        <i data-feather="edit-2" style="width:13px;height:13px;"></i> Edit
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <!-- View mode -->
                    <div id="td1-view-panel">
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Federal TD1 Credits</label>
                                <p class="mw-hr-value">
                                    <?php echo $emp['td1_federal'] ? '$' . number_format((float)$emp['td1_federal'], 2) : '—'; ?>
                                </p>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="mw-hr-label">Provincial TD1 Credits</label>
                                <p class="mw-hr-value">
                                    <?php echo $emp['td1_provincial'] ? '$' . number_format((float)$emp['td1_provincial'], 2) : '—'; ?>
                                </p>
                            </div>
                            <div class="col-sm-12 mb-3">
                                <label class="mw-hr-label">TD1 / T4 Notes</label>
                                <p class="mw-hr-value"><?php echo h($emp['td1_notes'] ?? '—'); ?></p>
                            </div>
                        </div>
                    </div>

                    <?php if ($canHrEdit): ?>
                    <!-- Edit mode -->
                    <div id="td1-edit-panel" class="d-none">
                        <form id="td1-edit-form">
                            <input type="hidden" name="id" value="<?php echo $empId; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                            <input type="hidden" name="action" value="update_td1">

                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Federal TD1 Total Credits ($)</label>
                                        <input type="number" class="form-control" name="td1_federal" step="0.01" min="0"
                                               value="<?php echo h((string)($emp['td1_federal'] ?? '')); ?>"
                                               placeholder="15705.00">
                                        <small class="form-text text-muted">Basic personal amount + any applicable credits.</small>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Provincial TD1 Total Credits ($)</label>
                                        <input type="number" class="form-control" name="td1_provincial" step="0.01" min="0"
                                               value="<?php echo h((string)($emp['td1_provincial'] ?? '')); ?>"
                                               placeholder="11141.00">
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <div class="form-group">
                                        <label>Notes</label>
                                        <textarea class="form-control" name="td1_notes" rows="3"
                                                  placeholder="e.g. T4 issued Feb 2025, additional deductions..."><?php echo h($emp['td1_notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex" style="gap:8px;">
                                <button type="submit" class="btn btn-primary btn-sm">Save TD1</button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleTd1Edit()">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white"><h6 class="mb-0">Payroll Security</h6></div>
                <div class="card-body small text-muted">
                    <p>Account numbers are AES-256 encrypted and never displayed in full.</p>
                    <p>All changes to payroll information are logged.</p>
                    <p class="mb-0">Ensure direct deposit details are confirmed before first payroll run.</p>
                </div>
            </div>
        </div>
    </div>
</div><!-- /payroll pane -->

<?php endif; // canHrView ?>

<?php if ($canTeamEdit): ?>

<!-- ── Tab: Install App ───────────────────────────────────────────────── -->
<div class="tab-pane fade" id="pane-install" role="tabpanel">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Send App Install Link</h6>
                </div>
                <div class="card-body">
                    <p class="text-muted">Send <?php echo h($empDisplayName ?: 'this employee'); ?> a link to install the Mowology field app on their device.</p>

                    <?php if (!empty($emp['install_link_sent_at'])): ?>
                    <div class="alert alert-info py-2">
                        <i data-feather="check-circle" style="width:14px;height:14px;"></i>
                        Install link last sent <strong><?php echo date('M j, Y g:ia', strtotime($emp['install_link_sent_at'])); ?></strong>
                    </div>
                    <?php endif; ?>

                    <form id="install-link-form">
                        <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">

                        <div class="form-group">
                            <label>Send via</label>
                            <div class="d-flex" style="gap:12px;">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="sendViaEmail" name="send_email" value="1"
                                           <?php echo !empty($emp['email']) ? 'checked' : 'disabled'; ?>>
                                    <label class="custom-control-label" for="sendViaEmail">
                                        Email
                                        <?php if (!empty($emp['email'])): ?>
                                        <small class="text-muted">(<?php echo h($emp['email']); ?>)</small>
                                        <?php else: ?>
                                        <small class="text-danger">(no email)</small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="sendViaSms" name="send_sms" value="1"
                                           <?php echo !empty($emp['phone']) ? 'checked' : 'disabled'; ?>>
                                    <label class="custom-control-label" for="sendViaSms">
                                        SMS
                                        <?php if (!empty($emp['phone'])): ?>
                                        <small class="text-muted">(<?php echo h($emp['phone']); ?>)</small>
                                        <?php else: ?>
                                        <small class="text-danger">(no phone)</small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Install URL <span class="text-danger">*</span></label>
                            <input type="url" class="form-control" name="install_url" id="installUrl"
                                   placeholder="https://app.mowology.ca/install" required>
                            <small class="form-text text-muted">The URL the employee will open to install the app.</small>
                        </div>

                        <div class="form-group">
                            <label>Custom Message <span class="text-muted">(optional)</span></label>
                            <textarea class="form-control" name="custom_message" rows="3"
                                      placeholder="Hi [name], please install the Mowology app at the link below..."><?php echo 'Hi ' . h($empFirstName ?: 'there') . ', please install the Mowology field app using the link below. Contact us if you need help.'; ?></textarea>
                        </div>

                        <div id="install-result" class="d-none"></div>

                        <button type="submit" class="btn btn-primary" id="install-send-btn">
                            <i data-feather="send" style="width:14px;height:14px;"></i> Send Install Link
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div><!-- /install pane -->

<?php endif; // canTeamEdit ?>

<?php if ($canTeamEdit): ?>

<!-- ── Tab: Training ─────────────────────────────────────────────────────── -->
<div class="tab-pane fade" id="pane-training" role="tabpanel">
    <div class="row">

        <!-- Left: Pesticide Training Toggle + Status ─────────────────────── -->
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i data-feather="shield" class="mr-2" style="width:15px;height:15px;color:var(--mw-orange);"></i> BC Pesticide Applicator Certificate</span>
                    <?php if ($pesticideCertRecord && ($pesticideCertRecord['status'] ?? '') === 'active'): ?>
                        <span class="badge badge-success">Certified</span>
                    <?php elseif ($pesticideRequired): ?>
                        <span class="badge badge-warning">Required — Not Yet Passed</span>
                    <?php else: ?>
                        <span class="badge badge-secondary">Optional</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">

                    <?php if (!$pesticideCourseId): ?>
                        <div class="alert alert-warning mb-0">
                            <strong>Setup required.</strong> Run
                            <a href="/crm/api/run-migration-991.php" target="_blank">/crm/api/run-migration-991.php</a>
                            then
                            <a href="/crm/api/run-migration-pesticide-quiz-seed.php" target="_blank">the seed script</a>
                            to activate pesticide training.
                        </div>
                    <?php else: ?>

                        <!-- Require training toggle -->
                        <div class="d-flex align-items-center justify-content-between mb-4 p-3"
                             style="background:var(--mw-light);border-radius:6px;">
                            <div>
                                <strong style="font-size:.9rem;">Require this training for <?php echo h($empFirstName ?: $empDisplayName); ?></strong>
                                <div class="text-muted" style="font-size:.8rem;margin-top:2px;">
                                    Marks training as mandatory on their profile and flags it until they pass.
                                </div>
                            </div>
                            <div class="custom-control custom-switch ml-3">
                                <input type="checkbox" class="custom-control-input" id="pesticideToggle"
                                    <?php echo $pesticideRequired ? 'checked' : ''; ?>
                                    onchange="togglePesticideTraining(this.checked)">
                                <label class="custom-control-label" for="pesticideToggle"></label>
                            </div>
                        </div>

                        <!-- Certification status card -->
                        <?php if ($pesticideCertRecord): ?>
                            <?php
                            $certStatus = $pesticideCertRecord['status'] ?? 'pending';
                            $certScore  = (int) ($pesticideCertRecord['best_score_pct'] ?? 0);
                            $certIssued = $pesticideCertRecord['issued_at'] ?? null;
                            $certExpiry = $pesticideCertRecord['expires_at'] ?? null;
                            $statusBadge = ['active' => 'success', 'expired' => 'danger', 'revoked' => 'danger', 'pending' => 'warning'][$certStatus] ?? 'secondary';
                            ?>
                            <div class="d-flex align-items-center p-3 mb-3"
                                 style="background:#f0f9f4;border:1px solid #c3e6cb;border-radius:6px;">
                                <div style="font-size:2rem;line-height:1;margin-right:16px;">🏆</div>
                                <div class="flex-grow-1">
                                    <strong>BC Pesticide Applicator Certificate</strong>
                                    <div style="font-size:.82rem;color:#555;margin-top:3px;">
                                        Best score: <strong><?php echo $certScore; ?>%</strong>
                                        &nbsp;·&nbsp; Status: <span class="badge badge-<?php echo $statusBadge; ?>"><?php echo ucfirst(h($certStatus)); ?></span>
                                        <?php if ($certIssued): ?>
                                            &nbsp;·&nbsp; Issued: <?php echo date('M j, Y', strtotime($certIssued)); ?>
                                        <?php endif; ?>
                                        <?php if ($certExpiry): ?>
                                            &nbsp;·&nbsp; Expires: <?php echo date('M j, Y', strtotime($certExpiry)); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-light border" style="font-size:.85rem;">
                                <i data-feather="info" style="width:14px;height:14px;" class="mr-1"></i>
                                <?php echo h($empFirstName ?: $empDisplayName); ?> has not yet completed this certification.
                                <?php if ($pesticideRequired): ?>
                                    This training is <strong>required</strong> for their role.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Progress bar from last attempt -->
                        <?php if (!empty($pesticideExamHistory)): ?>
                            <?php $lastAttempt = $pesticideExamHistory[0]; ?>
                            <?php if ($lastAttempt['completed_at']): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1" style="font-size:.8rem;color:#555;">
                                    <span>Last attempt score</span>
                                    <strong><?php echo (int)$lastAttempt['score_pct']; ?>%
                                        <?php echo $lastAttempt['passed'] ? '✓ Passed' : '✗ Not yet'; ?>
                                    </strong>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar <?php echo $lastAttempt['passed'] ? 'bg-success' : 'bg-warning'; ?>"
                                         style="width:<?php echo (int)$lastAttempt['score_pct']; ?>%"></div>
                                </div>
                                <div class="text-muted mt-1" style="font-size:.75rem;">
                                    <?php echo (int)$lastAttempt['correct_count']; ?>/<?php echo (int)$lastAttempt['questions_count']; ?> correct
                                    &nbsp;·&nbsp; <?php echo date('M j, Y', strtotime($lastAttempt['completed_at'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Action buttons -->
                        <div class="d-flex gap-2 flex-wrap" style="gap:.5rem;">
                            <a href="/crm/quiz-play_appstack.php?course=bc-pesticide-applicator&user=<?php echo $empId; ?>"
                               class="btn btn-sm btn-primary">
                                <i data-feather="play" style="width:13px;height:13px;" class="mr-1"></i>
                                <?php echo $pesticideCertRecord ? 'Retake Exam' : 'Start Exam'; ?>
                            </a>
                            <a href="/crm/quiz-learn_appstack.php?category=BC+Pesticide+Applicator"
                               class="btn btn-sm btn-outline-secondary">
                                <i data-feather="book-open" style="width:13px;height:13px;" class="mr-1"></i>
                                Study Mode
                            </a>
                        </div>

                    <?php endif; // pesticideCourseId ?>
                </div>
            </div>
        </div>

        <!-- Right: Exam history ───────────────────────────────────────────── -->
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header">
                    <i data-feather="clock" class="mr-2" style="width:14px;height:14px;"></i> Exam History
                </div>
                <div class="card-body p-0">
                    <?php if (empty($pesticideExamHistory)): ?>
                        <div class="p-4 text-center text-muted" style="font-size:.85rem;">
                            No exam attempts yet.
                        </div>
                    <?php else: ?>
                        <table class="table table-sm mb-0" style="font-size:.82rem;">
                            <thead class="thead-light">
                                <tr>
                                    <th>Date</th>
                                    <th class="text-center">Score</th>
                                    <th class="text-center">Result</th>
                                    <th class="text-center">Attempt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pesticideExamHistory as $attempt): ?>
                                <tr class="<?php echo $attempt['passed'] ? 'table-success' : ''; ?>">
                                    <td class="text-muted">
                                        <?php echo $attempt['completed_at']
                                            ? date('M j, Y', strtotime($attempt['completed_at']))
                                            : '<em>In progress</em>'; ?>
                                    </td>
                                    <td class="text-center">
                                        <strong><?php echo $attempt['completed_at'] ? (int)$attempt['score_pct'] . '%' : '—'; ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($attempt['passed'] === null): ?>
                                            <span class="badge badge-info">In progress</span>
                                        <?php elseif ($attempt['passed']): ?>
                                            <span class="badge badge-success">Passed</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Not yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-muted">#<?php echo (int)$attempt['attempt_number']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Six-week study plan hint -->
            <div class="card">
                <div class="card-header" style="font-size:.82rem;">
                    <i data-feather="calendar" class="mr-1" style="width:13px;height:13px;"></i> Recommended Study Path
                </div>
                <div class="card-body p-3" style="font-size:.78rem;color:#555;line-height:1.6;">
                    <strong>6-Week Plan:</strong>
                    <ol class="pl-3 mb-0 mt-1">
                        <li>Orientation + exam rules + label basics</li>
                        <li>General pesticide characteristics + classification</li>
                        <li>Human health + exposure routes + symptoms</li>
                        <li>PPE + hierarchy of controls + safe work</li>
                        <li>Environment + drift/runoff + buffer concepts</li>
                        <li>Emergency response + equipment + mock review</li>
                    </ol>
                    <div class="mt-2 text-muted">~20–30 min/week + 1 longer mock session.</div>
                </div>
            </div>
        </div>

    </div>
</div><!-- /training pane -->

<?php endif; // canTeamEdit (training tab) ?>

<?php if ($canAccountTab): ?>

<!-- ── Tab: Account & Access ─────────────────────────────────────────────── -->
<div class="tab-pane fade" id="pane-account" role="tabpanel">
    <div class="row">

        <!-- Left column -->
        <div class="col-md-6 mb-4">

            <!-- Account Status -->
            <div class="card mb-4">
                <div class="card-header"><h6 class="mb-0">Account Status</h6></div>
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <span class="badge badge-<?php echo $emp['is_active'] ? 'success' : 'danger'; ?>" id="acc-status-badge">
                                <?php echo $emp['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                            <small class="text-muted ml-2">
                                <?php echo $emp['is_active'] ? 'Can log in to the app' : 'Login is disabled'; ?>
                            </small>
                        </div>
                        <?php if ($canTeamEdit && $empId !== $user['id']): ?>
                        <button class="btn btn-sm btn-outline-<?php echo $emp['is_active'] ? 'danger' : 'success'; ?>"
                                onclick="toggleActiveStatus(<?php echo $empId; ?>, <?php echo $emp['is_active'] ? 0 : 1; ?>)">
                            <?php echo $emp['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <label class="mw-hr-label">Last Login</label>
                            <p class="mw-hr-value mb-0"><?php echo $emp['last_login'] ? date('M j, Y g:ia', strtotime($emp['last_login'])) : 'Never'; ?></p>
                        </div>
                        <div class="col-6">
                            <label class="mw-hr-label">Account Created</label>
                            <p class="mw-hr-value mb-0"><?php echo date('M j, Y', strtotime($emp['created_at'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- App & Device Settings -->
            <?php if ($canTeamEdit): ?>
            <div class="card">
                <div class="card-header"><h6 class="mb-0">App & Device Settings</h6></div>
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <strong>Weather SMS Alerts</strong>
                            <div class="small text-muted">Salt/snow rescheduling notifications</div>
                        </div>
                        <label class="mw-toggle-switch mb-0">
                            <input type="checkbox" id="acc-weather-sms"
                                   <?php echo !empty($emp['receive_weather_sms']) ? 'checked' : ''; ?>
                                   onchange="accUpdate('receive_weather_sms', this.checked ? 1 : 0)">
                            <span class="mw-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <strong>Location Tracking</strong>
                            <div class="small text-muted">GPS position sharing while clocked in</div>
                        </div>
                        <label class="mw-toggle-switch mb-0">
                            <input type="checkbox" id="acc-tracking"
                                   <?php echo $emp['location_tracking_enabled'] ? 'checked' : ''; ?>
                                   onchange="accUpdate('location_tracking_enabled', this.checked ? 1 : 0); document.getElementById('acc-ping-row').style.display = this.checked ? '' : 'none';">
                            <span class="mw-toggle-slider"></span>
                        </label>
                    </div>
                    <div id="acc-ping-row" class="mb-3" <?php echo $emp['location_tracking_enabled'] ? '' : 'style="display:none"'; ?>>
                        <label class="mw-hr-label">GPS Ping Rate</label>
                        <select class="form-control form-control-sm" onchange="accUpdate('location_ping_rate', this.value)">
                            <option value="low"    <?php echo ($emp['location_ping_rate'] ?? 'high') === 'low'    ? 'selected' : ''; ?>>Low (every 10 min)</option>
                            <option value="medium" <?php echo ($emp['location_ping_rate'] ?? 'high') === 'medium' ? 'selected' : ''; ?>>Medium (every 2 min)</option>
                            <option value="high"   <?php echo ($emp['location_ping_rate'] ?? 'high') === 'high'   ? 'selected' : ''; ?>>High (every 30 sec)</option>
                        </select>
                    </div>
                    <div>
                        <label class="mw-hr-label">Device Type</label>
                        <select class="form-control form-control-sm" onchange="accUpdate('device_type', this.value)">
                            <option value="personal" <?php echo ($emp['device_type'] ?? 'personal') === 'personal' ? 'selected' : ''; ?>>Personal Phone — GPS during jobs only</option>
                            <option value="truck"    <?php echo ($emp['device_type'] ?? 'personal') === 'truck'    ? 'selected' : ''; ?>>Truck Tablet — GPS continuous</option>
                        </select>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /col left -->

        <!-- Right column -->
        <div class="col-md-6 mb-4">

            <!-- RBAC Roles -->
            <?php if ($canManageUsers): ?>
            <div class="card">
                <div class="card-header"><h6 class="mb-0">System Roles & Permissions</h6></div>
                <div class="card-body">
                    <?php if (!empty($allRoles)): ?>
                    <form id="acc-roles-form">
                        <input type="hidden" name="action" value="update_roles">
                        <input type="hidden" name="id" value="<?php echo $empId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                        <?php foreach ($allRoles as $r): ?>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input"
                                   name="roles[]"
                                   value="<?php echo (int)$r['id']; ?>"
                                   id="acc_role_<?php echo (int)$r['id']; ?>"
                                   <?php echo in_array((int)$r['id'], $userRoleIds) ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="acc_role_<?php echo (int)$r['id']; ?>">
                                <strong><?php echo h($r['name']); ?></strong>
                                <?php if (!empty($r['description'])): ?>
                                <small class="text-muted d-block"><?php echo h($r['description']); ?></small>
                                <?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary btn-sm" id="acc-roles-save-btn">Save Roles</button>
                        </div>
                    </form>
                    <?php else: ?>
                    <p class="text-muted mb-0">RBAC tables not configured yet.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /col right -->

    </div>
</div><!-- /account pane -->

<?php endif; // canAccountTab ?>

</div><!-- /tab-content -->

<script>
var profileCsrf = '<?php echo h(generateCSRFToken()); ?>';
var profileEmpId = <?php echo $empId; ?>;

// ── Toggle edit panels ─────────────────────────────────────────────────────
function toggleOverviewEdit() {
    var view = document.getElementById('overview-view-panel');
    var edit = document.getElementById('overview-edit-panel');
    view.classList.toggle('d-none');
    edit.classList.toggle('d-none');
    // Always clear the password input when entering edit mode so browser
    // autofill can't silently overwrite the employee's real password.
    var pw = edit.querySelector('input[name="password"]');
    if (pw) pw.value = '';
}
function toggleHrEdit() {
    document.getElementById('hr-view-panel').classList.toggle('d-none');
    document.getElementById('hr-edit-panel').classList.toggle('d-none');
}
function togglePayrollEdit() {
    document.getElementById('payroll-view-panel').classList.toggle('d-none');
    document.getElementById('payroll-edit-panel').classList.toggle('d-none');
}
function toggleTd1Edit() {
    document.getElementById('td1-view-panel').classList.toggle('d-none');
    document.getElementById('td1-edit-panel').classList.toggle('d-none');
}

// ── Generic form save ──────────────────────────────────────────────────────
function saveForm(formEl, btnEl, afterSave) {
    var data = {};
    new FormData(formEl).forEach(function(v, k) { data[k] = v; });

    var orig = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = 'Saving…';

    fetch('/crm/api/employees.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(function(r) { return r.json(); })
    .then(function(json) {
        btnEl.disabled = false;
        btnEl.innerHTML = orig;
        if (json.success) {
            if (afterSave) afterSave(json);
            else location.reload();
        } else {
            alert(json.error || 'Save failed');
        }
    })
    .catch(function() {
        btnEl.disabled = false;
        btnEl.innerHTML = orig;
        alert('Network error. Please try again.');
    });
}

// ── Overview form ──────────────────────────────────────────────────────────
var overviewForm = document.getElementById('overview-edit-form');
if (overviewForm) {
    overviewForm.addEventListener('submit', function(e) {
        e.preventDefault();
        saveForm(this, this.querySelector('[type=submit]'));
    });
}

// ── HR form ────────────────────────────────────────────────────────────────
var hrForm = document.getElementById('hr-edit-form');
if (hrForm) {
    hrForm.addEventListener('submit', function(e) {
        e.preventDefault();
        saveForm(this, this.querySelector('[type=submit]'));
    });
}

// ── Payroll form ───────────────────────────────────────────────────────────
var payrollForm = document.getElementById('payroll-edit-form');
if (payrollForm) {
    payrollForm.addEventListener('submit', function(e) {
        e.preventDefault();
        saveForm(this, this.querySelector('[type=submit]'));
    });
}

// ── TD1 form ───────────────────────────────────────────────────────────────
var td1Form = document.getElementById('td1-edit-form');
if (td1Form) {
    td1Form.addEventListener('submit', function(e) {
        e.preventDefault();
        saveForm(this, this.querySelector('[type=submit]'));
    });
}

// ── Install link form ──────────────────────────────────────────────────────
var installForm = document.getElementById('install-link-form');
if (installForm) {
    installForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var data = {};
        new FormData(this).forEach(function(v, k) { data[k] = v; });

        // Collect checkboxes manually
        data.send_email = document.getElementById('sendViaEmail') && document.getElementById('sendViaEmail').checked ? 1 : 0;
        data.send_sms   = document.getElementById('sendViaSms')   && document.getElementById('sendViaSms').checked   ? 1 : 0;

        if (!data.send_email && !data.send_sms) {
            alert('Please select at least one delivery method (email or SMS).');
            return;
        }

        var btn = document.getElementById('install-send-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Sending…';

        fetch('/crm/api/send-install-link.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            btn.disabled = false;
            btn.innerHTML = '<i data-feather="send" style="width:14px;height:14px;"></i> Send Install Link';
            feather.replace();

            var result = document.getElementById('install-result');
            result.classList.remove('d-none', 'alert-success', 'alert-danger');

            if (json.success) {
                result.className = 'alert alert-success py-2';
                result.innerHTML = '✓ ' + (json.message || 'Install link sent successfully.');
            } else {
                result.className = 'alert alert-danger py-2';
                result.innerHTML = '✗ ' + (json.error || 'Failed to send install link.');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i data-feather="send" style="width:14px;height:14px;"></i> Send Install Link';
            feather.replace();
            var result = document.getElementById('install-result');
            result.className = 'alert alert-danger py-2';
            result.classList.remove('d-none');
            result.innerHTML = '✗ Network error. Please try again.';
        });
    });
}

// ── Account tab: toggle active/inactive ───────────────────────────────────
function toggleActiveStatus(id, newActive) {
    var msg = newActive
        ? 'Activate this account? They will be able to log in.'
        : 'Deactivate this account? They will not be able to log in.';
    if (!confirm(msg)) return;
    fetch('/crm/api/employees.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'update', id: id, is_active: newActive, csrf_token: profileCsrf})
    })
    .then(function(r) { return r.json(); })
    .then(function(json) {
        if (json.success) location.reload();
        else alert(json.error || 'Failed to update status');
    })
    .catch(function() { alert('Network error'); });
}

// ── Account tab: quick field update (toggles, selects) ────────────────────
function accUpdate(field, value) {
    var body = {action: 'update', id: profileEmpId, csrf_token: profileCsrf};
    body[field] = value;
    fetch('/crm/api/employees.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
    })
    .then(function(r) { return r.json(); })
    .then(function(json) {
        if (!json.success) alert(json.error || 'Update failed');
    })
    .catch(function() { alert('Network error'); });
}

// ── Training tab: pesticide training toggle ────────────────────────────────
function togglePesticideTraining(required) {
    accUpdate('pesticide_training_required', required ? 1 : 0);
}

// ── Account tab: RBAC roles form ──────────────────────────────────────────
var accRolesForm = document.getElementById('acc-roles-form');
if (accRolesForm) {
    accRolesForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var roles = [];
        accRolesForm.querySelectorAll('input[name="roles[]"]:checked').forEach(function(cb) {
            roles.push(parseInt(cb.value, 10));
        });
        var btn = document.getElementById('acc-roles-save-btn');
        var orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Saving…';
        fetch('/crm/api/employees.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'update_roles', id: profileEmpId, roles: roles, csrf_token: profileCsrf})
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            btn.disabled = false;
            if (json.success) {
                btn.textContent = 'Saved ✓';
                setTimeout(function() { btn.textContent = orig; }, 2000);
            } else {
                btn.textContent = orig;
                alert(json.error || 'Save failed');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = orig;
            alert('Network error');
        });
    });
}

// ── Reveal SIN (AJAX) ──────────────────────────────────────────────────────
function revealSin(btn) {
    if (btn.dataset.revealed) {
        btn.closest('p').querySelector('.mw-hr-sin-val').textContent = '••• ••• ' + btn.dataset.last3;
        btn.dataset.revealed = '';
        return;
    }
    var id = btn.dataset.id;
    fetch('/crm/api/employees.php?action=get_sin&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success && json.sin) {
                var sinSpan = btn.closest('p').querySelector('.mw-hr-sin-val');
                if (!sinSpan) {
                    sinSpan = document.createElement('span');
                    sinSpan.className = 'mw-hr-sin-val';
                    btn.before(sinSpan);
                }
                sinSpan.textContent = json.sin;
                btn.dataset.revealed = '1';
                btn.dataset.last3 = json.sin.replace(/\D/g, '').slice(-3);
                setTimeout(function() {
                    if (btn.dataset.revealed) {
                        sinSpan.textContent = '••• ••• ' + btn.dataset.last3;
                        btn.dataset.revealed = '';
                    }
                }, 15000);
            } else {
                alert(json.error || 'Could not retrieve SIN');
            }
        });
}

// ── Reveal driver's licence number (AJAX) ─────────────────────────────────
function revealDl(btn) {
    if (btn.dataset.revealed) {
        var span = btn.closest('p').querySelector('.mw-hr-dl-val');
        if (span) span.textContent = '••••' + btn.dataset.last4;
        btn.dataset.revealed = '';
        return;
    }
    var id = btn.dataset.id;
    fetch('/crm/api/employees.php?action=get_dl&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success && json.dl_number) {
                var dlSpan = btn.closest('p').querySelector('.mw-hr-dl-val');
                if (!dlSpan) {
                    dlSpan = document.createElement('span');
                    dlSpan.className = 'mw-hr-dl-val';
                    btn.before(dlSpan);
                }
                dlSpan.textContent = json.dl_number;
                btn.dataset.revealed = '1';
                btn.dataset.last4 = json.dl_number.slice(-4);
                setTimeout(function() {
                    if (btn.dataset.revealed) {
                        dlSpan.textContent = '••••' + btn.dataset.last4;
                        btn.dataset.revealed = '';
                    }
                }, 15000);
            } else {
                alert(json.error || 'Could not retrieve licence number');
            }
        });
}

// ── Google Places address autocomplete ───────────────────────────────────
function initAddressAutocomplete(inputId, cityId, postalId, provinceId) {
    var input = document.getElementById(inputId);
    if (!input) return;

    if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
        setTimeout(function() { initAddressAutocomplete(inputId, cityId, postalId, provinceId); }, 200);
        return;
    }

    var ac = new google.maps.places.Autocomplete(input, {
        types: ['address'],
        componentRestrictions: { country: ['ca'] },
        fields: ['address_components', 'geometry']
    });

    ac.addListener('place_changed', function() {
        var place = ac.getPlace();
        if (!place || !place.address_components) return;

        var street = '', city = '', postal = '', province = '';
        for (var i = 0; i < place.address_components.length; i++) {
            var c = place.address_components[i];
            if (c.types.indexOf('street_number') !== -1) street = c.long_name + ' ';
            if (c.types.indexOf('route') !== -1) street += c.long_name;
            if (c.types.indexOf('locality') !== -1) city = c.long_name;
            if (c.types.indexOf('postal_code') !== -1) postal = c.long_name;
            if (c.types.indexOf('administrative_area_level_1') !== -1) province = c.short_name;
        }

        if (street.trim()) input.value = street.trim();
        var cityEl = document.getElementById(cityId);
        if (cityEl && city) cityEl.value = city;
        var postalEl = document.getElementById(postalId);
        if (postalEl && postal) postalEl.value = postal;
        if (provinceId) {
            var provEl = document.getElementById(provinceId);
            if (provEl && province) provEl.value = province;
        }
    });
}

initAddressAutocomplete('hrAddress', 'hrCity', 'hrPostalCode', 'hrProvince');

// ── Home address geocoding (Places Autocomplete) ──────────────────────────
function initHomeAddressAutocomplete() {
    var input = document.getElementById('homeAddressSearch');
    if (!input) return;
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
        setTimeout(initHomeAddressAutocomplete, 200);
        return;
    }
    var ac = new google.maps.places.Autocomplete(input, {
        types: ['geocode'],
        componentRestrictions: { country: ['ca'] }
    });
    ac.addListener('place_changed', function() {
        var place = ac.getPlace();
        if (!place.geometry || !place.geometry.location) return;
        var lat = place.geometry.location.lat();
        var lng = place.geometry.location.lng();
        document.getElementById('homeLatInput').value = lat;
        document.getElementById('homeLngInput').value = lng;
        document.getElementById('homeAddressHint').textContent =
            'Selected: ' + lat.toFixed(6) + ', ' + lng.toFixed(6);
    });
}
initHomeAddressAutocomplete();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
