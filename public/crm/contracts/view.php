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
}

// Flash message from redirect
if (isset($_GET['created'])) { $message = 'Contract created successfully!'; $messageType = 'success'; }
if (isset($_GET['from']) && $_GET['from'] === 'quote') { $message = 'Contract already exists for this quote.'; $messageType = 'info'; }

$plans = getContractPlans($contractId);

$billingCycleLabels = [
    'monthly'   => 'Monthly',
    'per_visit' => 'Per Visit',
    'seasonal'  => 'Seasonal',
    'annual'    => 'Annual',
    'custom'    => 'Custom',
];

$csrfToken  = generateCSRFToken();
$pageTitle  = $contract['contract_number'] . ' — Contract';
$activePage = 'contracts';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="../contracts_appstack.php" class="mw-back-link">&larr; All Contracts</a>

          <?php if ($message): ?>
              <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : ($messageType === 'info' ? 'info' : 'success'); ?> mb-3">
                  <?php echo htmlspecialchars($message); ?>
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
                  <?php if ($contract['quote_id']): ?>
                      <a href="../jobs/create-from-quote.php?quote_id=<?php echo (int)$contract['quote_id']; ?>&contract_id=<?php echo $contractId; ?>"
                         class="btn btn-primary">
                          <i data-feather="plus" style="width:14px;height:14px;"></i> Add Plan
                      </a>
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
                              <?php echo $contract['renewal_date'] ? 'Renewal Date' : 'End Date'; ?>
                          </div>
                          <div class="mw-stat-value">
                              <?php
                              $d = $contract['renewal_date'] ?: $contract['end_date'];
                              echo $d ? date('M j, Y', strtotime($d)) : 'Ongoing';
                              ?>
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
                  <?php if ($contract['quote_id']): ?>
                      <a href="../jobs/create-from-quote.php?quote_id=<?php echo (int)$contract['quote_id']; ?>&contract_id=<?php echo $contractId; ?>"
                         class="btn btn-sm btn-outline-primary">
                          <i data-feather="plus" style="width:12px;height:12px;"></i> Add Plan
                      </a>
                  <?php endif; ?>
              </div>
              <div class="card-body p-0">
                  <?php if (empty($plans)): ?>
                      <div class="text-center p-4 text-muted">
                          <i data-feather="inbox" style="width:32px;height:32px;opacity:.3;"></i>
                          <p class="mt-2 mb-0">No plans yet.</p>
                          <?php if ($contract['quote_id']): ?>
                              <a href="../jobs/create-from-quote.php?quote_id=<?php echo (int)$contract['quote_id']; ?>&contract_id=<?php echo $contractId; ?>"
                                 class="btn btn-primary mt-3">
                                  <i data-feather="plus" style="width:14px;height:14px;"></i> Create First Plan
                              </a>
                          <?php endif; ?>
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
                          </dl>
                      </div>
                  </div>
              </div>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
