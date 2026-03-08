<?php
/**
 * New Employee Onboarding Wizard — 3-step guided flow
 * Step 1: Basic Info  →  Step 2: Role & Access  →  Step 3: Send Install Link
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('team.edit');

$pageTitle = 'Add New Employee';
$activePage = 'team';
$csrfToken = generateCSRFToken();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<a href="index.php" class="mw-back-link">&larr; Back to Team</a>

<div class="mw-page-header mb-3">
    <h1 class="h3 mb-0">Add New Employee</h1>
    <p class="text-muted">Complete the steps below to set up a new team member.</p>
</div>

<!-- Wizard Stepper -->
<div class="mw-stepper mb-4" id="onboardStepper">
    <div class="mw-stepper-step active" data-wizard-step="1">
        <span class="mw-stepper-dot">1</span>
        <span class="mw-stepper-label">Basic Info</span>
    </div>
    <div class="mw-stepper-step" data-wizard-step="2">
        <span class="mw-stepper-dot">2</span>
        <span class="mw-stepper-label">Role &amp; Access</span>
    </div>
    <div class="mw-stepper-step" data-wizard-step="3">
        <span class="mw-stepper-dot">3</span>
        <span class="mw-stepper-label">App Install</span>
    </div>
</div>

<!-- Messages -->
<div id="onboardError" class="alert alert-danger" style="display:none;"></div>

<!-- ═══════════════════════════════════════════════════════════════════════
     STEP 1: Basic Info
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="mw-wizard-step" data-step="1" id="onboardStep1">
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0"><i data-feather="user" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;color:var(--mw-green);"></i>Basic Information</h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="ob_first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="ob_first_name" maxlength="100" required autofocus>
                </div>
                <div class="col-md-6">
                    <label for="ob_last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="ob_last_name" maxlength="100" required>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="ob_email" class="form-label">Email Address <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" id="ob_email" maxlength="255" required placeholder="employee@example.com">
                </div>
                <div class="col-md-6">
                    <label for="ob_phone" class="form-label">Phone</label>
                    <input type="tel" class="form-control" id="ob_phone" maxlength="20" placeholder="(604) 555-1234">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label for="ob_hourly_rate" class="form-label">Hourly Rate</label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                        <input type="number" class="form-control" id="ob_hourly_rate" min="0" step="0.50" placeholder="25.00">
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="ob_password" class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="ob_password" minlength="8" placeholder="Min 8 characters">
                </div>
                <div class="col-md-4">
                    <label for="ob_password_confirm" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="ob_password_confirm" minlength="8">
                </div>
            </div>
        </div>
    </div>
    <div class="mw-wizard-nav">
        <a href="index.php" class="btn btn-secondary">Cancel</a>
        <button type="button" class="btn btn-primary mw-wizard-next" onclick="onboardWizard.next()">
            Next: Role &amp; Access <i data-feather="arrow-right" style="width:16px;height:16px;vertical-align:middle;margin-left:4px;"></i>
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     STEP 2: Role & Access
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="mw-wizard-step" data-step="2" id="onboardStep2" style="display:none;">
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0"><i data-feather="shield" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;color:var(--mw-green);"></i>Role &amp; Permissions</h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-4">Choose the access level for this employee. You can adjust permissions later from their profile.</p>
            <div class="mw-onboard-roles">

                <!-- Field Crew -->
                <div class="mw-onboard-role-card active" data-role="user" onclick="onboardWizard.selectRole(this)">
                    <div class="mw-onboard-role-header">
                        <div class="mw-onboard-role-icon" style="background:var(--mw-light);color:var(--mw-green);">
                            <i data-feather="tool" style="width:20px;height:20px;"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 font-weight-bold">Field Crew</h6>
                            <small class="text-muted">Day-to-day field operations</small>
                        </div>
                        <div class="mw-onboard-role-radio">
                            <input type="radio" name="ob_role" value="user" checked>
                        </div>
                    </div>
                    <div class="mw-onboard-role-perms">
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> View daily schedule and job details</div>
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> Clock in/out and track time</div>
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> Upload job photos and notes</div>
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> View client contact info</div>
                        <div class="mw-perm-denied"><i data-feather="x" style="width:14px;height:14px;"></i> Cannot create or edit quotes, jobs, or invoices</div>
                        <div class="mw-perm-denied"><i data-feather="x" style="width:14px;height:14px;"></i> Cannot access financial data or reports</div>
                    </div>
                </div>

                <!-- Manager -->
                <div class="mw-onboard-role-card" data-role="manager" onclick="onboardWizard.selectRole(this)">
                    <div class="mw-onboard-role-header">
                        <div class="mw-onboard-role-icon" style="background:#e8f0fe;color:#1a56db;">
                            <i data-feather="clipboard" style="width:20px;height:20px;"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 font-weight-bold">Manager</h6>
                            <small class="text-muted">Operations and team oversight</small>
                        </div>
                        <div class="mw-onboard-role-radio">
                            <input type="radio" name="ob_role" value="manager">
                        </div>
                    </div>
                    <div class="mw-onboard-role-perms">
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> Everything Field Crew can do</div>
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> Create and edit quotes, jobs, and invoices</div>
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> Assign crew to jobs and manage schedules</div>
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> View team timesheets and reports</div>
                        <div class="mw-perm-denied"><i data-feather="x" style="width:14px;height:14px;"></i> Cannot access admin settings or HR data</div>
                    </div>
                </div>

                <!-- Admin -->
                <div class="mw-onboard-role-card" data-role="admin" onclick="onboardWizard.selectRole(this)">
                    <div class="mw-onboard-role-header">
                        <div class="mw-onboard-role-icon" style="background:#fef3c7;color:#d97706;">
                            <i data-feather="star" style="width:20px;height:20px;"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 font-weight-bold">Admin</h6>
                            <small class="text-muted">Full system access</small>
                        </div>
                        <div class="mw-onboard-role-radio">
                            <input type="radio" name="ob_role" value="admin">
                        </div>
                    </div>
                    <div class="mw-onboard-role-perms">
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> Full access to all CRM features</div>
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> Manage company settings and branding</div>
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> Manage employee accounts and HR details</div>
                        <div class="mw-perm-allowed"><i data-feather="check" style="width:14px;height:14px;"></i> Access developer tools and database</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="mw-wizard-nav">
        <button type="button" class="btn btn-secondary" onclick="onboardWizard.prev()">
            <i data-feather="arrow-left" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Back
        </button>
        <button type="button" class="btn btn-primary mw-wizard-next" id="createEmployeeBtn" onclick="onboardWizard.createEmployee()">
            <i data-feather="user-plus" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Create Employee &amp; Continue
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     STEP 3: Send Install Link
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="mw-wizard-step" data-step="3" id="onboardStep3" style="display:none;">
    <div class="alert alert-success d-flex align-items-center mb-4">
        <i data-feather="check-circle" style="width:24px;height:24px;margin-right:12px;flex-shrink:0;"></i>
        <div>
            <strong id="successEmployeeName">Employee</strong> has been created successfully!
            <span class="text-muted d-block">Send them the app install link so they can get started.</span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0"><i data-feather="smartphone" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;color:var(--mw-green);"></i>Send App Install Link</h5>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="ob_install_url" class="form-label">Install URL</label>
                <input type="url" class="form-control" id="ob_install_url" value="https://mowology.ca/crm/jobs/schedule.php" placeholder="https://app.mowology.ca/install">
                <small class="form-text text-muted">The URL where the employee can access or install the Mowology field app.</small>
            </div>
            <div class="mb-3">
                <label class="form-label">Send via</label>
                <div class="d-flex" style="gap:16px;">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="ob_send_email" checked>
                        <label class="custom-control-label" for="ob_send_email">Email</label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="ob_send_sms">
                        <label class="custom-control-label" for="ob_send_sms">SMS</label>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Message Preview</label>
                <div class="card bg-light border-0 p-3" id="installPreviewMsg" style="font-size:14px;">
                    <em>Hi <span id="previewFirstName">there</span>, please install the Mowology field app using the link below. Contact us if you need help.</em>
                </div>
            </div>
            <div id="installSendResult" class="alert" style="display:none;"></div>
        </div>
    </div>
    <div class="mw-wizard-nav">
        <a href="index.php" class="btn btn-secondary">
            Skip &mdash; Go to Team List
        </a>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-primary" id="sendInstallBtn" onclick="onboardWizard.sendInstallLink()">
                <i data-feather="send" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Send Install Link
            </button>
            <a href="#" class="btn btn-outline-primary" id="viewProfileBtn" style="display:none;">
                View Profile <i data-feather="arrow-right" style="width:14px;height:14px;vertical-align:middle;margin-left:4px;"></i>
            </a>
        </div>
    </div>
</div>

<!-- Onboarding Wizard Controller -->
<script>
(function() {
    var currentStep = 1;
    var createdEmployeeId = null;
    var csrfToken = '<?php echo $csrfToken; ?>';

    function showError(msg) {
        var el = document.getElementById('onboardError');
        el.textContent = msg;
        el.style.display = '';
        el.scrollIntoView({ behavior: 'smooth' });
    }

    function hideError() {
        document.getElementById('onboardError').style.display = 'none';
    }

    function updateStepper() {
        var steps = document.querySelectorAll('#onboardStepper .mw-stepper-step');
        for (var i = 0; i < steps.length; i++) {
            var stepNum = parseInt(steps[i].getAttribute('data-wizard-step'));
            steps[i].classList.remove('active', 'completed');
            if (stepNum === currentStep) {
                steps[i].classList.add('active');
            } else if (stepNum < currentStep) {
                steps[i].classList.add('completed');
            }
        }
    }

    function showStep(step) {
        currentStep = step;
        for (var i = 1; i <= 3; i++) {
            var el = document.getElementById('onboardStep' + i);
            if (el) el.style.display = (i === step) ? '' : 'none';
        }
        updateStepper();
        document.getElementById('onboardStepper').scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (typeof feather !== 'undefined') setTimeout(function() { feather.replace(); }, 50);
    }

    window.onboardWizard = {
        next: function() {
            hideError();
            if (currentStep === 1) {
                // Validate Step 1
                var firstName = document.getElementById('ob_first_name').value.trim();
                var lastName  = document.getElementById('ob_last_name').value.trim();
                var email     = document.getElementById('ob_email').value.trim();
                var password  = document.getElementById('ob_password').value;
                var confirm   = document.getElementById('ob_password_confirm').value;

                if (!firstName) { showError('First name is required.'); return; }
                if (!lastName) { showError('Last name is required.'); return; }
                if (!email) { showError('Email address is required.'); return; }
                if (password.length < 8) { showError('Password must be at least 8 characters.'); return; }
                if (password !== confirm) { showError('Passwords do not match.'); return; }

                showStep(2);
            }
        },

        prev: function() {
            if (currentStep > 1 && currentStep < 3) {
                showStep(currentStep - 1);
            }
        },

        selectRole: function(card) {
            var cards = document.querySelectorAll('.mw-onboard-role-card');
            for (var i = 0; i < cards.length; i++) {
                cards[i].classList.remove('active');
            }
            card.classList.add('active');
            var radio = card.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        },

        createEmployee: function() {
            hideError();
            var btn = document.getElementById('createEmployeeBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Creating...';

            var payload = {
                action: 'create',
                csrf_token: csrfToken,
                first_name: document.getElementById('ob_first_name').value.trim(),
                last_name: document.getElementById('ob_last_name').value.trim(),
                email: document.getElementById('ob_email').value.trim(),
                phone: document.getElementById('ob_phone').value.trim(),
                password: document.getElementById('ob_password').value,
                hourly_rate: document.getElementById('ob_hourly_rate').value || null,
                role: document.querySelector('input[name="ob_role"]:checked')?.value || 'user'
            };

            fetch('/crm/api/employees.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    createdEmployeeId = data.employee_id;

                    // Update Step 3 with employee info
                    document.getElementById('successEmployeeName').textContent =
                        payload.first_name + ' ' + payload.last_name;
                    document.getElementById('previewFirstName').textContent = payload.first_name;
                    document.getElementById('viewProfileBtn').href = 'profile.php?id=' + createdEmployeeId;
                    document.getElementById('viewProfileBtn').style.display = '';

                    showStep(3);
                } else {
                    showError(data.error || 'Failed to create employee.');
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="user-plus" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Create Employee &amp; Continue';
                    if (typeof feather !== 'undefined') feather.replace();
                }
            })
            .catch(function(e) {
                showError('Network error: ' + e.message);
                btn.disabled = false;
                btn.innerHTML = '<i data-feather="user-plus" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Create Employee &amp; Continue';
                if (typeof feather !== 'undefined') feather.replace();
            });
        },

        sendInstallLink: function() {
            if (!createdEmployeeId) { showError('Employee not created yet.'); return; }

            var sendEmail = document.getElementById('ob_send_email').checked ? 1 : 0;
            var sendSms   = document.getElementById('ob_send_sms').checked ? 1 : 0;
            if (!sendEmail && !sendSms) { showError('Please select at least one delivery method.'); return; }

            var btn = document.getElementById('sendInstallBtn');
            var result = document.getElementById('installSendResult');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Sending...';
            result.style.display = 'none';

            fetch('/crm/api/send-install-link.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    employee_id: createdEmployeeId,
                    install_url: document.getElementById('ob_install_url').value.trim(),
                    send_email: sendEmail,
                    send_sms: sendSms
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                result.style.display = '';
                if (data.success) {
                    result.className = 'alert alert-success';
                    result.textContent = 'Install link sent successfully!';
                    btn.innerHTML = '<i data-feather="check" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Sent!';
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-success');
                    setTimeout(function() {
                        window.location.href = 'profile.php?id=' + createdEmployeeId;
                    }, 1500);
                } else {
                    result.className = 'alert alert-danger';
                    result.textContent = data.error || 'Failed to send install link.';
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="send" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Send Install Link';
                }
                if (typeof feather !== 'undefined') feather.replace();
            })
            .catch(function(e) {
                result.style.display = '';
                result.className = 'alert alert-danger';
                result.textContent = 'Network error: ' + e.message;
                btn.disabled = false;
                btn.innerHTML = '<i data-feather="send" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Send Install Link';
                if (typeof feather !== 'undefined') feather.replace();
            });
        }
    };
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
