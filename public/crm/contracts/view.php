<?php
/**
 * Contract View — dashboard redesign
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.view');

$db = getDB();

$contractId = intval($_GET['id'] ?? 0);
if (!$contractId) {
    header('Location: ../contracts_appstack.php');
    exit;
}

$contract = getContractById($contractId);
if (!$contract) {
    $_SESSION['alert'] = ['type' => 'error', 'message' => 'Contract not found.'];
    header('Location: ../contracts_appstack.php');
    exit;
}

// ── POST: status / data changes ─────────────────────────────────────────────
$message     = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'pause_contract' && $contract['status'] === 'active') {
        $db->prepare("UPDATE contracts SET status = 'paused', updated_at = NOW() WHERE id = ?")
           ->execute([$contractId]);
        $message     = 'Contract paused.';
        $messageType = 'success';
        $contract    = getContractById($contractId);
    }

    if ($action === 'resume_contract' && $contract['status'] === 'paused') {
        $db->prepare("UPDATE contracts SET status = 'active', updated_at = NOW() WHERE id = ?")
           ->execute([$contractId]);
        $message     = 'Contract resumed.';
        $messageType = 'success';
        $contract    = getContractById($contractId);
    }

    if ($action === 'cancel_contract') {
        $db->prepare("UPDATE contracts SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
           ->execute([$contractId]);
        $message     = 'Contract cancelled.';
        $messageType = 'success';
        $contract    = getContractById($contractId);
    }

    if ($action === 'update_contract') {
        $data = [
            'title'                => trim($_POST['title'] ?? ''),
            'billing_cycle'        => $_POST['billing_cycle'] ?? 'monthly',
            'billing_amount'       => $_POST['billing_amount'] ?? '',
            'start_date'           => $_POST['start_date'] ?? '',
            'end_date'             => $_POST['end_date'] ?? '',
            'renewal_date'         => $_POST['renewal_date'] ?? '',
            'auto_renew'           => !empty($_POST['auto_renew']) ? 1 : 0,
            'renewal_increase_pct' => $_POST['renewal_increase_pct'] ?? '0',
            'notes'                => trim($_POST['notes'] ?? ''),
        ];
        $result = updateContract($contractId, $data, (int)$user['id']);
        if ($result['success']) {
            $contract    = getContractById($contractId);
            $message     = 'Contract updated successfully.';
            $messageType = 'success';
        } else {
            $message     = implode(' ', $result['errors']);
            $messageType = 'error';
        }
    }
}

// Flash messages from redirects
if (isset($_GET['created']))    { $message = 'Contract created successfully!'; $messageType = 'success'; }
if (isset($_GET['plan_added'])) { $message = 'Plan added to contract.';       $messageType = 'success'; }
if (isset($_GET['from']) && $_GET['from'] === 'quote') {
    $message = 'Contract already exists for this quote.';
    $messageType = 'info';
}

$plans          = getContractPlans($contractId);
$billingStats   = getContractBillingStats($contractId);
$recentInvoices = getContractRecentInvoices($contractId, 5);

// Billing reconcile
$plansAllocated  = array_sum(array_map(fn($p) => (float)($p['estimated_amount'] ?? 0), $plans));
$contractBilling = (float)($contract['billing_amount'] ?? 0);
$reconcileDiff   = $contractBilling - $plansAllocated;

$billingCycleLabels = [
    'monthly'   => 'Monthly',
    'per_visit' => 'Per Visit',
    'seasonal'  => 'Seasonal',
    'annual'    => 'Annual',
    'custom'    => 'Custom',
];
$invoiceTimingLabels = [
    'after_visit'  => 'After Visit',
    'end_of_month' => 'End of Month',
    'upfront'      => 'Upfront',
];

// Property border check
$hasPropCoords = !empty($contract['latitude']) && !empty($contract['longitude']);
$hasBorder     = false;
if ($contract['property_id'] && $hasPropCoords) {
    $bStmt = $db->prepare("
        SELECT id FROM job_geofences
        WHERE property_id = ? AND zone_type = 'arrival_border'
        LIMIT 1
    ");
    $bStmt->execute([$contract['property_id']]);
    $hasBorder = (bool)$bStmt->fetchColumn();
}
$firstPlanId = !empty($plans) ? (int)$plans[0]['id'] : 0;

// Billing progress bar
$totalBilled = (float)$billingStats['total_billed'];
$totalPaid   = (float)$billingStats['total_paid'];
$paidPct     = $totalBilled > 0 ? min(100, round(($totalPaid / $totalBilled) * 100)) : 0;
$barColor    = $paidPct >= 80 ? 'var(--mw-green)' : ($paidPct >= 40 ? 'var(--mw-orange)' : '#dc3545');

// Invoice status colours
$invStatusColors = [
    'paid'     => ['bg' => '#d4edda', 'color' => '#155724'],
    'overdue'  => ['bg' => '#f8d7da', 'color' => '#721c24'],
    'partial'  => ['bg' => '#fff3cd', 'color' => '#856404'],
    'sent'     => ['bg' => '#d1ecf1', 'color' => '#0c5460'],
    'viewed'   => ['bg' => '#d1ecf1', 'color' => '#0c5460'],
    'draft'    => ['bg' => '#e9ecef', 'color' => '#495057'],
    'cancelled'=> ['bg' => '#e9ecef', 'color' => '#6c757d'],
];

// Plan add URL
$addPlanUrl = $contract['quote_id']
    ? '../jobs/create-from-quote.php?quote_id=' . (int)$contract['quote_id'] . '&contract_id=' . $contractId
    : '../jobs/create.php?contract_id=' . $contractId . '&property_id=' . (int)$contract['property_id'] . '&contact_id=' . (int)$contract['contact_id'];

$csrfToken  = generateCSRFToken();
$pageTitle  = $contract['contract_number'] . ' — Contract';
$activePage = 'contracts';

$extraHead  = '<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">';
if ($hasPropCoords && !$hasBorder) {
    $extraHead .= '<link rel="stylesheet" href="/crm/js/leaflet/leaflet.min.css">';
    $extraHead .= '<script src="/crm/js/leaflet/leaflet.min.js"></script>';
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="../contracts_appstack.php" class="mw-back-link">&larr; All Contracts</a>

          <?php if ($message): ?>
              <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : ($messageType === 'info' ? 'info' : 'success'); ?> mb-3">
                  <?php echo htmlspecialchars($message); ?>
              </div>
          <?php endif; ?>

          <!-- ══════════════════════════════════════════════════════════════════
               HERO HEADER + KPI STRIP
               ══════════════════════════════════════════════════════════════════ -->
          <div class="mw-ctr-hero mb-4">

              <div class="mw-ctr-hero-top">
                  <div class="mw-ctr-hero-identity">
                      <div class="mw-ctr-hero-badges">
                          <?php echo getStatusBadge($contract['status'], 'contract'); ?>
                          <?php if ($hasPropCoords && $hasBorder): ?>
                              <span class="mw-border-ok mw-border-ok--compact">
                                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24"
                                       fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                      <polyline points="20 6 9 17 4 12"/>
                                  </svg>
                                  GPS active
                              </span>
                          <?php endif; ?>
                      </div>
                      <h1 class="mw-ctr-number"><?php echo htmlspecialchars($contract['contract_number']); ?></h1>
                      <?php if (!empty($contract['title'])): ?>
                          <div class="mw-ctr-hero-contract-title"><?php echo htmlspecialchars($contract['title']); ?></div>
                      <?php endif; ?>
                      <div class="mw-ctr-hero-meta">
                          <span>
                              <i data-feather="user" style="width:12px;height:12px;vertical-align:-1px;"></i>
                              <?php echo htmlspecialchars(trim($contract['first_name'] . ' ' . $contract['last_name'])); ?>
                          </span>
                          <span class="mw-ctr-hero-meta-sep">·</span>
                          <span>
                              <i data-feather="map-pin" style="width:12px;height:12px;vertical-align:-1px;"></i>
                              <?php echo htmlspecialchars($contract['property_address'] . ', ' . $contract['property_city']); ?>
                          </span>
                          <span class="mw-ctr-hero-meta-sep">·</span>
                          <span>Started <?php echo $contract['start_date'] ? date('M j, Y', strtotime($contract['start_date'])) : '—'; ?></span>
                      </div>
                  </div>

                  <div class="mw-ctr-hero-actions">
                      <a href="<?php echo $addPlanUrl; ?>" class="btn btn-light btn-sm">
                          <i data-feather="plus" style="width:12px;height:12px;"></i> Add Plan
                      </a>
                      <?php if (in_array($contract['status'], ['active', 'paused'])): ?>
                          <button type="button" class="btn btn-outline-light btn-sm"
                                  data-toggle="modal" data-target="#editContractModal">
                              <i data-feather="edit-2" style="width:12px;height:12px;"></i> Edit
                          </button>
                      <?php endif; ?>
                      <?php if ($contract['status'] === 'active'): ?>
                          <form method="POST" class="d-inline">
                              <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                              <button type="submit" name="action" value="pause_contract" class="btn btn-warning btn-sm">Pause</button>
                          </form>
                      <?php elseif ($contract['status'] === 'paused'): ?>
                          <form method="POST" class="d-inline">
                              <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                              <button type="submit" name="action" value="resume_contract" class="btn btn-success btn-sm">Resume</button>
                          </form>
                      <?php endif; ?>
                      <?php if (in_array($contract['status'], ['active', 'paused'])): ?>
                          <form method="POST" class="d-inline"
                                onsubmit="return confirm('Cancel this contract? Plans will remain but billing stops.')">
                              <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                              <button type="submit" name="action" value="cancel_contract"
                                      class="btn btn-sm btn-outline-light" style="color:#fca5a5;border-color:rgba(252,165,165,.4);">Cancel</button>
                          </form>
                      <?php endif; ?>
                  </div>
              </div><!-- /hero-top -->

              <!-- ── KPI Strip ─────────────────────────────────────────────── -->
              <div class="mw-ctr-kpi-strip">

                  <div class="mw-ctr-kpi-cell mw-ctr-kpi-cell--good">
                      <div class="mw-ctr-kpi-cell-label">Plans</div>
                      <div class="mw-ctr-kpi-cell-value"><?php echo count($plans); ?></div>
                  </div>

                  <div class="mw-ctr-kpi-cell">
                      <div class="mw-ctr-kpi-cell-label">Billing Rate</div>
                      <div class="mw-ctr-kpi-cell-value">
                          <?php if ($contractBilling > 0): ?>
                              $<?php echo number_format($contractBilling, 0); ?>
                              <span class="mw-ctr-kpi-cell-sub"><?php echo $billingCycleLabels[$contract['billing_cycle']] ?? ''; ?></span>
                          <?php else: ?>
                              <span style="color:rgba(255,255,255,.3);">—</span>
                          <?php endif; ?>
                      </div>
                  </div>

                  <div class="mw-ctr-kpi-cell">
                      <div class="mw-ctr-kpi-cell-label">Total Billed</div>
                      <div class="mw-ctr-kpi-cell-value">
                          <?php if ($totalBilled > 0): ?>
                              $<?php echo number_format($totalBilled, 0); ?>
                          <?php else: ?>
                              <span style="color:rgba(255,255,255,.3);">—</span>
                          <?php endif; ?>
                      </div>
                  </div>

                  <div class="mw-ctr-kpi-cell<?php echo $totalPaid > 0 ? ' mw-ctr-kpi-cell--good' : ''; ?>">
                      <div class="mw-ctr-kpi-cell-label">Collected</div>
                      <div class="mw-ctr-kpi-cell-value">
                          <?php if ($totalPaid > 0): ?>
                              $<?php echo number_format($totalPaid, 0); ?>
                              <?php if ($totalBilled > 0): ?>
                                  <span class="mw-ctr-kpi-cell-sub"><?php echo $paidPct; ?>%</span>
                              <?php endif; ?>
                          <?php else: ?>
                              <span style="color:rgba(255,255,255,.3);">—</span>
                          <?php endif; ?>
                      </div>
                  </div>

                  <div class="mw-ctr-kpi-cell<?php echo (float)$billingStats['overdue_amount'] > 0 ? ' mw-ctr-kpi-cell--alert' : ((float)$billingStats['total_outstanding'] > 0 ? ' mw-ctr-kpi-cell--warn' : ''); ?>">
                      <div class="mw-ctr-kpi-cell-label">Outstanding</div>
                      <div class="mw-ctr-kpi-cell-value">
                          <?php if ((float)$billingStats['total_outstanding'] > 0): ?>
                              $<?php echo number_format((float)$billingStats['total_outstanding'], 0); ?>
                              <?php if ((float)$billingStats['overdue_amount'] > 0): ?>
                                  <span class="mw-ctr-kpi-cell-sub" style="color:#fca5a5;">Overdue</span>
                              <?php endif; ?>
                          <?php else: ?>
                              <span style="color:#86efac;">Clear</span>
                          <?php endif; ?>
                      </div>
                  </div>

                  <div class="mw-ctr-kpi-cell">
                      <div class="mw-ctr-kpi-cell-label">Next Service</div>
                      <div class="mw-ctr-kpi-cell-value" style="font-size:1.1rem;">
                          <?php if ($billingStats['next_visit_date']): ?>
                              <?php echo date('M j', strtotime($billingStats['next_visit_date'])); ?>
                              <span class="mw-ctr-kpi-cell-sub"><?php echo date('Y', strtotime($billingStats['next_visit_date'])); ?></span>
                          <?php else: ?>
                              <span style="color:rgba(255,255,255,.3);">—</span>
                          <?php endif; ?>
                      </div>
                  </div>

              </div><!-- /kpi-strip -->

          </div><!-- /hero -->

          <?php if ($hasPropCoords && !$hasBorder): ?>
          <!-- ── No-border prompt (compact, below hero) ────────────────────── -->
          <div class="mw-border-prompt mb-4">
              <div class="mw-border-prompt-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
                       fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/>
                      <line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                  </svg>
              </div>
              <div class="mw-border-prompt-body">
                  <div class="mw-border-prompt-title">No property border drawn</div>
                  <div class="mw-border-prompt-text">
                      Crew cannot auto clock-in without a property boundary. Draw it once — it activates GPS tracking for every plan at this property.
                  </div>
              </div>
              <button type="button" class="mw-border-prompt-btn" data-toggle="modal" data-target="#ctrBorderModal">
                  Draw Border Now
              </button>
          </div>
          <?php endif; ?>

          <!-- ══════════════════════════════════════════════════════════════════
               MAIN CONTENT — two columns
               ══════════════════════════════════════════════════════════════════ -->
          <div class="row">

              <!-- ── LEFT: Service Plans ──────────────────────────────────────── -->
              <div class="col-lg-7 mb-4">
                  <div class="card h-100">
                      <div class="card-header d-flex align-items-center justify-content-between">
                          <h5 class="card-title mb-0">
                              <i data-feather="briefcase" style="width:15px;height:15px;vertical-align:-2px;margin-right:6px;"></i>
                              Service Plans
                          </h5>
                          <a href="<?php echo $addPlanUrl; ?>" class="btn btn-sm btn-outline-primary">
                              <i data-feather="plus" style="width:12px;height:12px;"></i> Add Plan
                          </a>
                      </div>
                      <div class="card-body p-0">
                          <?php if (empty($plans)): ?>
                              <div class="text-center p-5 text-muted">
                                  <i data-feather="inbox" style="width:32px;height:32px;opacity:.3;display:block;margin:0 auto 12px;"></i>
                                  <p class="mb-3">No plans yet.</p>
                                  <a href="<?php echo $addPlanUrl; ?>" class="btn btn-primary btn-sm">
                                      <i data-feather="plus" style="width:13px;height:13px;"></i> Create First Plan
                                  </a>
                              </div>
                          <?php else: ?>
                              <div class="table-responsive">
                                  <table class="table table-hover mb-0 mw-ctr-plans-table">
                                      <thead>
                                          <tr>
                                              <th>Plan</th>
                                              <th>Service</th>
                                              <th>Schedule</th>
                                              <th>Visits</th>
                                              <th>Next</th>
                                              <th>Status</th>
                                              <th></th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($plans as $plan): ?>
                                              <tr>
                                                  <td>
                                                      <a href="../jobs/view.php?id=<?php echo (int)$plan['id']; ?>" class="font-weight-bold text-dark">
                                                          <?php echo htmlspecialchars($plan['plan_number']); ?>
                                                      </a>
                                                      <?php if ($plan['title']): ?>
                                                          <div class="text-muted" style="font-size:.78rem;"><?php echo htmlspecialchars($plan['title']); ?></div>
                                                      <?php endif; ?>
                                                  </td>
                                                  <td>
                                                      <span class="mw-badge-status" style="background:var(--mw-light);color:var(--mw-dark);">
                                                          <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $plan['service_type']))); ?>
                                                      </span>
                                                  </td>
                                                  <td class="text-nowrap">
                                                      <?php if ($plan['is_recurring']): ?>
                                                          <span class="text-success" style="font-size:.85rem;">
                                                              <?php echo ucfirst($plan['recurrence_pattern'] ?? 'recurring'); ?>
                                                          </span>
                                                      <?php else: ?>
                                                          <span class="text-muted" style="font-size:.85rem;">One-time</span>
                                                      <?php endif; ?>
                                                  </td>
                                                  <td class="text-nowrap" style="font-size:.9rem;">
                                                      <span style="font-weight:600;"><?php echo (int)$plan['visits_completed']; ?></span>
                                                      <span class="text-muted">/ <?php echo (int)$plan['total_visits']; ?></span>
                                                  </td>
                                                  <td class="text-nowrap" style="font-size:.85rem;">
                                                      <?php if ($plan['next_visit_date']): ?>
                                                          <?php echo date('M j', strtotime($plan['next_visit_date'])); ?>
                                                      <?php else: ?>
                                                          <span class="text-muted">—</span>
                                                      <?php endif; ?>
                                                  </td>
                                                  <td><?php echo getStatusBadge($plan['status'], 'plan'); ?></td>
                                                  <td class="text-right">
                                                      <a href="../jobs/view.php?id=<?php echo (int)$plan['id']; ?>"
                                                         class="btn btn-sm btn-outline-secondary">View</a>
                                                  </td>
                                              </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>

                              <?php if ($contractBilling > 0): ?>
                              <div class="mw-contract-reconcile">
                                  <div class="mw-reconcile-item">
                                      <span class="mw-reconcile-label">Plans allocated</span>
                                      <span class="mw-reconcile-value">$<?php echo number_format($plansAllocated, 2); ?></span>
                                  </div>
                                  <div class="mw-reconcile-sep"></div>
                                  <div class="mw-reconcile-item">
                                      <span class="mw-reconcile-label">Contract <?php echo htmlspecialchars($billingCycleLabels[$contract['billing_cycle']] ?? ''); ?></span>
                                      <span class="mw-reconcile-value">$<?php echo number_format($contractBilling, 2); ?></span>
                                  </div>
                                  <div class="mw-reconcile-sep"></div>
                                  <div class="mw-reconcile-item">
                                      <span class="mw-reconcile-label"><?php echo $reconcileDiff >= 0 ? 'Unallocated' : 'Over by'; ?></span>
                                      <span class="mw-reconcile-value <?php echo abs($reconcileDiff) < 0.01 ? 'mw-reconcile--balanced' : ($reconcileDiff > 0 ? 'mw-reconcile--under' : 'mw-reconcile--over'); ?>">
                                          <?php echo $reconcileDiff >= 0 ? '$' . number_format($reconcileDiff, 2) : '-$' . number_format(abs($reconcileDiff), 2); ?>
                                      </span>
                                  </div>
                              </div>
                              <?php endif; ?>
                          <?php endif; ?>
                      </div>
                  </div>
              </div><!-- /col left -->

              <!-- ── RIGHT: Billing + Details ─────────────────────────────────── -->
              <div class="col-lg-5 mb-4">

                  <!-- Billing Summary -->
                  <div class="card mb-3">
                      <div class="card-header d-flex align-items-center justify-content-between">
                          <h5 class="card-title mb-0">
                              <i data-feather="dollar-sign" style="width:15px;height:15px;vertical-align:-2px;margin-right:6px;"></i>
                              Billing Summary
                          </h5>
                          <?php if (!empty($contract['invoice_timing'])): ?>
                              <span class="mw-badge-status" style="background:var(--mw-light);color:var(--mw-dark);">
                                  <?php echo htmlspecialchars($invoiceTimingLabels[$contract['invoice_timing']] ?? $contract['invoice_timing']); ?>
                              </span>
                          <?php endif; ?>
                      </div>
                      <div class="card-body">
                          <div class="mw-ctr-billing-trio mb-3">
                              <div class="mw-ctr-billing-trio-item">
                                  <div class="mw-ctr-billing-trio-label">Total Billed</div>
                                  <div class="mw-ctr-billing-trio-value">$<?php echo number_format($totalBilled, 2); ?></div>
                              </div>
                              <div class="mw-ctr-billing-trio-item">
                                  <div class="mw-ctr-billing-trio-label">Collected</div>
                                  <div class="mw-ctr-billing-trio-value" style="color:var(--mw-green);">$<?php echo number_format($totalPaid, 2); ?></div>
                              </div>
                              <div class="mw-ctr-billing-trio-item">
                                  <div class="mw-ctr-billing-trio-label">Outstanding</div>
                                  <div class="mw-ctr-billing-trio-value" style="<?php echo (float)$billingStats['total_outstanding'] > 0 ? 'color:#dc3545;' : 'color:#aaa;'; ?>">
                                      $<?php echo number_format((float)$billingStats['total_outstanding'], 2); ?>
                                  </div>
                              </div>
                          </div>

                          <?php if ($totalBilled > 0): ?>
                          <div class="mw-ctr-billing-bar mb-2">
                              <div class="mw-ctr-billing-bar-fill" style="width:<?php echo $paidPct; ?>%;background:<?php echo $barColor; ?>;"></div>
                          </div>
                          <div style="font-size:.78rem;color:#888;margin-bottom:.5rem;">
                              <?php echo $paidPct; ?>% collected
                              <?php if ((float)$billingStats['overdue_amount'] > 0): ?>
                                  &nbsp;<span class="mw-ctr-overdue-badge">$<?php echo number_format((float)$billingStats['overdue_amount'], 2); ?> overdue</span>
                              <?php endif; ?>
                          </div>
                          <?php endif; ?>

                          <?php if ((int)$billingStats['invoice_count'] > 0): ?>
                          <div class="mw-ctr-inv-summary">
                              <?php if ((int)$billingStats['paid_count'] > 0): ?>
                                  <span class="mw-ctr-inv-pill mw-ctr-inv-pill--paid"><?php echo (int)$billingStats['paid_count']; ?> paid</span>
                              <?php endif; ?>
                              <?php if ((int)$billingStats['open_count'] > 0): ?>
                                  <span class="mw-ctr-inv-pill mw-ctr-inv-pill--open"><?php echo (int)$billingStats['open_count']; ?> open</span>
                              <?php endif; ?>
                              <span class="mw-ctr-inv-pill mw-ctr-inv-pill--total"><?php echo (int)$billingStats['invoice_count']; ?> total</span>
                          </div>
                          <?php endif; ?>

                          <?php if ($billingStats['last_visit_date']): ?>
                          <div class="mw-ctr-visit-row mt-3">
                              <span class="mw-ctr-visit-label">Last service</span>
                              <span class="mw-ctr-visit-val"><?php echo date('M j, Y', strtotime($billingStats['last_visit_date'])); ?></span>
                          </div>
                          <?php endif; ?>
                          <?php if ($billingStats['next_visit_date']): ?>
                          <div class="mw-ctr-visit-row">
                              <span class="mw-ctr-visit-label">Next service</span>
                              <span class="mw-ctr-visit-val" style="color:var(--mw-green);font-weight:600;"><?php echo date('M j, Y', strtotime($billingStats['next_visit_date'])); ?></span>
                          </div>
                          <?php endif; ?>
                      </div>
                  </div><!-- /billing summary -->

                  <!-- Recent Invoices -->
                  <?php if (!empty($recentInvoices)): ?>
                  <div class="card mb-3">
                      <div class="card-header d-flex align-items-center justify-content-between">
                          <h5 class="card-title mb-0">
                              <i data-feather="file-text" style="width:15px;height:15px;vertical-align:-2px;margin-right:6px;"></i>
                              Recent Invoices
                          </h5>
                      </div>
                      <div class="card-body p-0">
                          <?php foreach ($recentInvoices as $inv): ?>
                              <?php
                              $sc = $invStatusColors[$inv['status']] ?? ['bg'=>'#e9ecef','color'=>'#495057'];
                              ?>
                              <div class="mw-ctr-inv-row">
                                  <div class="mw-ctr-inv-num">
                                      <a href="../invoices/view.php?id=<?php echo (int)$inv['id']; ?>">
                                          <?php echo htmlspecialchars($inv['invoice_number']); ?>
                                      </a>
                                      <span class="mw-ctr-inv-date"><?php echo $inv['issue_date'] ? date('M j, Y', strtotime($inv['issue_date'])) : '—'; ?></span>
                                  </div>
                                  <div class="mw-ctr-inv-right">
                                      <span class="mw-ctr-inv-amount">$<?php echo number_format((float)$inv['total'], 2); ?></span>
                                      <span class="mw-ctr-inv-status"
                                            style="background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>;">
                                          <?php echo ucfirst($inv['status']); ?>
                                      </span>
                                  </div>
                              </div>
                          <?php endforeach; ?>
                          <div class="mw-ctr-inv-footer">
                              <a href="../invoices/index.php" style="color:var(--mw-green);font-weight:600;font-size:.82rem;">
                                  View all invoices &rarr;
                              </a>
                          </div>
                      </div>
                  </div>
                  <?php endif; ?>

                  <!-- Contract Details -->
                  <div class="card">
                      <div class="card-header"><h5 class="card-title mb-0">Contract Details</h5></div>
                      <div class="card-body p-0">
                          <dl class="mw-ctr-detail-list">
                              <dt>Property</dt>
                              <dd>
                                  <?php echo htmlspecialchars($contract['property_address']); ?><br>
                                  <span class="text-muted"><?php echo htmlspecialchars($contract['property_city']); ?></span>
                              </dd>
                              <dt>Client</dt>
                              <dd>
                                  <?php echo htmlspecialchars(trim($contract['first_name'] . ' ' . $contract['last_name'])); ?>
                                  <?php if ($contract['contact_email']): ?>
                                      <br><a href="mailto:<?php echo htmlspecialchars($contract['contact_email']); ?>" class="text-muted small"><?php echo htmlspecialchars($contract['contact_email']); ?></a>
                                  <?php endif; ?>
                                  <?php if ($contract['contact_phone']): ?>
                                      <br><a href="tel:<?php echo htmlspecialchars($contract['contact_phone']); ?>" class="text-muted small"><?php echo htmlspecialchars($contract['contact_phone']); ?></a>
                                  <?php endif; ?>
                              </dd>
                              <?php if ($contract['quote_id']): ?>
                              <dt>Source Quote</dt>
                              <dd>
                                  <a href="../quotes/view.php?id=<?php echo (int)$contract['quote_id']; ?>">
                                      <?php echo htmlspecialchars($contract['quote_number'] ?? 'View Quote'); ?>
                                  </a>
                              </dd>
                              <?php endif; ?>
                              <dt>Created By</dt>
                              <dd><?php echo htmlspecialchars($contract['created_by_name'] ?? '—'); ?></dd>
                              <dt>Created</dt>
                              <dd><?php echo date('M j, Y', strtotime($contract['created_at'])); ?></dd>
                              <dt>Auto-Renew</dt>
                              <dd>
                                  <?php if ($contract['auto_renew'] ?? 0): ?>
                                      <span style="color:var(--mw-green);font-weight:600;">Enabled</span>
                                      <?php if (($contract['renewal_increase_pct'] ?? 0) > 0): ?>
                                          <span class="text-muted"> — +<?php echo number_format((float)$contract['renewal_increase_pct'], 1); ?>%</span>
                                      <?php endif; ?>
                                  <?php else: ?>
                                      <span class="text-muted">Off</span>
                                  <?php endif; ?>
                              </dd>
                              <?php if ($contract['renewal_date'] || $contract['end_date']): ?>
                              <dt><?php echo ($contract['auto_renew'] ?? 0) ? 'Next Renewal' : ($contract['renewal_date'] ? 'Renewal Date' : 'End Date'); ?></dt>
                              <dd><?php $d = $contract['renewal_date'] ?: $contract['end_date']; echo $d ? date('M j, Y', strtotime($d)) : '—'; ?></dd>
                              <?php endif; ?>
                              <?php if (!empty($contract['notes'])): ?>
                              <dt>Notes</dt>
                              <dd><?php echo nl2br(htmlspecialchars($contract['notes'])); ?></dd>
                              <?php endif; ?>
                          </dl>
                      </div>
                  </div>

              </div><!-- /col right -->

          </div><!-- /row -->


<!-- ══════════════════════════════════════════════════════
     EDIT CONTRACT MODAL
     ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="editContractModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update_contract">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i data-feather="edit-2" style="width:16px;height:16px;vertical-align:-2px;margin-right:6px;"></i>
                        Edit Contract
                    </h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">

                    <div class="row">
                        <div class="col-sm-8">
                            <div class="form-group">
                                <label class="form-label">Contract Title</label>
                                <input type="text" name="title" class="form-control"
                                       value="<?php echo htmlspecialchars($contract['title'] ?? ''); ?>"
                                       placeholder="e.g. Lawn Care – 2026 Season">
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label class="form-label">Billing Cycle</label>
                                <select name="billing_cycle" class="form-control">
                                    <?php
                                    $cycleOpts = ['monthly' => 'Monthly', 'per_visit' => 'Per Visit', 'seasonal' => 'Seasonal', 'annual' => 'Annual', 'custom' => 'Custom'];
                                    foreach ($cycleOpts as $v => $l): ?>
                                        <option value="<?php echo $v; ?>"<?php echo ($contract['billing_cycle'] ?? '') === $v ? ' selected' : ''; ?>>
                                            <?php echo $l; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label class="form-label">Billing Amount</label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                    <input type="number" name="billing_amount" class="form-control"
                                           min="0" step="0.01"
                                           value="<?php echo htmlspecialchars($contract['billing_amount'] ?? ''); ?>"
                                           placeholder="0.00">
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control"
                                       value="<?php echo htmlspecialchars($contract['start_date'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label class="form-label">End Date <small class="text-muted">(optional)</small></label>
                                <input type="date" name="end_date" class="form-control"
                                       value="<?php echo htmlspecialchars($contract['end_date'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label class="form-label">Renewal Date</label>
                                <input type="date" name="renewal_date" class="form-control"
                                       value="<?php echo htmlspecialchars($contract['renewal_date'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mw-contract-toggle mb-3">
                        <label class="mw-contract-toggle-label">
                            <input type="checkbox" name="auto_renew" value="1"<?php echo ($contract['auto_renew'] ?? 0) ? ' checked' : ''; ?>>
                            <span class="mw-contract-toggle-track"></span>
                        </label>
                        <div class="mw-contract-toggle-text">
                            <strong>Auto-renew</strong>
                            <small>Automatically extend this contract on the renewal date</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Renewal Price Increase <small class="text-muted">(% — 0 for no increase)</small></label>
                        <div class="input-group" style="max-width:180px;">
                            <input type="number" name="renewal_increase_pct" class="form-control"
                                   min="0" max="100" step="0.1"
                                   value="<?php echo htmlspecialchars($contract['renewal_increase_pct'] ?? '0'); ?>">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="Internal notes about this contract…"><?php echo htmlspecialchars($contract['notes'] ?? ''); ?></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($hasPropCoords && !$hasBorder): ?>
<!-- ══════════════════════════════════════════════════════
     PROPERTY BORDER DRAW MODAL
     ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="ctrBorderModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog mw-modal-fullscreen" role="document">
        <div class="modal-content">

            <div class="modal-header py-2 px-3">
                <h5 class="modal-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="var(--mw-orange)" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px;">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    Draw Property Border
                    <span class="text-muted font-weight-normal small ml-2">
                        <?php echo htmlspecialchars($contract['property_address'] . ', ' . $contract['property_city']); ?>
                    </span>
                </h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>

            <div style="flex:1;position:relative;overflow:hidden;">
                <div id="ctr-border-map" style="position:absolute;inset:0;width:100%;height:100%;"></div>
            </div>

            <div class="modal-footer py-2 px-3 d-flex align-items-center" style="flex-shrink:0;gap:8px;">
                <div id="ctr-hint" class="mw-wz-hint mw-wz-hint--info flex-grow-1 mr-2"
                     style="border-radius:5px;margin:0;">
                    <span id="ctr-hint-text">Click <strong>Draw Border</strong> then click the map to trace the property boundary.</span>
                </div>
                <div class="flex-shrink-0 d-flex" style="gap:6px;">
                    <button class="btn btn-sm btn-outline-secondary" id="ctr-cancel-btn"
                            style="display:none;" onclick="ctrCancelDraw()">Cancel Draw</button>
                    <button class="btn btn-sm btn-outline-success" id="ctr-finish-btn"
                            style="display:none;" onclick="ctrFinishDraw()" disabled>&#10003; Finish Drawing</button>
                    <button class="btn btn-sm btn-outline-primary" id="ctr-draw-btn"
                            onclick="ctrStartDraw()" disabled>Draw Border</button>
                    <button class="btn btn-sm btn-success" id="ctr-save-btn"
                            style="display:none;" onclick="ctrSave()">
                        <span id="ctr-save-spinner" style="display:none;">&#8987; </span>Save Border
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="/crm/js/geofence/geofence-manager.js?v=3"></script>
<script>
(function() {
    var CTR_PROP_ID  = <?php echo (int)$contract['property_id']; ?>;
    var CTR_PROP_LAT = <?php echo (float)($contract['latitude']  ?? 49.2827); ?>;
    var CTR_PROP_LNG = <?php echo (float)($contract['longitude'] ?? -123.1207); ?>;
    var CTR_CSRF     = <?php echo json_encode(generateCSRFToken()); ?>;
    var CTR_API      = '/crm/api/geofence.php';

    var ctrMgr        = null;
    var ctrRing       = null;
    var ctrIsSaving   = false;
    var ctrVertices   = 0;

    document.addEventListener('DOMContentLoaded', function() {
        $('#ctrBorderModal').on('shown.bs.modal', function() { ctrInitMap(); });
        $('#ctrBorderModal').on('hidden.bs.modal', function() {
            if (ctrMgr) { ctrMgr.destroy(); ctrMgr = null; }
            ctrRing     = null;
            ctrVertices = 0;
        });
    });

    function ctrInitMap() {
        if (ctrMgr) { ctrMgr.destroy(); ctrMgr = null; }
        ctrMgr = new GeofenceManager({
            mapContainer: 'ctr-border-map',
            apiBase:      CTR_API,
            csrfToken:    CTR_CSRF,
            planId:       null,
            mode:         'edit',
            center:       [CTR_PROP_LAT, CTR_PROP_LNG],
            zoom:         18,
            strokeColor:  '#e85d04',
            fillColor:    '#e85d04',
            onDraw: function(ring) {
                ctrRing = ring;
                document.getElementById('ctr-draw-btn').style.display   = 'inline-flex';
                document.getElementById('ctr-cancel-btn').style.display  = 'none';
                document.getElementById('ctr-finish-btn').style.display  = 'none';
                document.getElementById('ctr-save-btn').style.display    = 'inline-flex';
                ctrSetHint('Border traced (' + ring.length + ' points). Click <strong>Save Border</strong> to activate auto clock-in.', 'success');
            },
        });
        ctrMgr.init();

        if (ctrMgr._map) {
            ctrMgr._map.on('click', function() {
                if (ctrVertices >= 0) {
                    ctrVertices++;
                    var fBtn = document.getElementById('ctr-finish-btn');
                    if (fBtn) fBtn.disabled = (ctrVertices < 3);
                }
            });
        }

        document.getElementById('ctr-draw-btn').disabled = false;
        ctrSetHint('Click <strong>Draw Border</strong> then click the map to trace the property boundary. Double-click to close the shape.', 'info');
    }

    window.ctrStartDraw = function() {
        if (!ctrMgr) return;
        ctrRing     = null;
        ctrVertices = 0;
        ctrMgr.startDraw();
        document.getElementById('ctr-draw-btn').style.display   = 'none';
        document.getElementById('ctr-cancel-btn').style.display  = 'inline-flex';
        document.getElementById('ctr-finish-btn').style.display  = 'inline-flex';
        document.getElementById('ctr-save-btn').style.display    = 'none';
        document.getElementById('ctr-finish-btn').disabled = true;
        ctrSetHint('Click map to add corner points. Double-click (or Finish) to close the shape.', 'draw');
    };

    window.ctrFinishDraw = function() { if (ctrMgr) ctrMgr.finishDraw(); };

    window.ctrCancelDraw = function() {
        if (!ctrMgr) return;
        ctrVertices = 0;
        ctrMgr.cancelDraw();
        ctrMgr.setPolygon([]);
        document.getElementById('ctr-draw-btn').style.display   = 'inline-flex';
        document.getElementById('ctr-cancel-btn').style.display  = 'none';
        document.getElementById('ctr-finish-btn').style.display  = 'none';
        document.getElementById('ctr-save-btn').style.display    = 'none';
        ctrSetHint('Cancelled. Click <strong>Draw Border</strong> to try again.', 'info');
    };

    window.ctrSave = function() {
        if (!ctrRing || ctrIsSaving) return;
        ctrIsSaving = true;
        var spinner = document.getElementById('ctr-save-spinner');
        var saveBtn = document.getElementById('ctr-save-btn');
        if (spinner) spinner.style.display = 'inline';
        if (saveBtn) saveBtn.disabled = true;
        ctrSetHint('Saving property border…', 'info');

        fetch(CTR_API, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify({
                action:      'save_zone',
                csrf_token:  CTR_CSRF,
                property_id: CTR_PROP_ID,
                zone_type:   'arrival_border',
                plan_id:     null,
                ring:        ctrRing,
                label:       null,
            }),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) throw new Error(data.error || 'Save failed');
            window.location.reload();
        })
        .catch(function(err) {
            ctrIsSaving = false;
            if (spinner) spinner.style.display = 'none';
            if (saveBtn) saveBtn.disabled = false;
            ctrSetHint('Save failed: ' + err.message, 'error');
        });
    };

    function ctrSetHint(html, variant) {
        var el   = document.getElementById('ctr-hint');
        var span = document.getElementById('ctr-hint-text');
        if (!el || !span) return;
        span.innerHTML = html;
        el.className = 'mw-wz-hint mw-wz-hint--' + (variant || 'info') + ' flex-grow-1 mr-2';
        el.style.borderRadius = '5px';
        el.style.margin = '0';
    }
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
