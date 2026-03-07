<?php
/**
 * Marketing Command Center — Pricing Intel, Pipeline, Channels, Trends
 *
 * Surfaces pricing win-rate data, pipeline health, lead source ROI,
 * and seasonal trends from existing quote, visit, and campaign data.
 *
 * @package Mowology CRM
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$db = getDB();

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt_money_i(?float $v, bool $sign = false): string {
    if ($v === null || $v == 0) return '<span class="text-muted">—</span>';
    $abs = '$' . number_format(abs($v), 0);
    if ($v < 0)          return '<span class="text-danger">-'  . $abs . '</span>';
    if ($sign && $v > 0) return '<span class="text-success">+' . $abs . '</span>';
    return $abs;
}
function fmt_pct_i(?float $v): string {
    if ($v === null) return '<span class="text-muted">—</span>';
    $cls = $v < 30 ? 'text-danger' : ($v < 50 ? 'text-warning' : 'text-success');
    return '<span class="' . $cls . '">' . number_format($v, 0) . '%</span>';
}
function svc_label_i(string $slug): string {
    static $map = [
        'lawn_care'          => 'Lawn Care',
        'landscaping'        => 'Landscaping',
        'snow_removal'       => 'Snow Removal',
        'hedge_trimming'     => 'Hedge Trimming',
        'garden_maintenance' => 'Garden',
        'seasonal_cleanup'   => 'Seasonal Cleanup',
        'hardscaping'        => 'Hardscaping',
        'irrigation'         => 'Irrigation',
        'tree_care'          => 'Tree Care',
    ];
    return $map[$slug] ?? ucwords(str_replace('_', ' ', $slug));
}

// ── Period Filter ─────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'all_time';

switch ($period) {
    case 'last_90':
        $dateFrom    = date('Y-m-d', strtotime('-90 days'));
        $dateTo      = date('Y-m-d');
        $periodLabel = 'Last 90 Days';
        break;
    case 'last_6mo':
        $dateFrom    = date('Y-m-d', strtotime('-6 months'));
        $dateTo      = date('Y-m-d');
        $periodLabel = 'Last 6 Months';
        break;
    case 'ytd':
        $dateFrom    = date('Y-01-01');
        $dateTo      = date('Y-m-d');
        $periodLabel = 'Year to Date';
        break;
    case 'last_year':
        $dateFrom    = date('Y-01-01', strtotime('-1 year'));
        $dateTo      = date('Y-12-31', strtotime('-1 year'));
        $periodLabel = 'Last Year';
        break;
    default:
        $period      = 'all_time';
        $dateFrom    = '2020-01-01';
        $dateTo      = date('Y-m-d');
        $periodLabel = 'All Time';
}

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 1: PRICING INTEL
// ═══════════════════════════════════════════════════════════════════════════════

// KPI stats
$decidedStatuses = "'sent','viewed','accepted','declined','expired'";
$pricingKpi = $db->prepare("
    SELECT
        COUNT(*)                                                           AS total_decided,
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END)              AS won,
        SUM(CASE WHEN status IN ('declined','expired') THEN 1 ELSE 0 END) AS lost,
        AVG(CASE WHEN status = 'accepted' THEN total_amount END)           AS avg_won,
        AVG(CASE WHEN status IN ('declined','expired') THEN total_amount END) AS avg_lost,
        AVG(total_amount)                                                   AS avg_all
    FROM quotes
    WHERE status IN ($decidedStatuses)
    AND created_at BETWEEN ? AND ?
");
$pricingKpi->execute([$dateFrom, $dateTo]);
$pk = $pricingKpi->fetch(PDO::FETCH_ASSOC);
$winRate   = $pk['total_decided'] > 0 ? round((int)$pk['won'] / (int)$pk['total_decided'] * 100) : null;
$priceGap  = ($pk['avg_lost'] && $pk['avg_won']) ? (float)$pk['avg_lost'] - (float)$pk['avg_won'] : null;

// Price brackets
$brackets = $db->prepare("
    SELECT
        CASE
            WHEN total_amount <= 200  THEN '\$0–200'
            WHEN total_amount <= 500  THEN '\$201–500'
            WHEN total_amount <= 1000 THEN '\$501–1K'
            WHEN total_amount <= 2500 THEN '\$1K–2.5K'
            ELSE '\$2.5K+'
        END AS bracket,
        MIN(total_amount)                                                 AS sort_key,
        COUNT(*)                                                          AS total,
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END)             AS won,
        SUM(CASE WHEN status IN ('declined','expired') THEN 1 ELSE 0 END) AS lost,
        AVG(total_amount)                                                  AS avg_amount
    FROM quotes
    WHERE status IN ($decidedStatuses)
    AND created_at BETWEEN ? AND ?
    GROUP BY bracket
    ORDER BY sort_key ASC
");
$brackets->execute([$dateFrom, $dateTo]);
$bracketRows = $brackets->fetchAll(PDO::FETCH_ASSOC);

// By service type
$byService = $db->prepare("
    SELECT
        COALESCE(service_type, 'other')                                     AS service_type,
        COUNT(*)                                                            AS total,
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END)               AS won,
        AVG(total_amount)                                                    AS avg_amount,
        AVG(CASE WHEN status = 'accepted' THEN total_amount END)             AS avg_won,
        AVG(CASE WHEN status IN ('declined','expired') THEN total_amount END) AS avg_lost
    FROM quotes
    WHERE status IN ($decidedStatuses)
    AND created_at BETWEEN ? AND ?
    GROUP BY service_type
    ORDER BY (SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) / COUNT(*)) ASC
");
$byService->execute([$dateFrom, $dateTo]);
$serviceRows = $byService->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 2: PIPELINE
// ═══════════════════════════════════════════════════════════════════════════════

// Pipeline KPIs
$pipeKpi = $db->query("
    SELECT
        COUNT(*)                                                     AS open_count,
        COALESCE(SUM(total_amount), 0)                               AS pipeline_value,
        SUM(CASE WHEN status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS stale_count
    FROM quotes
    WHERE status IN ('draft','sent','viewed')
")->fetch(PDO::FETCH_ASSOC);

$avgDaysToClose = $db->query("
    SELECT AVG(DATEDIFF(accepted_at, sent_at)) AS avg_days
    FROM quotes
    WHERE status = 'accepted'
    AND sent_at IS NOT NULL AND accepted_at IS NOT NULL
")->fetchColumn();

// Funnel counts
$funnel = $db->prepare("
    SELECT
        SUM(CASE WHEN status IN ('draft','sent','viewed','accepted','declined','expired') THEN 1 ELSE 0 END) AS total_created,
        SUM(CASE WHEN status IN ('sent','viewed','accepted','declined','expired') THEN 1 ELSE 0 END)          AS total_sent,
        SUM(CASE WHEN status IN ('viewed','accepted') THEN 1 ELSE 0 END)                                       AS total_viewed,
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END)                                                    AS total_accepted
    FROM quotes
    WHERE created_at BETWEEN ? AND ?
");
$funnel->execute([$dateFrom, $dateTo]);
$fn = $funnel->fetch(PDO::FETCH_ASSOC);

// Aging list (open quotes)
$aging = $db->query("
    SELECT q.id, q.quote_number, q.total_amount, q.status, q.sent_at, q.viewed_at,
           q.service_type, DATEDIFF(NOW(), COALESCE(q.sent_at, q.created_at)) AS days_open,
           c.first_name, c.last_name, p.address, p.city
    FROM quotes q
    LEFT JOIN quote_requests qr ON qr.quote_id = q.id
    LEFT JOIN contacts c ON c.id = COALESCE(qr.contact_id, q.property_id)
    LEFT JOIN properties p ON p.id = q.property_id
    WHERE q.status IN ('sent','viewed')
    ORDER BY COALESCE(q.sent_at, q.created_at) ASC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 3: CHANNELS
// ═══════════════════════════════════════════════════════════════════════════════

$channelKpi = $db->prepare("
    SELECT
        COUNT(*)                                                       AS total_leads,
        SUM(CASE WHEN quote_id IS NOT NULL THEN 1 ELSE 0 END)          AS quoted,
        SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END)          AS converted
    FROM quote_requests
    WHERE status != 'spam'
    AND created_at BETWEEN ? AND ?
");
$channelKpi->execute([$dateFrom, $dateTo]);
$ck = $channelKpi->fetch(PDO::FETCH_ASSOC);

$leadToQuote = $ck['total_leads'] > 0 ? round((int)$ck['quoted'] / (int)$ck['total_leads'] * 100) : 0;
$leadToJob   = $ck['total_leads'] > 0 ? round((int)$ck['converted'] / (int)$ck['total_leads'] * 100) : 0;

// Campaign revenue (safe — table may not have data)
$campRevenue = 0;
try {
    $campRevenue = (float)$db->prepare("
        SELECT COALESCE(SUM(revenue), 0) FROM campaign_revenue_log WHERE recorded_at BETWEEN ? AND ?
    ")->execute([$dateFrom, $dateTo]) ? (float)$db->prepare("
        SELECT COALESCE(SUM(revenue), 0) FROM campaign_revenue_log WHERE recorded_at BETWEEN ? AND ?
    ")->execute([$dateFrom, $dateTo]) : 0;
    $crStmt = $db->prepare("SELECT COALESCE(SUM(revenue), 0) FROM campaign_revenue_log WHERE recorded_at BETWEEN ? AND ?");
    $crStmt->execute([$dateFrom, $dateTo]);
    $campRevenue = (float)$crStmt->fetchColumn();
} catch (PDOException $e) { $campRevenue = 0; }

// Lead source breakdown
$sources = $db->prepare("
    SELECT
        COALESCE(NULLIF(source, ''), 'direct') AS source,
        COUNT(*)                                                       AS leads,
        SUM(CASE WHEN quote_id IS NOT NULL THEN 1 ELSE 0 END)          AS quoted,
        SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END)          AS converted
    FROM quote_requests
    WHERE status != 'spam'
    AND created_at BETWEEN ? AND ?
    GROUP BY source
    ORDER BY converted DESC, leads DESC
");
$sources->execute([$dateFrom, $dateTo]);
$sourceRows = $sources->fetchAll(PDO::FETCH_ASSOC);

// Top campaigns
$topCampaigns = [];
try {
    $topCampaigns = $db->query("
        SELECT
            mc.name,
            mc.sent_count,
            mc.open_count,
            mc.click_count,
            CASE WHEN mc.sent_count > 0 THEN ROUND(mc.open_count / mc.sent_count * 100) ELSE 0 END AS open_rate,
            CASE WHEN mc.sent_count > 0 THEN ROUND(mc.click_count / mc.sent_count * 100) ELSE 0 END AS click_rate
        FROM marketing_campaigns mc
        WHERE mc.status = 'completed' AND mc.sent_count > 0
        ORDER BY (mc.open_count / mc.sent_count) DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $topCampaigns = []; }

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 4: TRENDS
// ═══════════════════════════════════════════════════════════════════════════════

// Monthly quote volume + win rate (12 months)
$trendQuotes = $db->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m')                                AS month,
        DATE_FORMAT(created_at, '%b')                                   AS month_label,
        COUNT(*)                                                        AS total,
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END)           AS won,
        CASE WHEN COUNT(*) > 0
            THEN ROUND(SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) / COUNT(*) * 100)
            ELSE 0 END                                                   AS win_rate,
        AVG(total_amount)                                                AS avg_amount
    FROM quotes
    WHERE status IN ('sent','viewed','accepted','declined','expired')
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
    ORDER BY month ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Monthly revenue + margins (12 months)
$trendMargins = [];
try {
    $trendMargins = $db->query("
        SELECT
            DATE_FORMAT(visit_date, '%Y-%m')        AS month,
            DATE_FORMAT(visit_date, '%b')            AS month_label,
            COALESCE(SUM(quoted_amount), 0)          AS revenue,
            COALESCE(AVG(margin_pct), 0)             AS margin_pct
        FROM visit_margin_snapshots
        WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(visit_date, '%Y-%m'), DATE_FORMAT(visit_date, '%b')
        ORDER BY month ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $trendMargins = []; }

// Seasonal services (by service + quarter)
$seasonal = $db->query("
    SELECT
        COALESCE(service_type, 'other')                                    AS service_type,
        CONCAT('Q', QUARTER(created_at))                                   AS quarter,
        COUNT(*)                                                           AS total,
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END)              AS won,
        CASE WHEN COUNT(*) > 0
            THEN ROUND(SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) / COUNT(*) * 100)
            ELSE 0 END                                                      AS win_rate,
        AVG(total_amount)                                                    AS avg_amount
    FROM quotes
    WHERE status IN ('sent','viewed','accepted','declined','expired')
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
    GROUP BY service_type, QUARTER(created_at)
    ORDER BY service_type, QUARTER(created_at)
")->fetchAll(PDO::FETCH_ASSOC);

// Group seasonal by service
$seasonalGrouped = [];
foreach ($seasonal as $row) {
    $svc = $row['service_type'];
    if (!isset($seasonalGrouped[$svc])) $seasonalGrouped[$svc] = [];
    $seasonalGrouped[$svc][$row['quarter']] = $row;
}

// ── Inject chart data ─────────────────────────────────────────────────────────
$chartBrackets = array_map(function($r) {
    return [
        'bracket' => $r['bracket'],
        'total'   => (int)$r['total'],
        'won'     => (int)$r['won'],
        'lost'    => (int)$r['lost'],
    ];
}, $bracketRows);

$chartTrendQuotes = array_map(function($r) {
    return [
        'month_label' => $r['month_label'],
        'total'       => (int)$r['total'],
        'won'         => (int)$r['won'],
        'win_rate'    => (float)$r['win_rate'],
    ];
}, $trendQuotes);

$chartTrendMargins = array_map(function($r) {
    return [
        'month_label' => $r['month_label'],
        'revenue'     => (float)$r['revenue'],
        'margin_pct'  => (float)$r['margin_pct'],
    ];
}, $trendMargins);

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle  = 'Marketing Intel';
$activePage = 'intel';
$extraHead  = '<script src="/crm/js/marketing-intel.js?v=1" defer></script>';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- ── Inject chart data ──────────────────────────────────── -->
          <script>
            window.INTEL_DATA = {
              brackets:     <?php echo json_encode(array_values($chartBrackets)); ?>,
              trendQuotes:  <?php echo json_encode(array_values($chartTrendQuotes)); ?>,
              trendMargins: <?php echo json_encode(array_values($chartTrendMargins)); ?>
            };
          </script>

          <!-- ── Page Header ─────────────────────────────────────────── -->
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
              <div>
                  <h1 class="h3 mb-0">Marketing Intel</h1>
                  <p class="text-muted mb-0">Pricing · Pipeline · Channels · Trends</p>
              </div>
              <div class="d-flex align-items-center mt-2 mt-md-0">
                  <div class="btn-group btn-group-sm">
                      <?php
                      $periods = [
                          'last_90'   => '90 Days',
                          'last_6mo'  => '6 Months',
                          'ytd'       => 'YTD',
                          'last_year' => 'Last Year',
                          'all_time'  => 'All Time',
                      ];
                      foreach ($periods as $key => $label): ?>
                          <a href="?period=<?php echo $key; ?>"
                             class="btn btn-<?php echo $period === $key ? 'success' : 'outline-secondary'; ?>">
                              <?php echo $label; ?>
                          </a>
                      <?php endforeach; ?>
                  </div>
              </div>
          </div>

          <!-- ── Tab Nav ──────────────────────────────────────────────── -->
          <ul class="nav nav-tabs mb-4" id="intelTabs">
              <li class="nav-item">
                  <a class="nav-link active" href="#" data-section="pricing" onclick="switchIntelTab('pricing');return false;">
                      <i data-feather="dollar-sign" style="width:14px;height:14px;"></i> Pricing Intel
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" href="#" data-section="pipeline" onclick="switchIntelTab('pipeline');return false;">
                      <i data-feather="filter" style="width:14px;height:14px;"></i> Pipeline
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" href="#" data-section="channels" onclick="switchIntelTab('channels');return false;">
                      <i data-feather="share-2" style="width:14px;height:14px;"></i> Channels
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" href="#" data-section="trends" onclick="switchIntelTab('trends');return false;">
                      <i data-feather="trending-up" style="width:14px;height:14px;"></i> Trends
                  </a>
              </li>
          </ul>


          <!-- ═══════════════════════════════════════════════════════════
               SECTION 1 — PRICING INTEL
          ════════════════════════════════════════════════════════════════ -->
          <div id="section-pricing">

              <!-- KPI Row -->
              <div class="row mb-4">
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-green);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Win Rate</p>
                              <div class="mw-intel-kpi-value"><?php echo $winRate !== null ? fmt_pct_i((float)$winRate) : '<span class="text-muted">—</span>'; ?></div>
                              <small class="text-muted"><?php echo (int)$pk['won']; ?> won / <?php echo (int)$pk['total_decided']; ?> decided</small>
                          </div>
                      </div>
                  </div>
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-green);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Avg Winning Quote</p>
                              <div class="mw-intel-kpi-value"><?php echo fmt_money_i($pk['avg_won'] ? (float)$pk['avg_won'] : null); ?></div>
                          </div>
                      </div>
                  </div>
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-orange);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Avg Losing Quote</p>
                              <div class="mw-intel-kpi-value"><?php echo fmt_money_i($pk['avg_lost'] ? (float)$pk['avg_lost'] : null); ?></div>
                          </div>
                      </div>
                  </div>
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid <?php echo $priceGap !== null && $priceGap > 0 ? 'var(--mw-orange)' : 'var(--mw-green)'; ?>;">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Price Gap</p>
                              <div class="mw-intel-kpi-value"><?php echo $priceGap !== null ? fmt_money_i($priceGap, true) : '<span class="text-muted">—</span>'; ?></div>
                              <small class="text-muted"><?php echo $priceGap !== null && $priceGap > 0 ? 'Lost quotes priced higher' : ($priceGap !== null ? 'Price not the issue' : ''); ?></small>
                          </div>
                      </div>
                  </div>
              </div>

              <div class="row">
                  <!-- Price Bracket Chart -->
                  <div class="col-lg-7 mb-4">
                      <div class="card">
                          <div class="card-header"><h5 class="card-title mb-0">Win Rate by Price Bracket</h5></div>
                          <div class="card-body">
                              <canvas id="intel-c-brackets" height="280" style="width:100%;"></canvas>
                          </div>
                      </div>
                  </div>

                  <!-- Price Bracket Table -->
                  <div class="col-lg-5 mb-4">
                      <div class="card">
                          <div class="card-header"><h5 class="card-title mb-0">Bracket Details</h5></div>
                          <div class="card-body p-0">
                              <div class="table-responsive">
                                  <table class="table table-sm mb-0">
                                      <thead>
                                          <tr><th>Bracket</th><th class="text-center">Quotes</th><th class="text-center">Won</th><th class="text-center">Win Rate</th></tr>
                                      </thead>
                                      <tbody>
                                          <?php if (empty($bracketRows)): ?>
                                              <tr><td colspan="4" class="text-center text-muted py-4">No quote data in this period</td></tr>
                                          <?php else: foreach ($bracketRows as $br):
                                              $bWinRate = $br['total'] > 0 ? round((int)$br['won'] / (int)$br['total'] * 100) : 0;
                                              $barColor = $bWinRate >= 50 ? 'var(--mw-green)' : ($bWinRate >= 30 ? 'var(--mw-orange)' : '#dc3545');
                                          ?>
                                              <tr>
                                                  <td><strong><?php echo htmlspecialchars($br['bracket']); ?></strong></td>
                                                  <td class="text-center"><?php echo (int)$br['total']; ?></td>
                                                  <td class="text-center"><?php echo (int)$br['won']; ?></td>
                                                  <td>
                                                      <div class="d-flex align-items-center">
                                                          <div class="mw-intel-bar-track">
                                                              <div class="mw-intel-bar" style="width:<?php echo $bWinRate; ?>%;background:<?php echo $barColor; ?>;"></div>
                                                          </div>
                                                          <span class="ml-2" style="min-width:36px;font-weight:600;color:<?php echo $barColor; ?>;"><?php echo $bWinRate; ?>%</span>
                                                      </div>
                                                  </td>
                                              </tr>
                                          <?php endforeach; endif; ?>
                                      </tbody>
                                  </table>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>

              <!-- By Service Type -->
              <div class="card mb-4">
                  <div class="card-header"><h5 class="card-title mb-0">Win Rate by Service Type</h5></div>
                  <div class="card-body p-0">
                      <div class="table-responsive">
                          <table class="table table-sm mb-0">
                              <thead>
                                  <tr>
                                      <th>Service</th>
                                      <th class="text-center">Quotes</th>
                                      <th class="text-center">Won</th>
                                      <th class="text-center">Win Rate</th>
                                      <th class="text-right">Avg Quote</th>
                                      <th class="text-right">Avg Won</th>
                                      <th class="text-right">Avg Lost</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php if (empty($serviceRows)): ?>
                                      <tr><td colspan="7" class="text-center text-muted py-4">No quote data in this period</td></tr>
                                  <?php else: foreach ($serviceRows as $sr):
                                      $sWinRate = $sr['total'] > 0 ? round((int)$sr['won'] / (int)$sr['total'] * 100) : 0;
                                      $sBarColor = $sWinRate >= 50 ? 'var(--mw-green)' : ($sWinRate >= 30 ? 'var(--mw-orange)' : '#dc3545');
                                  ?>
                                      <tr>
                                          <td><strong><?php echo htmlspecialchars(svc_label_i($sr['service_type'])); ?></strong></td>
                                          <td class="text-center"><?php echo (int)$sr['total']; ?></td>
                                          <td class="text-center"><?php echo (int)$sr['won']; ?></td>
                                          <td>
                                              <div class="d-flex align-items-center">
                                                  <div class="mw-intel-bar-track">
                                                      <div class="mw-intel-bar" style="width:<?php echo $sWinRate; ?>%;background:<?php echo $sBarColor; ?>;"></div>
                                                  </div>
                                                  <span class="ml-2" style="min-width:36px;font-weight:600;color:<?php echo $sBarColor; ?>;"><?php echo $sWinRate; ?>%</span>
                                              </div>
                                          </td>
                                          <td class="text-right"><?php echo fmt_money_i($sr['avg_amount'] ? (float)$sr['avg_amount'] : null); ?></td>
                                          <td class="text-right"><?php echo fmt_money_i($sr['avg_won'] ? (float)$sr['avg_won'] : null); ?></td>
                                          <td class="text-right"><?php echo fmt_money_i($sr['avg_lost'] ? (float)$sr['avg_lost'] : null); ?></td>
                                      </tr>
                                  <?php endforeach; endif; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>
              </div>

          </div><!-- /section-pricing -->


          <!-- ═══════════════════════════════════════════════════════════
               SECTION 2 — PIPELINE
          ════════════════════════════════════════════════════════════════ -->
          <div id="section-pipeline" style="display:none;">

              <!-- KPI Row -->
              <div class="row mb-4">
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-green);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Open Quotes</p>
                              <div class="mw-intel-kpi-value"><?php echo (int)$pipeKpi['open_count']; ?></div>
                          </div>
                      </div>
                  </div>
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-green);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Pipeline Value</p>
                              <div class="mw-intel-kpi-value"><?php echo fmt_money_i((float)$pipeKpi['pipeline_value']); ?></div>
                          </div>
                      </div>
                  </div>
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-green);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Avg Days to Close</p>
                              <div class="mw-intel-kpi-value"><?php echo $avgDaysToClose ? number_format((float)$avgDaysToClose, 0) . ' days' : '<span class="text-muted">—</span>'; ?></div>
                          </div>
                      </div>
                  </div>
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid <?php echo (int)$pipeKpi['stale_count'] > 0 ? 'var(--mw-orange)' : 'var(--mw-green)'; ?>;">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Stale (&gt;30d)</p>
                              <div class="mw-intel-kpi-value"><?php echo (int)$pipeKpi['stale_count']; ?></div>
                          </div>
                      </div>
                  </div>
              </div>

              <!-- Funnel -->
              <div class="card mb-4">
                  <div class="card-header"><h5 class="card-title mb-0">Quote Funnel (<?php echo htmlspecialchars($periodLabel); ?>)</h5></div>
                  <div class="card-body">
                      <?php
                      $funnelStages = [
                          ['label' => 'Created',  'count' => (int)$fn['total_created']],
                          ['label' => 'Sent',     'count' => (int)$fn['total_sent']],
                          ['label' => 'Viewed',   'count' => (int)$fn['total_viewed']],
                          ['label' => 'Accepted', 'count' => (int)$fn['total_accepted']],
                      ];
                      $maxFunnel = max(1, $funnelStages[0]['count']);
                      foreach ($funnelStages as $idx => $stage):
                          $pct = round($stage['count'] / $maxFunnel * 100);
                          $convLabel = '';
                          if ($idx > 0 && $funnelStages[$idx - 1]['count'] > 0) {
                              $convRate = round($stage['count'] / $funnelStages[$idx - 1]['count'] * 100);
                              $convLabel = $convRate . '% →';
                          }
                      ?>
                          <div class="mw-intel-funnel-row">
                              <div class="mw-intel-funnel-label">
                                  <?php echo $stage['label']; ?>
                                  <span class="text-muted ml-1">(<?php echo $stage['count']; ?>)</span>
                              </div>
                              <div class="mw-intel-funnel-track">
                                  <div class="mw-intel-funnel-bar" style="width:<?php echo $pct; ?>%;"></div>
                              </div>
                              <?php if ($convLabel): ?>
                                  <small class="text-muted ml-2"><?php echo $convLabel; ?></small>
                              <?php endif; ?>
                          </div>
                      <?php endforeach; ?>
                  </div>
              </div>

              <!-- Aging Table -->
              <div class="card mb-4">
                  <div class="card-header"><h5 class="card-title mb-0">Open Quotes — Aging</h5></div>
                  <div class="card-body p-0">
                      <div class="table-responsive">
                          <table class="table table-sm mb-0">
                              <thead>
                                  <tr>
                                      <th>Quote</th><th>Client</th><th>Service</th>
                                      <th class="text-right">Amount</th><th class="text-center">Status</th>
                                      <th class="text-center">Days Open</th><th>Last Viewed</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php if (empty($aging)): ?>
                                      <tr><td colspan="7" class="text-center text-muted py-4">No open quotes</td></tr>
                                  <?php else: foreach ($aging as $aq):
                                      $agingClass = '';
                                      if ((int)$aq['days_open'] > 60) $agingClass = 'mw-intel-aging-crit';
                                      elseif ((int)$aq['days_open'] > 30) $agingClass = 'mw-intel-aging-warn';
                                  ?>
                                      <tr class="<?php echo $agingClass; ?>">
                                          <td><a href="/crm/quotes/view.php?id=<?php echo (int)$aq['id']; ?>"><?php echo htmlspecialchars($aq['quote_number'] ?? 'Q-' . $aq['id']); ?></a></td>
                                          <td><?php echo htmlspecialchars(trim(($aq['first_name'] ?? '') . ' ' . ($aq['last_name'] ?? '')) ?: '<span class="text-muted">—</span>'); ?></td>
                                          <td><?php echo htmlspecialchars(svc_label_i($aq['service_type'] ?? 'other')); ?></td>
                                          <td class="text-right"><?php echo fmt_money_i($aq['total_amount'] ? (float)$aq['total_amount'] : null); ?></td>
                                          <td class="text-center">
                                              <span class="badge badge-<?php echo $aq['status'] === 'viewed' ? 'info' : 'warning'; ?>"><?php echo ucfirst($aq['status']); ?></span>
                                          </td>
                                          <td class="text-center"><strong><?php echo (int)$aq['days_open']; ?>d</strong></td>
                                          <td><?php echo $aq['viewed_at'] ? date('M j', strtotime($aq['viewed_at'])) : '<span class="text-muted">Never</span>'; ?></td>
                                      </tr>
                                  <?php endforeach; endif; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>
              </div>

          </div><!-- /section-pipeline -->


          <!-- ═══════════════════════════════════════════════════════════
               SECTION 3 — CHANNELS
          ════════════════════════════════════════════════════════════════ -->
          <div id="section-channels" style="display:none;">

              <!-- KPI Row -->
              <div class="row mb-4">
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-green);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Total Leads</p>
                              <div class="mw-intel-kpi-value"><?php echo (int)$ck['total_leads']; ?></div>
                          </div>
                      </div>
                  </div>
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-green);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Lead → Quote</p>
                              <div class="mw-intel-kpi-value"><?php echo $leadToQuote; ?>%</div>
                          </div>
                      </div>
                  </div>
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-green);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Lead → Job</p>
                              <div class="mw-intel-kpi-value"><?php echo $leadToJob; ?>%</div>
                          </div>
                      </div>
                  </div>
                  <div class="col-6 col-md-3 mb-3">
                      <div class="card mw-intel-kpi" style="border-left:4px solid var(--mw-green);">
                          <div class="card-body py-3">
                              <p class="mw-intel-kpi-label">Campaign Revenue</p>
                              <div class="mw-intel-kpi-value"><?php echo fmt_money_i($campRevenue); ?></div>
                          </div>
                      </div>
                  </div>
              </div>

              <!-- Lead Sources -->
              <div class="card mb-4">
                  <div class="card-header"><h5 class="card-title mb-0">Lead Sources (<?php echo htmlspecialchars($periodLabel); ?>)</h5></div>
                  <div class="card-body p-0">
                      <div class="table-responsive">
                          <table class="table table-sm mb-0">
                              <thead>
                                  <tr>
                                      <th>Source</th><th class="text-center">Leads</th><th class="text-center">Quoted</th>
                                      <th class="text-center">Converted</th><th class="text-center">Conversion</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php if (empty($sourceRows)): ?>
                                      <tr><td colspan="5" class="text-center text-muted py-4">No leads in this period</td></tr>
                                  <?php else: foreach ($sourceRows as $src):
                                      $srcConv = $src['leads'] > 0 ? round((int)$src['converted'] / (int)$src['leads'] * 100) : 0;
                                  ?>
                                      <tr>
                                          <td><strong><?php echo htmlspecialchars(ucwords(str_replace(['_', '-'], ' ', $src['source']))); ?></strong></td>
                                          <td class="text-center"><?php echo (int)$src['leads']; ?></td>
                                          <td class="text-center"><?php echo (int)$src['quoted']; ?></td>
                                          <td class="text-center"><?php echo (int)$src['converted']; ?></td>
                                          <td class="text-center">
                                              <div class="d-flex align-items-center justify-content-center">
                                                  <div class="mw-intel-bar-track" style="width:60px;">
                                                      <div class="mw-intel-bar" style="width:<?php echo $srcConv; ?>%;background:var(--mw-green);"></div>
                                                  </div>
                                                  <span class="ml-2" style="font-weight:600;"><?php echo $srcConv; ?>%</span>
                                              </div>
                                          </td>
                                      </tr>
                                  <?php endforeach; endif; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>
              </div>

              <!-- Top Campaigns -->
              <div class="card mb-4">
                  <div class="card-header"><h5 class="card-title mb-0">Top Campaigns by Open Rate</h5></div>
                  <div class="card-body p-0">
                      <div class="table-responsive">
                          <table class="table table-sm mb-0">
                              <thead>
                                  <tr>
                                      <th>Campaign</th><th class="text-center">Sent</th>
                                      <th class="text-center">Opened</th><th class="text-center">Open Rate</th>
                                      <th class="text-center">Clicked</th><th class="text-center">Click Rate</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php if (empty($topCampaigns)): ?>
                                      <tr><td colspan="6" class="text-center text-muted py-4">No completed campaigns yet</td></tr>
                                  <?php else: foreach ($topCampaigns as $tc): ?>
                                      <tr>
                                          <td><strong><?php echo htmlspecialchars($tc['name']); ?></strong></td>
                                          <td class="text-center"><?php echo (int)$tc['sent_count']; ?></td>
                                          <td class="text-center"><?php echo (int)$tc['open_count']; ?></td>
                                          <td class="text-center"><?php echo (int)$tc['open_rate']; ?>%</td>
                                          <td class="text-center"><?php echo (int)$tc['click_count']; ?></td>
                                          <td class="text-center"><?php echo (int)$tc['click_rate']; ?>%</td>
                                      </tr>
                                  <?php endforeach; endif; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>
              </div>

          </div><!-- /section-channels -->


          <!-- ═══════════════════════════════════════════════════════════
               SECTION 4 — TRENDS
          ════════════════════════════════════════════════════════════════ -->
          <div id="section-trends" style="display:none;">

              <!-- Chart: Monthly Quote Volume & Win Rate -->
              <div class="card mb-4">
                  <div class="card-header"><h5 class="card-title mb-0">Monthly Quote Volume & Win Rate (12 Months)</h5></div>
                  <div class="card-body">
                      <canvas id="intel-c-trend-quotes" height="260" style="width:100%;"></canvas>
                  </div>
              </div>

              <!-- Chart: Monthly Revenue & Margins -->
              <div class="card mb-4">
                  <div class="card-header"><h5 class="card-title mb-0">Monthly Revenue & Margins (12 Months)</h5></div>
                  <div class="card-body">
                      <canvas id="intel-c-trend-margins" height="260" style="width:100%;"></canvas>
                  </div>
              </div>

              <!-- Seasonal Services Table -->
              <div class="card mb-4">
                  <div class="card-header"><h5 class="card-title mb-0">Seasonal Patterns (Last 2 Years)</h5></div>
                  <div class="card-body p-0">
                      <div class="table-responsive">
                          <table class="table table-sm mb-0">
                              <thead>
                                  <tr>
                                      <th>Service</th>
                                      <th class="text-center">Q1<br><small>Jan–Mar</small></th>
                                      <th class="text-center">Q2<br><small>Apr–Jun</small></th>
                                      <th class="text-center">Q3<br><small>Jul–Sep</small></th>
                                      <th class="text-center">Q4<br><small>Oct–Dec</small></th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php if (empty($seasonalGrouped)): ?>
                                      <tr><td colspan="5" class="text-center text-muted py-4">Not enough data for seasonal analysis</td></tr>
                                  <?php else: foreach ($seasonalGrouped as $svc => $quarters): ?>
                                      <tr>
                                          <td><strong><?php echo htmlspecialchars(svc_label_i($svc)); ?></strong></td>
                                          <?php for ($q = 1; $q <= 4; $q++):
                                              $qd = $quarters['Q' . $q] ?? null;
                                          ?>
                                              <td class="text-center">
                                                  <?php if ($qd): ?>
                                                      <div style="font-weight:600;"><?php echo (int)$qd['total']; ?> quotes</div>
                                                      <div style="font-size:.75rem;color:<?php echo (int)$qd['win_rate'] >= 50 ? 'var(--mw-green)' : ((int)$qd['win_rate'] >= 30 ? 'var(--mw-orange)' : '#dc3545'); ?>;">
                                                          <?php echo (int)$qd['win_rate']; ?>% win
                                                      </div>
                                                      <div style="font-size:.72rem;" class="text-muted">avg <?php echo fmt_money_i((float)$qd['avg_amount']); ?></div>
                                                  <?php else: ?>
                                                      <span class="text-muted">—</span>
                                                  <?php endif; ?>
                                              </td>
                                          <?php endfor; ?>
                                      </tr>
                                  <?php endforeach; endif; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>
              </div>

          </div><!-- /section-trends -->

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
