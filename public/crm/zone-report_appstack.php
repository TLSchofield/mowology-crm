<?php
/**
 * Zone Time Report — time spent per named work zone across visits.
 * Shows: zone name, plan, quoted vs actual time, crew, transit time.
 * Transit time highlighted in amber for crew leaders.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

require_once __DIR__ . '/../../app/Modules/Geofence/Models/GeofenceModel.php';

// Filters
$dateFrom   = $_GET['date_from']   ?? date('Y-m-01');        // default: first of current month
$dateTo     = $_GET['date_to']     ?? date('Y-m-d');          // default: today
$propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : null;

// Clamp dates
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

// Load properties for filter dropdown
$propertiesStmt = $db->query("SELECT id, address FROM properties WHERE id IN (SELECT DISTINCT property_id FROM job_plans) ORDER BY address ASC");
$properties     = $propertiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Run report
$rows = geofenceGetZoneReport($db, $dateFrom, $dateTo, $propertyId ?: null);

// Also get per-visit transit totals for the period
$transitWhere  = 'WHERE jv.scheduled_date BETWEEN ? AND ? AND jv.transit_seconds IS NOT NULL';
$transitParams = [$dateFrom, $dateTo];
if ($propertyId) {
    $transitWhere .= ' AND jp.property_id = ?';
    $transitParams[] = $propertyId;
}
$transitStmt = $db->prepare("
    SELECT SUM(jv.transit_seconds) AS total_transit, COUNT(DISTINCT jv.id) AS visit_count
    FROM job_visits jv
    JOIN job_plans jp ON jp.id = jv.plan_id
    $transitWhere
");
$transitStmt->execute($transitParams);
$transitTotals = $transitStmt->fetch(PDO::FETCH_ASSOC);

// Helper: format seconds as h:mm
function fmtSec(int $sec): string {
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
}

// Compute totals
$totalInSec      = array_sum(array_column($rows, 'total_in_seconds'));
$totalTransitSec = (int)($transitTotals['total_transit'] ?? 0);

$pageTitle  = 'Zone Time Report';
$activePage = 'jobs';
?>
<?php include 'includes/appstack_head.php'; ?>

<!-- Header -->
<div class="row mb-3">
    <div class="col-12">
        <h3 class="mb-1"><i data-feather="layers" class="me-2"></i>Zone Time Report</h3>
        <p class="text-muted mb-0">GPS-derived time attribution per named work zone</p>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-sm-3">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-sm-4">
                <label class="form-label small mb-1">Property</label>
                <select name="property_id" class="form-select form-select-sm">
                    <option value="">All properties</option>
                    <?php foreach ($properties as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $propertyId == $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['address']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <button type="submit" class="btn btn-sm btn-success w-100">
                    <i data-feather="filter" class="me-1"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-sm-4 mb-2">
        <div class="card mw-stat-card h-100">
            <div class="card-body text-center">
                <div class="mw-stat-value"><?= fmtSec($totalInSec) ?></div>
                <div class="mw-stat-label">Total Zone Time</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4 mb-2">
        <div class="card h-100 <?= $totalTransitSec > $totalInSec * 0.2 ? 'mw-transit-warning' : 'mw-stat-card' ?>">
            <div class="card-body text-center">
                <div class="mw-stat-value <?= $totalTransitSec > $totalInSec * 0.2 ? 'text-warning' : '' ?>">
                    <?= fmtSec($totalTransitSec) ?>
                </div>
                <div class="mw-stat-label">
                    Transit Time
                    <?php if ($totalTransitSec > $totalInSec * 0.2): ?>
                    <span class="badge bg-warning text-dark ms-1" title="Transit >20% of zone time">High</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4 mb-2">
        <div class="card mw-stat-card h-100">
            <div class="card-body text-center">
                <div class="mw-stat-value"><?= count($rows) ?></div>
                <div class="mw-stat-label">Zones Tracked</div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($rows)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i data-feather="map-pin" style="width:48px;height:48px;stroke:#ccc;"></i>
        <p class="text-muted mt-3 mb-0">
            No zone time data for this period.<br>
            <small>Zone sessions are computed when visits are completed and work zones have been drawn.</small>
        </p>
        <a href="/crm/jobs/zone-editor.php" class="btn btn-sm btn-success mt-3">
            <i data-feather="layers" class="me-1"></i> Draw Work Zones
        </a>
    </div>
</div>

<?php else: ?>

<!-- Zone Breakdown Table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <strong>Zone Breakdown</strong>
        <span class="badge bg-secondary"><?= count($rows) ?> zones</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Zone</th>
                    <th>Plan / Service</th>
                    <th>Property</th>
                    <th class="text-end">Time</th>
                    <th class="text-center">Visits</th>
                    <th class="text-center">Crew</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $currentProperty = null;
                foreach ($rows as $r):
                    $inSec = (int)$r['total_in_seconds'];
                    $pct   = $totalInSec > 0 ? round($inSec / $totalInSec * 100) : 0;
                ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="mw-zone-dot-sm" style="background:var(--mw-green);"></span>
                            <div>
                                <div class="fw-semibold small"><?= htmlspecialchars($r['zone_label'] ?? 'Unnamed Zone') ?></div>
                                <div class="mw-zone-bar mt-1">
                                    <div class="mw-zone-bar-fill" style="width:<?= $pct ?>%;"></div>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="small"><?= htmlspecialchars($r['plan_title'] ?? '—') ?></div>
                        <?php if ($r['plan_service_type']): ?>
                        <span class="badge bg-light text-dark" style="font-size:10px;"><?= htmlspecialchars($r['plan_service_type']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small text-muted"><?= htmlspecialchars($r['property_address'] ?? '—') ?></div>
                    </td>
                    <td class="text-end">
                        <span class="fw-semibold"><?= fmtSec($inSec) ?></span>
                        <div class="text-muted" style="font-size:11px;"><?= $pct ?>% of total</div>
                    </td>
                    <td class="text-center"><?= (int)$r['visit_count'] ?></td>
                    <td class="text-center"><?= (int)$r['max_crew'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-semibold">
                <tr>
                    <td colspan="3">Totals</td>
                    <td class="text-end"><?= fmtSec($totalInSec) ?></td>
                    <td colspan="2"></td>
                </tr>
                <?php if ($totalTransitSec > 0): ?>
                <tr class="<?= $totalTransitSec > $totalInSec * 0.2 ? 'table-warning' : '' ?>">
                    <td colspan="3">
                        <i data-feather="navigation" class="me-1" style="width:14px;height:14px;"></i>
                        Transit (on-site, between zones)
                    </td>
                    <td class="text-end <?= $totalTransitSec > $totalInSec * 0.2 ? 'text-warning' : '' ?>">
                        <?= fmtSec($totalTransitSec) ?>
                    </td>
                    <td colspan="2">
                        <?php if ($totalTransitSec > $totalInSec * 0.2): ?>
                        <small class="text-warning">Review routing</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>
    </div>
</div>

<?php if ($totalTransitSec > $totalInSec * 0.2): ?>
<div class="alert alert-warning mt-3 mb-0 small">
    <i data-feather="alert-triangle" class="me-1" style="width:14px;"></i>
    <strong>High transit time detected</strong> —
    crew spent <?= fmtSec($totalTransitSec) ?> moving between zones
    (<?= $totalInSec > 0 ? round($totalTransitSec / $totalInSec * 100) : 0 ?>% of zone time).
    Consider adjusting the zone layout or reviewing crew routing.
</div>
<?php endif; ?>

<?php endif; ?>

<?php include 'includes/appstack_footer.php'; ?>
