<?php
/**
 * Work Zones — time spent per named work zone across visits.
 * Grouped by property, with avg time/visit, transit breakdown, and zone color palette.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

// Try server path first (app/ inside public_html), then local dev path (app/ at repo root)
$__geoModelPaths = [
    dirname(__DIR__) . '/app/Modules/Geofence/Models/GeofenceModel.php',
    __DIR__ . '/../../app/Modules/Geofence/Models/GeofenceModel.php',
];
foreach ($__geoModelPaths as $__p) {
    if (is_file($__p)) { require_once $__p; break; }
}

// Filters
$dateFrom   = $_GET['date_from']   ?? date('Y-m-01');
$dateTo     = $_GET['date_to']     ?? date('Y-m-d');
$propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : null;
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

// Properties for filter dropdown
$propertiesStmt = $db->query("
    SELECT id, address FROM properties
    WHERE id IN (SELECT DISTINCT property_id FROM job_plans)
    ORDER BY address ASC
");
$properties = $propertiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Zone rows (grouped by property in PHP)
$rows = geofenceGetZoneReport($db, $dateFrom, $dateTo, $propertyId ?: null);

// Transit totals for same period
$transitWhere  = 'WHERE jv.scheduled_date BETWEEN ? AND ? AND jv.transit_seconds IS NOT NULL';
$transitParams = [$dateFrom, $dateTo];
if ($propertyId) {
    $transitWhere .= ' AND jp.property_id = ?';
    $transitParams[] = $propertyId;
}
$transitStmt = $db->prepare("
    SELECT jp.property_id,
           SUM(jv.transit_seconds)    AS total_transit,
           COUNT(DISTINCT jv.id)      AS visit_count
    FROM job_visits jv
    JOIN job_plans jp ON jp.id = jv.plan_id
    $transitWhere
    GROUP BY jp.property_id
");
$transitStmt->execute($transitParams);
$transitByProperty = [];
foreach ($transitStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $transitByProperty[(int)$t['property_id']] = $t;
}

// Helper: format seconds as h:mm
function fmtSec(int $sec): string {
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
}

// Zone color palette — same order as zone-editor-manager.js
$ZONE_COLORS = ['#2D8659','#e85d04','#0d6efd','#6f42c1','#20c997','#fd7e14','#dc3545','#0dcaf0'];

// Group rows by property
$byProperty = [];
foreach ($rows as $r) {
    $pid = (int)$r['property_id'];
    if (!isset($byProperty[$pid])) {
        $byProperty[$pid] = [
            'address' => $r['property_address'],
            'zones'   => [],
            'total_seconds' => 0,
        ];
    }
    $byProperty[$pid]['zones'][]      = $r;
    $byProperty[$pid]['total_seconds'] += (int)$r['total_in_seconds'];
}

// Grand totals
$grandTotalZoneSec    = array_sum(array_column($rows, 'total_in_seconds'));
$grandTotalTransitSec = array_sum(array_column($transitByProperty, 'total_transit'));
$totalZoneCount       = count($rows);

$pageTitle  = 'Work Zones';
$activePage = 'work-zones';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Work Zones</h1>
        <p class="mw-page-subtitle">GPS-derived time attribution per named work zone</p>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <label class="form-label small mb-1">From</label>
                <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#zone-date-from"
                        data-mw-dp-range-group="zone-range" data-mw-dp-range-role="start" aria-haspopup="true" aria-expanded="false">
                    <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="mw-datepicker-date" data-mw-dp-label></span>
                    <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <input type="date" id="zone-date-from" name="date_from" class="form-control form-control-sm" hidden
                       value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-sm-3">
                <label class="form-label small mb-1">To</label>
                <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#zone-date-to"
                        data-mw-dp-range-group="zone-range" data-mw-dp-range-role="end" aria-haspopup="true" aria-expanded="false">
                    <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="mw-datepicker-date" data-mw-dp-label></span>
                    <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <input type="date" id="zone-date-to" name="date_to" class="form-control form-control-sm" hidden
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
                <div class="mw-stat-value"><?= fmtSec((int)$grandTotalZoneSec) ?></div>
                <div class="mw-stat-label">Total Zone Time</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4 mb-2">
        <?php $transitHigh = $grandTotalZoneSec > 0 && $grandTotalTransitSec > $grandTotalZoneSec * 0.2; ?>
        <div class="card h-100 <?= $transitHigh ? 'mw-transit-warning' : 'mw-stat-card' ?>">
            <div class="card-body text-center">
                <div class="mw-stat-value <?= $transitHigh ? 'text-warning' : '' ?>">
                    <?= fmtSec((int)$grandTotalTransitSec) ?>
                </div>
                <div class="mw-stat-label">
                    Transit (on-site, between zones)
                    <?php if ($transitHigh): ?>
                    <span class="badge bg-warning text-dark ms-1">High</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4 mb-2">
        <div class="card mw-stat-card h-100">
            <div class="card-body text-center">
                <div class="mw-stat-value"><?= $totalZoneCount ?></div>
                <div class="mw-stat-label">Zones Tracked</div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($rows)): ?>

<div class="card">
    <div class="card-body text-center py-5">
        <i data-feather="map-pin" style="width:48px;height:48px;stroke:#ccc;"></i>
        <p class="text-muted mt-3 mb-1">No zone time data for this period.</p>
        <p class="text-muted small mb-3">
            Zone sessions are computed when visits are completed and work zones have been drawn for the property.
        </p>
        <a href="/crm/jobs/zone-editor.php" class="btn btn-sm btn-success">
            <i data-feather="layers" class="me-1"></i> Draw Work Zones
        </a>
    </div>
</div>

<?php else: ?>

<?php foreach ($byProperty as $pid => $propData):
    $propTransit    = (int)($transitByProperty[$pid]['total_transit'] ?? 0);
    $propZoneSec    = (int)$propData['total_seconds'];
    $propOnSite     = $propZoneSec + $propTransit;
    $propTransitHigh = $propZoneSec > 0 && $propTransit > $propZoneSec * 0.2;
    $colorIdx       = 0;  // reset color counter per property
?>

<div class="card mb-4">
    <!-- Property header -->
    <div class="card-header d-flex align-items-center justify-content-between">
        <div>
            <i data-feather="home" style="width:14px;height:14px;" class="me-1 text-muted"></i>
            <strong><?= htmlspecialchars($propData['address']) ?></strong>
        </div>
        <div class="d-flex align-items-center gap-3">
            <?php if ($propTransit > 0): ?>
            <span class="small text-muted">
                <i data-feather="navigation" style="width:12px;height:12px;" class="me-1"></i>
                <?= fmtSec($propTransit) ?> transit
                <?php if ($propTransitHigh): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:10px;">High</span>
                <?php endif; ?>
            </span>
            <?php endif; ?>
            <span class="badge bg-secondary"><?= count($propData['zones']) ?> zone<?= count($propData['zones']) !== 1 ? 's' : '' ?></span>
            <span class="small fw-semibold"><?= fmtSec($propZoneSec) ?> zone time</span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="mw-table">
            <thead class="table-light">
                <tr>
                    <th>Zone</th>
                    <th>Plan / Service</th>
                    <th class="text-end">Total Time</th>
                    <th class="text-end">Avg / Visit</th>
                    <th class="text-center">Visits</th>
                    <th class="text-center">Crew</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($propData['zones'] as $r):
                    $inSec   = (int)$r['total_in_seconds'];
                    $visits  = max(1, (int)$r['visit_count']);
                    $avgSec  = intdiv($inSec, $visits);
                    $pct     = $propZoneSec > 0 ? round($inSec / $propZoneSec * 100) : 0;
                    $color   = $ZONE_COLORS[$colorIdx++ % count($ZONE_COLORS)];
                ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="mw-zone-dot-sm flex-shrink-0" style="background:<?= $color ?>;"></span>
                            <div>
                                <div class="fw-semibold small"><?= htmlspecialchars($r['zone_label'] ?? 'Unnamed Zone') ?></div>
                                <div class="mw-zone-bar mt-1">
                                    <div class="mw-zone-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="small"><?= htmlspecialchars($r['plan_title'] ?? '—') ?></div>
                        <?php if (!empty($r['plan_service_type'])): ?>
                        <span class="badge bg-light text-dark" style="font-size:10px;"><?= htmlspecialchars($r['plan_service_type']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <span class="fw-semibold"><?= fmtSec($inSec) ?></span>
                        <div class="text-muted" style="font-size:11px;"><?= $pct ?>% of property</div>
                    </td>
                    <td class="text-end">
                        <span class="small"><?= fmtSec($avgSec) ?></span>
                    </td>
                    <td class="text-center small"><?= $visits ?></td>
                    <td class="text-center small"><?= (int)$r['max_crew'] ?></td>
                </tr>
                <?php endforeach; ?>

                <?php if ($propTransit > 0): ?>
                <tr class="<?= $propTransitHigh ? 'table-warning' : '' ?> text-muted">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="mw-zone-dot-sm flex-shrink-0" style="background:#adb5bd;"></span>
                            <div>
                                <div class="small fst-italic">Transit (between zones)</div>
                                <?php if ($propTransitHigh): ?>
                                <div class="text-warning" style="font-size:10px;">Review zone layout or crew routing</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td colspan="2" class="text-end">
                        <span class="<?= $propTransitHigh ? 'text-warning fw-semibold' : '' ?>"><?= fmtSec($propTransit) ?></span>
                    </td>
                    <td colspan="3"></td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-light fw-semibold">
                <tr>
                    <td colspan="2">On-site total</td>
                    <td class="text-end"><?= fmtSec($propOnSite) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php endforeach; ?>

<?php if ($transitHigh): ?>
<div class="alert alert-warning mt-2 mb-0 small">
    <i data-feather="alert-triangle" class="me-1" style="width:14px;"></i>
    <strong>High transit time</strong> —
    crew spent <?= fmtSec((int)$grandTotalTransitSec) ?> moving between zones
    (<?= $grandTotalZoneSec > 0 ? round($grandTotalTransitSec / $grandTotalZoneSec * 100) : 0 ?>% of zone time).
    Consider adjusting zone layout or reviewing crew routing.
</div>
<?php endif; ?>

<?php endif; ?>

<?php include 'includes/appstack_footer.php'; ?>
