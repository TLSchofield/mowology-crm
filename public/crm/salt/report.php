<?php
/**
 * Winter Service Report — Individual visit view
 * Shows weather decision, GPS stats, delivery status.
 * Actions: Generate PDF, Email to PM, Attach to Invoice.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    header('Location: ../dashboard_appstack.php');
    exit;
}

$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
if ($visitId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$db = getDB();

// Load visit + report + weather + contact
$stmt = $db->prepare("
    SELECT
        jv.id AS visit_id, jv.scheduled_date, jv.started_at, jv.completed_at,
        jv.status, jv.gps_dwell_minutes, jv.gps_dwell_quality, jv.gps_points_count,
        jp.title AS plan_title, jp.plan_number,
        pr.address AS property_address, pr.city AS property_city,
        pr.province AS property_province,
        CONCAT(c.first_name, ' ', c.last_name) AS contact_name, c.email AS contact_email,
        u.full_name AS crew_name,
        srr.id AS report_id, srr.report_number, srr.pdf_path, srr.pdf_hash,
        srr.pdf_generated_at, srr.portal_available_at,
        srr.invoice_id, srr.invoice_attached_at,
        srr.pm_email_sent_at, srr.pm_email_recipient, srr.pm_email_status,
        srr.salt_product,
        swd.overnight_low_c, swd.trigger_threshold_c, swd.weather_condition,
        swd.decision_at, swd.data_source, swd.source_url
    FROM job_visits jv
    JOIN job_plans jp ON jp.id = jv.plan_id
    LEFT JOIN properties pr ON pr.id = jp.property_id
    LEFT JOIN contacts c ON c.id = pr.site_contact_id
    LEFT JOIN users u ON u.id = jv.assigned_crew_id
    LEFT JOIN salt_run_reports srr ON srr.visit_id = jv.id
    LEFT JOIN salt_weather_decisions swd ON swd.visit_id = jv.id
    WHERE jv.id = ?
");
$stmt->execute([$visitId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    header('Location: dashboard.php');
    exit;
}

// Count photos
$phStmt = $db->prepare("SELECT COUNT(*) FROM visit_photos WHERE visit_id = ? AND deleted_at IS NULL");
$phStmt->execute([$visitId]);
$photoCount = (int)$phStmt->fetchColumn();

// Open invoices for this plan (for attach-to-invoice dropdown)
$invStmt = $db->prepare("
    SELECT i.id, i.invoice_number, i.issue_date, i.status
    FROM invoices i
    JOIN job_plans jp ON jp.id = ?
    WHERE (i.visit_id = ? OR i.plan_id = jp.id)
      AND i.status NOT IN ('cancelled')
    ORDER BY i.issue_date DESC
    LIMIT 20
");
$invStmt->execute([$data['plan_id'] ?? 0, $visitId]);
$invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// Fallback: all invoices linked to this contact
if (empty($invoices)) {
    $allInvStmt = $db->prepare("
        SELECT i.id, i.invoice_number, i.issue_date, i.status
        FROM invoices i
        JOIN job_plans jp2 ON jp2.id = ?
        LEFT JOIN contacts c2 ON c2.id = ?
        WHERE i.contact_id = c2.id
          AND i.status NOT IN ('cancelled')
        ORDER BY i.issue_date DESC
        LIMIT 10
    ");
    $allInvStmt->execute([$data['plan_id'] ?? 0, 0]);
    $invoices = $allInvStmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrfToken = generateCSRFToken();

$pageTitle  = 'Winter Service Report — ' . ($data['plan_title'] ?? 'Visit #' . $visitId);
$activePage = 'jobs';

?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="d-flex align-items-center mb-4">
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary mr-3">
        <i data-feather="arrow-left"></i> All Reports
    </a>
    <div>
        <h1 class="h3 mb-0">Winter Service Report</h1>
        <p class="text-muted mb-0">
            <?= htmlspecialchars($data['plan_title'] ?? '') ?>
            &bull; <?= htmlspecialchars($data['property_address'] ?? '') . ', ' . htmlspecialchars($data['property_city'] ?? '') ?>
        </p>
    </div>
</div>

<?php if (!empty($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?= htmlspecialchars($_GET['success']) ?>
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
<?php endif; ?>

<div class="row">

    <!-- Left: report status + actions -->
    <div class="col-md-8">

        <!-- Report status card -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <?php if ($data['report_number']): ?>
                    <span class="badge badge-primary mr-2"><?= htmlspecialchars($data['report_number']) ?></span>
                    <?php endif; ?>
                    Service Record
                </h5>
                <div>
                    <?php if ($data['pdf_path']): ?>
                    <span class="badge badge-success">PDF Generated</span>
                    <?php else: ?>
                    <span class="badge badge-warning">PDF Pending</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Service Date</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($data['scheduled_date'] ? date('l, F j, Y', strtotime($data['scheduled_date'])) : 'N/A') ?></dd>

                    <dt class="col-sm-4">Property</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars(trim($data['property_address'] . ', ' . $data['property_city'] . ', ' . $data['property_province'])) ?></dd>

                    <dt class="col-sm-4">Crew</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($data['crew_name'] ?? '—') ?></dd>

                    <dt class="col-sm-4">Clock In / Out</dt>
                    <dd class="col-sm-8">
                        <?= $data['started_at'] ? date('g:i A', strtotime($data['started_at'])) : '—' ?>
                        &rarr;
                        <?= $data['completed_at'] ? date('g:i A', strtotime($data['completed_at'])) : '—' ?>
                    </dd>

                    <dt class="col-sm-4">GPS Dwell</dt>
                    <dd class="col-sm-8">
                        <?php
                        $dwell = (int)$data['gps_dwell_minutes'];
                        $quality = (int)$data['gps_dwell_quality'];
                        $qlabels = [0 => 'Insufficient', 1 => 'Sparse', 2 => 'Moderate', 3 => 'Good'];
                        echo $dwell > 0 ? $dwell . ' min (' . ($qlabels[$quality] ?? '?') . ' GPS quality)' : '—';
                        ?>
                    </dd>

                    <dt class="col-sm-4">GPS Pings</dt>
                    <dd class="col-sm-8"><?= (int)$data['gps_points_count'] ?> recorded</dd>

                    <dt class="col-sm-4">Photos</dt>
                    <dd class="col-sm-8"><?= $photoCount ?> photo<?= $photoCount === 1 ? '' : 's' ?></dd>

                    <?php if ($data['pdf_generated_at']): ?>
                    <dt class="col-sm-4">PDF Generated</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($data['pdf_generated_at']))) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Weather decision card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i data-feather="cloud-snow" class="mr-2"></i> Weather Authorization
                </h5>
            </div>
            <div class="card-body">
                <?php if ($data['overnight_low_c'] !== null): ?>
                <div class="alert alert-info mb-3">
                    <strong>Trigger:</strong>
                    Overnight low of <?= htmlspecialchars($data['overnight_low_c']) ?>°C forecast
                    (threshold ≤<?= htmlspecialchars($data['trigger_threshold_c']) ?>°C).
                    <?= htmlspecialchars($data['weather_condition'] ?? '') ?>
                </div>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Data Source</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($data['data_source'] ?? 'Environment Canada') ?></dd>

                    <dt class="col-sm-4">Decision Captured</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($data['decision_at'] ?? '—') ?></dd>

                    <?php if ($data['source_url']): ?>
                    <dt class="col-sm-4">Source URL</dt>
                    <dd class="col-sm-8 text-truncate" style="max-width:300px;">
                        <small class="text-muted"><?= htmlspecialchars($data['source_url']) ?></small>
                    </dd>
                    <?php endif; ?>
                </dl>
                <?php else: ?>
                <p class="text-muted mb-0">No weather decision record captured yet. The record is written automatically when the 2pm weather cron fires.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Delivery status card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Delivery Status</h5>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Portal</dt>
                    <dd class="col-sm-8">
                        <?php if ($data['portal_available_at']): ?>
                        <span class="badge badge-success">Available</span>
                        <small class="text-muted ml-1"><?= date('M j, Y', strtotime($data['portal_available_at'])) ?></small>
                        <?php else: ?>
                        <span class="badge badge-secondary">Not yet available</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4">PM Email</dt>
                    <dd class="col-sm-8">
                        <?php if ($data['pm_email_sent_at']): ?>
                        <span class="badge badge-success">Sent</span>
                        <small class="text-muted ml-1">
                            to <?= htmlspecialchars($data['pm_email_recipient'] ?? '') ?>
                            on <?= date('M j, Y g:i A', strtotime($data['pm_email_sent_at'])) ?>
                        </small>
                        <?php elseif ($data['pm_email_status'] === 'failed'): ?>
                        <span class="badge badge-danger">Failed</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">Not sent</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4">Invoice Attached</dt>
                    <dd class="col-sm-8">
                        <?php if ($data['invoice_id']): ?>
                        <span class="badge badge-success">Yes</span>
                        <a href="../invoices/view.php?id=<?= (int)$data['invoice_id'] ?>" class="ml-1">
                            View Invoice &rarr;
                        </a>
                        <?php else: ?>
                        <span class="badge badge-secondary">Not yet attached</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>

    </div>

    <!-- Right: action buttons -->
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header"><h6 class="card-title mb-0">Actions</h6></div>
            <div class="card-body">

                <!-- Generate / Re-generate -->
                <form method="post" action="../api/salt-report.php" id="formGenerate">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="generate">
                    <input type="hidden" name="visit_id" value="<?= $visitId ?>">
                    <?php if ($data['pdf_path']): ?>
                    <input type="hidden" name="force" value="1">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-block mb-2" id="btnGenerate">
                        <i data-feather="file-text"></i>
                        <?= $data['pdf_path'] ? 'Re-generate PDF' : 'Generate PDF' ?>
                    </button>
                </form>

                <?php if ($data['pdf_path']): ?>
                <!-- Preview PDF -->
                <a href="<?= htmlspecialchars('/crm/documents/download.php?path=' . urlencode($data['pdf_path'])) ?>"
                   target="_blank" class="btn btn-outline-secondary btn-block mb-2">
                    <i data-feather="eye"></i> Preview PDF
                </a>

                <!-- Email to PM -->
                <?php if ($data['contact_email']): ?>
                <form method="post" action="../api/salt-report.php" id="formEmail">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="email_pm">
                    <input type="hidden" name="visit_id" value="<?= $visitId ?>">
                    <button type="submit" class="btn btn-outline-primary btn-block mb-2">
                        <i data-feather="send"></i>
                        <?= $data['pm_email_sent_at'] ? 'Re-send to PM' : 'Email to PM' ?>
                    </button>
                </form>
                <small class="text-muted d-block mb-2">
                    <?= htmlspecialchars($data['contact_email']) ?>
                </small>
                <?php else: ?>
                <button class="btn btn-outline-secondary btn-block mb-2" disabled title="No email on file">
                    <i data-feather="send"></i> Email to PM (no email on file)
                </button>
                <?php endif; ?>

                <!-- Attach to invoice -->
                <?php if (!empty($invoices) && !$data['invoice_id']): ?>
                <form method="post" action="../api/salt-report.php" id="formAttach">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="attach_invoice">
                    <input type="hidden" name="visit_id" value="<?= $visitId ?>">
                    <div class="input-group mb-2">
                        <select name="invoice_id" class="custom-select custom-select-sm" required>
                            <option value="">— Select invoice —</option>
                            <?php foreach ($invoices as $inv): ?>
                            <option value="<?= (int)$inv['id'] ?>">
                                <?= htmlspecialchars($inv['invoice_number']) ?>
                                (<?= htmlspecialchars(date('M j', strtotime($inv['issue_date']))) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="input-group-append">
                            <button type="submit" class="btn btn-sm btn-outline-success">Attach</button>
                        </div>
                    </div>
                </form>
                <?php elseif ($data['invoice_id']): ?>
                <div class="alert alert-success py-2 mb-2">
                    <i data-feather="check-circle" style="width:14px;height:14px;"></i>
                    Attached to invoice #<?= htmlspecialchars($data['invoice_id']) ?>
                </div>
                <?php endif; ?>

                <?php endif; /* pdf exists */ ?>

                <hr>
                <a href="../jobs/view.php?id=<?= (int)($data['plan_id'] ?? 0) ?>" class="btn btn-link btn-sm p-0">
                    &larr; View job plan
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('form[id^="form"]').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        var btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Working...';
        }

        // Intercept generate / email / attach forms to show JSON error inline
        var action = form.querySelector('[name="action"]');
        if (!action) return;

        e.preventDefault();
        var formData = new FormData(form);

        fetch(form.action, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.href = window.location.href.split('?')[0]
                        + '?visit_id=<?= $visitId ?>'
                        + (data.report_number ? '&success=' + encodeURIComponent('Report generated: ' + data.report_number) : '&success=Done');
                } else {
                    var msg = data.error || 'Unknown error';
                    if (data.error_file) msg += ' (' + data.error_file + ':' + data.error_line + ')';
                    alert('Error: ' + msg);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = btn.getAttribute('data-orig') || 'Try again';
                    }
                }
            })
            .catch(function(err) {
                alert('Request failed: ' + err);
                if (btn) { btn.disabled = false; btn.innerHTML = 'Try again'; }
            });
    });

    // Store original button text before disabling
    var btn = form.querySelector('button[type="submit"]');
    if (btn) btn.setAttribute('data-orig', btn.innerHTML);
});
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
