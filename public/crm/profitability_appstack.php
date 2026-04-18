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

$view = ($_GET['view'] ?? 'classic') === 'spectrum' ? 'spectrum' : 'classic';

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

// ── Insights & Recommendations data ─────────────────────────────────────────
// Industry benchmarks (from Breakthrough Academy Profitability Playbook)
$benchmarks = [
    'maintenance' => [
        'label'    => 'Maintenance',
        'gross'    => ['needs_work' => 40, 'solid' => 45, 'exceptional' => 55],
        'net'      => ['needs_work' => 10, 'solid' => 12, 'exceptional' => 18],
    ],
    'construction' => [
        'label'    => 'Construction / Design',
        'gross'    => ['needs_work' => 30, 'solid' => 34, 'exceptional' => 40],
        'net'      => ['needs_work' => 10, 'solid' => 15, 'exceptional' => 20],
    ],
];

// Per-plan profitability with route efficiency (drive cost as % of revenue)
$planInsightsStmt = $db->prepare("
    SELECT
        jp.id                                   AS plan_id,
        jp.plan_number,
        jp.service_type,
        jp.estimated_duration_minutes,
        jp.price_per_visit,
        CONCAT(c.first_name, ' ', c.last_name)  AS client_name,
        p.address                               AS property_address,
        p.city,
        p.postal_code,
        COUNT(vms.visit_id)                     AS visit_count,
        COALESCE(SUM(vms.quoted_amount), 0)     AS total_revenue,
        COALESCE(SUM(vms.labor_cost), 0)        AS total_labor,
        COALESCE(SUM(vms.material_cost), 0)     AS total_material,
        COALESCE(SUM(vms.drive_cost), 0)        AS total_drive,
        COALESCE(SUM(vms.gross_margin), 0)      AS total_margin,
        AVG(vms.margin_pct)                     AS avg_margin_pct,
        AVG(vms.drive_cost)                     AS avg_drive_cost,
        AVG(jv.actual_duration_minutes)         AS avg_actual_minutes
    FROM visit_margin_snapshots vms
    JOIN job_plans   jp ON jp.id = vms.plan_id
    JOIN job_visits  jv ON jv.id = vms.visit_id
    LEFT JOIN contacts   c ON c.id = vms.contact_id
    LEFT JOIN properties p ON p.id = vms.property_id
    WHERE vms.visit_date BETWEEN ? AND ?
    GROUP BY jp.id, jp.plan_number, jp.service_type, jp.estimated_duration_minutes,
             jp.price_per_visit, c.first_name, c.last_name, p.address, p.city, p.postal_code
    HAVING COUNT(vms.visit_id) >= 1
    ORDER BY AVG(vms.margin_pct) ASC
");
$planInsightsStmt->execute([$dateFrom, $dateTo]);
$planInsights = $planInsightsStmt->fetchAll(PDO::FETCH_ASSOC);

// Classify each plan with actionable recommendations
$insights = [];
foreach ($planInsights as $pi) {
    $margin    = $pi['avg_margin_pct'] !== null ? (float)$pi['avg_margin_pct'] : null;
    $driveRatio = ($pi['total_revenue'] > 0)
        ? round(($pi['total_drive'] / $pi['total_revenue']) * 100, 1)
        : 0;
    $durationDrift = 0;
    if ($pi['estimated_duration_minutes'] > 0 && $pi['avg_actual_minutes'] > 0) {
        $durationDrift = round((($pi['avg_actual_minutes'] - $pi['estimated_duration_minutes']) / $pi['estimated_duration_minutes']) * 100, 0);
    }

    // Build recommendations
    $recs = [];
    $severity = 'ok'; // ok, warning, danger

    // 1. Margin check
    if ($margin !== null && $margin < 0) {
        $recs[] = ['icon' => 'alert-circle', 'color' => '#dc3545', 'text' => 'Losing money — reprice or drop this job'];
        $severity = 'danger';
    } elseif ($margin !== null && $margin < 20) {
        $recs[] = ['icon' => 'alert-triangle', 'color' => '#f59e0b', 'text' => 'Below target margin — review pricing'];
        $severity = 'warning';
    }

    // 2. Route efficiency
    if ($driveRatio > 25) {
        $recs[] = ['icon' => 'navigation', 'color' => '#dc3545', 'text' => "Drive cost is {$driveRatio}% of revenue — consider rerouting to a closer crew day"];
        if ($severity === 'ok') $severity = 'warning';
    } elseif ($driveRatio > 15) {
        $recs[] = ['icon' => 'navigation', 'color' => '#f59e0b', 'text' => "Drive cost is {$driveRatio}% of revenue — check if a closer route day exists"];
    }

    // 3. Estimate accuracy
    if ($durationDrift > 30) {
        $recs[] = ['icon' => 'clock', 'color' => '#f59e0b', 'text' => "Taking {$durationDrift}% longer than estimated — update duration estimate"];
    } elseif ($durationDrift < -20) {
        $recs[] = ['icon' => 'zap', 'color' => '#2D8659', 'text' => "Completing " . abs($durationDrift) . "% faster than estimated — crew is efficient here"];
    }

    // 4. Revenue check
    if ($pi['price_per_visit'] > 0 && $margin !== null && $margin >= 40) {
        $recs[] = ['icon' => 'thumbs-up', 'color' => '#2D8659', 'text' => 'Strong performer — keep this job'];
    }

    $pi['drive_ratio']      = $driveRatio;
    $pi['duration_drift']   = $durationDrift;
    $pi['recommendations']  = $recs;
    $pi['severity']         = $severity;
    $insights[] = $pi;
}

// Separate into categories
$insightsDanger  = array_filter($insights, fn($i) => $i['severity'] === 'danger');
$insightsWarning = array_filter($insights, fn($i) => $i['severity'] === 'warning');
$insightsOk      = array_filter($insights, fn($i) => $i['severity'] === 'ok' && !empty($i['recommendations']));

// ── Spectrum data (only when spectrum view is active) ─────────────────────────
$spectrumData = ['weeks52' => [], 'months' => [], 'services' => [], 'crew' => [], 'waterfall' => []];
$ytdSpec      = ['revenue' => 0, 'costs' => 0, 'net' => 0, 'margin' => 0];

if ($view === 'spectrum') {
    try {
        // 52-week weekly profit bars
        $w52Stmt = $db->query("
            SELECT
                FLOOR(DATEDIFF(CURDATE(), visit_date) / 7) AS weeks_ago,
                SUM(gross_margin) AS profit
            FROM visit_margin_snapshots
            WHERE visit_date > DATE_SUB(CURDATE(), INTERVAL 52 WEEK)
              AND visit_date <= CURDATE()
            GROUP BY FLOOR(DATEDIFF(CURDATE(), visit_date) / 7)
        ");
        $w52Map = [];
        foreach ($w52Stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $w52Map[(int)$r['weeks_ago']] = (float)$r['profit'];
        }
        $weeks52 = [];
        for ($i = 51; $i >= 0; $i--) { $weeks52[] = $w52Map[$i] ?? 0; }

        // 12-month pulse
        $m12Stmt = $db->query("
            SELECT DATE_FORMAT(visit_date, '%b') AS m, SUM(gross_margin) AS profit
            FROM visit_margin_snapshots
            WHERE visit_date > DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(visit_date, '%Y-%m'), DATE_FORMAT(visit_date, '%b')
            ORDER BY MIN(visit_date) ASC
        ");
        $months12 = array_map(
            fn($r) => ['m' => $r['m'], 'profit' => (float)$r['profit']],
            $m12Stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        // Service radar (margin fraction 0–1)
        $svcStmt = $db->query("
            SELECT service_type, AVG(COALESCE(margin_pct, 0)) / 100 AS margin
            FROM visit_margin_snapshots
            WHERE service_type IS NOT NULL
            GROUP BY service_type
            ORDER BY AVG(margin_pct) DESC
            LIMIT 7
        ");
        $serviceRadar = array_map(
            fn($r) => ['name' => svc_label($r['service_type']), 'margin' => round(max(0, min(1, (float)$r['margin'])), 4)],
            $svcStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        // Crew × month matrix (current year)
        $crewStmt = $db->prepare("
            SELECT
                u.full_name AS name,
                MONTH(vms.visit_date) AS month_num,
                SUM(vms.gross_margin) AS margin
            FROM visit_margin_snapshots vms
            JOIN job_visits jv ON jv.id = vms.visit_id
            JOIN users u ON u.id = jv.assigned_crew_id
            WHERE YEAR(vms.visit_date) = YEAR(CURDATE())
              AND u.is_active = 1
            GROUP BY u.id, u.full_name, MONTH(vms.visit_date)
            ORDER BY u.full_name, month_num
        ");
        $crewStmt->execute();
        $crewMap = [];
        foreach ($crewStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = $r['name'];
            if (!isset($crewMap[$name])) $crewMap[$name] = array_fill(0, 12, 0);
            $crewMap[$name][(int)$r['month_num'] - 1] = (float)$r['margin'];
        }
        $crewMatrix = array_map(
            fn($n, $m) => ['name' => $n, 'months' => array_values($m)],
            array_keys($crewMap), array_values($crewMap)
        );

        // Cost waterfall (YTD)
        $wfStmt = $db->query("
            SELECT
                COALESCE(SUM(quoted_amount), 0) AS revenue,
                COALESCE(SUM(labor_cost), 0)    AS labor,
                COALESCE(SUM(material_cost), 0) AS material,
                COALESCE(SUM(drive_cost), 0)    AS drive,
                COALESCE(SUM(gross_margin), 0)  AS net
            FROM visit_margin_snapshots
            WHERE YEAR(visit_date) = YEAR(CURDATE())
        ");
        $wf = $wfStmt->fetch(PDO::FETCH_ASSOC);
        $waterfallData = [
            ['label' => 'Revenue',   'value' =>  (float)($wf['revenue']  ?? 0),              'type' => 'total-pos'],
            ['label' => 'Labour',    'value' => -(float)abs((float)($wf['labor']    ?? 0)),   'type' => 'cost'],
            ['label' => 'Materials', 'value' => -(float)abs((float)($wf['material'] ?? 0)),   'type' => 'cost'],
            ['label' => 'Drive',     'value' => -(float)abs((float)($wf['drive']    ?? 0)),   'type' => 'cost'],
            ['label' => 'Net',       'value' =>  (float)($wf['net']      ?? 0),               'type' => (($wf['net'] ?? 0) >= 0 ? 'total-pos' : 'total-neg')],
        ];

        $ytdRev   = (float)($wf['revenue'] ?? 0);
        $ytdCosts = abs((float)($wf['labor'] ?? 0)) + abs((float)($wf['material'] ?? 0)) + abs((float)($wf['drive'] ?? 0));
        $ytdNet   = (float)($wf['net'] ?? 0);
        $ytdSpec  = [
            'revenue' => $ytdRev,
            'costs'   => $ytdCosts,
            'net'     => $ytdNet,
            'margin'  => $ytdRev > 0 ? round(($ytdNet / $ytdRev) * 100, 1) : 0,
        ];

        $spectrumData = [
            'weeks52'   => $weeks52,
            'months'    => $months12,
            'services'  => $serviceRadar,
            'crew'      => array_values($crewMatrix),
            'waterfall' => $waterfallData,
        ];
    } catch (Exception $e) {
        error_log("Spectrum data error: " . $e->getMessage());
    }
}

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle  = 'Profitability';
$activePage = 'profitability';
$extraHead  = $view === 'spectrum'
    ? '<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">'
    : '';
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
            <a href="?period=<?php echo $p; ?><?php echo $serviceFilter ? '&service=' . urlencode($serviceFilter) : ''; ?><?php echo $view === 'spectrum' ? '&view=spectrum' : ''; ?>"
               class="btn btn-<?php echo $period === $p ? 'primary' : 'outline-secondary'; ?>">
                <?php echo $l; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if ($availableServices): ?>
        <select class="form-control form-control-sm" style="width:auto;"
            onchange="location='?period=<?php echo $period; ?>&service='+this.value+'<?php echo $view === 'spectrum' ? '&view=spectrum' : ''; ?>'">
            <option value="">All Services</option>
            <?php foreach ($availableServices as $svc): ?>
            <option value="<?php echo htmlspecialchars($svc); ?>" <?php echo $serviceFilter === $svc ? 'selected' : ''; ?>>
                <?php echo svc_label($svc); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="btn-group btn-group-sm">
            <a href="?period=<?php echo $period; ?><?php echo $serviceFilter ? '&service=' . urlencode($serviceFilter) : ''; ?>"
               class="btn btn-<?php echo $view === 'classic' ? 'secondary' : 'outline-secondary'; ?>" title="Classic view">
                <i data-feather="grid" style="width:12px;height:12px;"></i> Classic
            </a>
            <a href="?period=<?php echo $period; ?><?php echo $serviceFilter ? '&service=' . urlencode($serviceFilter) : ''; ?>&view=spectrum"
               class="btn btn-<?php echo $view === 'spectrum' ? 'secondary' : 'outline-secondary'; ?>" title="Spectrum view">
                <i data-feather="activity" style="width:12px;height:12px;"></i> Spectrum
            </a>
        </div>
        <a href="/crm/expenses_appstack.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="dollar-sign" style="width:13px;height:13px;"></i> Expenses
        </a>
    </div>
</div>

<?php if ($view !== 'spectrum'): ?>
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
        ['label'=>'Avg Margin %',     'val'=>fmt_pct($avgPct) . ($avgPct !== null ? '<div style="font-size:.6rem;color:#9ca3af;margin-top:2px;">' . ($avgPct >= 45 ? '&#9733; Exceptional' : ($avgPct >= 34 ? '&#9679; Solid' : '&#9660; Needs Work')) . '</div>' : ''),
                                                                                              'icon'=>'percent',        'accent'=>($avgPct ?? 100) < 20 ? '#f59e0b' : '#2D8659'],
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

<!-- ══════════════════════════════════════════════════════════════════════════
     INDUSTRY BENCHMARKS (Breakthrough Academy Profitability Playbook)
     ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($avgPct !== null): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:var(--mw-forest);color:#fff;">
        <h5 class="card-title mb-0" style="color:#fff;">
            <i data-feather="target" style="width:15px;height:15px;display:inline;"></i>
            Industry Benchmarks
        </h5>
        <span style="font-size:.72rem;opacity:.8;">Source: Breakthrough Academy — 1,537 contractors benchmarked</span>
    </div>
    <div class="card-body py-3">
        <div class="row">
            <?php foreach ($benchmarks as $bKey => $b): ?>
            <div class="col-md-6 mb-3">
                <h6 class="mb-2" style="font-size:.85rem;font-weight:700;"><?php echo $b['label']; ?></h6>
                <div style="display:flex;align-items:center;gap:4px;margin-bottom:6px;">
                    <div style="font-size:.68rem;color:#9ca3af;width:80px;">Gross Margin</div>
                    <div style="flex:1;height:18px;background:#f3f4f6;border-radius:9px;position:relative;overflow:hidden;">
                        <!-- Benchmark zones -->
                        <div style="position:absolute;top:0;left:0;height:100%;width:<?php echo $b['gross']['needs_work']; ?>%;background:#fecaca;border-radius:9px 0 0 9px;" title="Needs Work: <<?php echo $b['gross']['needs_work']; ?>%"></div>
                        <div style="position:absolute;top:0;left:<?php echo $b['gross']['needs_work']; ?>%;height:100%;width:<?php echo $b['gross']['exceptional'] - $b['gross']['needs_work']; ?>%;background:#fef08a;" title="Solid: <?php echo $b['gross']['solid']; ?>-<?php echo $b['gross']['exceptional']; ?>%"></div>
                        <div style="position:absolute;top:0;left:<?php echo $b['gross']['exceptional']; ?>%;height:100%;width:<?php echo 100 - $b['gross']['exceptional']; ?>%;background:#bbf7d0;border-radius:0 9px 9px 0;" title="Exceptional: ><?php echo $b['gross']['exceptional']; ?>%"></div>
                        <!-- Your position marker -->
                        <?php if ($avgPct !== null): ?>
                        <div style="position:absolute;top:-1px;left:<?php echo min(98, max(1, $avgPct)); ?>%;width:3px;height:20px;background:#111;border-radius:2px;" title="Your avg: <?php echo number_format($avgPct, 1); ?>%"></div>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:.68rem;min-width:32px;text-align:right;">
                        <?php
                        if ($avgPct >= $b['gross']['exceptional']) echo '<span style="color:#059669;font-weight:700;">&#9733;</span>';
                        elseif ($avgPct >= $b['gross']['solid']) echo '<span style="color:#d97706;">&#9679;</span>';
                        else echo '<span style="color:#dc2626;">&#9660;</span>';
                        ?>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:.6rem;color:#9ca3af;padding:0 80px 0 80px;">
                    <span>&lt;<?php echo $b['gross']['needs_work']; ?>%</span>
                    <span><?php echo $b['gross']['solid']; ?>-<?php echo $b['gross']['exceptional']; ?>%</span>
                    <span>&gt;<?php echo $b['gross']['exceptional']; ?>%</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-2 text-center" style="font-size:.75rem;color:#6b7280;">
            <?php if ($avgPct >= 45): ?>
                <i data-feather="award" style="width:14px;height:14px;display:inline;color:#059669;"></i>
                <strong style="color:#059669;">Your <?php echo number_format($avgPct, 1); ?>% margin is in the Exceptional range for maintenance</strong>
            <?php elseif ($avgPct >= 34): ?>
                <i data-feather="check-circle" style="width:14px;height:14px;display:inline;color:#d97706;"></i>
                <strong style="color:#d97706;">Your <?php echo number_format($avgPct, 1); ?>% margin is Solid — room to push toward Exceptional (>45%)</strong>
            <?php elseif ($avgPct >= 0): ?>
                <i data-feather="alert-triangle" style="width:14px;height:14px;display:inline;color:#dc2626;"></i>
                <strong style="color:#dc2626;">Your <?php echo number_format($avgPct, 1); ?>% margin Needs Work — focus on pricing and efficiency</strong>
            <?php else: ?>
                <i data-feather="alert-circle" style="width:14px;height:14px;display:inline;color:#dc2626;"></i>
                <strong style="color:#dc2626;">Negative margin — immediate action needed on pricing and costs</strong>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     INSIGHTS & RECOMMENDATIONS — What's Working vs. What Needs Fixing
     ══════════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($insights)): ?>
<div class="card mb-4">
    <div class="card-header" style="background:var(--mw-forest);color:#fff;">
        <h5 class="card-title mb-0" style="color:#fff;">
            <i data-feather="compass" style="width:15px;height:15px;display:inline;"></i>
            Insights &amp; Recommendations
            <span class="badge badge-light ml-2" style="font-size:.7rem;color:var(--mw-forest);">
                <?php echo count($insightsDanger); ?> critical
                &middot; <?php echo count($insightsWarning); ?> review
                &middot; <?php echo count($insightsOk); ?> healthy
            </span>
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem;">
                <thead style="background:#f9fafb;">
                    <tr>
                        <th style="width:26px;"></th>
                        <th>Job / Client</th>
                        <th>Area</th>
                        <th class="text-right">Margin</th>
                        <th class="text-right">Drive %</th>
                        <th class="text-right">Duration Drift</th>
                        <th style="min-width:240px;">Recommendation</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($insights as $ins):
                    if (empty($ins['recommendations'])) continue;
                    $rowBg = $ins['severity'] === 'danger' ? 'background:#fef2f2;' : ($ins['severity'] === 'warning' ? 'background:#fffbeb;' : '');
                    $sevIcon = $ins['severity'] === 'danger' ? '&#128308;' : ($ins['severity'] === 'warning' ? '&#128992;' : '&#128994;');
                ?>
                <tr style="<?php echo $rowBg; ?>">
                    <td class="text-center"><?php echo $sevIcon; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars(trim($ins['client_name'] ?? '—')); ?></strong>
                        <div style="font-size:.68rem;color:#9ca3af;"><?php echo htmlspecialchars($ins['plan_number'] ?? ''); ?></div>
                    </td>
                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars(($ins['property_address'] ?? '') . ', ' . ($ins['city'] ?? '')); ?>">
                        <?php echo htmlspecialchars($ins['postal_code'] ?? ($ins['city'] ?? '—')); ?>
                    </td>
                    <td class="text-right"><?php echo fmt_pct($ins['avg_margin_pct'] !== null ? (float)$ins['avg_margin_pct'] : null); ?></td>
                    <td class="text-right">
                        <?php if ($ins['drive_ratio'] > 25): ?>
                            <span class="text-danger font-weight-bold"><?php echo $ins['drive_ratio']; ?>%</span>
                        <?php elseif ($ins['drive_ratio'] > 15): ?>
                            <span class="text-warning"><?php echo $ins['drive_ratio']; ?>%</span>
                        <?php else: ?>
                            <?php echo $ins['drive_ratio']; ?>%
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php if ($ins['duration_drift'] > 20): ?>
                            <span class="text-danger">+<?php echo $ins['duration_drift']; ?>%</span>
                        <?php elseif ($ins['duration_drift'] < -10): ?>
                            <span class="text-success"><?php echo $ins['duration_drift']; ?>%</span>
                        <?php elseif ($ins['duration_drift'] != 0): ?>
                            <?php echo ($ins['duration_drift'] > 0 ? '+' : '') . $ins['duration_drift']; ?>%
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php foreach ($ins['recommendations'] as $rec): ?>
                        <div style="display:flex;align-items:flex-start;gap:4px;margin-bottom:3px;">
                            <i data-feather="<?php echo $rec['icon']; ?>" style="width:13px;height:13px;flex-shrink:0;margin-top:1px;color:<?php echo $rec['color']; ?>;"></i>
                            <span style="font-size:.78rem;"><?php echo htmlspecialchars($rec['text']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <a href="/crm/jobs/view.php?id=<?php echo (int)$ins['plan_id']; ?>" class="btn btn-sm btn-outline-secondary" title="View job">
                            <i data-feather="external-link" style="width:12px;height:12px;"></i>
                        </a>
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

<?php endif; // classic view ?>

<?php if ($view === 'spectrum'): ?>
<div class="mw-spectrum-wrap">
  <div class="mw-spectrum-inner">

    <!-- Spectrum header -->
    <div class="d-flex align-items-center justify-content-between mb-4" style="flex-wrap:wrap;gap:16px;">
      <div class="d-flex align-items-center" style="gap:12px;">
        <span class="mw-spectrum-dot"></span>
        <span class="mw-spectrum-title">Profit Spectrum</span>
      </div>
      <div class="mw-spectrum-stats">
        <div class="mw-spectrum-stat">
          <div class="mw-spectrum-stat-val">$<?php echo number_format($ytdSpec['revenue'], 0); ?></div>
          <div class="mw-spectrum-stat-lbl">YTD Revenue</div>
        </div>
        <div class="mw-spectrum-stat">
          <div class="mw-spectrum-stat-val <?php echo $ytdSpec['net'] < 0 ? 'text-danger' : ''; ?>">
            <?php echo ($ytdSpec['net'] < 0 ? '-' : '') . '$' . number_format(abs($ytdSpec['net']), 0); ?>
          </div>
          <div class="mw-spectrum-stat-lbl">Net Margin</div>
        </div>
        <div class="mw-spectrum-stat">
          <div class="mw-spectrum-stat-val <?php echo $ytdSpec['margin'] < 20 ? 'text-warning' : ''; ?>">
            <?php echo $ytdSpec['margin']; ?>%
          </div>
          <div class="mw-spectrum-stat-lbl">Margin Rate</div>
        </div>
        <div class="mw-spectrum-stat">
          <div class="mw-spectrum-stat-val">$<?php echo number_format($ytdSpec['costs'], 0); ?></div>
          <div class="mw-spectrum-stat-lbl">YTD Costs</div>
        </div>
      </div>
    </div>

    <!-- Visualization grid -->
    <div class="mw-spectrum-grid">

      <!-- C1: 52-week spectrum (full width) -->
      <div class="mw-spectrum-card full-width" id="sc1">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.1em;color:#4ade80;font-weight:700;margin-bottom:2px;">Profit Spectrum</div>
            <div style="font-size:.78rem;color:#6b7280;">52-week weekly profit / loss</div>
          </div>
          <span class="mw-spectrum-badge green">52W</span>
        </div>
        <div class="mw-spectrum-canvas-wrap"><canvas id="spec-c1" height="200"></canvas></div>
      </div>

      <!-- C2: Monthly pulse -->
      <div class="mw-spectrum-card" id="sc2">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.1em;color:#4ade80;font-weight:700;margin-bottom:2px;">Profit Pulse</div>
            <div style="font-size:.78rem;color:#6b7280;">12-month margin signal</div>
          </div>
          <span class="mw-spectrum-badge green">12M</span>
        </div>
        <div class="mw-spectrum-canvas-wrap"><canvas id="spec-c2" height="200"></canvas></div>
      </div>

      <!-- C3: Service radar -->
      <div class="mw-spectrum-card" id="sc3">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.1em;color:#4ade80;font-weight:700;margin-bottom:2px;">Service Radar</div>
            <div style="font-size:.78rem;color:#6b7280;">margin efficiency by service</div>
          </div>
          <span class="mw-spectrum-badge green">SVC</span>
        </div>
        <div class="mw-spectrum-canvas-wrap"><canvas id="spec-c3" height="200"></canvas></div>
      </div>

      <!-- C4: Crew matrix -->
      <div class="mw-spectrum-card" id="sc4">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.1em;color:#4ade80;font-weight:700;margin-bottom:2px;">Crew Matrix</div>
            <div style="font-size:.78rem;color:#6b7280;">crew margin contribution by month</div>
          </div>
          <span class="mw-spectrum-badge green">CRW</span>
        </div>
        <div class="mw-spectrum-canvas-wrap"><canvas id="spec-c4" height="200"></canvas></div>
      </div>

      <!-- C5: Cost waterfall -->
      <div class="mw-spectrum-card" id="sc5">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.1em;color:#4ade80;font-weight:700;margin-bottom:2px;">Cost Cascade</div>
            <div style="font-size:.78rem;color:#6b7280;">YTD revenue → net waterfall</div>
          </div>
          <span class="mw-spectrum-badge green">YTD</span>
        </div>
        <div class="mw-spectrum-canvas-wrap"><canvas id="spec-c5" height="200"></canvas></div>
      </div>

    </div><!-- /.mw-spectrum-grid -->
  </div><!-- /.mw-spectrum-inner -->
</div><!-- /.mw-spectrum-wrap -->

<script>window.SPECTRUM_DATA = <?php echo json_encode($spectrumData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="/crm/js/profit-spectrum.js?v=2"></script>
<?php endif; // spectrum view ?>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
