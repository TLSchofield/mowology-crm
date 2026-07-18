<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';
require_once 'includes/error-handler.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.view');

$db = getDB();

// Initialize error handler
$errorHandler = new CRMErrorHandler('Territory Map', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

// ── Data Queries ────────────────────────────────────────────

$properties = [];
$quoteRequests = [];
$allProperties = [];
$fsaStats = [];

try {
    // 1. Active properties (jobs/quotes) — existing map pins
    $properties = $db->query("
        SELECT DISTINCT
            p.id, p.address, p.city, p.province, p.postal_code,
            p.latitude, p.longitude,
            COUNT(DISTINCT jv.id) as active_jobs,
            COUNT(DISTINCT q.id) as active_quotes
        FROM properties p
        LEFT JOIN job_plans jp ON p.id = jp.property_id AND jp.status = 'active'
        LEFT JOIN job_visits jv ON jp.id = jv.plan_id AND jv.status IN ('scheduled', 'in_progress')
        LEFT JOIN quotes q ON p.id = q.property_id AND q.status IN ('sent', 'accepted')
        WHERE (jv.id IS NOT NULL OR q.id IS NOT NULL)
          AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL
          AND p.latitude != 0 AND p.longitude != 0
        GROUP BY p.id
        ORDER BY COALESCE(active_jobs, 0) + COALESCE(active_quotes, 0) DESC
        LIMIT 50
    ")->fetchAll();

    // 2. Quote requests
    $quoteRequests = $db->query("
        SELECT
            qr.id, qr.urgency, qr.status, qr.created_at,
            c.first_name, c.last_name,
            p.id as property_id, p.address, p.city, p.latitude, p.longitude,
            GROUP_CONCAT(DISTINCT qr.service_types) as services
        FROM quote_requests qr
        LEFT JOIN contacts c ON qr.contact_id = c.id
        LEFT JOIN properties p ON qr.property_id = p.id
        WHERE qr.status IN ('new', 'reviewing')
        GROUP BY qr.id
        ORDER BY
            CASE qr.urgency WHEN 'asap' THEN 1 WHEN 'soon' THEN 2 ELSE 3 END,
            qr.created_at DESC
    ")->fetchAll();

    // 3. ALL properties with coordinates (for density heatmap)
    $allProperties = $db->query("
        SELECT
            p.id, p.address, p.city, p.province, p.postal_code,
            LEFT(p.postal_code, 3) as fsa,
            p.latitude, p.longitude, p.total_lawn_sqft
        FROM properties p
        WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL
          AND p.latitude != 0 AND p.longitude != 0
        ORDER BY p.postal_code
    ")->fetchAll();

    // 4. FSA-level territory intelligence
    //    Combines: property count, active plans, plan revenue, quote pipeline
    $fsaStats = $db->query("
        SELECT
            LEFT(p.postal_code, 3) as fsa,
            p.city,
            COUNT(DISTINCT p.id) as property_count,
            AVG(p.latitude) as center_lat,
            AVG(p.longitude) as center_lng,
            COUNT(DISTINCT jp.id) as active_plans,
            COUNT(DISTINCT q.id) as total_quotes,
            COUNT(DISTINCT CASE WHEN q.status = 'accepted' THEN q.id END) as accepted_quotes,
            COALESCE(SUM(DISTINCT jp.price_per_visit), 0) as revenue_per_cycle,
            COUNT(DISTINCT jv.id) as total_visits
        FROM properties p
        LEFT JOIN job_plans jp ON p.id = jp.property_id AND jp.status = 'active'
        LEFT JOIN quotes q ON p.id = q.property_id
        LEFT JOIN job_visits jv ON jp.id = jv.plan_id
        WHERE p.postal_code IS NOT NULL AND p.postal_code != ''
          AND p.latitude IS NOT NULL AND p.latitude != 0
        GROUP BY fsa
        HAVING fsa IS NOT NULL AND fsa != ''
        ORDER BY property_count DESC
    ")->fetchAll();

    // 5. Service breakdown per FSA (for territory cards)
    $fsaServices = $db->query("
        SELECT
            LEFT(p.postal_code, 3) as fsa,
            pli.service_type,
            COUNT(*) as cnt,
            SUM(pli.line_total) as revenue
        FROM plan_line_items pli
        JOIN job_plans jp ON pli.plan_id = jp.id AND jp.status = 'active'
        JOIN properties p ON jp.property_id = p.id
        WHERE p.postal_code IS NOT NULL AND p.postal_code != ''
        GROUP BY fsa, pli.service_type
        ORDER BY fsa, revenue DESC
    ")->fetchAll();

    // Group services by FSA
    $servicesByFsa = [];
    foreach ($fsaServices as $svc) {
        $servicesByFsa[$svc['fsa']][] = $svc;
    }

    // Attach services to FSA stats
    foreach ($fsaStats as &$fsa) {
        $fsa['services'] = $servicesByFsa[$fsa['fsa']] ?? [];
    }
    unset($fsa);

    // 6. Unscheduled properties — active plans, no calendar stop this week (for 3b filter)
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd   = date('Y-m-d', strtotime('sunday this week'));
    $unscheduledProperties = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT
                p.id, p.address, p.city, p.province, p.postal_code,
                p.latitude, p.longitude,
                COALESCE(c.first_name, '') as first_name,
                COALESCE(c.last_name, '')  as last_name,
                jp.id   as plan_id,
                COALESCE(jp.title, 'Service') as plan_title,
                jp.price_per_visit,
                (SELECT jv2.id FROM job_visits jv2
                 WHERE jv2.plan_id = jp.id
                   AND jv2.status NOT IN ('completed', 'cancelled', 'skipped')
                 ORDER BY jv2.id LIMIT 1) as visit_id,
                (SELECT jv2.service_type FROM job_visits jv2
                 WHERE jv2.plan_id = jp.id
                   AND jv2.status NOT IN ('completed', 'cancelled', 'skipped')
                 ORDER BY jv2.id LIMIT 1) as service_type
            FROM properties p
            JOIN job_plans jp ON p.id = jp.property_id AND jp.status = 'active'
            LEFT JOIN contacts c ON p.site_contact_id = c.id
            WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL
              AND p.latitude != 0 AND p.longitude != 0
              AND NOT EXISTS (
                  SELECT 1 FROM calendar_stops cs
                  WHERE cs.property_id = p.id
                    AND cs.stop_date BETWEEN ? AND ?
                    AND cs.stop_status != 'cancelled'
              )
            ORDER BY p.id
            LIMIT 30
        ");
        $stmt->execute([$weekStart, $weekEnd]);
        $unscheduledProperties = $stmt->fetchAll();
    } catch (PDOException $e) {
        $unscheduledProperties = [];
    }

    // 7. Properties WITHOUT postal codes (for the gap alert)
    $missingPostalCount = $db->query("
        SELECT COUNT(*) as cnt FROM properties
        WHERE (postal_code IS NULL OR postal_code = '')
          AND latitude IS NOT NULL AND latitude != 0
    ")->fetchColumn();

    // 7. Overall summary stats
    $totalProperties = count($allProperties);
    $totalWithPlans = 0;
    $totalRevPerCycle = 0;
    $totalVisits = 0;
    foreach ($fsaStats as $fsa) {
        $totalWithPlans += $fsa['active_plans'];
        $totalRevPerCycle += $fsa['revenue_per_cycle'];
        $totalVisits += $fsa['total_visits'];
    }

    // Estimate annual revenue (weekly plans × ~30 weeks for seasonal lawn care in Vancouver)
    $estimatedAnnualRevenue = $totalRevPerCycle * 30;

} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Unable to load map data. Please refresh the page.');
    $properties = [];
    $quoteRequests = [];
    $allProperties = [];
    $fsaStats = [];
    $unscheduledProperties = [];
    $missingPostalCount = 0;
    $totalProperties = 0;
    $totalWithPlans = 0;
    $totalRevPerCycle = 0;
    $totalVisits = 0;
    $estimatedAnnualRevenue = 0;
}

// FSA neighborhood names (Vancouver-specific)
$fsaNames = [
    'V5N' => 'East Vancouver',
    'V5P' => 'South Vancouver',
    'V5S' => 'Killarney',
    'V5Y' => 'Mount Pleasant',
    'V5Z' => 'Cambie Corridor',
    'V6B' => 'Downtown / Yaletown',
    'V6K' => 'Kitsilano',
    'V6L' => 'Quilchena / Arbutus',
    'V6M' => 'Kerrisdale',
    'V6N' => 'Dunbar South',
    'V6P' => 'Marpole',
    'V6R' => 'West Point Grey',
    'V6S' => 'Dunbar / Southlands',
    'V6T' => 'UBC',
];

$pageTitle = 'Territory Map';
$activePage = 'map';
?>
<?php include 'includes/appstack_head.php'; ?>

          <!-- Session Alert Display -->
          <?php if (isset($_SESSION['alert'])):
              $alert = $_SESSION['alert'];
              $alertClass = [
                  'error' => 'alert-danger',
                  'warning' => 'alert-warning',
                  'success' => 'alert-success',
                  'info' => 'alert-info'
              ][$alert['type']] ?? 'alert-info';
          ?>
              <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                  <strong><?php echo ucfirst($alert['type']); ?>:</strong> <?php echo h($alert['message']); ?>
                  <button type="button" class="btn-close" data-dismiss="alert" aria-label="Close"></button>
              </div>
              <?php unset($_SESSION['alert']); ?>
          <?php endif; ?>

          <!-- ── Page Header ────────────────────────────────── -->
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">
              <i data-feather="map" class="align-middle mr-2" style="width:24px;height:24px;"></i>
              Territory Intelligence
            </h1>
            <div class="btn-group" role="group">
              <button type="button" class="btn btn-sm btn-outline-secondary active" id="btn-pins" onclick="toggleLayer('pins')">
                <i data-feather="map-pin" style="width:14px;height:14px;display:inline;"></i> Pins
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary active" id="btn-density" onclick="toggleLayer('density')">
                <i data-feather="target" style="width:14px;height:14px;display:inline;"></i> Density
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-revenue" onclick="toggleLayer('revenue')">
                <i data-feather="dollar-sign" style="width:14px;height:14px;display:inline;"></i> Revenue
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary active" id="btn-requests" onclick="toggleLayer('requests')">
                <i data-feather="inbox" style="width:14px;height:14px;display:inline;"></i> Requests
              </button>
              <button type="button" class="btn btn-sm btn-outline-warning" id="btn-unscheduled" onclick="toggleLayer('unscheduled')">
                <i data-feather="alert-circle" style="width:14px;height:14px;display:inline;"></i> No Visit This Week
                <?php if (!empty($unscheduledProperties)): ?>
                  <span class="badge badge-warning ml-1"><?php echo count($unscheduledProperties); ?></span>
                <?php endif; ?>
              </button>
            </div>
          </div>

          <!-- ── Summary Stats Row ──────────────────────────── -->
          <div class="row mb-3">
            <div class="col-md-3 col-6">
              <div class="card mw-territory-stat">
                <div class="card-body py-3 px-3">
                  <div class="mw-stat-label">Total Properties</div>
                  <div class="mw-stat-value"><?php echo $totalProperties; ?></div>
                  <div class="mw-stat-detail"><?php echo count($fsaStats); ?> neighborhoods</div>
                </div>
              </div>
            </div>
            <div class="col-md-3 col-6">
              <div class="card mw-territory-stat">
                <div class="card-body py-3 px-3">
                  <div class="mw-stat-label">Active Plans</div>
                  <div class="mw-stat-value"><?php echo $totalWithPlans; ?></div>
                  <div class="mw-stat-detail">recurring service</div>
                </div>
              </div>
            </div>
            <div class="col-md-3 col-6">
              <div class="card mw-territory-stat">
                <div class="card-body py-3 px-3">
                  <div class="mw-stat-label">Revenue / Cycle</div>
                  <div class="mw-stat-value">$<?php echo number_format($totalRevPerCycle, 0); ?></div>
                  <div class="mw-stat-detail">~$<?php echo number_format($estimatedAnnualRevenue, 0); ?>/season est.</div>
                </div>
              </div>
            </div>
            <div class="col-md-3 col-6">
              <div class="card mw-territory-stat">
                <div class="card-body py-3 px-3">
                  <div class="mw-stat-label">Total Visits</div>
                  <div class="mw-stat-value"><?php echo $totalVisits; ?></div>
                  <div class="mw-stat-detail">all time</div>
                </div>
              </div>
            </div>
          </div>

          <!-- ── Map ────────────────────────────────────────── -->
          <div class="card">
            <div class="card-body p-0" style="position: relative; height: 600px; overflow: hidden;">
              <div id="mapContainer" style="width: 100%; height: 100%; background: #f5f7fa;"></div>

              <!-- Map Legend (dynamic based on active layers) -->
              <div class="mw-map-legend" id="mapLegend">
                <div class="mw-legend-title">Legend</div>
                <div id="legend-pins">
                  <div class="mw-legend-item">
                    <span class="mw-legend-pin mw-pin-job"></span>
                    <span>Active Job</span>
                  </div>
                  <div class="mw-legend-item">
                    <span class="mw-legend-pin mw-pin-quote"></span>
                    <span>Active Quote</span>
                  </div>
                  <div class="mw-legend-item">
                    <span class="mw-legend-pin" style="background:#e85d04;"></span>
                    <span>Truck</span>
                  </div>
                </div>
                <div id="legend-requests">
                  <div class="mw-legend-item">
                    <span class="mw-legend-pin mw-pin-request-asap"></span>
                    <span>Request - ASAP</span>
                  </div>
                  <div class="mw-legend-item">
                    <span class="mw-legend-pin mw-pin-request-soon"></span>
                    <span>Request - Soon</span>
                  </div>
                  <div class="mw-legend-item">
                    <span class="mw-legend-pin mw-pin-request-inquiring"></span>
                    <span>Request - Inquiring</span>
                  </div>
                </div>
                <div id="legend-density">
                  <div class="mw-legend-item">
                    <span class="mw-legend-circle mw-circle-high"></span>
                    <span>High Density (4+)</span>
                  </div>
                  <div class="mw-legend-item">
                    <span class="mw-legend-circle mw-circle-medium"></span>
                    <span>Medium (2-3)</span>
                  </div>
                  <div class="mw-legend-item">
                    <span class="mw-legend-circle mw-circle-low"></span>
                    <span>Low (1)</span>
                  </div>
                </div>
                <div id="legend-revenue" style="display:none;">
                  <div class="mw-legend-item">
                    <span class="mw-legend-circle mw-circle-revenue-high"></span>
                    <span>High Revenue</span>
                  </div>
                  <div class="mw-legend-item">
                    <span class="mw-legend-circle mw-circle-revenue-medium"></span>
                    <span>Active Plans</span>
                  </div>
                  <div class="mw-legend-item">
                    <span class="mw-legend-circle mw-circle-revenue-none"></span>
                    <span>No Revenue Yet</span>
                  </div>
                </div>
                <div id="legend-unscheduled" style="display:none;">
                  <div class="mw-legend-item">
                    <span class="mw-legend-pin mw-pin-unscheduled"></span>
                    <span>No Visit This Week</span>
                  </div>
                </div>
              </div>

              <!-- Quick-Schedule Modal (3b) -->
              <div class="mw-qsched-overlay" id="mwQschedOverlay" style="display:none">
                <div class="mw-qsched-modal">
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                      <div class="mw-qsched-title" id="mwQschedTitle">Schedule Visit</div>
                      <div class="mw-qsched-sub" id="mwQschedSub"></div>
                    </div>
                    <button type="button" class="mw-qsched-close" id="mwQschedClose" aria-label="Close">&times;</button>
                  </div>
                  <div class="form-group mb-2">
                    <label class="mw-qsched-label">Schedule for:</label>
                    <input type="date" class="form-control form-control-sm" id="mwQschedDate">
                  </div>
                  <div class="mw-qsched-msg" id="mwQschedMsg"></div>
                  <div style="display:flex;gap:8px;">
                    <button type="button" class="btn btn-sm btn-success mw-qsched-submit-btn" id="mwQschedSubmit">Schedule Visit</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mwQschedCancel">Cancel</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <?php if ($missingPostalCount > 0): ?>
          <div class="alert alert-warning mt-3 mb-0" style="font-size: 13px;">
            <i data-feather="alert-triangle" style="width:14px;height:14px;display:inline;"></i>
            <strong><?php echo $missingPostalCount; ?> properties</strong> are missing postal codes and won't appear in neighborhood analytics.
            <a href="clients_appstack.php" class="alert-link">Update properties</a>
          </div>
          <?php endif; ?>

          <!-- ── Territory Intelligence Cards ───────────────── -->
          <div class="row mt-4">
            <div class="col-12">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">
                    <i data-feather="bar-chart-2" style="width:16px;height:16px;display:inline;margin-right:6px;"></i>
                    Neighborhood Breakdown
                  </h5>
                  <small class="text-muted">Grouped by FSA (first 3 digits of postal code)</small>
                </div>
                <div class="card-body">
                  <?php if (empty($fsaStats)): ?>
                    <div class="text-center text-muted py-4">
                      <p>No postal code data available yet. Add postal codes to properties to see neighborhood analytics.</p>
                    </div>
                  <?php else: ?>
                    <div class="mw-territory-grid">
                      <?php foreach ($fsaStats as $fsa):
                          $neighborhoodName = $fsaNames[$fsa['fsa']] ?? $fsa['city'];
                          $densityClass = 'mw-density-low';
                          if ($fsa['property_count'] >= 4) $densityClass = 'mw-density-high';
                          elseif ($fsa['property_count'] >= 2) $densityClass = 'mw-density-medium';

                          $hasRevenue = $fsa['revenue_per_cycle'] > 0;
                          $annualEst = $fsa['revenue_per_cycle'] * 30;
                      ?>
                        <div class="mw-territory-card <?php echo $densityClass; ?>" onclick="focusFsa('<?php echo h($fsa['fsa']); ?>')">
                          <div class="mw-territory-card-header">
                            <div>
                              <span class="mw-fsa-code"><?php echo h($fsa['fsa']); ?></span>
                              <span class="mw-neighborhood-name"><?php echo h($neighborhoodName); ?></span>
                            </div>
                            <span class="mw-density-badge <?php echo $densityClass; ?>">
                              <?php echo $fsa['property_count']; ?> <?php echo $fsa['property_count'] === 1 ? 'property' : 'properties'; ?>
                            </span>
                          </div>
                          <div class="mw-territory-card-body">
                            <div class="mw-territory-metrics">
                              <div class="mw-metric">
                                <span class="mw-metric-value"><?php echo $fsa['active_plans']; ?></span>
                                <span class="mw-metric-label">Plans</span>
                              </div>
                              <div class="mw-metric">
                                <span class="mw-metric-value">$<?php echo number_format($fsa['revenue_per_cycle'], 0); ?></span>
                                <span class="mw-metric-label">/ Visit</span>
                              </div>
                              <div class="mw-metric">
                                <span class="mw-metric-value"><?php echo $fsa['total_visits']; ?></span>
                                <span class="mw-metric-label">Visits</span>
                              </div>
                              <div class="mw-metric">
                                <span class="mw-metric-value"><?php echo $fsa['total_quotes']; ?></span>
                                <span class="mw-metric-label">Quotes</span>
                              </div>
                            </div>
                            <?php if (!empty($fsa['services'])): ?>
                              <div class="mw-territory-services">
                                <?php foreach (array_slice($fsa['services'], 0, 3) as $svc): ?>
                                  <span class="badge badge-light"><?php echo h($svc['service_type']); ?></span>
                                <?php endforeach; ?>
                              </div>
                            <?php endif; ?>
                            <?php if ($hasRevenue): ?>
                              <div class="mw-territory-revenue-bar">
                                <div class="mw-revenue-fill" style="width: <?php echo min(100, ($fsa['revenue_per_cycle'] / max($totalRevPerCycle, 1)) * 100); ?>%"></div>
                              </div>
                              <div class="mw-territory-est">
                                ~$<?php echo number_format($annualEst, 0); ?>/season est.
                              </div>
                            <?php else: ?>
                              <div class="mw-territory-opportunity">
                                <i data-feather="trending-up" style="width:12px;height:12px;display:inline;"></i>
                                Growth opportunity — no active plans yet
                              </div>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- ── Marketing Insights ────────────────────────── -->
          <?php if (!empty($fsaStats)): ?>
          <div class="row mt-4">
            <div class="col-md-6">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">
                    <i data-feather="zap" style="width:16px;height:16px;display:inline;margin-right:6px;"></i>
                    Strongest Territories
                  </h5>
                </div>
                <div class="card-body">
                  <?php
                    // Sort by a composite score: plans * 3 + properties * 2 + visits
                    $ranked = $fsaStats;
                    usort($ranked, function($a, $b) {
                        $scoreA = ($a['active_plans'] * 3) + ($a['property_count'] * 2) + ($a['total_visits'] * 0.1);
                        $scoreB = ($b['active_plans'] * 3) + ($b['property_count'] * 2) + ($b['total_visits'] * 0.1);
                        return $scoreB <=> $scoreA;
                    });
                    $topTerritories = array_slice($ranked, 0, 5);
                  ?>
                  <div class="list-group list-group-flush">
                    <?php foreach ($topTerritories as $i => $t):
                        $name = $fsaNames[$t['fsa']] ?? $t['city'];
                        $score = ($t['active_plans'] * 3) + ($t['property_count'] * 2) + ($t['total_visits'] * 0.1);
                    ?>
                      <div class="list-group-item px-0 mw-territory-rank-item" onclick="focusFsa('<?php echo h($t['fsa']); ?>')">
                        <div class="d-flex align-items-center">
                          <span class="mw-rank-number"><?php echo $i + 1; ?></span>
                          <div class="flex-grow-1">
                            <strong><?php echo h($t['fsa']); ?></strong> — <?php echo h($name); ?>
                            <div class="text-muted" style="font-size:12px;">
                              <?php echo $t['property_count']; ?> properties &middot;
                              <?php echo $t['active_plans']; ?> plans &middot;
                              $<?php echo number_format($t['revenue_per_cycle'], 0); ?>/cycle
                            </div>
                          </div>
                          <div class="mw-strength-bar">
                            <div class="mw-strength-fill" style="width: <?php echo min(100, ($score / max(1, $score)) * 100); ?>%"></div>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">
                    <i data-feather="trending-up" style="width:16px;height:16px;display:inline;margin-right:6px;"></i>
                    Growth Opportunities
                  </h5>
                </div>
                <div class="card-body">
                  <?php
                    // FSAs with properties but no/low active plans = growth opportunity
                    $opportunities = array_filter($fsaStats, function($f) {
                        return $f['property_count'] >= 2 && $f['active_plans'] < $f['property_count'];
                    });
                    usort($opportunities, function($a, $b) {
                        $gapA = $a['property_count'] - $a['active_plans'];
                        $gapB = $b['property_count'] - $b['active_plans'];
                        return $gapB <=> $gapA;
                    });
                    $opportunities = array_slice($opportunities, 0, 5);
                  ?>
                  <?php if (empty($opportunities)): ?>
                    <div class="text-center text-muted py-3">
                      <p>All territories are well-covered!</p>
                    </div>
                  <?php else: ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($opportunities as $opp):
                          $name = $fsaNames[$opp['fsa']] ?? $opp['city'];
                          $gap = $opp['property_count'] - $opp['active_plans'];
                          $conversionRate = $opp['property_count'] > 0 ? round(($opp['active_plans'] / $opp['property_count']) * 100) : 0;
                      ?>
                        <div class="list-group-item px-0 mw-territory-rank-item" onclick="focusFsa('<?php echo h($opp['fsa']); ?>')">
                          <div class="d-flex align-items-center">
                            <span class="mw-opp-icon">
                              <i data-feather="target" style="width:16px;height:16px;color:var(--mw-orange);"></i>
                            </span>
                            <div class="flex-grow-1">
                              <strong><?php echo h($opp['fsa']); ?></strong> — <?php echo h($name); ?>
                              <div class="text-muted" style="font-size:12px;">
                                <?php echo $opp['property_count']; ?> properties, only <?php echo $opp['active_plans']; ?> active plans
                                (<?php echo $conversionRate; ?>% conversion)
                              </div>
                            </div>
                            <span class="badge badge-warning"><?php echo $gap; ?> to convert</span>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div class="mt-3 p-3 rounded" style="background: var(--mw-light); font-size: 13px;">
                      <i data-feather="info" style="width:14px;height:14px;display:inline;"></i>
                      <strong>Marketing tip:</strong> Focus flyer drops and door-knocking in neighborhoods where you already have clients.
                      Existing service trucks in the area = lower travel cost per new client.
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- ── Properties & Requests Lists ────────────────── -->
          <div class="row mt-4">
            <div class="col-md-6">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">
                    <i data-feather="map-pin" style="width:16px;height:16px;display:inline;margin-right:6px;"></i>
                    Active Properties
                    <span class="badge badge-primary ml-2"><?php echo count($properties); ?></span>
                  </h5>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                  <?php if (empty($properties)): ?>
                    <div class="text-center text-muted py-4">
                      <p>No properties with active jobs or quotes</p>
                    </div>
                  <?php else: ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($properties as $prop): ?>
                        <a href="#" class="list-group-item list-group-item-action" onclick="focusProperty(<?php echo (int)$prop['id']; ?>); return false;">
                          <div class="d-flex justify-content-between align-items-start">
                            <div>
                              <h6 class="mb-1"><?php echo h($prop['address']); ?></h6>
                              <small class="text-muted">
                                <?php echo h($prop['city'] . ', ' . $prop['province']); ?>
                                <?php if (!empty($prop['postal_code'])): ?>
                                  <span class="mw-fsa-tag"><?php echo h(substr($prop['postal_code'], 0, 3)); ?></span>
                                <?php endif; ?>
                              </small>
                            </div>
                            <div class="text-right">
                              <?php if ($prop['active_jobs'] > 0): ?>
                                <span class="badge badge-success" title="Active jobs">
                                  <i data-feather="briefcase" style="width:12px;height:12px;display:inline;"></i> <?php echo $prop['active_jobs']; ?>
                                </span>
                              <?php endif; ?>
                              <?php if ($prop['active_quotes'] > 0): ?>
                                <span class="badge badge-info" title="Active quotes">
                                  <i data-feather="file-text" style="width:12px;height:12px;display:inline;"></i> <?php echo $prop['active_quotes']; ?>
                                </span>
                              <?php endif; ?>
                            </div>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">
                    <i data-feather="inbox" style="width:16px;height:16px;display:inline;margin-right:6px;"></i>
                    Pending Quote Requests
                    <span class="badge badge-primary ml-2"><?php echo count($quoteRequests); ?></span>
                  </h5>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                  <?php if (empty($quoteRequests)): ?>
                    <div class="text-center text-muted py-4">
                      <p>No pending quote requests</p>
                    </div>
                  <?php else: ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($quoteRequests as $qr): ?>
                        <?php
                          $qrName = trim(($qr['first_name'] ?? '') . ' ' . ($qr['last_name'] ?? ''));
                          if (empty($qrName)) $qrName = 'Unknown Contact';
                          $urgencyClass = 'mw-urgency-' . ($qr['urgency'] ?? 'inquiring');
                          $urgencyLabel = ucfirst($qr['urgency'] ?? 'inquiring');
                        ?>
                        <a href="products/quote-requests.php?id=<?php echo (int)$qr['id']; ?>" class="list-group-item list-group-item-action">
                          <div class="d-flex justify-content-between align-items-start">
                            <div>
                              <h6 class="mb-1"><?php echo h($qrName); ?></h6>
                              <small class="text-muted">
                                <?php if ($qr['address']): ?>
                                  <?php echo h($qr['address']); ?><?php if ($qr['city']): ?>, <?php echo h($qr['city']); ?><?php endif; ?>
                                <?php else: ?>
                                  No address specified
                                <?php endif; ?>
                              </small>
                            </div>
                            <span class="mw-urgency-badge <?php echo $urgencyClass; ?>">
                              <?php echo $urgencyLabel; ?>
                            </span>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <script>
            // ── Data from PHP ──────────────────────────────────
            const propertiesData = <?php echo json_encode($properties); ?>;
            const quoteRequestsData = <?php echo json_encode($quoteRequests); ?>;
            const allPropertiesData = <?php echo json_encode($allProperties); ?>;
            const fsaStatsData = <?php echo json_encode($fsaStats); ?>;
            const fsaNames = <?php echo json_encode($fsaNames); ?>;
            const unscheduledData = <?php echo json_encode($unscheduledProperties); ?>;

            // ── Layer State ────────────────────────────────────
            const layerVisibility = {
              pins: true,
              density: true,
              revenue: false,
              requests: true,
              unscheduled: false
            };

            // ── Map & Overlays ─────────────────────────────────
            let gmap = null;
            const markers = { properties: [], requests: [], unscheduled: [] };
            const overlays = { density: [], revenue: [], labels: [] };

            // ── Initialize ─────────────────────────────────────
            function initMap() {
              const mapContainer = document.getElementById('mapContainer');
              let mapCenter = { lat: 49.2527, lng: -123.1507 };
              let bounds = new google.maps.LatLngBounds();
              let hasCoordinates = false;

              // Build bounds from ALL properties
              allPropertiesData.forEach(p => {
                const lat = parseFloat(p.latitude), lng = parseFloat(p.longitude);
                if (lat && lng) { bounds.extend({ lat, lng }); hasCoordinates = true; }
              });
              quoteRequestsData.forEach(qr => {
                const lat = parseFloat(qr.latitude), lng = parseFloat(qr.longitude);
                if (lat && lng) { bounds.extend({ lat, lng }); hasCoordinates = true; }
              });

              gmap = new google.maps.Map(mapContainer, {
                zoom: 13,
                center: mapCenter,
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                styles: [
                  { elementType: 'geometry', stylers: [{ color: '#f5f5f5' }] },
                  { elementType: 'labels.text.stroke', stylers: [{ color: '#ffffff' }] },
                  { elementType: 'labels.text.fill', stylers: [{ color: '#616161' }] },
                  { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#c9e7f2' }] },
                  { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#e8e8e8' }] },
                  { featureType: 'park', elementType: 'geometry', stylers: [{ color: '#d4edda' }] }
                ]
              });

              if (hasCoordinates) {
                setTimeout(() => { gmap.fitBounds(bounds); }, 100);
              }

              renderPropertyMarkers();
              renderRequestMarkers();
              renderDensityOverlay();
              startTruckLayer();
            }

            // ── Trackimo Truck Layer ───────────────────────────
            // Polls /crm/api/truck-location.php every 30s and keeps a single
            // marker in sync. Uses a plain Marker (no OverlayView/CSS module
            // here). No-ops silently if no tracker is configured / no ping yet.
            let truckMarker = null;
            let truckInfoWindow = null;
            const TRUCK_POLL_MS = 30000;
            const TRUCK_STALE_S = 600; // 10 min → grey

            function startTruckLayer() {
              pollTruck();
              setInterval(pollTruck, TRUCK_POLL_MS);
            }

            function pollTruck() {
              fetch('/crm/api/truck-location.php', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                  if (!data || !data.ok || !data.location) return;
                  placeTruck(data.location);
                })
                .catch(() => { /* silently ignore — next poll retries */ });
            }

            function placeTruck(loc) {
              if (!gmap || typeof loc.lat !== 'number' || typeof loc.lng !== 'number') return;
              const pos = { lat: loc.lat, lng: loc.lng };
              const isStale = (loc.age_seconds || 0) > TRUCK_STALE_S;
              const icon = {
                path: 'M12 0C7.58 0 4 3.58 4 8c0 5.25 8 16 8 16s8-10.75 8-16c0-4.42-3.58-8-8-8z',
                fillColor: isStale ? '#9ca3af' : '#e85d04',
                fillOpacity: 1,
                scale: 1.4,
                strokeColor: '#fff',
                strokeWeight: 2,
                anchor: new google.maps.Point(12, 24)
              };

              if (!truckMarker) {
                truckMarker = new google.maps.Marker({
                  position: pos, map: gmap, icon: icon,
                  title: 'Truck', zIndex: 9999
                });
                truckInfoWindow = new google.maps.InfoWindow();
                truckMarker.addListener('click', () => {
                  closeAllInfoWindows();
                  truckInfoWindow.setContent(truckBubble(loc));
                  truckInfoWindow.open(gmap, truckMarker);
                });
              } else {
                truckMarker.setPosition(pos);
                truckMarker.setIcon(icon);
              }
            }

            function truckBubble(loc) {
              const ageMin = Math.round((loc.age_seconds || 0) / 60);
              const ageLabel = ageMin < 1 ? 'just now' : ageMin + ' min ago';
              const speed = (loc.speed_kph != null) ? Math.round(loc.speed_kph) + ' km/h' : '&mdash;';
              const batt  = (loc.battery_pct != null) ? loc.battery_pct + '%' : '&mdash;';
              return '<div style="font-family:sans-serif;font-size:13px;min-width:160px;line-height:1.5">' +
                     '<strong style="color:#e85d04">&#128739; Truck</strong><br>' +
                     '<span style="color:#666">Last seen ' + ageLabel + '</span><br>' +
                     'Speed: ' + speed + '<br>Battery: ' + batt + '</div>';
            }

            // ── Property Markers (pins layer) ──────────────────
            function renderPropertyMarkers() {
              markers.properties.forEach(m => m.setMap(null));
              markers.properties = [];
              if (!layerVisibility.pins) return;

              propertiesData.forEach(prop => {
                const lat = parseFloat(prop.latitude), lng = parseFloat(prop.longitude);
                if (!lat || !lng) return;

                const hasJobs = parseInt(prop.active_jobs) > 0;
                const icon = {
                  path: 'M12 0C7.58 0 4 3.58 4 8c0 5.25 8 16 8 16s8-10.75 8-16c0-4.42-3.58-8-8-8zm0 11c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3z',
                  fillColor: hasJobs ? '#059669' : '#2D8659',
                  fillOpacity: 0.9,
                  scale: 1.5,
                  strokeColor: '#fff',
                  strokeWeight: 2,
                  anchor: new google.maps.Point(12, 24)
                };

                const marker = new google.maps.Marker({
                  position: { lat, lng },
                  map: gmap,
                  title: prop.address,
                  icon: icon,
                  data: prop,
                  type: 'property'
                });

                const fsa = (prop.postal_code || '').substring(0, 3);
                const fsaLabel = fsa ? ` <span style="background:#e2e8f0;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;">${esc(fsa)}</span>` : '';
                const infoContent = `
                  <div style="padding:10px;min-width:220px;">
                    <h6 style="margin:0 0 8px;color:#2D8659;">${esc(prop.address)}${fsaLabel}</h6>
                    <p style="margin:0 0 6px;font-size:12px;color:#666;">${esc(prop.city)}, ${esc(prop.province)} ${prop.postal_code || ''}</p>
                    <div style="margin-top:8px;padding-top:8px;border-top:1px solid #eee;font-size:12px;">
                      ${prop.active_jobs > 0 ? `<p style="margin:4px 0;color:#059669;"><strong>Jobs:</strong> ${prop.active_jobs}</p>` : ''}
                      ${prop.active_quotes > 0 ? `<p style="margin:4px 0;color:#3B82F6;"><strong>Quotes:</strong> ${prop.active_quotes}</p>` : ''}
                    </div>
                  </div>`;

                const infoWindow = new google.maps.InfoWindow({ content: infoContent });
                marker.addListener('click', () => {
                  closeAllInfoWindows();
                  infoWindow.open(gmap, marker);
                });
                marker.infoWindow = infoWindow;
                markers.properties.push(marker);
              });
            }

            // ── Request Markers ────────────────────────────────
            function renderRequestMarkers() {
              markers.requests.forEach(m => m.setMap(null));
              markers.requests = [];
              if (!layerVisibility.requests) return;

              quoteRequestsData.forEach(qr => {
                const lat = parseFloat(qr.latitude), lng = parseFloat(qr.longitude);
                if (!lat || !lng) return;

                let color = '#3B82F6';
                if (qr.urgency === 'asap') color = '#e85d04';
                else if (qr.urgency === 'soon') color = '#F59E0B';

                const icon = {
                  path: 'M12 0C7.58 0 4 3.58 4 8c0 5.25 8 16 8 16s8-10.75 8-16c0-4.42-3.58-8-8-8zm0 11c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3z',
                  fillColor: color,
                  fillOpacity: 0.85,
                  scale: 1.2,
                  strokeColor: '#fff',
                  strokeWeight: 2,
                  anchor: new google.maps.Point(12, 24)
                };

                const marker = new google.maps.Marker({
                  position: { lat, lng },
                  map: gmap,
                  title: ((qr.first_name || '') + ' ' + (qr.last_name || '')).trim(),
                  icon: icon,
                  data: qr,
                  type: 'request'
                });

                const contactName = ((qr.first_name || '') + ' ' + (qr.last_name || '')).trim() || 'Unknown';
                const infoContent = `
                  <div style="padding:10px;min-width:220px;">
                    <h6 style="margin:0 0 8px;color:${color};">${esc(contactName)}</h6>
                    <p style="margin:0 0 4px;font-size:11px;">
                      <span style="display:inline-block;padding:3px 8px;border-radius:3px;background:${color};color:white;font-size:10px;font-weight:bold;">
                        ${(qr.urgency || 'inquiring').toUpperCase()}
                      </span>
                    </p>
                    <p style="margin:4px 0;font-size:12px;color:#666;">
                      ${esc(qr.address || 'No address')}${qr.city ? ', ' + esc(qr.city) : ''}
                    </p>
                    <p style="margin:6px 0 0;font-size:11px;color:#888;">
                      Services: ${esc((qr.services || '').substring(0, 50))}${(qr.services || '').length > 50 ? '...' : ''}
                    </p>
                    <div style="margin-top:8px;padding-top:8px;border-top:1px solid #eee;">
                      <a href="/crm/products/quote-requests.php?id=${qr.id}" style="color:${color};text-decoration:none;font-size:12px;font-weight:bold;">View Details &rarr;</a>
                    </div>
                  </div>`;

                const infoWindow = new google.maps.InfoWindow({ content: infoContent });
                marker.addListener('click', () => {
                  closeAllInfoWindows();
                  infoWindow.open(gmap, marker);
                });
                marker.addListener('dblclick', () => {
                  window.location.href = '/crm/products/quote-requests.php?id=' + qr.id;
                });
                marker.infoWindow = infoWindow;
                markers.requests.push(marker);
              });
            }

            // ── Density Circles + FSA Labels (on-map) ───────────
            // FsaLabelOverlay class — must be initialized after Google Maps loads
            let FsaLabelOverlay = null;
            function initFsaLabelClass() {
              if (FsaLabelOverlay) return;
              FsaLabelOverlay = class extends google.maps.OverlayView {
                constructor(position, html, map) {
                  super();
                  this.position = position;
                  this.html = html;
                  this.div = null;
                  this.setMap(map);
                }
                onAdd() {
                  this.div = document.createElement('div');
                  this.div.style.position = 'absolute';
                  this.div.style.transform = 'translate(-50%, -50%)';
                  this.div.style.pointerEvents = 'auto';
                  this.div.style.zIndex = '50';
                  this.div.innerHTML = this.html;
                  this.getPanes().overlayMouseTarget.appendChild(this.div);
                }
                draw() {
                  if (!this.div) return;
                  const proj = this.getProjection();
                  if (!proj) return;
                  const pos = proj.fromLatLngToDivPixel(this.position);
                  if (pos) {
                    this.div.style.left = pos.x + 'px';
                    this.div.style.top = pos.y + 'px';
                  }
                }
                onRemove() {
                  if (this.div && this.div.parentNode) {
                    this.div.parentNode.removeChild(this.div);
                    this.div = null;
                  }
                }
              };
            }
            function renderDensityOverlay() {
              clearOverlay('density');
              clearOverlay('labels');
              if (!layerVisibility.density) return;
              initFsaLabelClass();

              const maxCount = Math.max(...fsaStatsData.map(f => parseInt(f.property_count)), 1);

              fsaStatsData.forEach(fsa => {
                const lat = parseFloat(fsa.center_lat), lng = parseFloat(fsa.center_lng);
                if (!lat || !lng) return;

                const count = parseInt(fsa.property_count);
                const plans = parseInt(fsa.active_plans);
                const rev = parseFloat(fsa.revenue_per_cycle);
                const visits = parseInt(fsa.total_visits);
                const radius = 300 + (count / maxCount) * 700;

                let fillColor = '#94a3b8', strokeColor = '#64748b', tierClass = 'mw-fsa-tier-low';
                if (count >= 4) { fillColor = '#059669'; strokeColor = '#047857'; tierClass = 'mw-fsa-tier-high'; }
                else if (count >= 2) { fillColor = '#F59E0B'; strokeColor = '#D97706'; tierClass = 'mw-fsa-tier-medium'; }

                // Draw circle
                const circle = new google.maps.Circle({
                  center: { lat, lng },
                  radius: radius,
                  fillColor: fillColor,
                  fillOpacity: 0.2,
                  strokeColor: strokeColor,
                  strokeWeight: 2,
                  strokeOpacity: 0.5,
                  map: gmap,
                  clickable: false
                });
                overlays.density.push(circle);

                // Draw persistent HTML label on map
                const fsaName = fsaNames[fsa.fsa] || fsa.city || fsa.fsa;
                const revLine = rev > 0
                  ? `<div class="mw-fsa-label-rev">$${rev.toFixed(0)}/visit</div>`
                  : `<div class="mw-fsa-label-opp">opportunity</div>`;

                const labelHtml = `
                  <div class="mw-fsa-map-label ${tierClass}" onclick="focusFsa('${esc(fsa.fsa)}')">
                    <div class="mw-fsa-label-code">${esc(fsa.fsa)}</div>
                    <div class="mw-fsa-label-name">${esc(fsaName)}</div>
                    <div class="mw-fsa-label-stats">
                      <span>${count} prop</span>
                      <span>${plans} plans</span>
                    </div>
                    ${revLine}
                  </div>`;

                const labelOverlay = new FsaLabelOverlay(
                  new google.maps.LatLng(lat, lng),
                  labelHtml,
                  gmap
                );
                overlays.labels.push(labelOverlay);
              });
            }

            // ── Revenue Circles (FSA-level) ────────────────────
            function renderRevenueOverlay() {
              clearOverlay('revenue');
              if (!layerVisibility.revenue) return;

              const maxRev = Math.max(...fsaStatsData.map(f => parseFloat(f.revenue_per_cycle)), 1);

              fsaStatsData.forEach(fsa => {
                const lat = parseFloat(fsa.center_lat), lng = parseFloat(fsa.center_lng);
                if (!lat || !lng) return;

                const rev = parseFloat(fsa.revenue_per_cycle);
                const count = parseInt(fsa.property_count);
                const radius = 300 + (count / Math.max(...fsaStatsData.map(f => parseInt(f.property_count)), 1)) * 700;

                let fillColor, strokeColor;
                if (rev > 0 && parseInt(fsa.active_plans) >= 2) {
                  fillColor = '#059669'; strokeColor = '#047857'; // high revenue
                } else if (rev > 0) {
                  fillColor = '#2D8659'; strokeColor = '#1A5F4A'; // some revenue
                } else {
                  fillColor = '#e2e8f0'; strokeColor = '#94a3b8'; // no revenue
                }

                const circle = new google.maps.Circle({
                  center: { lat, lng },
                  radius: radius,
                  fillColor: fillColor,
                  fillOpacity: rev > 0 ? 0.3 : 0.15,
                  strokeColor: strokeColor,
                  strokeWeight: 2,
                  strokeOpacity: 0.7,
                  map: gmap,
                  clickable: true
                });

                const fsaName = fsaNames[fsa.fsa] || fsa.city || fsa.fsa;
                const annualEst = rev * 30;

                const infoContent = `<div style="padding:6px 10px;min-width:180px;text-align:center;">
                  <div style="font-weight:700;font-size:14px;color:${strokeColor};">${esc(fsa.fsa)} — ${esc(fsaName)}</div>
                  <div style="font-size:20px;font-weight:700;color:#059669;margin:6px 0;">$${rev.toFixed(0)}/visit</div>
                  <div style="font-size:11px;color:#888;">~$${annualEst.toFixed(0)}/season est.</div>
                  <div style="margin-top:6px;font-size:11px;color:#666;">
                    ${fsa.active_plans} plans &middot; ${fsa.property_count} properties
                  </div>
                </div>`;

                const label = new google.maps.InfoWindow({
                  content: infoContent,
                  position: { lat, lng },
                  disableAutoPan: true
                });

                circle.addListener('click', () => {
                  closeAllInfoWindows();
                  label.open(gmap);
                });

                overlays.revenue.push(circle);
              });
            }

            // ── Unscheduled Property Markers (3b) ─────────────
            function renderUnscheduledMarkers() {
              markers.unscheduled.forEach(m => m.setMap(null));
              markers.unscheduled = [];
              if (!layerVisibility.unscheduled) return;

              unscheduledData.forEach(prop => {
                const lat = parseFloat(prop.latitude), lng = parseFloat(prop.longitude);
                if (!lat || !lng) return;

                const icon = {
                  path: 'M12 0C7.58 0 4 3.58 4 8c0 5.25 8 16 8 16s8-10.75 8-16c0-4.42-3.58-8-8-8zm0 11c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3z',
                  fillColor: '#e85d04',
                  fillOpacity: 0.9,
                  scale: 1.6,
                  strokeColor: '#fff',
                  strokeWeight: 2,
                  anchor: new google.maps.Point(12, 24)
                };

                const contactName = ((prop.first_name || '') + ' ' + (prop.last_name || '')).trim() || '';
                const marker = new google.maps.Marker({
                  position: { lat, lng },
                  map: gmap,
                  title: prop.address,
                  icon,
                  data: prop,
                  type: 'unscheduled'
                });

                marker.addListener('click', () => {
                  closeAllInfoWindows();
                  openQuickSchedule(prop);
                });

                markers.unscheduled.push(marker);
              });
            }

            // ── Quick-Schedule (3b) ────────────────────────────
            let qschedCurrentProp = null;

            function openQuickSchedule(prop) {
              qschedCurrentProp = prop;
              const contactName = ((prop.first_name || '') + ' ' + (prop.last_name || '')).trim();
              document.getElementById('mwQschedTitle').textContent = prop.plan_title || 'Schedule Visit';
              document.getElementById('mwQschedSub').textContent =
                (prop.address || '') +
                (prop.city ? ', ' + prop.city : '') +
                (contactName ? ' — ' + contactName : '');
              document.getElementById('mwQschedMsg').textContent = '';
              document.getElementById('mwQschedSubmit').disabled = false;

              // Default to tomorrow
              const tomorrow = new Date();
              tomorrow.setDate(tomorrow.getDate() + 1);
              const tStr = tomorrow.getFullYear() + '-' +
                String(tomorrow.getMonth() + 1).padStart(2, '0') + '-' +
                String(tomorrow.getDate()).padStart(2, '0');
              const dateInput = document.getElementById('mwQschedDate');
              dateInput.value = tStr;
              dateInput.min = new Date().toISOString().split('T')[0];

              document.getElementById('mwQschedOverlay').style.display = 'flex';
            }

            function closeQuickSchedule() {
              document.getElementById('mwQschedOverlay').style.display = 'none';
              qschedCurrentProp = null;
            }

            function submitQuickSchedule() {
              if (!qschedCurrentProp || !qschedCurrentProp.visit_id) {
                document.getElementById('mwQschedMsg').textContent = 'No schedulable visit found for this property.';
                return;
              }
              const date = document.getElementById('mwQschedDate').value;
              if (!date) { document.getElementById('mwQschedMsg').textContent = 'Please select a date.'; return; }

              const msg = document.getElementById('mwQschedMsg');
              const btn = document.getElementById('mwQschedSubmit');
              msg.textContent = 'Scheduling\u2026';
              btn.disabled = true;

              fetch('/crm/api/schedule-visit.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
                body: JSON.stringify({ visit_id: parseInt(qschedCurrentProp.visit_id), date })
              })
              .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
              .then(res => {
                if (res.ok) {
                  msg.textContent = '\u2713 Scheduled for ' + date + '!';
                  setTimeout(() => { closeQuickSchedule(); window.location.reload(); }, 1400);
                } else {
                  btn.disabled = false;
                  msg.textContent = (res.data && res.data.error) ? res.data.error : 'Failed to schedule. Try again.';
                }
              })
              .catch(() => {
                btn.disabled = false;
                msg.textContent = 'Network error. Please try again.';
              });
            }

            // ── Layer Toggle ───────────────────────────────────
            function toggleLayer(layer) {
              layerVisibility[layer] = !layerVisibility[layer];

              const btn = document.getElementById('btn-' + layer);
              if (btn) btn.classList.toggle('active');

              // Update legend visibility
              const legendMap = { pins: 'legend-pins', requests: 'legend-requests', density: 'legend-density', revenue: 'legend-revenue', unscheduled: 'legend-unscheduled' };
              const legendEl = document.getElementById(legendMap[layer]);
              if (legendEl) legendEl.style.display = layerVisibility[layer] ? '' : 'none';

              // Re-render affected layers
              if (layer === 'pins') renderPropertyMarkers();
              if (layer === 'requests') renderRequestMarkers();
              if (layer === 'density') renderDensityOverlay();
              if (layer === 'revenue') renderRevenueOverlay();
              if (layer === 'unscheduled') renderUnscheduledMarkers();
            }

            // ── Focus Functions ────────────────────────────────
            function focusProperty(propertyId) {
              const prop = propertiesData.find(p => parseInt(p.id) === propertyId);
              if (prop && prop.latitude && prop.longitude) {
                gmap.setCenter({ lat: parseFloat(prop.latitude), lng: parseFloat(prop.longitude) });
                gmap.setZoom(18);
                const marker = markers.properties.find(m => parseInt(m.data.id) === propertyId);
                if (marker) google.maps.event.trigger(marker, 'click');
              }
            }

            function focusFsa(fsaCode) {
              const fsa = fsaStatsData.find(f => f.fsa === fsaCode);
              if (fsa && fsa.center_lat && fsa.center_lng) {
                gmap.setCenter({ lat: parseFloat(fsa.center_lat), lng: parseFloat(fsa.center_lng) });
                gmap.setZoom(15);

                // Auto-enable density layer if not visible
                if (!layerVisibility.density) toggleLayer('density');
              }
              // Scroll to map
              document.getElementById('mapContainer').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            // ── Helpers ────────────────────────────────────────
            function closeAllInfoWindows() {
              markers.properties.forEach(m => { if (m.infoWindow) m.infoWindow.close(); });
              markers.requests.forEach(m => { if (m.infoWindow) m.infoWindow.close(); });
            }

            function clearOverlay(type) {
              (overlays[type] || []).forEach(o => {
                if (o.setMap) o.setMap(null);
              });
              overlays[type] = [];
            }

            function esc(text) {
              if (!text) return '';
              const d = document.createElement('div');
              d.textContent = String(text);
              return d.innerHTML;
            }

            // ── Init ──────────────────────────────────────────
            document.addEventListener('DOMContentLoaded', () => {
              if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=drawing,geometry';
                script.onload = initMap;
                document.head.appendChild(script);
              } else {
                initMap();
              }

              // Wire quick-schedule modal buttons (3b)
              document.getElementById('mwQschedClose').addEventListener('click', closeQuickSchedule);
              document.getElementById('mwQschedCancel').addEventListener('click', closeQuickSchedule);
              document.getElementById('mwQschedSubmit').addEventListener('click', submitQuickSchedule);
              document.getElementById('mwQschedOverlay').addEventListener('click', function(e) {
                if (e.target === this) closeQuickSchedule();
              });
            });
          </script>

<?php include 'includes/appstack_footer.php'; ?>
