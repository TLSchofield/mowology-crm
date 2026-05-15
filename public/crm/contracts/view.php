<?php
/**
 * Contract View
 * Shows contract details, billing terms, and all child plans.
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

// ── POST: status changes ─────────────────────────────────────────────────
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
            $plans       = getContractPlans($contractId);
            $message     = 'Contract updated successfully.';
            $messageType = 'success';
        } else {
            $message     = implode(' ', $result['errors']);
            $messageType = 'error';
        }
    }
}

// Flash message from redirect
if (isset($_GET['created']))   { $message = 'Contract created successfully!'; $messageType = 'success'; }
if (isset($_GET['plan_added'])) { $message = 'Plan added to contract.'; $messageType = 'success'; }
if (isset($_GET['from']) && $_GET['from'] === 'quote') { $message = 'Contract already exists for this quote.'; $messageType = 'info'; }

$plans = getContractPlans($contractId);

// Reconciliation: sum of plan estimated_amounts vs contract billing_amount
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

// ── Property border check ─────────────────────────────────────────────────
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

$csrfToken  = generateCSRFToken();
$pageTitle  = $contract['contract_number'] . ' — Contract';
$activePage = 'contracts';

// Load Leaflet if we'll show the border draw modal
if ($hasPropCoords && !$hasBorder) {
    $extraHead  = '<link rel="stylesheet" href="/crm/js/leaflet/leaflet.min.css">';
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

          <?php if ($hasPropCoords && !$hasBorder): ?>
          <!-- ── Property Border Setup Card ──────────────────────────────────────── -->
          <div class="mw-bsetup-card mb-4">
              <div class="mw-bsetup-visual" aria-hidden="true">
                  <div class="mw-bsetup-scan-ring" style="position:relative;width:72px;height:72px;flex-shrink:0;">
                      <svg class="mw-bsetup-svg" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg"
                           width="72" height="72" style="width:72px;height:72px;display:block;"
                           fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                          <circle cx="32" cy="32" r="28" stroke-width="1.5" stroke-dasharray="5 3" opacity="0.45"/>
                          <circle cx="32" cy="32" r="19" stroke-width="1.5" opacity="0.6"/>
                          <path d="M32 14 C26 14 20 19.4 20 26.2 C20 34.5 32 50 32 50 C32 50 44 34.5 44 26.2 C44 19.4 38 14 32 14Z"
                                stroke-width="2" fill="rgba(232,93,4,0.12)"/>
                          <circle cx="32" cy="26" r="4" stroke-width="2" fill="rgba(232,93,4,0.25)"/>
                          <line x1="32" y1="4"  x2="32" y2="12" stroke-width="1.5" opacity="0.5"/>
                          <line x1="32" y1="52" x2="32" y2="60" stroke-width="1.5" opacity="0.5"/>
                          <line x1="4"  y1="32" x2="12" y2="32" stroke-width="1.5" opacity="0.5"/>
                          <line x1="52" y1="32" x2="60" y2="32" stroke-width="1.5" opacity="0.5"/>
                      </svg>
                      <div class="mw-bsetup-scan-line" aria-hidden="true"></div>
                  </div>
              </div>
              <div class="mw-bsetup-body">
                  <div class="mw-bsetup-eyebrow">GPS Boundary Required</div>
                  <h6 class="mw-bsetup-title">No property border drawn</h6>
                  <p class="mw-bsetup-text">
                      Crew cannot auto clock-in without a property boundary.
                      Draw it once &mdash; GPS tracking activates for every plan at this property.
                  </p>
                  <div class="mw-bsetup-actions">
                      <button type="button" class="mw-bsetup-btn" data-toggle="modal" data-target="#ctrBorderModal">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                              <polygon points="3 11 22 2 13 21 11 13 3 11"/>
                          </svg>
                          Draw Border Now
                      </button>
                      <span class="mw-bsetup-badge-inactive">
                          <span class="mw-bsetup-dot" aria-hidden="true"></span>
                          Auto clock-in inactive
                      </span>
                  </div>
              </div>
          </div>

          <?php elseif ($hasPropCoords && $hasBorder): ?>
          <!-- ── Property Border Confirmed ───────────────────────────────────────── -->
          <div class="mw-bconfirmed mb-3">
              <div class="mw-bconfirmed-icon" aria-hidden="true">
                  <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                       stroke-linecap="round" stroke-linejoin="round">
                      <polygon points="16,4 27,10 27,22 16,28 5,22 5,10"
                               fill="rgba(45,134,89,0.12)" stroke="var(--mw-green)" stroke-width="1.5"
                               stroke-dasharray="3 2"/>
                      <polyline points="10,16 14,20 22,12" stroke="var(--mw-green)" stroke-width="2.5"/>
                  </svg>
              </div>
              <div class="mw-bconfirmed-body">
                  <div class="mw-bconfirmed-title">Property border active</div>
                  <div class="mw-bconfirmed-sub">Crew auto clock-in is live for all plans at this property</div>
              </div>
              <div class="mw-bconfirmed-meta">
                  <span class="mw-bconfirmed-live">
                      <span class="mw-bconfirmed-live-dot" aria-hidden="true"></span>
                      GPS active
                  </span>
                  <?php if ($firstPlanId): ?>
                      <a href="../jobs/view.php?id=<?php echo $firstPlanId; ?>" class="mw-bconfirmed-link">
                          Manage zones
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                              <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                          </svg>
                      </a>
                  <?php endif; ?>
              </div>
          </div>
          <?php endif; ?>

          <!-- ── Header ─────────────────────────────────────────────────────── -->
          <div class="mw-page-header">
              <div>
                  <h1 class="h3 mb-0"><?php echo htmlspecialchars($contract['contract_number']); ?></h1>
                  <div class="mt-2">
                      <?php echo getStatusBadge($contract['status'], 'contract'); ?>
                      <span class="ml-2 text-muted">
                          <?php echo htmlspecialchars(trim($contract['first_name'] . ' ' . $contract['last_name'])); ?>
                          &mdash;
                          <?php echo htmlspecialchars($contract['property_address'] . ', ' . $contract['property_city']); ?>
                      </span>
                  </div>
              </div>
              <div class="mw-header-actions">
                  <?php
                  $addPlanUrl = $contract['quote_id']
                      ? '../jobs/create-from-quote.php?quote_id=' . (int)$contract['quote_id'] . '&contract_id=' . $contractId
                      : '../jobs/create.php?contract_id=' . $contractId . '&property_id=' . (int)$contract['property_id'] . '&contact_id=' . (int)$contract['contact_id'];
                  ?>
                  <a href="<?php echo $addPlanUrl; ?>" class="btn btn-primary">
                      <i data-feather="plus" style="width:14px;height:14px;"></i> Add Plan
                  </a>
                  <?php if (in_array($contract['status'], ['active', 'paused'])): ?>
                      <button type="button" class="btn btn-outline-secondary"
                              data-toggle="modal" data-target="#editContractModal">
                          <i data-feather="edit-2" style="width:14px;height:14px;"></i> Edit Contract
                      </button>
                  <?php endif; ?>
                  <?php if ($contract['status'] === 'active'): ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="pause_contract" class="btn btn-warning">Pause</button>
                      </form>
                  <?php elseif ($contract['status'] === 'paused'): ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="resume_contract" class="btn btn-success">Resume</button>
                      </form>
                  <?php endif; ?>
                  <?php if (in_array($contract['status'], ['active', 'paused'])): ?>
                      <form method="POST" class="d-inline"
                            onsubmit="return confirm('Cancel this contract? Plans will remain but billing stops.')">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="cancel_contract" class="btn btn-outline-danger">Cancel Contract</button>
                      </form>
                  <?php endif; ?>
              </div>
          </div>

          <!-- ── Summary Row ─────────────────────────────────────────────────── -->
          <div class="row mb-4">
              <div class="col-6 col-lg-3">
                  <div class="card">
                      <div class="card-body">
                          <div class="mw-stat-label">Plans</div>
                          <div class="mw-stat-value" style="color: var(--mw-green);">
                              <?php echo count($plans); ?>
                          </div>
                      </div>
                  </div>
              </div>
              <div class="col-6 col-lg-3">
                  <div class="card">
                      <div class="card-body">
                          <div class="mw-stat-label">Billing</div>
                          <div class="mw-stat-value">
                              <?php if ($contract['billing_amount']): ?>
                                  $<?php echo number_format((float)$contract['billing_amount'], 2); ?>
                                  <small class="text-muted d-block" style="font-size:.75rem;">
                                      <?php echo $billingCycleLabels[$contract['billing_cycle']] ?? $contract['billing_cycle']; ?>
                                  </small>
                              <?php else: ?>
                                  <span class="text-muted">—</span>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
              </div>
              <div class="col-6 col-lg-3">
                  <div class="card">
                      <div class="card-body">
                          <div class="mw-stat-label">Start Date</div>
                          <div class="mw-stat-value">
                              <?php echo $contract['start_date'] ? date('M j, Y', strtotime($contract['start_date'])) : '—'; ?>
                          </div>
                      </div>
                  </div>
              </div>
              <div class="col-6 col-lg-3">
                  <div class="card">
                      <div class="card-body">
                          <div class="mw-stat-label">
                              <?php
                              if ($contract['auto_renew'] ?? 0) {
                                  echo 'Next Renewal';
                              } else {
                                  echo $contract['renewal_date'] ? 'Renewal Date' : 'End Date';
                              }
                              ?>
                          </div>
                          <div class="mw-stat-value">
                              <?php
                              $d = $contract['renewal_date'] ?: $contract['end_date'];
                              echo $d ? date('M j, Y', strtotime($d)) : 'Ongoing';
                              ?>
                              <?php if ($contract['auto_renew'] ?? 0): ?>
                                  <small class="d-block" style="font-size:.72rem;color:var(--mw-green);font-weight:600;">
                                      Auto-renews<?php echo (($contract['renewal_increase_pct'] ?? 0) > 0) ? ' +' . number_format((float)$contract['renewal_increase_pct'], 1) . '%' : ''; ?>
                                  </small>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <!-- ── Service Plans ────────────────────────────────────────────────── -->
          <div class="card mb-4">
              <div class="card-header d-flex align-items-center justify-content-between">
                  <h5 class="card-title mb-0">
                      <i data-feather="briefcase" style="width:16px;height:16px;vertical-align:-2px;"></i>
                      Service Plans
                  </h5>
                  <a href="<?php echo $addPlanUrl; ?>" class="btn btn-sm btn-outline-primary">
                      <i data-feather="plus" style="width:12px;height:12px;"></i> Add Plan
                  </a>
              </div>
              <div class="card-body p-0">
                  <?php if (empty($plans)): ?>
                      <div class="text-center p-4 text-muted">
                          <i data-feather="inbox" style="width:32px;height:32px;opacity:.3;"></i>
                          <p class="mt-2 mb-0">No plans yet.</p>
                                  <a href="<?php echo $addPlanUrl; ?>" class="btn btn-primary mt-3">
                                  <i data-feather="plus" style="width:14px;height:14px;"></i> Create First Plan
                              </a>
                      </div>
                  <?php else: ?>
                      <div class="table-responsive">
                          <table class="table table-hover mb-0">
                              <thead>
                                  <tr>
                                      <th>Plan</th>
                                      <th>Service</th>
                                      <th>Schedule</th>
                                      <th>Visits</th>
                                      <th>Work Zone</th>
                                      <th>Status</th>
                                      <th></th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($plans as $plan): ?>
                                      <tr>
                                          <td>
                                              <a href="../jobs/view.php?id=<?php echo (int)$plan['id']; ?>" class="font-weight-bold">
                                                  <?php echo htmlspecialchars($plan['plan_number']); ?>
                                              </a>
                                              <?php if ($plan['title']): ?>
                                                  <div class="text-muted small"><?php echo htmlspecialchars($plan['title']); ?></div>
                                              <?php endif; ?>
                                          </td>
                                          <td>
                                              <span class="mw-badge-status" style="background: var(--mw-light); color: var(--mw-dark);">
                                                  <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $plan['service_type']))); ?>
                                              </span>
                                          </td>
                                          <td>
                                              <?php if ($plan['is_recurring']): ?>
                                                  <span class="text-success">
                                                      <?php echo ucfirst($plan['recurrence_pattern'] ?? 'recurring'); ?>
                                                  </span>
                                              <?php else: ?>
                                                  <span class="text-muted">One-time</span>
                                              <?php endif; ?>
                                              <?php if ($plan['next_visit_date']): ?>
                                                  <div class="text-muted small">
                                                      Next: <?php echo date('M j', strtotime($plan['next_visit_date'])); ?>
                                                  </div>
                                              <?php endif; ?>
                                          </td>
                                          <td>
                                              <?php echo (int)$plan['visits_completed']; ?>
                                              <span class="text-muted">/ <?php echo (int)$plan['total_visits']; ?></span>
                                          </td>
                                          <td>
                                              <?php if ($plan['has_work_zone']): ?>
                                                  <span style="color: var(--mw-green);">
                                                      <i data-feather="map-pin" style="width:13px;height:13px;"></i> Set
                                                  </span>
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
                  <?php endif; ?>
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
          </div>

          <!-- ── Contract Details ─────────────────────────────────────────────── -->
          <div class="row">
              <div class="col-md-6">
                  <div class="card mb-4">
                      <div class="card-header"><h5 class="card-title mb-0">Property &amp; Client</h5></div>
                      <div class="card-body">
                          <dl class="row mb-0">
                              <dt class="col-sm-4">Property</dt>
                              <dd class="col-sm-8">
                                  <?php echo htmlspecialchars($contract['property_address']); ?><br>
                                  <span class="text-muted"><?php echo htmlspecialchars($contract['property_city']); ?></span>
                              </dd>
                              <dt class="col-sm-4">Client</dt>
                              <dd class="col-sm-8">
                                  <?php echo htmlspecialchars(trim($contract['first_name'] . ' ' . $contract['last_name'])); ?>
                                  <?php if ($contract['contact_email']): ?>
                                      <div class="text-muted small"><?php echo htmlspecialchars($contract['contact_email']); ?></div>
                                  <?php endif; ?>
                                  <?php if ($contract['contact_phone']): ?>
                                      <div class="text-muted small"><?php echo htmlspecialchars($contract['contact_phone']); ?></div>
                                  <?php endif; ?>
                              </dd>
                              <?php if ($contract['notes']): ?>
                                  <dt class="col-sm-4">Notes</dt>
                                  <dd class="col-sm-8"><?php echo nl2br(htmlspecialchars($contract['notes'])); ?></dd>
                              <?php endif; ?>
                          </dl>
                      </div>
                  </div>
              </div>
              <div class="col-md-6">
                  <div class="card mb-4">
                      <div class="card-header"><h5 class="card-title mb-0">Origin &amp; Audit</h5></div>
                      <div class="card-body">
                          <dl class="row mb-0">
                              <?php if ($contract['quote_id']): ?>
                                  <dt class="col-sm-4">Source Quote</dt>
                                  <dd class="col-sm-8">
                                      <a href="../quotes/view.php?id=<?php echo (int)$contract['quote_id']; ?>">
                                          <?php echo htmlspecialchars($contract['quote_number'] ?? 'View Quote'); ?>
                                      </a>
                                  </dd>
                              <?php endif; ?>
                              <dt class="col-sm-4">Created By</dt>
                              <dd class="col-sm-8"><?php echo htmlspecialchars($contract['created_by_name'] ?? '—'); ?></dd>
                              <dt class="col-sm-4">Created</dt>
                              <dd class="col-sm-8"><?php echo date('M j, Y', strtotime($contract['created_at'])); ?></dd>
                              <dt class="col-sm-4">Auto-Renew</dt>
                              <dd class="col-sm-8">
                                  <?php if ($contract['auto_renew'] ?? 0): ?>
                                      <span style="color:var(--mw-green);font-weight:600;">Enabled</span>
                                      <?php if (($contract['renewal_increase_pct'] ?? 0) > 0): ?>
                                          <span class="text-muted"> &mdash; +<?php echo number_format((float)$contract['renewal_increase_pct'], 1); ?>% on renewal</span>
                                      <?php endif; ?>
                                  <?php else: ?>
                                      <span class="text-muted">Off</span>
                                  <?php endif; ?>
                              </dd>
                              <?php if (!empty($contract['last_renewed_at'])): ?>
                                  <dt class="col-sm-4">Last Renewed</dt>
                                  <dd class="col-sm-8"><?php echo date('M j, Y', strtotime($contract['last_renewed_at'])); ?></dd>
                              <?php endif; ?>
                          </dl>
                      </div>
                  </div>
              </div>
          </div>

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

                    <!-- Row 1: Title + Billing Cycle -->
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
                                    foreach ($cycleOpts as $val => $label):
                                    ?>
                                        <option value="<?php echo $val; ?>"<?php echo ($contract['billing_cycle'] ?? 'monthly') === $val ? ' selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2: Contract Value + Start Date + End Date -->
                    <div class="row">
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label class="form-label">Contract Value</label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                    <input type="number" name="billing_amount" class="form-control"
                                           step="0.01" min="0"
                                           value="<?php echo htmlspecialchars($contract['billing_amount'] ?? ''); ?>"
                                           placeholder="0.00">
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" class="form-control" required
                                       value="<?php echo htmlspecialchars($contract['start_date'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label class="form-label">End Date <small class="text-muted">(optional)</small></label>
                                <input type="date" name="end_date" class="form-control"
                                       value="<?php echo htmlspecialchars($contract['end_date'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Row 3: Renewal Date + Annual Increase % -->
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label class="form-label">Renewal Date</label>
                                <input type="date" name="renewal_date" class="form-control"
                                       value="<?php echo htmlspecialchars($contract['renewal_date'] ?? ''); ?>">
                                <small class="form-text text-muted">Cron auto-renews on or after this date.</small>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label class="form-label">Annual Increase %</label>
                                <div class="input-group">
                                    <input type="number" name="renewal_increase_pct" class="form-control"
                                           step="0.1" min="0" max="50"
                                           value="<?php echo htmlspecialchars(number_format((float)($contract['renewal_increase_pct'] ?? 0), 1)); ?>"
                                           placeholder="0.0">
                                    <div class="input-group-append"><span class="input-group-text">%</span></div>
                                </div>
                                <small class="form-text text-muted">Applied to billing amount on each renewal.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Row 4: Auto-Renew toggle -->
                    <div class="mw-contract-toggle mb-3">
                        <label class="mw-contract-toggle-label">
                            <input type="checkbox" name="auto_renew" value="1"
                                   <?php echo !empty($contract['auto_renew']) ? 'checked' : ''; ?>>
                            <span class="mw-contract-toggle-track"></span>
                            <span class="mw-contract-toggle-text">
                                <strong>Auto-Renew</strong>
                                <small class="d-block text-muted">Contract renews automatically on the renewal date.</small>
                            </span>
                        </label>
                    </div>

                    <!-- Row 5: Notes -->
                    <div class="form-group mb-0">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($contract['notes'] ?? ''); ?></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i data-feather="save" style="width:14px;height:14px;"></i> Save Changes
                    </button>
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

            <!-- Map fills modal body -->
            <div style="flex:1;position:relative;overflow:hidden;">
                <div id="ctr-border-map" style="position:absolute;inset:0;width:100%;height:100%;"></div>
            </div>

            <!-- Footer: hint + controls -->
            <div class="modal-footer py-2 px-3 d-flex align-items-center" style="flex-shrink:0;gap:8px;">
                <div id="ctr-hint" class="mw-wz-hint mw-wz-hint--info flex-grow-1 mr-2"
                     style="border-radius:5px;margin:0;">
                    <span id="ctr-hint-text">Click <strong>Draw Border</strong> then click the map to trace the property boundary.</span>
                </div>
                <div class="flex-shrink-0 d-flex" style="gap:6px;">
                    <button class="btn btn-sm btn-outline-secondary" id="ctr-cancel-btn"
                            style="display:none;" onclick="ctrCancelDraw()">Cancel Draw</button>
                    <button class="btn btn-sm btn-outline-success" id="ctr-finish-btn"
                            style="display:none;" onclick="ctrFinishDraw()" disabled>✓ Finish Drawing</button>
                    <button class="btn btn-sm btn-outline-primary" id="ctr-draw-btn"
                            onclick="ctrStartDraw()" disabled>Draw Border</button>
                    <button class="btn btn-sm btn-success" id="ctr-save-btn"
                            style="display:none;" onclick="ctrSave()">
                        <span id="ctr-save-spinner" style="display:none;">⏳ </span>Save Border
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
        $('#ctrBorderModal').on('shown.bs.modal', function() {
            ctrInitMap();
        });
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
            zoom:         19,
            strokeColor:  '#e85d04',
            fillColor:    '#e85d04',
            onDraw: function(ring) {
                ctrRing = ring;
                // Show save button
                document.getElementById('ctr-draw-btn').style.display   = 'inline-flex';
                document.getElementById('ctr-cancel-btn').style.display  = 'none';
                document.getElementById('ctr-finish-btn').style.display  = 'none';
                document.getElementById('ctr-save-btn').style.display    = 'inline-flex';
                ctrSetHint('Border traced (' + ring.length + ' points). Click <strong>Save Border</strong> to activate auto clock-in.', 'success');
            },
        });
        ctrMgr.init();

        // Property location marker — shows exactly which lot to trace
        if (ctrMgr._map) {
            var propMarker = L.circleMarker([CTR_PROP_LAT, CTR_PROP_LNG], {
                radius:      16,
                color:       '#e85d04',
                weight:      3,
                fillColor:   '#e85d04',
                fillOpacity: 0.45,
            }).addTo(ctrMgr._map);
            propMarker.bindTooltip(
                '<strong style="color:#e85d04;">&#9678; Property location</strong><br>Draw the border around this property',
                { permanent: true, direction: 'top', offset: [0, -18], opacity: 0.95 }
            ).openTooltip();
            // Outer pulse ring
            L.circleMarker([CTR_PROP_LAT, CTR_PROP_LNG], {
                radius:      28,
                color:       '#e85d04',
                weight:      2,
                fillOpacity: 0,
                opacity:     0.5,
            }).addTo(ctrMgr._map);
        }

        // Track vertices so we can enable Finish button after 3+
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

    window.ctrFinishDraw = function() {
        if (!ctrMgr) return;
        ctrMgr.finishDraw();
    };

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
            // Reload so the banner disappears and the green indicator shows
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
