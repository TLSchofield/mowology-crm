<?php
/**
 * Messaging Diagnostics — Admin Wizard
 *
 * Multi-step testing tool for email (mail() + PHPMailer),
 * SMS (email-to-SMS gateway), consent verification, and full pipeline.
 *
 * Admin-only access. Uses Bootstrap 4 tabbed interface.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once __DIR__ . '/functions.php';

requireLogin();
$user = getCurrentUser();

// Admin only
if (($user['role'] ?? '') !== 'admin') {
    header('Location: /crm/dashboard_appstack.php');
    exit;
}

$pageTitle  = 'Messaging Diagnostics';
$activePage = 'diagnostics';
$csrfToken  = generateCSRFToken();

// Active tab from query string
$activeStep = $_GET['step'] ?? 'overview';
$validSteps = ['overview', 'step1', 'step2', 'step3', 'step4', 'step5'];
if (!in_array($activeStep, $validSteps, true)) {
    $activeStep = 'overview';
}

// Flash result from session (set by run-test.php)
$testResult = $_SESSION['diagnostic_result'] ?? null;
unset($_SESSION['diagnostic_result']);

// Load data for the page
$recentResults   = getRecentTestResults(15);
$contactsForSelect = getContactsWithPhone(200);

// SMTP config info (masked)
$smtpHost = defined('SMTP_HOST') ? (string)SMTP_HOST : '(not configured)';
$smtpPort = defined('SMTP_PORT') ? (string)SMTP_PORT : '(not configured)';
$smtpUser = defined('SMTP_USER') ? (string)SMTP_USER : '(not configured)';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Page Header -->
<div class="row mb-3">
    <div class="col-12">
        <h1 class="h3 mb-1">Messaging Diagnostics</h1>
        <p class="text-muted mb-0">Test and verify email, SMS, and consent systems step by step.</p>
    </div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mw-diag-nav" role="tablist">
    <li class="nav-item">
        <a class="nav-link<?php echo $activeStep === 'overview' ? ' active' : ''; ?>"
           data-toggle="tab" href="#tab-overview" role="tab">
            <i data-feather="home" style="width:16px;height:16px;"></i> Overview
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?php echo $activeStep === 'step1' ? ' active' : ''; ?>"
           data-toggle="tab" href="#tab-step1" role="tab">
            <span class="step-num">1</span> PHP mail()
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?php echo $activeStep === 'step2' ? ' active' : ''; ?>"
           data-toggle="tab" href="#tab-step2" role="tab">
            <span class="step-num">2</span> PHPMailer
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?php echo $activeStep === 'step3' ? ' active' : ''; ?>"
           data-toggle="tab" href="#tab-step3" role="tab">
            <span class="step-num">3</span> SMS Gateway
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?php echo $activeStep === 'step4' ? ' active' : ''; ?>"
           data-toggle="tab" href="#tab-step4" role="tab">
            <span class="step-num">4</span> Consent
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?php echo $activeStep === 'step5' ? ' active' : ''; ?>"
           data-toggle="tab" href="#tab-step5" role="tab">
            <span class="step-num">5</span> Full Pipeline
        </a>
    </li>
</ul>

<!-- Tab Content -->
<div class="tab-content">

    <!-- ═══ OVERVIEW TAB ═══════════════════════════════════════════ -->
    <div class="tab-pane fade<?php echo $activeStep === 'overview' ? ' show active' : ''; ?>"
         id="tab-overview" role="tabpanel">

        <!-- System Info -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <i data-feather="mail" class="mb-2" style="width:32px;height:32px;color:var(--mw-green);"></i>
                        <h5 class="card-title">PHP mail()</h5>
                        <p class="text-muted mb-0">
                            <?php echo function_exists('mail') ? '<span class="text-success">Available</span>' : '<span class="text-danger">Unavailable</span>'; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <i data-feather="send" class="mb-2" style="width:32px;height:32px;color:var(--mw-green);"></i>
                        <h5 class="card-title">PHPMailer SMTP</h5>
                        <p class="text-muted mb-0">
                            Host: <?php echo h($smtpHost); ?><br>
                            Port: <?php echo h((string)$smtpPort); ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <i data-feather="smartphone" class="mb-2" style="width:32px;height:32px;color:var(--mw-green);"></i>
                        <h5 class="card-title">SMS Gateways</h5>
                        <p class="text-muted mb-0">
                            10 Canadian carriers configured
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test History -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Test History</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentResults)): ?>
                    <div class="p-4 text-center text-muted">
                        <i data-feather="clipboard" style="width:40px;height:40px;"></i>
                        <p class="mt-2 mb-0">No tests have been run yet. Use the tabs above to start testing.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 mw-diag-history">
                            <thead>
                                <tr>
                                    <th>Test</th>
                                    <th>Status</th>
                                    <th>Duration</th>
                                    <th>Run By</th>
                                    <th>When</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentResults as $row): ?>
                                    <?php
                                        $typeLabels = [
                                            'mail_function'  => 'PHP mail()',
                                            'phpmailer'      => 'PHPMailer',
                                            'sms_gateway'    => 'SMS Gateway',
                                            'consent_check'  => 'Consent Check',
                                            'full_pipeline'  => 'Full Pipeline',
                                        ];
                                    ?>
                                    <tr>
                                        <td><?php echo h($typeLabels[$row['test_type']] ?? $row['test_type']); ?></td>
                                        <td>
                                            <span class="mw-diag-badge <?php echo h($row['test_status']); ?>">
                                                <?php echo h(ucfirst($row['test_status'])); ?>
                                            </span>
                                        </td>
                                        <td class="mw-diag-timing"><?php echo $row['duration_ms'] !== null ? h((string)$row['duration_ms']) . ' ms' : '-'; ?></td>
                                        <td><?php echo h($row['run_by_name'] ?? 'Unknown'); ?></td>
                                        <td class="mw-diag-timing"><?php echo h(formatDateTime($row['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <!-- ═══ STEP 1: PHP mail() ════════════════════════════════════ -->
    <div class="tab-pane fade<?php echo $activeStep === 'step1' ? ' show active' : ''; ?>"
         id="tab-step1" role="tabpanel">

        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Step 1: PHP mail() Function</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Tests the native PHP <code>mail()</code> function. This is the foundation
                            for all email sending on this server. Sends a test email and reports
                            server configuration details.
                        </p>
                        <form method="POST" action="run-test.php">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="test_type" value="mail_function">
                            <div class="form-group">
                                <label for="step1-email">Recipient Email</label>
                                <input type="email" class="form-control" id="step1-email" name="email"
                                       required placeholder="you@example.com"
                                       value="<?php echo h($user['email'] ?? ''); ?>">
                                <small class="form-text text-muted">Test email will be sent to this address.</small>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i data-feather="play" style="width:16px;height:16px;"></i> Run Test
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if ($testResult && $testResult['test_type'] === 'mail_function'): ?>
                    <?php echo renderTestResult($testResult['result'], 'PHP mail() Test'); ?>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center text-muted py-5">
                            <i data-feather="inbox" style="width:40px;height:40px;"></i>
                            <p class="mt-2 mb-0">Run the test to see results here.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <!-- ═══ STEP 2: PHPMailer ═════════════════════════════════════ -->
    <div class="tab-pane fade<?php echo $activeStep === 'step2' ? ' show active' : ''; ?>"
         id="tab-step2" role="tabpanel">

        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Step 2: PHPMailer SMTP</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Tests PHPMailer via SMTP (using credentials from <code>secrets.php</code>)
                            and also in <code>isMail()</code> fallback mode. Captures the full SMTP
                            debug transcript for troubleshooting.
                        </p>
                        <div class="mb-3 p-3" style="background:var(--mw-light);border-radius:0.25rem;">
                            <small>
                                <strong>SMTP Host:</strong> <?php echo h($smtpHost); ?><br>
                                <strong>SMTP Port:</strong> <?php echo h((string)$smtpPort); ?><br>
                                <strong>SMTP User:</strong> <?php echo h($smtpUser); ?><br>
                                <strong>SMTP Pass:</strong> ****
                            </small>
                        </div>
                        <form method="POST" action="run-test.php">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="test_type" value="phpmailer">
                            <div class="form-group">
                                <label for="step2-email">Recipient Email</label>
                                <input type="email" class="form-control" id="step2-email" name="email"
                                       required placeholder="you@example.com"
                                       value="<?php echo h($user['email'] ?? ''); ?>">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i data-feather="play" style="width:16px;height:16px;"></i> Run Test
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if ($testResult && $testResult['test_type'] === 'phpmailer'): ?>
                    <?php echo renderTestResult($testResult['result'], 'PHPMailer Test'); ?>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center text-muted py-5">
                            <i data-feather="inbox" style="width:40px;height:40px;"></i>
                            <p class="mt-2 mb-0">Run the test to see results here.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <!-- ═══ STEP 3: SMS Gateway ═══════════════════════════════════ -->
    <div class="tab-pane fade<?php echo $activeStep === 'step3' ? ' show active' : ''; ?>"
         id="tab-step3" role="tabpanel">

        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Step 3: SMS Gateway</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Tests email-to-SMS delivery via Canadian carrier gateways.
                            You can test all 10 carriers or a specific one. The message is sent
                            via <code>mail()</code> to the carrier's SMS email domain.
                        </p>
                        <form method="POST" action="run-test.php">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="test_type" value="sms_gateway">
                            <div class="form-group">
                                <label for="step3-phone">Phone Number</label>
                                <input type="tel" class="form-control" id="step3-phone" name="phone"
                                       required placeholder="(604) 555-1234"
                                       pattern="[\d\s\-\(\)\+]{10,15}">
                                <small class="form-text text-muted">10-digit North American number.</small>
                            </div>
                            <div class="form-group">
                                <label for="step3-carrier">Carrier</label>
                                <select class="form-control" id="step3-carrier" name="carrier">
                                    <option value="all">All Carriers (test all 10)</option>
                                    <option value="Bell">Bell</option>
                                    <option value="Rogers">Rogers</option>
                                    <option value="Telus">Telus</option>
                                    <option value="Koodo">Koodo</option>
                                    <option value="Virgin">Virgin</option>
                                    <option value="Fido">Fido</option>
                                    <option value="Freedom">Freedom Mobile</option>
                                    <option value="PC Mobile">PC Mobile</option>
                                    <option value="Eastlink">Eastlink</option>
                                    <option value="SaskTel">SaskTel</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i data-feather="play" style="width:16px;height:16px;"></i> Run Test
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if ($testResult && $testResult['test_type'] === 'sms_gateway'): ?>
                    <?php echo renderTestResult($testResult['result'], 'SMS Gateway Test'); ?>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center text-muted py-5">
                            <i data-feather="inbox" style="width:40px;height:40px;"></i>
                            <p class="mt-2 mb-0">Run the test to see results here.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <!-- ═══ STEP 4: Consent Check ═════════════════════════════════ -->
    <div class="tab-pane fade<?php echo $activeStep === 'step4' ? ' show active' : ''; ?>"
         id="tab-step4" role="tabpanel">

        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Step 4: Consent Verification</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Verifies consent data for a specific contact. Checks the
                            <code>contacts</code> table fields, the <code>consent_log</code>
                            audit trail, and the <code>hasSmConsent()</code> function for consistency.
                        </p>
                        <form method="POST" action="run-test.php">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="test_type" value="consent_check">
                            <div class="form-group">
                                <label for="step4-contact">Contact</label>
                                <select class="form-control" id="step4-contact" name="contact_id" required>
                                    <option value="">— Select a contact —</option>
                                    <?php foreach ($contactsForSelect as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>">
                                            <?php echo h(trim($c['first_name'] . ' ' . $c['last_name'])); ?>
                                            — <?php echo h($c['phone']); ?>
                                            <?php if ($c['consent_sms'] || $c['receive_sms']): ?> [SMS: Yes]<?php else: ?> [SMS: No]<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($contactsForSelect)): ?>
                                    <small class="form-text text-danger">No contacts with phone numbers found.</small>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="btn btn-primary" <?php echo empty($contactsForSelect) ? 'disabled' : ''; ?>>
                                <i data-feather="play" style="width:16px;height:16px;"></i> Run Test
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if ($testResult && $testResult['test_type'] === 'consent_check'): ?>
                    <?php echo renderTestResult($testResult['result'], 'Consent Verification'); ?>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center text-muted py-5">
                            <i data-feather="inbox" style="width:40px;height:40px;"></i>
                            <p class="mt-2 mb-0">Run the test to see results here.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <!-- ═══ STEP 5: Full Pipeline ═════════════════════════════════ -->
    <div class="tab-pane fade<?php echo $activeStep === 'step5' ? ' show active' : ''; ?>"
         id="tab-step5" role="tabpanel">

        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Step 5: Full Pipeline</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            End-to-end test: runs all four tests in sequence, then also sends
                            a real email and SMS through the production <code>sendEmailViaSMTP()</code>
                            and <code>sendSmsViaMail()</code> functions.
                        </p>
                        <form method="POST" action="run-test.php">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="test_type" value="full_pipeline">
                            <div class="form-group">
                                <label for="step5-email">Recipient Email</label>
                                <input type="email" class="form-control" id="step5-email" name="email"
                                       required placeholder="you@example.com"
                                       value="<?php echo h($user['email'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="step5-phone">Phone Number</label>
                                <input type="tel" class="form-control" id="step5-phone" name="phone"
                                       required placeholder="(604) 555-1234"
                                       pattern="[\d\s\-\(\)\+]{10,15}">
                            </div>
                            <div class="form-group">
                                <label for="step5-contact">Contact (optional, for consent check)</label>
                                <select class="form-control" id="step5-contact" name="contact_id">
                                    <option value="">— Skip consent check —</option>
                                    <?php foreach ($contactsForSelect as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>">
                                            <?php echo h(trim($c['first_name'] . ' ' . $c['last_name'])); ?>
                                            — <?php echo h($c['phone']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i data-feather="play" style="width:16px;height:16px;"></i> Run Full Pipeline
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if ($testResult && $testResult['test_type'] === 'full_pipeline'): ?>
                    <?php echo renderFullPipelineResult($testResult['result']); ?>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center text-muted py-5">
                            <i data-feather="inbox" style="width:40px;height:40px;"></i>
                            <p class="mt-2 mb-0">Run the test to see results here.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /.tab-content -->

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<?php
// ─── Rendering Helpers ───────────────────────────────────────────────
// These are defined at the bottom since they output HTML and are only
// called from the template above via deferred rendering.

/**
 * Render a generic test result card
 */
function renderTestResult(array $result, string $title): string
{
    $status     = $result['status'] ?? 'error';
    $message    = $result['message'] ?? '';
    $details    = $result['details'] ?? [];
    $durationMs = $result['duration_ms'] ?? 0;
    $error      = $result['error'] ?? null;

    $statusLabel = ucfirst($status);
    $ob = '';
    $ob .= '<div class="mw-diag-result ' . h($status) . '">';

    // Header
    $ob .= '<div class="mw-diag-result-header">';
    $ob .= '<div><strong>' . h($title) . '</strong></div>';
    $ob .= '<div class="d-flex align-items-center gap-2">';
    $ob .= '<span class="mw-diag-timing mr-3">' . (int)$durationMs . ' ms</span>';
    $ob .= '<span class="mw-diag-badge ' . h($status) . '">' . h($statusLabel) . '</span>';
    $ob .= '</div></div>';

    // Body
    $ob .= '<div class="mw-diag-result-body">';
    $ob .= '<p class="mb-3">' . h($message) . '</p>';

    if ($error) {
        $ob .= '<div class="alert alert-danger py-2 px-3 mb-3" style="font-size:0.9rem;">';
        $ob .= '<strong>Error:</strong> ' . h($error);
        $ob .= '</div>';
    }

    // Detail grid
    if (!empty($details)) {
        $ob .= '<dl class="mw-diag-details">';
        foreach ($details as $key => $value) {
            // Skip nested arrays/objects in simple view (show them in debug)
            if (is_array($value)) continue;
            $ob .= '<dt>' . h(ucwords(str_replace('_', ' ', $key))) . '</dt>';
            $ob .= '<dd>';
            if (is_bool($value)) {
                $ob .= $value ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>';
            } else {
                $ob .= h((string)$value);
            }
            $ob .= '</dd>';
        }
        $ob .= '</dl>';
    }

    // Carrier results grid (for SMS test)
    if (!empty($details['carrier_results'])) {
        $ob .= '<h6 class="mt-3 mb-2">Carrier Results</h6>';
        $ob .= '<div class="mw-diag-carrier-grid">';
        $ob .= '<div class="header">Carrier</div><div class="header">Gateway</div><div class="header">Timing</div><div class="header">Result</div>';
        foreach ($details['carrier_results'] as $cr) {
            $ob .= '<div class="row-item">' . h($cr['carrier']) . '</div>';
            $ob .= '<div class="row-item"><code style="font-size:0.8rem;">' . h($cr['domain']) . '</code></div>';
            $ob .= '<div class="row-item mw-diag-timing">' . h((string)$cr['timing_ms']) . ' ms</div>';
            $ob .= '<div class="row-item">';
            $ob .= $cr['accepted']
                ? '<span class="mw-diag-badge pass">OK</span>'
                : '<span class="mw-diag-badge fail">Fail</span>';
            $ob .= '</div>';
        }
        $ob .= '</div>';
    }

    // Consent details (for consent test)
    if (!empty($details['consent_fields'])) {
        $ob .= '<h6 class="mt-3 mb-2">Consent Fields</h6>';
        $ob .= '<dl class="mw-diag-details">';
        foreach ($details['consent_fields'] as $key => $value) {
            $ob .= '<dt>' . h(ucwords(str_replace('_', ' ', $key))) . '</dt>';
            $ob .= '<dd>';
            if (is_bool($value)) {
                $ob .= $value ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>';
            } else {
                $ob .= h((string)($value ?? '-'));
            }
            $ob .= '</dd>';
        }
        $ob .= '</dl>';
    }

    // Consent audit log
    if (!empty($details['consent_log'])) {
        $ob .= '<h6 class="mt-3 mb-2">Consent Audit Log (' . count($details['consent_log']) . ' entries)</h6>';
        $ob .= '<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:0.85rem;">';
        $ob .= '<thead><tr><th>Type</th><th>Given</th><th>Source</th><th>When</th></tr></thead><tbody>';
        foreach ($details['consent_log'] as $log) {
            $ob .= '<tr>';
            $ob .= '<td>' . h($log['consent_type']) . '</td>';
            $ob .= '<td>' . ($log['consent_given'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>') . '</td>';
            $ob .= '<td>' . h($log['consent_source'] ?? '-') . '</td>';
            $ob .= '<td class="mw-diag-timing">' . h($log['created_at'] ?? '-') . '</td>';
            $ob .= '</tr>';
        }
        $ob .= '</tbody></table></div>';
    }

    // SMTP debug log (collapsible)
    if (!empty($details['smtp_debug_log'])) {
        $collapseId = 'smtpDebug' . rand(1000, 9999);
        $ob .= '<div class="mt-3">';
        $ob .= '<a class="btn btn-sm btn-outline-secondary" data-toggle="collapse" href="#' . $collapseId . '">';
        $ob .= 'Show SMTP Debug Log</a>';
        $ob .= '<div class="collapse mt-2" id="' . $collapseId . '">';
        $ob .= '<div class="mw-diag-debug">' . h($details['smtp_debug_log']) . '</div>';
        $ob .= '</div></div>';
    }

    // Headers (collapsible)
    if (!empty($details['headers'])) {
        $collapseId = 'headers' . rand(1000, 9999);
        $ob .= '<div class="mt-3">';
        $ob .= '<a class="btn btn-sm btn-outline-secondary" data-toggle="collapse" href="#' . $collapseId . '">';
        $ob .= 'Show Headers</a>';
        $ob .= '<div class="collapse mt-2" id="' . $collapseId . '">';
        $ob .= '<div class="mw-diag-debug">' . h($details['headers']) . '</div>';
        $ob .= '</div></div>';
    }

    $ob .= '</div>'; // result-body
    $ob .= '</div>'; // mw-diag-result

    return $ob;
}

/**
 * Render full pipeline result with summary cards
 */
function renderFullPipelineResult(array $result): string
{
    $details    = $result['details'] ?? [];
    $durationMs = $result['duration_ms'] ?? 0;
    $status     = $result['status'] ?? 'error';

    $ob = '';

    // Overall status header
    $ob .= '<div class="mw-diag-result ' . h($status) . ' mb-3">';
    $ob .= '<div class="mw-diag-result-header">';
    $ob .= '<div><strong>Full Pipeline Result</strong></div>';
    $ob .= '<div class="d-flex align-items-center">';
    $ob .= '<span class="mw-diag-timing mr-3">' . (int)$durationMs . ' ms total</span>';
    $ob .= '<span class="mw-diag-badge ' . h($status) . '">' . h(ucfirst($status)) . '</span>';
    $ob .= '</div></div>';
    $ob .= '<div class="mw-diag-result-body">';
    $ob .= '<p>' . h($result['message'] ?? '') . '</p>';
    $ob .= '</div></div>';

    // Pipeline step summary cards
    $steps = [
        ['key' => 'step_mail',      'label' => 'PHP mail()'],
        ['key' => 'step_phpmailer', 'label' => 'PHPMailer'],
        ['key' => 'step_sms',       'label' => 'SMS Gateway'],
        ['key' => 'step_consent',   'label' => 'Consent'],
    ];

    $ob .= '<div class="mw-diag-pipeline">';
    foreach ($steps as $step) {
        $stepResult = $details[$step['key']] ?? null;
        $stepStatus = $stepResult['status'] ?? 'error';
        $stepMsg    = $stepResult['message'] ?? 'No result';

        $ob .= '<div class="mw-diag-pipeline-step ' . h($stepStatus) . '">';
        $ob .= '<div class="mw-diag-badge ' . h($stepStatus) . ' mb-2">' . h(ucfirst($stepStatus)) . '</div>';
        $ob .= '<h6 class="mb-1">' . h($step['label']) . '</h6>';
        $ob .= '<small class="text-muted">' . h($stepMsg) . '</small>';
        $ob .= '</div>';
    }

    // Production function results
    $prodEmail = $details['production_email'] ?? 'error';
    $prodSms   = $details['production_sms'] ?? 'error';

    $ob .= '<div class="mw-diag-pipeline-step ' . h($prodEmail) . '">';
    $ob .= '<div class="mw-diag-badge ' . h($prodEmail) . ' mb-2">' . h(ucfirst($prodEmail)) . '</div>';
    $ob .= '<h6 class="mb-1">Prod Email</h6>';
    $ob .= '<small class="text-muted">sendEmailViaSMTP()</small>';
    $ob .= '</div>';

    $ob .= '<div class="mw-diag-pipeline-step ' . h($prodSms) . '">';
    $ob .= '<div class="mw-diag-badge ' . h($prodSms) . ' mb-2">' . h(ucfirst($prodSms)) . '</div>';
    $ob .= '<h6 class="mb-1">Prod SMS</h6>';
    $ob .= '<small class="text-muted">sendSmsViaMail()</small>';
    $ob .= '</div>';

    $ob .= '</div>'; // pipeline grid

    // Expandable detail for each sub-test
    foreach ($steps as $step) {
        $stepResult = $details[$step['key']] ?? null;
        if ($stepResult) {
            $ob .= renderTestResult($stepResult, $step['label']);
        }
    }

    return $ob;
}
?>
