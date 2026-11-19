<?php
/**
 * Winter Service Reports Dashboard
 * Seasonal overview of all salt/snow visits and report status.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    header('Location: ../dashboard_appstack.php');
    exit;
}

$db = getDB();

// Filters
$filterYear     = isset($_GET['year'])     ? (int)$_GET['year']                   : (int)date('Y');
$filterProperty = isset($_GET['property']) ? (int)$_GET['property']               : 0;

// Winter season = Oct–Apr spanning two calendar years
// e.g. "2026" = Oct 2025 through Apr 2026
$seasonStart = ($filterYear - 1) . '-10-01';
$seasonEnd   = $filterYear . '-04-30';

// Main query: visits with snow_removal or salt_application in this season
$params = [$seasonStart, $seasonEnd];
$propFilter = '';
if ($filterProperty > 0) {
    $propFilter = ' AND jp.property_id = ?';
    $params[] = $filterProperty;
}

$stmt = $db->prepare("
    SELECT
        jv.id AS visit_id, jv.scheduled_date, jv.status,
        jv.gps_dwell_minutes, jv.gps_points_count,
        jp.title AS plan_title, jp.property_id,
        pr.address AS property_address, pr.city AS property_city,
        CONCAT(c.first_name, ' ', c.last_name) AS contact_name,
        u.full_name AS crew_name,
        srr.report_number, srr.pdf_path, srr.pdf_generated_at,
        srr.pm_email_sent_at, srr.invoice_id, srr.portal_available_at,
        swd.overnight_low_c, swd.weather_condition
    FROM job_visits jv
    JOIN job_plans jp ON jp.id = jv.plan_id
    JOIN plan_line_items pli ON pli.plan_id = jp.id
    LEFT JOIN properties pr ON pr.id = jp.property_id
    LEFT JOIN contacts c ON c.id = pr.site_contact_id
    LEFT JOIN users u ON u.id = jv.assigned_crew_id
    LEFT JOIN salt_run_reports srr ON srr.visit_id = jv.id
    LEFT JOIN salt_weather_decisions swd ON swd.visit_id = jv.id
    WHERE jv.scheduled_date BETWEEN ? AND ?
      AND pli.service_type IN ('snow_removal', 'salt_application')
    {$propFilter}
    GROUP BY jv.id
    ORDER BY jv.scheduled_date DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$totalVisits    = count($rows);
$pdfGenerated   = 0;
$emailsSent     = 0;
$invoicesLinked = 0;

foreach ($rows as $r) {
    if ($r['pdf_path'])       $pdfGenerated++;
    if ($r['pm_email_sent_at']) $emailsSent++;
    if ($r['invoice_id'])     $invoicesLinked++;
}

// Available properties for filter
$propStmt = $db->prepare("
    SELECT DISTINCT jp.property_id, pr.address, pr.city
    FROM job_plans jp
    JOIN plan_line_items pli ON pli.plan_id = jp.id
    LEFT JOIN properties pr ON pr.id = jp.property_id
    WHERE pli.service_type IN ('snow_removal', 'salt_application')
      AND pr.address IS NOT NULL
    ORDER BY pr.address
");
$propStmt->execute();
$properties = $propStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCSRFToken();
$pageTitle  = 'Winter Service Reports';
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0">Winter Service Reports</h1>
        <p class="text-muted mb-0">Salt application &amp; snow removal proof-of-work records</p>
    </div>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <a href="trigger.php" class="btn btn-danger">
        <i data-feather="zap" class="feather-sm mr-1"></i> Manual Salt Run Trigger
    </a>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="row mb-4">
    <div class="col-sm-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="h2 mb-0 text-primary"><?= $totalVisits ?></div>
                <div class="text-muted small">Service Visits</div>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="h2 mb-0 <?= $pdfGenerated === $totalVisits ? 'text-success' : 'text-warning' ?>">
                    <?= $pdfGenerated ?>/<?= $totalVisits ?>
                </div>
                <div class="text-muted small">PDFs Generated</div>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="h2 mb-0"><?= $emailsSent ?></div>
                <div class="text-muted small">Emails Sent to PMs</div>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="h2 mb-0"><?= $invoicesLinked ?></div>
                <div class="text-muted small">Invoices Linked</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="get" class="form-inline">
            <label class="mr-2 text-muted small">Season:</label>
            <select name="year" class="custom-select custom-select-sm mr-3" onchange="this.form.submit()">
                <?php for ($y = (int)date('Y') + 1; $y >= 2024; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $filterYear ? 'selected' : '' ?>>
                    <?= ($y - 1) . '–' . $y ?>
                </option>
                <?php endfor; ?>
            </select>

            <label class="mr-2 text-muted small">Property:</label>
            <select name="property" class="custom-select custom-select-sm mr-3" onchange="this.form.submit()">
                <option value="0">All properties</option>
                <?php foreach ($properties as $prop): ?>
                <option value="<?= (int)$prop['property_id'] ?>" <?= $filterProperty === (int)$prop['property_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($prop['address'] . ', ' . $prop['city']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- Visits table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <?= $filterYear - 1 ?>–<?= $filterYear ?> Winter Season
            <span class="badge badge-secondary ml-2"><?= $totalVisits ?> visit<?= $totalVisits === 1 ? '' : 's' ?></span>
        </h5>
    </div>
    <?php if (empty($rows)): ?>
    <div class="card-body text-center text-muted py-5">
        <i data-feather="cloud-snow" style="width:48px;height:48px;opacity:0.3;"></i>
        <p class="mt-2">No winter service visits found for this season and filter.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>Date</th>
                    <th>Property</th>
                    <th>Overnight Low</th>
                    <th>Crew</th>
                    <th>GPS</th>
                    <th>PDF</th>
                    <th>Emailed</th>
                    <th>Invoice</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <?= htmlspecialchars(date('M j, Y', strtotime($r['scheduled_date']))) ?>
                    <?php if ($r['report_number']): ?>
                    <br><small class="text-muted"><?= htmlspecialchars($r['report_number']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars($r['property_address'] ?? '—') ?>, <?= htmlspecialchars($r['property_city'] ?? '') ?>
                    <?php if ($r['contact_name']): ?>
                    <br><small class="text-muted"><?= htmlspecialchars($r['contact_name']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['overnight_low_c'] !== null): ?>
                    <span class="badge badge-info"><?= htmlspecialchars($r['overnight_low_c']) ?>°C</span>
                    <br><small class="text-muted"><?= htmlspecialchars($r['weather_condition'] ?? '') ?></small>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['crew_name'] ?? '—') ?></td>
                <td>
                    <?php
                    $pts = (int)$r['gps_points_count'];
                    $dm  = (int)$r['gps_dwell_minutes'];
                    echo $pts > 0 ? $pts . ' pts' : '<span class="text-muted">—</span>';
                    if ($dm > 0) echo '<br><small class="text-muted">' . $dm . 'm</small>';
                    ?>
                </td>
                <td>
                    <?php if ($r['pdf_path']): ?>
                    <span class="badge badge-success">✓</span>
                    <small class="text-muted"><?= date('M j', strtotime($r['pdf_generated_at'])) ?></small>
                    <?php else: ?>
                    <span class="badge badge-warning">Pending</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['pm_email_sent_at']): ?>
                    <span class="badge badge-success">Sent</span>
                    <?php else: ?>
                    <span class="badge badge-secondary">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['invoice_id']): ?>
                    <a href="../invoices/view.php?id=<?= (int)$r['invoice_id'] ?>">
                        <span class="badge badge-success">Linked</span>
                    </a>
                    <?php else: ?>
                    <span class="badge badge-secondary">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="report.php?visit_id=<?= (int)$r['visit_id'] ?>"
                       class="btn btn-xs btn-outline-primary">
                        View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
