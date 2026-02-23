<?php
/**
 * Profitability Dashboard
 * ─────────────────────────────────────────────────────────────────────────────
 * Full margin intelligence: labor + materials + drive cost vs revenue.
 * Primary source: visit_margin_snapshots (captured by VisitCompletionService).
 * Fallback: legacy expenses-only view for jobs without snapshots.
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$db = getDB();

// ── Filters ───────────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'this_month';
$serviceFilter = trim($_GET['service'] ?? '');

switch ($period) {
    case 'last_month':
        $dateFrom    = date('Y-m-01', strtotime('first day of last month'));
        $dateTo      = date('Y-m-t',  strtotime('last day of last month'));
        $periodLabel = 'Last Month';
        break;
    case 'last_90':
        $dateFrom    = date('Y-m-d', strtotime('-90 days'));
        $dateTo      = date('Y-m-d');
        $periodLabel = 'Last 90 Days';
        break;
    case 'ytd':
        $dateFrom    = date('Y-01-01');
        $dateTo      = date('Y-m-d');
        $periodLabel = 'Year to Date';
        break;
    default:
        $period      = 'this_month';
        $dateFrom    = date('Y-m-01');
        $dateTo      = date('Y-m-d');
        $periodLabel = 'This Month';
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt_money(?float $v, bool $sign = false): string {
    if ($v === null) return '<span class="text-muted">—</span>';
    $abs = '$' . number_format(abs($v), 2);
    if ($v < 0)          return '<span class="text-danger">-'  . $abs . '</span>';
    if ($sign && $v > 0) return '<span class="text-success">+' . $abs . '</span>';
    return $abs;
}
function fmt_pct(?float $v): string {
    if ($v === null) return '<span class="text-muted">—</span>';
    $cls = $v < 0 ? 'text-danger' : ($v < 20 ? 'text-warning' : 'text-success');
    return '<span class="' . $cls . '">' . number_format($v, 1) . '%</span>';
}
function svc_label(string $slug): string {
    static $map = [
        'lawn_care'          => 'Lawn Care',
        'landscaping'        => 'Landscaping',
        'snow_removal'       => 'Snow Removal',
        'hedge_trimming'     => 'Hedge Trimming',
        'garden_maintenance' => 'Garden',
        'seasonal_cleanup'   => 'Seasonal Cleanup',
    ];
    return $map[$slug] ?? ucwords(str_replace('_', ' ', $slug));
}

// ── Build WHERE clause ────────────────────────────────────────────────────────
$snapBase   = "FROM visit_margin_snapshots vms WHERE vms.visit_date BETWEEN ? AND ?";
$snapParams = [$dateFrom, $dateTo];
if ($serviceFilter) {
    $snapBase    .= ' AND vms.service_type = ?';
    $snapParams[] = $serviceFilter;
}

// ── Summary stats ─────────────────────────────────────────────────────────────
$summaryStmt = $db->prepare("
    SELECT
        COUNT(*)                                              AS visit_count,
        COALESCE(SUM(vms.quoted_amount), 0)                  AS total_revenue,
        COALESCE(SUM(vms.labor_cost), 0)                     AS total_labor,
        COALESCE(SUM(vms.material_cost), 0)                  AS total_material,
        COALESCE(SUM(vms.drive_cost), 0)                     AS total_drive,
        COALESCE(SUM(vms.gross_margin), 0)                   AS total_margin,
        AVG(vms.margin_pct)                                  AS avg_margin_pct,
        COUNT(CASE WHEN vms.margin_pct < 20 THEN 1 END)      AS low_margin_count,
        COUNT(CASE WHEN vms.gross_margin < 0 THEN 1 END)     AS negative_count
    $snapBase
");
$summaryStmt->execute($snapParams);
$snap = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// Total completed visits in period (for coverage indicator)
$covBase   = "FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?";
$covParams = [$dateFrom, $dateTo];
if ($serviceFilter) { $covBase .= ' AND jp.service_type = ?'; $covParams[] = $serviceFilter; }
$totalCompleted = (int)$db->prepare("SELECT COUNT(*) $covBase")->execute($covParams) ? (int)$db->prepare("SELECT COUNT(*) $covBase")->execute($covParams) : 0;
$covStmt = $db->prepare("SELECT COUNT(*) $covBase");
$covStmt->execute($covParams);
$totalCompleted = (int)$covStmt->fetchColumn();
$snapshotCoverage = $totalCompleted > 0
    ? (int)round(((int)$snap['visit_count'] / $totalCompleted) * 100)
    : 0;

// ── By service ────────────────────────────────────────────────────────────────
$byServiceStmt = $db->prepare("
    SELECT
        COALESCE(vms.service_type, 'unknown')   AS service_type,
        COUNT(*)                                AS visit_count,
        SUM(vms.quoted_amount)                  AS revenue,
        SUM(vms.labor_cost)                     AS labor,
        SUM(vms.material_cost)                  AS material,
        SUM(vms.drive_cost)                     AS drive,
        SUM(vms.gross_margin)                   AS margin,
        AVG(vms.margin_pct)                     AS avg_margin_pct,
        AVG(jv.actual_duration_minutes)         AS avg_duration_min
    FROM visit_margin_snapshots vms
    LEFT JOIN job_visits jv ON jv.id = vms.visit_id
    WHERE vms.visit_date BETWEEN ? AND ?
    GROUP BY vms.service_type
    ORDER BY SUM(vms.gross_margin) DESC
");
$svcParams = [$dateFrom, $dateTo];
$byServiceStmt->execute($svcParams);
$byService = $byServiceStmt->fetchAll(PDO::FETCH_ASSOC);

// ── 6-month trend ─────────────────────────────────────────────────────────────
$trendStmt = $db->prepare("
    SELECT
        DATE_FORMAT(vms.visit_date, '%Y-%m')   AS month,
        COUNT(*)                               AS visits,
        COALESCE(SUM(vms.quoted_amount), 0)    AS revenue,
        COALESCE(SUM(vms.gross_margin), 0)     AS margin,
        AVG(vms.margin_pct)                    AS avg_margin_pct
    FROM visit_margin_snapshots vms
    WHERE vms.visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(vms.visit_date, '%Y-%m')
    ORDER BY month ASC
");
$trendStmt->execute();
$trend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Low-margin visits ─────────────────────────────────────────────────────────
$lowStmt = $db->prepare("
    SELECT
        vms.visit_id,
        vms.visit_date,
        vms.service_type,
        vms.quoted_amount,
        vms.labor_cost,
        vms.material_cost,
        vms.drive_cost,
        vms.gross_margin,
        vms.margin_pct,
        jv.actual_duration_minutes,
        jp.estimated_duration_minutes,
        CONCAT(c.first_name, ' ', c.last_name) AS client_name,
        p.address                               AS property_address,
        jp.id                                   AS plan_id
    FROM visit_margin_snapshots vms
    JOIN job_visits  jv ON jv.id  = vms.visit_id
    JOIN job_plans   jp ON jp.id  = vms.plan_id
    LEFT JOIN contacts   c ON c.id = vms.contact_id
    LEFT JOIN properties p ON p.id = vms.property_id
    WHERE vms.visit_date BETWEEN ? AND ?
      AND (vms.margin_pct < 30 OR vms.gross_margin < 0)
    ORDER BY vms.margin_pct ASC
    LIMIT 25
");
$lowStmt->execute([$dateFrom, $dateTo]);
$lowMargin = $lowStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Available service types for filter ────────────────────────────────────────
$svcListStmt = $db->query("SELECT DISTINCT service_type FROM visit_margin_snapshots WHERE service_type IS NOT NULL ORDER BY service_type");
$availableServices = $svcListStmt->fetchAll(PDO::FETCH_COLUMN);

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle  = 'Profitability';
$activePage = 'profitability';
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap" style="gap:8px;">
    <div>
        <h1 class="h3 mb-1">Profitability</h1>
        <p class="text-muted mb-0" style="font-size:0.85rem;">
            <?php echo htmlspecialchars($periodLabel); ?>
            <?php if ($totalCompleted > 0 && $snapshotCoverage < 100): ?>
            &mdash; <span class="badge badge-<?php echo $snapshotCoverage < 50 ? 'warning' : 'info'; ?>" style="font-size:0.7rem;">
                <?php echo $snapshotCoverage; ?>% of visits have full margin data
            </span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap" style="gap:8px;">
        <div class="btn-group btn-group-sm">
            <?php foreach (['this_month' => 'This Month', 'last_month' => 'Last Month', 'last_90' => 'Last 90d', 'ytd' => 'YTD'] as $p => $l): ?>
            <a href="?period=<?php echo $p; ?><?php echo $serviceFilter ? '&service=' . urlencode($serviceFilter) : ''; ?>"
               class="btn btn-<?php echo $period === $p ? 'primary' : 'outline-secondary'; ?>">
                <?php echo $l; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if ($availableServices): ?>
        <select class="form-control form-control-sm" style="width:auto;"
            onchange="location='?period=<?php echo $period; ?>&service='+this.value">
            <option value="">All Services</option>
            <?php foreach ($availableServices as $svc): ?>
            <option value="<?php echo htmlspecialchars($svc); ?>" <?php echo $serviceFilter === $svc ? 'selected' : ''; ?>>
                <?php echo svc_label($svc); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <a href="/crm/expenses_appstack.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="dollar-sign" style="width:13px;height:13px;"></i> Expenses
        </a>
    </div>
</div>

<?php if ($snapshotCoverage === 0 && $totalCompleted > 0): ?>
<div class="alert alert-info mb-4">
    <strong>No margin data yet for this period.</strong>
    Margin snapshots are captured automatically when visits are marked complete going forward.
    <?php if ($user['role'] === 'admin'): ?>
    Make sure crew have <a href="/crm/team/index.php">hourly rates set</a> and the
    <code>901_labor_cost_snapshots</code> migration has been applied.
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── KPI Cards ──────────────────────────────────────────────────────────── -->
<div class="row mb-4">
    <?php
    $totalRev   = (float)$snap['total_revenue'];
    $totalMar   = (float)$snap['total_margin'];
    $totalLab   = (float)$snap['total_labor'];
    $totalMat   = (float)$snap['total_material'];
    $totalDrv   = (float)$snap['total_drive'];
    $avgPct     = $snap['avg_margin_pct'] !== null ? (float)$snap['avg_margin_pct'] : null;
    $visitsSnap = (int)$snap['visit_count'];
    $lowCount   = (int)$snap['low_margin_count'];
    $negCount   = (int)$snap['negative_count'];

    $kpis = [
        ['label'=>'Revenue',          'val'=>fmt_money($totalRev ?: null),                  'icon'=>'dollar-sign',    'accent'=>'#3b82f6'],
        ['label'=>'Gross Margin',     'val'=>fmt_money($totalMar ?: null, true),             'icon'=>'trending-up',    'accent'=>$totalMar < 0 ? '#dc3545' : '#2D8659'],
        ['label'=>'Avg Margin %',     'val'=>fmt_pct($avgPct),                              'icon'=>'percent',        'accent'=>($avgPct ?? 100) < 20 ? '#f59e0b' : '#2D8659'],
        ['label'=>'Labor Cost',       'val'=>fmt_money($totalLab ?: null),                   'icon'=>'users',          'accent'=>'#f59e0b'],
        ['label'=>'Material Cost',    'val'=>fmt_money($totalMat ?: null),                   'icon'=>'package',        'accent'=>'#8b5cf6'],
        ['label'=>'Drive Cost',       'val'=>fmt_money($totalDrv ?: null),                   'icon'=>'navigation',     'accent'=>'#6b7280'],
        ['label'=>'Visits Tracked',   'val'=>'<strong>' . number_format($visitsSnap) . '</strong>', 'icon'=>'clipboard', 'accent'=>'#3b82f6'],
        ['label'=>'Low Margin (<20%)','val'=>'<strong class="' . ($lowCount > 0 ? 'text-warning' : '') . '">' . number_format($lowCount) . '</strong>', 'icon'=>'alert-triangle', 'accent'=>'#f59e0b'],
    ];
    foreach ($kpis as $k):
    ?>
    <div class="col-6 col-md-3 mb-3">
        <div class="card" style="border-left:4px solid <?php echo $k['accent']; ?>;">
            <div class="card-body py-3 px-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-muted mb-1" style="font-size:0.72rem;text-transform:uppercase;letter-spacing:.05em;">
                            <?php echo $k['label']; ?>
                        </p>
                        <div style="font-size:1.15rem;font-weight:600;"><?php echo $k['val']; ?></div>
                    </div>
                    <i data-feather="<?php echo $k['icon']; ?>" style="width:18px;height:18px;color:#d1d5db;flex-shrink:0;margin-top:2px;"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row mb-4">
    <!-- ── By Service ─────────────────────────────────────────────────────── -->
    <div class="col-lg-8 mb-4">
        <div class="card h-100">
            <div class="card-header" style="background:var(--mw-forest);color:#fff;">
                <h5 class="card-title mb-0" style="color:#fff;">
                    <i data-feather="layers" style="width:15px;height:15px;display:inline;"></i> Margin by Service Type
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if ($byService): ?>
                <!-- Table header -->
                <div style="display:grid;grid-template-columns:140px 1fr 56px 88px 88px 80px 76px;gap:6px;padding:8px 12px;
                            background:#f9fafb;font-size:.7rem;font-weight:700;text-transform:uppercase;
                            color:#9ca3af;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;">
                    <span>Service</span><span>Rev Bar</span><span>Visits</span>
                    <span>Revenue</span><span>Labor</span><span>Margin</span><span>Margin%</span>
                </div>
                <?php
                $maxRev = max(array_map(fn($r) => (float)($r['revenue'] ?? 0), $byService) ?: [1]);
                foreach ($byService as $row):
                    $pct    = (float)($row['avg_margin_pct'] ?? 0);
                    $bcolor = $pct < 0 ? '#dc3545' : ($pct < 20 ? '#f59e0b' : '#2D8659');
                    $bw     = $maxRev > 0 ? round(((float)($row['revenue'] ?? 0) / $maxRev) * 100) : 0;
                ?>
                <div style="display:grid;grid-template-columns:140px 1fr 56px 88px 88px 80px 76px;gap:6px;
                            padding:10px 12px;border-bottom:1px solid #f3f4f6;align-items:center;font-size:.83rem;">
                    <div>
                        <strong><?php echo svc_label(htmlspecialchars($row['service_type'])); ?></strong>
                        <?php if ($row['avg_duration_min']): ?>
                        <div style="font-size:.68rem;color:#9ca3af;">~<?php echo round((float)$row['avg_duration_min']); ?>min avg</div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="height:6px;background:#e9ecef;border-radius:3px;overflow:hidden;">
                            <div style="height:100%;width:<?php echo $bw; ?>%;background:<?php echo $bcolor; ?>;border-radius:3px;"></div>
                        </div>
                    </div>
                    <div><?php echo number_format((int)$row['visit_count']); ?></div>
                    <div><?php echo fmt_money($row['revenue'] ? (float)$row['revenue'] : null); ?></div>
                    <div><?php echo fmt_money($row['labor'] ? (float)$row['labor'] : null); ?></div>
                    <div><?php echo fmt_money($row['margin'] !== null ? (float)$row['margin'] : null, true); ?></div>
                    <div><?php echo fmt_pct($row['avg_margin_pct'] !== null ? (float)$row['avg_margin_pct'] : null); ?></div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i data-feather="bar-chart-2" style="width:36px;height:36px;opacity:.25;display:block;margin:0 auto 8px;"></i>
                    <p class="mb-0">No margin data for this period.</p>
                    <p class="small">Captured automatically when visits complete.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── 6-Month Trend ──────────────────────────────────────────────────── -->
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header" style="background:var(--mw-forest);color:#fff;">
                <h5 class="card-title mb-0" style="color:#fff;">
                    <i data-feather="bar-chart-2" style="width:15px;height:15px;display:inline;"></i> 6-Month Trend
                </h5>
            </div>
            <div class="card-body">
                <?php if ($trend):
                    $maxAbs = max(array_map(fn($r) => abs((float)($r['margin'] ?? 0)), $trend) ?: [1]);
                ?>
                <!-- Bar chart -->
                <div style="display:flex;align-items:flex-end;gap:6px;height:100px;margin-bottom:12px;">
                    <?php foreach ($trend as $tr):
                        $m  = (float)($tr['margin'] ?? 0);
                        $h  = $maxAbs > 0 ? max(3, (int)round(abs($m) / $maxAbs * 90)) : 3;
                        $bc = $m < 0 ? '#dc3545' : ($m < 50 ? '#f59e0b' : '#2D8659');
                        $ml = date('M', mktime(0,0,0,(int)substr($tr['month'],5,2),1));
                    ?>
                    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;">
                        <div style="font-size:.6rem;color:#9ca3af;">
                            <?php echo ($m >= 0 ? '' : '-') . '$' . number_format(abs($m)/1000, 1) . 'k'; ?>
                        </div>
                        <div style="width:100%;height:<?php echo $h; ?>px;background:<?php echo $bc; ?>;border-radius:3px 3px 0 0;"></div>
                        <div style="font-size:.6rem;color:#9ca3af;"><?php echo $ml; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <table class="table table-sm mb-0" style="font-size:.8rem;">
                    <thead><tr>
                        <th>Month</th><th class="text-right">Rev</th><th class="text-right">Margin</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($trend) as $tr): ?>
                    <tr>
                        <td><?php echo date('M Y', mktime(0,0,0,(int)substr($tr['month'],5,2),1,(int)substr($tr['month'],0,4))); ?></td>
                        <td class="text-right"><?php echo fmt_money($tr['revenue'] ? (float)$tr['revenue'] : null); ?></td>
                        <td class="text-right"><?php echo fmt_money($tr['margin'] !== null ? (float)$tr['margin'] : null, true); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i data-feather="bar-chart-2" style="width:28px;height:28px;opacity:.25;display:block;margin:0 auto 8px;"></i>
                    <p class="small mb-0">Trend builds as visits complete.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Low Margin Visits ──────────────────────────────────────────────────── -->
<?php if ($lowMargin): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:#7f1d1d;color:#fff;">
        <h5 class="card-title mb-0" style="color:#fff;">
            <i data-feather="alert-triangle" style="width:15px;height:15px;display:inline;"></i>
            Visits Below 30% Margin
            <span class="badge badge-light ml-2" style="font-size:.7rem;color:#7f1d1d;"><?php echo count($lowMargin); ?></span>
        </h5>
        <span style="font-size:.75rem;opacity:.8;">Review pricing or duration estimates for these jobs</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem;">
                <thead style="background:#f9fafb;">
                    <tr>
                        <th>Date</th><th>Client</th><th>Property</th><th>Service</th>
                        <th class="text-right">Quoted</th><th class="text-right">Labor</th>
                        <th class="text-right">Materials</th><th class="text-right">Drive</th>
                        <th class="text-right">Margin</th><th class="text-right">Margin%</th>
                        <th class="text-right">Duration</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lowMargin as $lv):
                    $pct = $lv['margin_pct'] !== null ? (float)$lv['margin_pct'] : null;
                    $rowCls = ($pct !== null && $pct < 0) ? 'table-danger' : (($pct !== null && $pct < 15) ? 'table-warning' : '');
                    $actual = (int)($lv['actual_duration_minutes'] ?? 0);
                    $est    = (int)($lv['estimated_duration_minutes'] ?? 0);
                    $over   = ($est > 0 && $actual > $est) ? $actual - $est : 0;
                ?>
                <tr class="<?php echo $rowCls; ?>">
                    <td><?php echo htmlspecialchars($lv['visit_date']); ?></td>
                    <td><?php echo htmlspecialchars(trim($lv['client_name'] ?? '—')); ?></td>
                    <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($lv['property_address'] ?? ''); ?>">
                        <?php echo htmlspecialchars($lv['property_address'] ?? '—'); ?>
                    </td>
                    <td><?php echo svc_label(htmlspecialchars($lv['service_type'] ?? '')); ?></td>
                    <td class="text-right"><?php echo fmt_money($lv['quoted_amount']   !== null ? (float)$lv['quoted_amount']   : null); ?></td>
                    <td class="text-right"><?php echo fmt_money($lv['labor_cost']      !== null ? (float)$lv['labor_cost']      : null); ?></td>
                    <td class="text-right"><?php echo fmt_money($lv['material_cost']   !== null ? (float)$lv['material_cost']   : null); ?></td>
                    <td class="text-right"><?php echo fmt_money($lv['drive_cost']      !== null ? (float)$lv['drive_cost']      : null); ?></td>
                    <td class="text-right"><?php echo fmt_money($lv['gross_margin']    !== null ? (float)$lv['gross_margin']    : null, true); ?></td>
                    <td class="text-right"><?php echo fmt_pct($pct); ?></td>
                    <td class="text-right">
                        <?php if ($actual > 0): ?>
                            <?php echo $actual; ?>min
                            <?php if ($over > 5): ?>
                            <span class="badge badge-warning" style="font-size:.65rem;">+<?php echo $over; ?>m</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($lv['plan_id']): ?>
                        <a href="/crm/jobs/view.php?id=<?php echo (int)$lv['plan_id']; ?>" class="btn btn-sm btn-outline-secondary" title="View job">
                            <i data-feather="external-link" style="width:12px;height:12px;"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Data coverage callout (admin) ─────────────────────────────────────── -->
<?php if ($user['role'] === 'admin' && $snapshotCoverage < 80 && $totalCompleted > 0): ?>
<div class="card border-left" style="border-left:4px solid #3b82f6 !important;">
    <div class="card-body py-3">
        <h6 class="mb-2">
            <i data-feather="info" style="width:14px;height:14px;display:inline;"></i>
            Margin Data Coverage: <?php echo $snapshotCoverage; ?>% of <?php echo number_format($totalCompleted); ?> completed visits
        </h6>
        <p class="mb-2 small text-muted">To improve coverage:</p>
        <ul class="small mb-0" style="padding-left:1.2rem;">
            <li>Set <a href="/crm/team/index.php">hourly rates for all crew</a></li>
            <li>Apply migration <code>901_labor_cost_snapshots.sql</code> if not yet run</li>
            <li>Use the proof-of-work <em>End Visit</em> button to complete visits (not manual DB changes)</li>
            <li>Link material expenses to visit IDs when snapping receipts</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
