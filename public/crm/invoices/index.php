<?php
/**
 * Invoices Management - List View
 * Supports single-row "Mark Paid" and multi-row bulk payment via checkbox selection.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.view');

// Handle filters
$statusFilter = $_GET['status'] ?? '';
$searchQuery  = trim($_GET['search'] ?? '');

// ── Pagination + Sorting params ──────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
$sortCol  = $_GET['sort'] ?? 'created_at';
$sortDir  = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowedSorts = [
    'invoice_number' => 'i.invoice_number',
    'client'         => 'display_client',
    'amount'         => 'i.total',
    'balance'        => 'i.balance_due',
    'due_date'       => 'i.due_date',
    'status'         => 'i.status',
    'created_at'     => 'i.created_at',
];
$orderBy = $allowedSorts[$sortCol] ?? 'i.created_at';

// Build query
$db     = getDB();
$params = [];
$whereConditions = ['1=1'];

if ($statusFilter) {
    $whereConditions[] = 'i.status = ?';
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $whereConditions[] = '(i.invoice_number LIKE ? OR c.company_name LIKE ? OR CONCAT(ct.first_name," ",ct.last_name) LIKE ?)';
    $searchParam = "%{$searchQuery}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

// Count total matching rows
$countParams = $params;
$cntStmt = $db->prepare("
    SELECT COUNT(*) FROM invoices i
    LEFT JOIN companies c ON i.company_id = c.id
    LEFT JOIN contacts ct ON i.contact_id = ct.id
    WHERE {$whereClause}
");
$cntStmt->execute($countParams);
$filteredTotal = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("
    SELECT
        i.*,
        COALESCE(c.company_name, CONCAT(ct.first_name,' ',ct.last_name)) as display_client,
        c.company_name,
        ct.first_name as contact_first,
        ct.last_name  as contact_last,
        jp.plan_number,
        jp.title as plan_title
    FROM invoices i
    LEFT JOIN companies  c  ON i.company_id = c.id
    LEFT JOIN contacts   ct ON i.contact_id = ct.id
    LEFT JOIN job_plans  jp ON i.plan_id    = jp.id
    LEFT JOIN job_visits jv ON i.visit_id   = jv.id
    WHERE {$whereClause}
    ORDER BY {$orderBy} {$sortDir}
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts
$countStmt = $db->query("
    SELECT status, COUNT(*) as count, SUM(balance_due) as total_due
    FROM invoices
    GROUP BY status
");
$statusCounts   = [];
$totalOutstanding = 0;
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
    if (in_array($row['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
        $totalOutstanding += floatval($row['total_due']);
    }
}
$totalCount = array_sum($statusCounts);

// ── Invoice Insight Queries ──────────────────────────────────────────────

// Aging buckets (outstanding balance by age)
$aging = $db->query("
    SELECT
      COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled','draft') AND due_date >= CURDATE() THEN balance_due ELSE 0 END), 0) as on_time,
      COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled') AND due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) <= 30 THEN balance_due ELSE 0 END), 0) as late_30,
      COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled') AND due_date < CURDATE() AND DATEDIFF(CURDATE(), due_date) > 30 THEN balance_due ELSE 0 END), 0) as late_31
    FROM invoices
")->fetch(PDO::FETCH_ASSOC);

$agingOnTime = (float)$aging['on_time'];
$agingLate30 = (float)$aging['late_30'];
$agingLate31 = (float)$aging['late_31'];
$agingTotal  = $agingOnTime + $agingLate30 + $agingLate31;
$w1 = $agingTotal > 0 ? round(($agingOnTime / $agingTotal) * 100) : 100;
$w2 = $agingTotal > 0 ? round(($agingLate30 / $agingTotal) * 100) : 0;
$w3 = $agingTotal > 0 ? max(0, 100 - $w1 - $w2) : 0;

// Payment velocity: avg days from sent → paid
$velocity = $db->query("
    SELECT
      ROUND(AVG(DATEDIFF(paid_at, sent_at))) as avg_overall,
      ROUND(AVG(CASE WHEN paid_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN DATEDIFF(paid_at, sent_at) END)) as avg_this_month,
      ROUND(AVG(CASE WHEN paid_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m-01')
                      AND paid_at <  DATE_FORMAT(CURDATE(),'%Y-%m-01')
                     THEN DATEDIFF(paid_at, sent_at) END)) as avg_last_month,
      COUNT(*) as paid_count
    FROM invoices
    WHERE status = 'paid' AND paid_at IS NOT NULL AND sent_at IS NOT NULL
      AND DATEDIFF(paid_at, sent_at) BETWEEN 0 AND 365
")->fetch(PDO::FETCH_ASSOC);

$avgDays            = ($velocity['avg_overall'] !== null) ? (int)$velocity['avg_overall'] : null;
$avgThisMonth       = ($velocity['avg_this_month'] !== null) ? (int)$velocity['avg_this_month'] : null;
$avgLastMonth       = ($velocity['avg_last_month'] !== null) ? (int)$velocity['avg_last_month'] : null;
$velocityDelta      = ($avgThisMonth !== null && $avgLastMonth !== null) ? ($avgLastMonth - $avgThisMonth) : null; // positive = faster = good
$hasPaidHistory     = (int)($velocity['paid_count'] ?? 0) >= 3;

// Invoices sent but customer never viewed the portal
$unopenedCount = (int)$db->query("
    SELECT COUNT(*) FROM invoices
    WHERE status IN ('sent','overdue')
      AND viewed_at IS NULL AND sent_at IS NOT NULL
")->fetchColumn();

// Revenue: this month vs last month
$revenue = $db->query("
    SELECT
      COALESCE(SUM(CASE WHEN DATE_FORMAT(issue_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m') THEN total ELSE 0 END), 0) as this_month,
      COALESCE(SUM(CASE WHEN DATE_FORMAT(issue_date,'%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m') THEN total ELSE 0 END), 0) as last_month
    FROM invoices
    WHERE status NOT IN ('draft','cancelled')
      AND issue_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m-01')
")->fetch(PDO::FETCH_ASSOC);

$thisMonthRev  = (float)$revenue['this_month'];
$lastMonthRev  = (float)$revenue['last_month'];
$revTrendPct   = $lastMonthRev > 0 ? round((($thisMonthRev - $lastMonthRev) / $lastMonthRev) * 100) : null;
$lastMonthName = date('M', strtotime('first day of last month'));

// 6-month sparkline data
$sparkRows = $db->query("
    SELECT DATE_FORMAT(issue_date,'%Y-%m') as mk,
           DATE_FORMAT(issue_date,'%b') as label,
           COALESCE(SUM(total),0) as total
    FROM invoices
    WHERE status NOT IN ('draft','cancelled')
      AND issue_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
    GROUP BY DATE_FORMAT(issue_date,'%Y-%m'), DATE_FORMAT(issue_date,'%b')
    ORDER BY mk ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$sparkMax = 1;
foreach ($sparkRows as $r) $sparkMax = max($sparkMax, (float)$r['total']);
$currentMonthKey = date('Y-m');

// Cash forecast: bucket outstanding invoices by expected arrival
$avgDaysForecast = $avgDays ?? 30;
$outstandingForForecast = $db->query("
    SELECT balance_due, DATEDIFF(CURDATE(), sent_at) as days_sent
    FROM invoices
    WHERE status IN ('sent','viewed','partial','overdue')
      AND sent_at IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

$forecastWeek  = 0.0;
$forecastMonth = 0.0;
foreach ($outstandingForForecast as $oi) {
    $remaining = max(0, $avgDaysForecast - (int)$oi['days_sent']);
    if ($remaining <= 7)      $forecastWeek  += (float)$oi['balance_due'];
    elseif ($remaining <= 30) $forecastMonth += (float)$oi['balance_due'];
}
$forecastMonth += $forecastWeek;
$hasForecastData = $avgDays !== null && count($outstandingForForecast) > 0;

// Needs attention: sent 14+ days ago, not paid
$attention = $db->query("
    SELECT COUNT(*) as cnt, COALESCE(SUM(balance_due),0) as total
    FROM invoices
    WHERE status IN ('sent','viewed','overdue')
      AND sent_at IS NOT NULL
      AND DATEDIFF(CURDATE(), sent_at) >= 14
")->fetch(PDO::FETCH_ASSOC);

// Sales mix: recurring (plan_id IS NOT NULL) vs one-off, YTD vs same period last year
$mixRows = $db->query("
    SELECT
      YEAR(issue_date) as yr,
      COALESCE(SUM(CASE WHEN plan_id IS NOT NULL THEN total ELSE 0 END), 0) as recurring_total,
      COALESCE(SUM(CASE WHEN plan_id IS NULL     THEN total ELSE 0 END), 0) as onetime_total,
      COALESCE(SUM(total), 0) as grand_total
    FROM invoices
    WHERE status NOT IN ('draft','cancelled')
      AND (
        (YEAR(issue_date) = YEAR(CURDATE())
         AND issue_date <= CURDATE())
        OR
        (YEAR(issue_date) = YEAR(CURDATE()) - 1
         AND DATE_FORMAT(issue_date,'%m-%d') <= DATE_FORMAT(CURDATE(),'%m-%d'))
      )
    GROUP BY YEAR(issue_date)
    ORDER BY yr ASC
")->fetchAll(PDO::FETCH_ASSOC);

$mixByYear = [];
foreach ($mixRows as $r) $mixByYear[(int)$r['yr']] = $r;
$thisYear     = (int)date('Y');
$lastYear     = $thisYear - 1;
$mixThis      = $mixByYear[$thisYear] ?? null;
$mixLast      = $mixByYear[$lastYear] ?? null;
$recurPctThis = ($mixThis && $mixThis['grand_total'] > 0) ? (int)round(($mixThis['recurring_total'] / $mixThis['grand_total']) * 100) : null;
$recurPctLast = ($mixLast && $mixLast['grand_total'] > 0) ? (int)round(($mixLast['recurring_total'] / $mixLast['grand_total']) * 100) : null;
$mixShift     = ($recurPctThis !== null && $recurPctLast !== null) ? ($recurPctThis - $recurPctLast) : null;
$showMixCard  = ($mixThis && $mixThis['grand_total'] > 0) || ($mixLast && $mixLast['grand_total'] > 0);

// Helper functions for sort/pagination URLs
function invSortUrl($col, $curSort, $curDir) {
    $p = $_GET; $p['sort'] = $col;
    $p['dir'] = ($curSort === $col && $curDir === 'ASC') ? 'DESC' : 'ASC';
    unset($p['page']); return '?' . http_build_query($p);
}
function invSortClass($col, $curSort, $curDir) {
    if ($curSort !== $col) return 'mw-sortable';
    return 'mw-sortable mw-sort-' . strtolower($curDir);
}
function invPageUrl($pageNum) {
    $p = $_GET; $p['page'] = $pageNum; return '?' . http_build_query($p);
}

$csrfToken = generateCSRFToken();

$pageTitle  = 'Invoices';
$activePage = 'invoices';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Invoices</h1>
                    <p class="text-muted mb-0">Track payments and manage billing</p>
                </div>
                <div class="d-flex" style="gap: 12px;">
                    <a href="/crm/api/export-invoices.php" class="btn btn-outline-secondary btn-sm" style="align-self:center;"><i data-feather="download" class="mr-1"></i> Export CSV</a>
                    <a href="create.php" class="btn btn-primary">
                        <i data-feather="plus" style="width:16px;height:16px;margin-right:4px;vertical-align:middle;"></i>
                        Create Invoice
                    </a>
                </div>
            </div>

            <!-- Invoice Insight Cards -->
            <div class="mw-inv-insights">

                <!-- Card 1: Aging Pipeline -->
                <div class="mw-inv-card">
                    <div class="mw-inv-card-label">Outstanding</div>
                    <div class="mw-inv-card-value"><?php echo formatCurrency($totalOutstanding); ?></div>
                    <?php if ($agingTotal > 0): ?>
                    <div class="mw-inv-aging-bar" title="<?php echo htmlspecialchars(formatCurrency($agingOnTime) . ' on time · ' . formatCurrency($agingLate30) . ' 1–30d · ' . formatCurrency($agingLate31) . ' 31d+'); ?>">
                        <?php if ($w1 > 0): ?><div class="mw-inv-aging-seg-blue"  style="width:<?php echo $w1; ?>%"></div><?php endif; ?>
                        <?php if ($w2 > 0): ?><div class="mw-inv-aging-seg-amber" style="width:<?php echo $w2; ?>%"></div><?php endif; ?>
                        <?php if ($w3 > 0): ?><div class="mw-inv-aging-seg-red"   style="width:<?php echo $w3; ?>%"></div><?php endif; ?>
                    </div>
                    <div class="mw-inv-aging-labels">
                        <?php if ($agingOnTime > 0): ?><span class="blue"><?php echo htmlspecialchars(formatCurrency($agingOnTime)); ?> on time</span><?php endif; ?>
                        <?php if ($agingLate30 > 0): ?><span class="amber"><?php echo htmlspecialchars(formatCurrency($agingLate30)); ?> 1–30d</span><?php endif; ?>
                        <?php if ($agingLate31 > 0): ?><span class="red"><?php echo htmlspecialchars(formatCurrency($agingLate31)); ?> 31d+</span><?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="mw-inv-card-sub">No outstanding invoices</div>
                    <?php endif; ?>
                </div>

                <!-- Card 2: Cash Forecast -->
                <div class="mw-inv-card">
                    <div class="mw-inv-card-label">Expected This Week</div>
                    <?php if ($hasForecastData): ?>
                    <div class="mw-inv-card-value">~<?php echo formatCurrency($forecastWeek); ?></div>
                    <div class="mw-inv-card-sub">
                        ~<?php echo formatCurrency($forecastMonth); ?> in 30 days
                        &middot; your avg cycle is <?php echo $avgDaysForecast; ?> days
                    </div>
                    <?php else: ?>
                    <div class="mw-inv-card-value mw-inv-card-value--muted">—</div>
                    <div class="mw-inv-card-sub">Record payments to build your forecast</div>
                    <?php endif; ?>
                </div>

                <!-- Card 3: Payment Speed -->
                <div class="mw-inv-card">
                    <div class="mw-inv-card-label">Avg Days to Pay</div>
                    <?php if ($hasPaidHistory && $avgDays !== null): ?>
                    <div class="mw-inv-card-value"><?php echo $avgDays; ?> <span class="mw-inv-card-unit">days</span></div>
                    <?php if ($velocityDelta !== null): ?>
                    <div class="mw-inv-card-trend <?php echo $velocityDelta > 0 ? 'up' : ($velocityDelta < 0 ? 'down' : 'neutral'); ?>">
                        <?php if ($velocityDelta > 0): ?>
                            ↓ <?php echo abs($velocityDelta); ?>d faster than last month
                        <?php elseif ($velocityDelta < 0): ?>
                            ↑ <?php echo abs($velocityDelta); ?>d slower than last month
                        <?php else: ?>
                            Same pace as last month
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($unopenedCount > 0): ?>
                    <div class="mw-inv-card-sub"><?php echo $unopenedCount; ?> sent but never opened</div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="mw-inv-card-value mw-inv-card-value--muted">—</div>
                    <div class="mw-inv-card-sub">Not enough data yet</div>
                    <?php endif; ?>
                </div>

                <!-- Card 4: Revenue Pulse -->
                <div class="mw-inv-card">
                    <div class="mw-inv-card-label">Invoiced This Month</div>
                    <div class="mw-inv-card-value"><?php echo formatCurrency($thisMonthRev); ?></div>
                    <?php if ($revTrendPct !== null): ?>
                    <div class="mw-inv-card-trend <?php echo $revTrendPct > 0 ? 'up' : ($revTrendPct < 0 ? 'down' : 'neutral'); ?>">
                        <?php if ($revTrendPct > 0): ?>↑ +<?php echo $revTrendPct; ?>%<?php elseif ($revTrendPct < 0): ?>↓ <?php echo $revTrendPct; ?>%<?php else: ?>—<?php endif; ?> vs <?php echo $lastMonthName; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($sparkRows)): ?>
                    <div class="mw-inv-sparkline">
                        <?php foreach ($sparkRows as $sr):
                            $barH = $sparkMax > 0 ? max(4, (int)round(((float)$sr['total'] / $sparkMax) * 36)) : 4;
                        ?>
                        <div class="mw-inv-spark-wrap" title="<?php echo htmlspecialchars($sr['label'] . ': ' . formatCurrency($sr['total'])); ?>">
                            <div class="mw-inv-spark-bar <?php echo $sr['mk'] === $currentMonthKey ? 'current' : ''; ?>" style="height:<?php echo $barH; ?>px"></div>
                            <div class="mw-inv-spark-label"><?php echo htmlspecialchars(substr($sr['label'], 0, 1)); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>

            <?php if ((int)$attention['cnt'] > 0): ?>
            <div class="mw-inv-attention-banner">
                <i data-feather="alert-triangle" class="mw-inv-attn-icon"></i>
                <div class="mw-inv-attn-text">
                    <strong><?php echo (int)$attention['cnt']; ?> invoice<?php echo (int)$attention['cnt'] !== 1 ? 's' : ''; ?></strong>
                    sent 14+ days ago <?php echo (int)$attention['cnt'] !== 1 ? 'are' : 'is'; ?> unpaid —
                    <?php echo htmlspecialchars(formatCurrency($attention['total'])); ?> at risk
                </div>
                <a href="?" class="mw-inv-attn-link">View all <i data-feather="arrow-right" style="width:14px;height:14px;vertical-align:middle;"></i></a>
            </div>
            <?php endif; ?>

            <?php if ($showMixCard): ?>
            <div class="mw-inv-mix-card">
                <div class="mw-inv-mix-header">
                    <div>
                        <div class="mw-inv-card-label">Sales Mix — Recurring vs One-off</div>
                        <div class="mw-inv-mix-subtitle">YTD comparison (Jan 1 → today vs same period last year)</div>
                    </div>
                    <?php if ($mixShift !== null && abs($mixShift) > 10): ?>
                    <div class="mw-inv-mix-shift <?php echo $mixShift < 0 ? 'warning' : 'positive'; ?>">
                        <?php if ($mixShift < 0): ?>
                            Recurring share down <?php echo abs($mixShift); ?> pts vs last year — worth reviewing client retention
                        <?php else: ?>
                            Recurring share up <?php echo abs($mixShift); ?> pts vs last year — strong loyalty
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mw-inv-mix-body">
                    <?php
                    $mixYearsToShow = [];
                    if ($mixLast && $mixLast['grand_total'] > 0) $mixYearsToShow[] = ['year' => $lastYear, 'data' => $mixLast, 'rpct' => $recurPctLast ?? 0];
                    if ($mixThis && $mixThis['grand_total'] > 0)  $mixYearsToShow[] = ['year' => $thisYear, 'data' => $mixThis, 'rpct' => $recurPctThis ?? 0];
                    foreach ($mixYearsToShow as $mx):
                        $rPct = $mx['rpct'];
                        $oPct = 100 - $rPct;
                    ?>
                    <div class="mw-inv-mix-col">
                        <div class="mw-inv-mix-bar">
                            <div class="mw-inv-mix-seg-recurring" style="height:<?php echo $rPct; ?>%" title="Recurring: <?php echo htmlspecialchars(formatCurrency($mx['data']['recurring_total'])); ?>">
                                <?php if ($rPct >= 15): ?><span><?php echo $rPct; ?>%</span><?php endif; ?>
                            </div>
                            <div class="mw-inv-mix-seg-onetime" style="height:<?php echo $oPct; ?>%" title="One-off: <?php echo htmlspecialchars(formatCurrency($mx['data']['onetime_total'])); ?>">
                                <?php if ($oPct >= 15): ?><span><?php echo $oPct; ?>%</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="mw-inv-mix-year-label"><?php echo $mx['year']; ?></div>
                        <div class="mw-inv-mix-total"><?php echo htmlspecialchars(formatCurrency($mx['data']['grand_total'])); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <div class="mw-inv-mix-legend">
                        <span class="mw-inv-mix-legend-item recurring"><span class="dot"></span> Recurring plans</span>
                        <span class="mw-inv-mix-legend-item onetime"><span class="dot"></span> One-off jobs</span>
                    </div>
                </div>
                <?php if (count($mixYearsToShow) === 1 && $mixYearsToShow[0]['year'] === $thisYear): ?>
                <div class="mw-inv-mix-firstyear">First year — mix tracking begins. Check back next year for YoY comparison.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Filters + search -->
            <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 16px;">
                <div class="mw-filter-tabs">
                    <a href="?status=" class="mw-filter-tab <?php echo !$statusFilter ? 'active' : ''; ?>">
                        All <span class="count"><?php echo $totalCount; ?></span>
                    </a>
                    <a href="?status=draft" class="mw-filter-tab <?php echo $statusFilter === 'draft' ? 'active' : ''; ?>">
                        Draft <span class="count"><?php echo $statusCounts['draft'] ?? 0; ?></span>
                    </a>
                    <a href="?status=sent" class="mw-filter-tab <?php echo $statusFilter === 'sent' ? 'active' : ''; ?>">
                        Sent <span class="count"><?php echo $statusCounts['sent'] ?? 0; ?></span>
                    </a>
                    <a href="?status=paid" class="mw-filter-tab <?php echo $statusFilter === 'paid' ? 'active' : ''; ?>">
                        Paid <span class="count"><?php echo $statusCounts['paid'] ?? 0; ?></span>
                    </a>
                    <a href="?status=overdue" class="mw-filter-tab <?php echo $statusFilter === 'overdue' ? 'active' : ''; ?>">
                        Overdue <span class="count"><?php echo $statusCounts['overdue'] ?? 0; ?></span>
                    </a>
                </div>

                <form class="mw-search-box" method="GET">
                    <?php if ($statusFilter): ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                    <?php endif; ?>
                    <input type="text" name="search" class="mw-search-input"
                           placeholder="Search invoices…"
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </form>
            </div>

            <!-- Bulk action bar (hidden until ≥1 checkbox ticked) -->
            <div id="mw-bulk-bar" class="mw-bulk-bar" style="display:none;" aria-live="polite">
                <div class="mw-bulk-bar-left">
                    <span id="mw-bulk-count" class="mw-bulk-count">0 selected</span>
                    <span class="mw-bulk-total-label">Total: <strong id="mw-bulk-total">$0.00</strong></span>
                </div>
                <div class="mw-bulk-bar-right">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="mwClearSelection()">
                        Clear
                    </button>
                    <button type="button" class="btn btn-sm btn-success" onclick="mwOpenBulkPayment()">
                        <i data-feather="check-circle" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
                        Record Payment
                    </button>
                </div>
            </div>

            <!-- Invoice table -->
            <div class="mw-table-card">
                <?php if (empty($invoices)): ?>
                    <div class="mw-empty-state">
                        <span class="mw-empty-state-icon">📄</span>
                        <p>No invoices found. Complete a job to create your first invoice!</p>
                    </div>
                <?php else: ?>
                    <table class="mw-table" id="mw-invoice-table">
                        <thead>
                            <tr>
                                <th class="mw-col-check">
                                    <input type="checkbox" id="mw-check-all" class="mw-checkbox"
                                           title="Select all payable invoices" onchange="mwToggleAll(this)">
                                </th>
                                <th class="<?php echo invSortClass('invoice_number', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('invoice_number', $sortCol, $sortDir); ?>">Invoice #</a></th>
                                <th class="<?php echo invSortClass('client', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('client', $sortCol, $sortDir); ?>">Client</a></th>
                                <th>Plan</th>
                                <th class="text-right <?php echo invSortClass('amount', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('amount', $sortCol, $sortDir); ?>">Amount</a></th>
                                <th class="text-right <?php echo invSortClass('balance', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('balance', $sortCol, $sortDir); ?>">Balance</a></th>
                                <th class="<?php echo invSortClass('due_date', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('due_date', $sortCol, $sortDir); ?>">Due Date</a></th>
                                <th class="<?php echo invSortClass('status', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('status', $sortCol, $sortDir); ?>">Status</a></th>
                                <th>Tracking</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <?php
                                $isPayable = in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue']);
                                $balance   = floatval($invoice['balance_due']);
                                $client    = htmlspecialchars($invoice['display_client'] ?? $invoice['company_name'] ?? 'N/A');
                                ?>
                                <tr class="<?php echo $isPayable ? 'mw-payable-row' : ''; ?>"
                                    data-href="view.php?id=<?php echo (int)$invoice['id']; ?>"
                                    data-invoice-id="<?php echo $invoice['id']; ?>"
                                    data-balance="<?php echo number_format($balance, 2, '.', ''); ?>"
                                    data-invoice-number="<?php echo htmlspecialchars($invoice['invoice_number']); ?>"
                                    data-client="<?php echo $client; ?>">
                                    <td class="mw-col-check">
                                        <?php if ($isPayable): ?>
                                            <input type="checkbox" class="mw-checkbox mw-row-check"
                                                   value="<?php echo $invoice['id']; ?>"
                                                   data-balance="<?php echo number_format($balance, 2, '.', ''); ?>"
                                                   onchange="mwUpdateBulkBar()">
                                        <?php else: ?>
                                            <span class="mw-check-placeholder"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="view.php?id=<?php echo $invoice['id']; ?>" class="invoice-number">
                                            <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo $client; ?></td>
                                    <td>
                                        <?php if (!empty($invoice['plan_number'])): ?>
                                            <a href="../jobs/view.php?id=<?php echo $invoice['plan_id']; ?>">
                                                <?php echo htmlspecialchars($invoice['plan_number']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right mw-amount"><?php echo formatCurrency($invoice['total']); ?></td>
                                    <td class="text-right mw-amount <?php echo $isPayable && $balance > 0 ? 'mw-balance-due' : ''; ?>">
                                        <?php echo formatCurrency($balance); ?>
                                    </td>
                                    <td><?php echo formatDate($invoice['due_date']); ?></td>
                                    <td><?php echo getStatusBadge($invoice['status'], 'invoice'); ?></td>
                                    <td class="mw-tracking-cell">
                                        <?php if ($invoice['status'] !== 'draft'): ?>
                                            <?php
                                            $viewCount   = (int)($invoice['view_count'] ?? 0);
                                            $emailOpened = !empty($invoice['email_opened_at']);
                                            $hasViewed   = !empty($invoice['viewed_at']);
                                            ?>
                                            <?php if ($emailOpened): ?>
                                                <span class="mw-tracking-badge mw-tracking-opened" title="Email opened <?php echo formatDateTime($invoice['email_opened_at'], 'M j, g:i A'); ?>">Opened</span>
                                            <?php endif; ?>
                                            <?php if ($viewCount > 0): ?>
                                                <span class="mw-tracking-badge mw-tracking-viewed" title="<?php echo $viewCount; ?> portal view(s), last: <?php echo formatDateTime($invoice['last_viewed_at'] ?? $invoice['viewed_at'], 'M j, g:i A'); ?>"><?php echo $viewCount; ?> view<?php echo $viewCount !== 1 ? 's' : ''; ?></span>
                                            <?php endif; ?>
                                            <?php if (!$emailOpened && !$hasViewed && !empty($invoice['sent_at'])): ?>
                                                <span class="text-muted" style="font-size:12px;">No engagement</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions">
                                        <a href="view.php?id=<?php echo $invoice['id']; ?>" class="mw-action-btn mw-action-btn-view">View</a>
                                        <?php if ($isPayable): ?>
                                            <button type="button" class="mw-action-btn mw-action-btn-paid"
                                                    onclick="mwOpenSinglePayment(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>', <?php echo number_format($balance, 2, '.', ''); ?>, <?php echo json_encode($client); ?>)">
                                                Pay
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($totalPages > 1): ?>
                    <div class="mw-pagination">
                        <div class="mw-pagination-info">
                            <span>Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $filteredTotal); ?> of <?php echo $filteredTotal; ?> invoices</span>
                            <div class="mw-pagination-per-page">
                                <span>Show</span>
                                <select onchange="window.location.href=this.value;">
                                    <?php foreach ([10, 25, 50, 100] as $pp): ?>
                                    <option value="<?php $p = $_GET; $p['per_page'] = $pp; $p['page'] = 1; echo '?' . http_build_query($p); ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span>per page</span>
                            </div>
                        </div>
                        <div class="mw-pagination-pages">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo invPageUrl($page - 1); ?>">&laquo;</a>
                            <?php else: ?>
                                <span class="disabled">&laquo;</span>
                            <?php endif; ?>
                            <?php
                            $startP = max(1, $page - 2);
                            $endP = min($totalPages, $page + 2);
                            if ($startP > 1) echo '<a href="' . invPageUrl(1) . '">1</a>';
                            if ($startP > 2) echo '<span class="disabled">&hellip;</span>';
                            for ($i = $startP; $i <= $endP; $i++):
                            ?>
                                <?php if ($i === $page): ?>
                                    <span class="active"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo invPageUrl($i); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor;
                            if ($endP < $totalPages - 1) echo '<span class="disabled">&hellip;</span>';
                            if ($endP < $totalPages) echo '<a href="' . invPageUrl($totalPages) . '">' . $totalPages . '</a>';
                            ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo invPageUrl($page + 1); ?>">&raquo;</a>
                            <?php else: ?>
                                <span class="disabled">&raquo;</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- Bulk / Single Payment Modal                                              -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div id="mw-payment-modal" class="mw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="mw-payment-modal-title">
    <div class="mw-modal mw-payment-modal-content">
        <div class="mw-modal-header">
            <h5 class="mb-0" id="mw-payment-modal-title">
                <i data-feather="check-circle" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"></i>
                <span id="mw-modal-title-text">Record Payment</span>
            </h5>
            <button type="button" class="mw-modal-close" onclick="mwClosePaymentModal()" aria-label="Close">&times;</button>
        </div>

        <div class="mw-modal-body">

            <!-- Invoice summary list (populated by JS) -->
            <div id="mw-payment-invoice-list" class="mw-payment-invoice-list"></div>

            <!-- Grand total row -->
            <div class="mw-payment-total-row">
                <span>Total Payment</span>
                <strong id="mw-payment-grand-total">$0.00</strong>
            </div>

            <!-- Payment details -->
            <div class="mw-form-row" style="margin-top:16px;">
                <div class="mw-form-group">
                    <label class="form-label" for="mw-pay-method">Payment Method</label>
                    <select id="mw-pay-method" class="form-control" onchange="mwToggleRefLabel()">
                        <option value="e_transfer">e-Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="cheque">Cheque</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="mw-form-group">
                    <label class="form-label" for="mw-pay-date">Payment Date</label>
                    <input type="date" id="mw-pay-date" class="form-control"
                           value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <div class="mw-form-group">
                <label class="form-label" id="mw-ref-label" for="mw-pay-ref">Confirmation / Reference #</label>
                <input type="text" id="mw-pay-ref" class="form-control"
                       placeholder="e.g., e-Transfer confirmation number">
            </div>

            <div class="mw-form-group">
                <label class="form-label" for="mw-pay-notes">Internal Notes <span class="text-muted">(optional)</span></label>
                <input type="text" id="mw-pay-notes" class="form-control"
                       placeholder="e.g., paid at front door">
            </div>

        </div>

        <div class="mw-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="mwClosePaymentModal()">Cancel</button>
            <button type="button" class="btn btn-success" id="mw-pay-submit" onclick="mwSubmitPayment()">
                <span id="mw-pay-submit-label">Record Payment</span>
                <span id="mw-pay-submit-spinner" style="display:none;">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    Saving…
                </span>
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- Toast notification                                                        -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div id="mw-toast" class="mw-toast" role="alert" aria-live="assertive" style="display:none;"></div>

<script>
(function () {
    'use strict';

    var CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

    // Map of selected invoice IDs → {balance, number, client}
    var selected = {};

    // ── Checkbox helpers ───────────────────────────────────────────────────────
    window.mwToggleAll = function (masterCb) {
        var checks = document.querySelectorAll('.mw-row-check');
        checks.forEach(function (cb) {
            cb.checked = masterCb.checked;
        });
        mwUpdateBulkBar();
    };

    window.mwUpdateBulkBar = function () {
        selected = {};
        document.querySelectorAll('.mw-row-check:checked').forEach(function (cb) {
            var id  = parseInt(cb.value, 10);
            var row = cb.closest('tr');
            selected[id] = {
                balance : parseFloat(cb.dataset.balance),
                number  : row.dataset.invoiceNumber,
                client  : row.dataset.client
            };
        });

        var count = Object.keys(selected).length;
        var total = Object.values(selected).reduce(function (s, v) { return s + v.balance; }, 0);

        var bar   = document.getElementById('mw-bulk-bar');
        var countEl = document.getElementById('mw-bulk-count');
        var totalEl = document.getElementById('mw-bulk-total');

        bar.style.display   = count > 0 ? 'flex' : 'none';
        countEl.textContent = count + (count === 1 ? ' invoice selected' : ' invoices selected');
        totalEl.textContent = formatMoney(total);
    };

    window.mwClearSelection = function () {
        document.querySelectorAll('.mw-row-check').forEach(function (cb) { cb.checked = false; });
        var master = document.getElementById('mw-check-all');
        if (master) master.checked = false;
        mwUpdateBulkBar();
    };

    // ── Open modal (bulk) ──────────────────────────────────────────────────────
    window.mwOpenBulkPayment = function () {
        if (Object.keys(selected).length === 0) return;
        mwRenderModal(selected);
        document.getElementById('mw-payment-modal').style.display = 'flex';
        mwToggleRefLabel();
    };

    // ── Open modal (single, from row Pay button) ───────────────────────────────
    window.mwOpenSinglePayment = function (id, number, balance, client) {
        var single = {};
        single[id] = { balance: balance, number: number, client: client };
        mwRenderModal(single);
        document.getElementById('mw-payment-modal').style.display = 'flex';
        mwToggleRefLabel();
    };

    function mwRenderModal(invoiceMap) {
        var ids    = Object.keys(invoiceMap);
        var isSingle = ids.length === 1;
        var total  = 0;

        // Title
        document.getElementById('mw-modal-title-text').textContent =
            isSingle ? 'Record Payment' : 'Record Payment (' + ids.length + ' Invoices)';

        // Invoice list
        var listEl = document.getElementById('mw-payment-invoice-list');
        listEl.innerHTML = '';

        ids.forEach(function (id) {
            var inv = invoiceMap[id];
            total += inv.balance;

            var row = document.createElement('div');
            row.className = 'mw-payment-inv-row';
            row.innerHTML =
                '<div class="mw-payment-inv-info">' +
                    '<span class="mw-payment-inv-number">' + escHtml(inv.number) + '</span>' +
                    '<span class="mw-payment-inv-client">' + escHtml(inv.client) + '</span>' +
                '</div>' +
                '<div class="mw-payment-inv-amount">' + formatMoney(inv.balance) + '</div>';

            listEl.appendChild(row);
        });

        // Store IDs for submission
        listEl.dataset.invoiceIds = JSON.stringify(Object.keys(invoiceMap).map(Number));

        // Grand total
        document.getElementById('mw-payment-grand-total').textContent = formatMoney(total);

        // Reset form fields
        document.getElementById('mw-pay-method').value = 'e_transfer';
        document.getElementById('mw-pay-date').value   = new Date().toISOString().slice(0, 10);
        document.getElementById('mw-pay-ref').value    = '';
        document.getElementById('mw-pay-notes').value  = '';
        clearPayError();
    }

    // ── Toggle ref label based on method ──────────────────────────────────────
    window.mwToggleRefLabel = function () {
        var method = document.getElementById('mw-pay-method').value;
        var label  = document.getElementById('mw-ref-label');
        var input  = document.getElementById('mw-pay-ref');
        var labels = {
            e_transfer  : 'e-Transfer Confirmation #',
            cheque      : 'Cheque Number',
            cash        : 'Receipt / Notes',
            credit_card : 'Authorization Code',
            other       : 'Reference / Notes',
        };
        var placeholders = {
            e_transfer  : 'e.g., 123456789',
            cheque      : 'e.g., #1042',
            cash        : 'e.g., cash received',
            credit_card : 'e.g., auth code',
            other       : '',
        };
        label.textContent       = labels[method] || 'Reference';
        input.placeholder       = placeholders[method] || '';
    };

    // ── Close modal ────────────────────────────────────────────────────────────
    window.mwClosePaymentModal = function () {
        document.getElementById('mw-payment-modal').style.display = 'none';
        clearPayError();
    };

    // Close on overlay click
    document.getElementById('mw-payment-modal').addEventListener('click', function (e) {
        if (e.target === this) window.mwClosePaymentModal();
    });

    // ── Submit ─────────────────────────────────────────────────────────────────
    window.mwSubmitPayment = function () {
        var listEl     = document.getElementById('mw-payment-invoice-list');
        var invoiceIds = JSON.parse(listEl.dataset.invoiceIds || '[]');
        if (!invoiceIds.length) return;

        var method = document.getElementById('mw-pay-method').value;
        var date   = document.getElementById('mw-pay-date').value;
        var ref    = document.getElementById('mw-pay-ref').value.trim();
        var notes  = document.getElementById('mw-pay-notes').value.trim();

        clearPayError();
        setSubmitLoading(true);

        // Build form data (supports multiple invoice_ids[])
        var body = new URLSearchParams();
        body.append('csrf_token', CSRF_TOKEN);
        invoiceIds.forEach(function (id) { body.append('invoice_ids[]', id); });
        body.append('payment_method', method);
        body.append('payment_date', date);
        body.append('transaction_ref', ref);
        body.append('notes', notes);

        fetch('record-payment.php', {
            method  : 'POST',
            headers : { 'X-Requested-With': 'XMLHttpRequest' },
            body    : body,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            setSubmitLoading(false);
            if (data.success) {
                mwClosePaymentModal();
                mwClearSelection();
                showToast(data.message, 'success');
                // Reload after a short delay so the user sees the toast
                setTimeout(function () { window.location.reload(); }, 1400);
            } else {
                showPayError(data.message || 'An error occurred. Please try again.');
            }
        })
        .catch(function (err) {
            setSubmitLoading(false);
            console.error('[mwPayment] fetch error:', err);
            showPayError('Network error — please try again.');
        });
    };

    // ── Helpers ────────────────────────────────────────────────────────────────
    function formatMoney(n) {
        return '$' + parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function showPayError(msg) {
        var el = document.getElementById('mw-pay-error');
        if (!el) {
            el = document.createElement('div');
            el.id = 'mw-pay-error';
            el.className = 'alert alert-danger mt-3';
            document.querySelector('.mw-modal-body').appendChild(el);
        }
        el.textContent   = msg;
        el.style.display = 'block';
    }
    function clearPayError() {
        var el = document.getElementById('mw-pay-error');
        if (el) { el.style.display = 'none'; el.textContent = ''; }
    }
    function setSubmitLoading(loading) {
        var btn     = document.getElementById('mw-pay-submit');
        var label   = document.getElementById('mw-pay-submit-label');
        var spinner = document.getElementById('mw-pay-submit-spinner');
        btn.disabled          = loading;
        label.style.display   = loading ? 'none' : 'inline';
        spinner.style.display = loading ? 'inline' : 'none';
    }
    function showToast(msg, type) {
        var el = document.getElementById('mw-toast');
        el.textContent = msg;
        el.className   = 'mw-toast mw-toast-' + (type || 'success');
        el.style.display = 'flex';
        setTimeout(function () { el.style.display = 'none'; }, 3000);
    }

}());
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
