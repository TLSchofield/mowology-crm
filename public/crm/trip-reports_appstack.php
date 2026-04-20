<?php
/**
 * Trip Reports — Admin list of all vehicle trip inspection records.
 * Allows filtering by date range, viewing status, and downloading PDFs.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();

// ── Filters ───────────────────────────────────────────────────────────────────
$filterFrom   = trim($_GET['from']   ?? date('Y-m-01'));   // start of current month
$filterTo     = trim($_GET['to']     ?? date('Y-m-d'));     // today
$filterStatus = trim($_GET['status'] ?? '');
$filterDriver = (int)($_GET['driver_id'] ?? 0);

// Sanitise date inputs
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   $filterTo   = date('Y-m-d');

// ── Query ─────────────────────────────────────────────────────────────────────
$params = [];
$where  = ['r.report_date BETWEEN ? AND ?'];
$params[] = $filterFrom;
$params[] = $filterTo;

if ($filterStatus !== '') {
    $where[]  = 'r.status = ?';
    $params[] = $filterStatus;
}
if ($filterDriver > 0) {
    $where[]  = 'r.driver_id = ?';
    $params[] = $filterDriver;
}

// Non-admin drivers only see their own records
if ($user['role'] === 'user') {
    $where[]  = 'r.driver_id = ?';
    $params[] = (int)$user['id'];
}

$whereClause = implode(' AND ', $where);

// Soft-check for migration 1014's trip_sequence column so old
// deployments don't crash during partial rollout.
$hasTripSequence = false;
try {
    $hasTripSequence = (bool)$db->query("SHOW COLUMNS FROM vehicle_trip_reports LIKE 'trip_sequence'")->fetch();
} catch (Throwable $e) { /* column absent */ }

// r.* already returns trip_sequence when the column exists, so only
// alias a constant 1 when the column is missing (pre-1014 deployment).
$seqSelect = $hasTripSequence ? '' : ', 1 AS trip_sequence';
$seqOrder  = $hasTripSequence ? ', r.trip_sequence ASC' : '';

$stmt = $db->prepare("
    SELECT r.*{$seqSelect}, u.full_name as driver_name
    FROM vehicle_trip_reports r
    JOIN users u ON r.driver_id = u.id
    WHERE {$whereClause}
    ORDER BY r.report_date DESC, r.driver_id ASC{$seqOrder}
");
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Driver list for filter (admin/manager only) ───────────────────────────────
$drivers = [];
if ($user['role'] !== 'user') {
    $dStmt = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 AND role IN ('admin','manager','user') ORDER BY full_name");
    $drivers = $dStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$total     = count($reports);
$complete  = 0;
$preOnly   = 0;
$pending   = 0;
foreach ($reports as $r) {
    if ($r['status'] === 'complete')     $complete++;
    elseif ($r['status'] === 'pre_complete') $preOnly++;
    else $pending++;
}

$pageTitle  = 'Trip Reports';
$activePage = 'driver';
?>
<?php include 'includes/appstack_head.php'; ?>

<!-- Page header -->
<div class="mw-page-header">
  <div class="mw-page-header-left">
    <h1 class="mw-page-title">Vehicle Trip Reports</h1>
    <p class="mw-page-subtitle">RAM 3500 PF8865 &mdash; Daily pre/post trip inspection records</p>
  </div>
  <div class="mw-page-actions">
    <a href="/crm/driver-portal.php" class="btn btn-success">
      <i data-feather="truck"></i> Driver Portal
    </a>
  </div>
</div>

<!-- Stats row -->
<div class="row mb-4">
    <div class="col-6 col-md-3 mb-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="h2 mb-0 text-success"><?php echo $complete; ?></div>
                <div class="small text-muted">Complete</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="h2 mb-0" style="color:var(--mw-orange);"><?php echo $preOnly; ?></div>
                <div class="small text-muted">Pre-Trip Only</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="h2 mb-0 text-secondary"><?php echo $pending; ?></div>
                <div class="small text-muted">Pending</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="h2 mb-0"><?php echo $total; ?></div>
                <div class="small text-muted">Total Records</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter bar -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="form-inline flex-wrap" style="gap:8px;">
            <input type="date" name="from" class="form-control form-control-sm"
                   value="<?php echo htmlspecialchars($filterFrom); ?>">
            <span class="text-muted">to</span>
            <input type="date" name="to" class="form-control form-control-sm"
                   value="<?php echo htmlspecialchars($filterTo); ?>">
            <select name="status" class="form-control form-control-sm">
                <option value="">All statuses</option>
                <option value="complete"     <?php echo $filterStatus === 'complete'     ? 'selected' : ''; ?>>Complete</option>
                <option value="pre_complete" <?php echo $filterStatus === 'pre_complete' ? 'selected' : ''; ?>>Pre-Trip Only</option>
                <option value="pre_pending"  <?php echo $filterStatus === 'pre_pending'  ? 'selected' : ''; ?>>Pending</option>
            </select>
            <?php if (!empty($drivers)): ?>
            <select name="driver_id" class="form-control form-control-sm">
                <option value="">All drivers</option>
                <?php foreach ($drivers as $d): ?>
                <option value="<?php echo (int)$d['id']; ?>" <?php echo $filterDriver === (int)$d['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($d['full_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-success">Filter</button>
            <a href="/crm/trip-reports_appstack.php" class="btn btn-sm btn-outline-secondary">Reset</a>
        </form>
    </div>
</div>

<!-- Reports table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <?php echo $total; ?> record<?php echo $total !== 1 ? 's' : ''; ?>
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($reports)): ?>
        <div class="text-center py-5 text-muted">
            <i data-feather="clipboard" style="width:48px;height:48px;opacity:.3;margin-bottom:12px;"></i>
            <p>No trip reports found for this date range.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="mw-table">
                <thead class="thead-light">
                    <tr>
                        <th>Date</th>
                        <th>Driver</th>
                        <th style="width:56px;">Trip</th>
                        <th>Status</th>
                        <th>Pre-Trip</th>
                        <th>Post-Trip</th>
                        <th>Odometer</th>
                        <th>Safe</th>
                        <th class="text-center">PDF</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $r): ?>
                    <tr>
                        <td>
                            <strong><?php echo date('D, M j', strtotime($r['report_date'])); ?></strong>
                            <div class="small text-muted"><?php echo date('Y', strtotime($r['report_date'])); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($r['driver_name']); ?></td>
                        <td>
                            <span class="badge" style="background:var(--mw-light);color:var(--mw-dark);font-size:11px;padding:3px 7px;font-weight:700;">
                                #<?php echo (int)($r['trip_sequence'] ?? 1); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'complete'): ?>
                                <span class="badge" style="background:var(--mw-light);color:var(--mw-dark);font-size:11px;padding:4px 8px;">Complete</span>
                            <?php elseif ($r['status'] === 'pre_complete'): ?>
                                <span class="badge badge-warning" style="font-size:11px;padding:4px 8px;">Pre-Trip Only</span>
                            <?php else: ?>
                                <span class="badge badge-secondary" style="font-size:11px;padding:4px 8px;">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['pre_trip_at']): ?>
                                <span class="text-success">
                                    <i data-feather="check-circle" style="width:13px;height:13px;"></i>
                                    <?php echo date('g:i a', strtotime($r['pre_trip_at'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['post_trip_at']): ?>
                                <span class="text-success">
                                    <i data-feather="check-circle" style="width:13px;height:13px;"></i>
                                    <?php echo date('g:i a', strtotime($r['post_trip_at'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted" style="font-size:12px;">
                            <?php if ($r['odometer_start']): ?>
                            <?php echo number_format((int)$r['odometer_start']); ?> km
                            <?php if ($r['odometer_end']): ?>
                                <span class="text-muted"> → <?php echo number_format((int)$r['odometer_end']); ?> km</span>
                                <div class="small text-success">+<?php echo number_format((int)$r['odometer_end'] - (int)$r['odometer_start']); ?> km</div>
                            <?php endif; ?>
                            <?php else: ?>
                            &mdash;
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['safe_to_drive']): ?>
                                <span class="text-success" title="Safe to drive confirmed">
                                    <i data-feather="check-circle" style="width:16px;height:16px;"></i>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($r['pdf_path'])): ?>
                            <a href="/crm/api/trip-report.php?action=download&id=<?php echo (int)$r['id']; ?>"
                               title="Download PDF" class="btn btn-sm btn-outline-secondary" style="padding:3px 8px;">
                                <i data-feather="download" style="width:14px;height:14px;"></i>
                            </a>
                            <?php else: ?>
                            <span class="text-muted small">No PDF</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/appstack_footer.php'; ?>
