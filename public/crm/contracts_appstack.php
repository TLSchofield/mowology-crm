<?php
/**
 * Contracts List
 * Shows all service contracts grouped by status.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.view');

$db = getDB();

$filterStatus = $_GET['status'] ?? 'all';

// Build query
$params = [];
$where  = '';
if ($filterStatus !== 'all') {
    $where    = 'WHERE c.status = ?';
    $params[] = $filterStatus;
}

$contracts = $db->prepare("
    SELECT c.*,
           p.address AS property_address, p.city AS property_city,
           ct.first_name, ct.last_name,
           co.company_name,
           q.quote_number,
           COUNT(jp.id) AS plan_count
    FROM contracts c
    JOIN  properties p  ON c.property_id = p.id
    JOIN  contacts ct   ON c.contact_id  = ct.id
    LEFT JOIN companies co ON co.primary_contact_id = ct.id
    LEFT JOIN quotes q  ON c.quote_id    = q.id
    LEFT JOIN job_plans jp ON jp.contract_id = c.id
    {$where}
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
$contracts->execute($params);
$contracts = $contracts->fetchAll(PDO::FETCH_ASSOC);

// Status counts for tabs
$counts = [];
$countRows = $db->query("SELECT status, COUNT(*) AS n FROM contracts GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($countRows as $row) {
    $counts[$row['status']] = (int)$row['n'];
}
$counts['all'] = array_sum($counts);

$billingCycleLabels = [
    'monthly'   => 'Monthly',
    'per_visit' => 'Per Visit',
    'seasonal'  => 'Seasonal',
    'annual'    => 'Annual',
    'custom'    => 'Custom',
];

// ── Stats for info cards ───────────────────────────────────────────────────
$statsRow = $db->query("
    SELECT
        COUNT(*) AS active_count,
        SUM(CASE WHEN billing_cycle = 'monthly'  THEN billing_amount ELSE 0 END) AS monthly_mrr,
        SUM(CASE WHEN billing_cycle = 'annual'   THEN billing_amount / 12 ELSE 0 END) AS annual_monthly,
        SUM(CASE WHEN billing_cycle = 'per_visit' THEN billing_amount ELSE 0 END) AS per_visit_sum,
        AVG(billing_amount) AS avg_contract,
        MIN(start_date)     AS oldest_start
    FROM contracts
    WHERE status = 'active'
")->fetch(PDO::FETCH_ASSOC);

$activeMrr    = (float)($statsRow['monthly_mrr'] ?? 0) + (float)($statsRow['annual_monthly'] ?? 0);
$activeCount  = (int)($statsRow['active_count'] ?? 0);
$annualRun    = $activeMrr * 12;
$avgContract  = (float)($statsRow['avg_contract'] ?? 0);

// Paused contracts — revenue on hold
$pausedMrr = (float)$db->query("
    SELECT COALESCE(SUM(CASE WHEN billing_cycle='monthly' THEN billing_amount ELSE billing_amount/12 END),0)
    FROM contracts WHERE status='paused'
")->fetchColumn();

$pageTitle  = 'Contracts';
$activePage = 'contracts';
?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="mw-page-header">
              <div>
                  <h1 class="h3 mb-0">Contracts</h1>
                  <p class="text-muted mb-0">Service agreements linking billing to plans</p>
              </div>
              <div class="mw-header-actions">
                  <a href="contracts/create.php" class="btn btn-primary">
                      <i data-feather="plus" style="width:14px;height:14px;"></i> New Contract
                  </a>
              </div>
          </div>

          <!-- Stat Cards -->
          <div class="row mb-4">
              <div class="col-6 col-md-3 mb-3 mb-md-0">
                  <div class="card mw-ctr-stat-card">
                      <div class="card-body">
                          <div class="mw-ctr-stat-label">Monthly Revenue</div>
                          <div class="mw-ctr-stat-value">$<?php echo number_format($activeMrr, 0); ?></div>
                          <div class="mw-ctr-stat-sub"><?php echo $activeCount; ?> active contract<?php echo $activeCount !== 1 ? 's' : ''; ?></div>
                      </div>
                  </div>
              </div>
              <div class="col-6 col-md-3 mb-3 mb-md-0">
                  <div class="card mw-ctr-stat-card">
                      <div class="card-body">
                          <div class="mw-ctr-stat-label">Annual Run Rate</div>
                          <div class="mw-ctr-stat-value">$<?php echo number_format($annualRun, 0); ?></div>
                          <div class="mw-ctr-stat-sub">Based on active MRR × 12</div>
                      </div>
                  </div>
              </div>
              <div class="col-6 col-md-3 mb-3 mb-md-0">
                  <div class="card mw-ctr-stat-card">
                      <div class="card-body">
                          <div class="mw-ctr-stat-label">Avg Contract</div>
                          <div class="mw-ctr-stat-value">$<?php echo number_format($avgContract, 0); ?></div>
                          <div class="mw-ctr-stat-sub">Per billing cycle</div>
                      </div>
                  </div>
              </div>
              <div class="col-6 col-md-3">
                  <div class="card mw-ctr-stat-card <?php echo ($pausedMrr > 0) ? 'mw-ctr-stat-card--warn' : ''; ?>">
                      <div class="card-body">
                          <div class="mw-ctr-stat-label">Paused Revenue</div>
                          <div class="mw-ctr-stat-value">$<?php echo number_format($pausedMrr, 0); ?></div>
                          <div class="mw-ctr-stat-sub"><?php echo ($counts['paused'] ?? 0); ?> paused contract<?php echo ($counts['paused'] ?? 0) !== 1 ? 's' : ''; ?></div>
                      </div>
                  </div>
              </div>
          </div>

          <!-- Filter Tabs -->
          <div class="mw-filter-tabs mb-3">
              <?php
              $tabs = [
                  'all'       => 'All',
                  'active'    => 'Active',
                  'paused'    => 'Paused',
                  'expired'   => 'Expired',
                  'cancelled' => 'Cancelled',
              ];
              foreach ($tabs as $key => $label):
                  $count = $counts[$key] ?? 0;
                  $active = ($filterStatus === $key) ? ' mw-filter-tab--active' : '';
              ?>
                  <a href="?status=<?php echo $key; ?>" class="mw-filter-tab<?php echo $active; ?>">
                      <?php echo $label; ?>
                      <?php if ($count): ?><span class="mw-filter-tab-count"><?php echo $count; ?></span><?php endif; ?>
                  </a>
              <?php endforeach; ?>
          </div>

          <?php if (empty($contracts)): ?>
              <div class="mw-empty-state">
                  <i data-feather="pen-tool" class="mw-empty-icon"></i>
                  <h3>No contracts yet</h3>
                  <p>Accept a quote and convert it to a contract, or create one manually.</p>
                  <a href="contracts/create.php" class="btn btn-primary">New Contract</a>
              </div>
          <?php else: ?>
              <div class="card">
                  <div class="table-responsive">
                      <table class="table table-hover mb-0">
                          <thead>
                              <tr>
                                  <th>Contract</th>
                                  <th>Property</th>
                                  <th>Client</th>
                                  <th>Plans</th>
                                  <th>Billing</th>
                                  <th>Start Date</th>
                                  <th>Status</th>
                                  <th></th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php foreach ($contracts as $c): ?>
                                  <tr data-href="contracts/view.php?id=<?php echo (int)$c['id']; ?>">
                                      <td>
                                          <a href="contracts/view.php?id=<?php echo (int)$c['id']; ?>" class="font-weight-bold">
                                              <?php echo htmlspecialchars($c['contract_number']); ?>
                                          </a>
                                          <?php if ($c['title']): ?>
                                              <div class="text-muted small"><?php echo htmlspecialchars($c['title']); ?></div>
                                          <?php endif; ?>
                                      </td>
                                      <td>
                                          <?php echo htmlspecialchars($c['property_address']); ?><br>
                                          <span class="text-muted small"><?php echo htmlspecialchars($c['property_city']); ?></span>
                                      </td>
                                      <td>
                                          <?php echo htmlspecialchars(trim($c['first_name'] . ' ' . $c['last_name'])); ?>
                                          <?php if (!empty($c['company_name'])): ?>
                                              <div class="text-muted small"><?php echo htmlspecialchars($c['company_name']); ?></div>
                                          <?php endif; ?>
                                      </td>
                                      <td>
                                          <span class="mw-badge-status"><?php echo (int)$c['plan_count']; ?> plan<?php echo $c['plan_count'] != 1 ? 's' : ''; ?></span>
                                      </td>
                                      <td>
                                          <?php if ($c['billing_amount']): ?>
                                              <strong>$<?php echo number_format((float)$c['billing_amount'], 2); ?></strong>
                                              <span class="text-muted small d-block"><?php echo $billingCycleLabels[$c['billing_cycle']] ?? $c['billing_cycle']; ?></span>
                                          <?php else: ?>
                                              <span class="text-muted">—</span>
                                          <?php endif; ?>
                                      </td>
                                      <td><?php echo $c['start_date'] ? date('M j, Y', strtotime($c['start_date'])) : '—'; ?></td>
                                      <td><?php echo getStatusBadge($c['status'], 'contract'); ?></td>
                                      <td class="text-right">
                                          <a href="contracts/view.php?id=<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                      </td>
                                  </tr>
                              <?php endforeach; ?>
                          </tbody>
                      </table>
                  </div>
              </div>
          <?php endif; ?>

<?php include 'includes/appstack_footer.php'; ?>
