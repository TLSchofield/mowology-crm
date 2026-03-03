<?php
/**
 * Create Contract
 * Can be reached from an accepted quote (quote_id in GET) or manually from the Contracts list.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.edit');

$db = getDB();

// ── Pre-fill from quote if provided ──────────────────────────────────────
$quoteId = intval($_GET['quote_id'] ?? 0);
$quote   = null;
$prop    = null;
$contact = null;

if ($quoteId) {
    $stmt = $db->prepare("
        SELECT q.*,
               p.address AS property_address, p.city AS property_city,
               p.site_contact_id,
               COALESCE(qr.contact_id, p.site_contact_id) AS resolved_contact_id,
               COALESCE(qrc.first_name, pc.first_name) AS contact_first,
               COALESCE(qrc.last_name,  pc.last_name)  AS contact_last
        FROM quotes q
        JOIN properties p ON q.property_id = p.id
        LEFT JOIN quote_requests qr  ON qr.quote_id  = q.id
        LEFT JOIN contacts qrc       ON qrc.id = qr.contact_id
        LEFT JOIN contacts pc        ON pc.id  = p.site_contact_id
        WHERE q.id = ? AND q.status = 'accepted'
    ");
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if a contract already exists for this quote
    if ($quote) {
        $existing = $db->prepare("SELECT id, contract_number FROM contracts WHERE quote_id = ? LIMIT 1");
        $existing->execute([$quoteId]);
        $existingContract = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingContract) {
            // Contract already exists — redirect to its view
            header("Location: view.php?id={$existingContract['id']}&from=quote");
            exit;
        }
    }
}

// ── POST: create contract ─────────────────────────────────────────────────
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $data = [
        'property_id'    => intval($_POST['property_id'] ?? 0),
        'contact_id'     => intval($_POST['contact_id'] ?? 0),
        'quote_id'       => intval($_POST['quote_id'] ?? 0) ?: null,
        'title'          => trim($_POST['title'] ?? ''),
        'billing_cycle'  => $_POST['billing_cycle'] ?? 'monthly',
        'billing_amount' => $_POST['billing_amount'] ?? '',
        'start_date'     => $_POST['start_date'] ?? '',
        'end_date'       => $_POST['end_date'] ?? '',
        'renewal_date'   => $_POST['renewal_date'] ?? '',
        'notes'          => trim($_POST['notes'] ?? ''),
    ];

    $result = createContract($data, (int)$user['id']);

    if ($result['success']) {
        $contractId = $result['contract_id'];
        // If from a quote, go directly to create-from-quote with contract context
        if (!empty($data['quote_id'])) {
            header("Location: ../jobs/create-from-quote.php?quote_id={$data['quote_id']}&contract_id={$contractId}");
        } else {
            header("Location: view.php?id={$contractId}&created=1");
        }
        exit;
    } else {
        $error = implode(' ', $result['errors']);
    }
}

$csrfToken = generateCSRFToken();

// Defaults from quote
$defaultPropertyId  = $quote['property_id'] ?? 0;
$defaultContactId   = $quote['resolved_contact_id'] ?? 0;
$defaultTitle       = $quote ? ($quote['title'] ?: '') : '';
$defaultStartDate   = $quote && $quote['accepted_at'] ? date('Y-m-d', strtotime($quote['accepted_at'])) : date('Y-m-d');
$defaultBillingAmt  = $quote ? (number_format((float)($quote['total_amount'] ?? 0), 2)) : '';
$contactName        = $quote ? trim($quote['contact_first'] . ' ' . $quote['contact_last']) : '';
$propertyAddress    = $quote ? trim($quote['property_address'] . ', ' . $quote['property_city']) : '';

$pageTitle  = 'New Contract';
$activePage = 'contracts';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="<?php echo $quoteId ? '../quotes/view.php?id=' . $quoteId : '../contracts_appstack.php'; ?>" class="mw-back-link">
              &larr; <?php echo $quoteId ? 'Back to Quote' : 'Back to Contracts'; ?>
          </a>

          <h1 class="h3 mb-1">New Contract</h1>
          <p class="text-muted mb-4">
              <?php if ($quote): ?>
                  From <strong><?php echo htmlspecialchars($quote['quote_number']); ?></strong>
                  &mdash; <?php echo htmlspecialchars($contactName); ?>
                  &mdash; <?php echo htmlspecialchars($propertyAddress); ?>
              <?php else: ?>
                  Create a service contract for a client property.
              <?php endif; ?>
          </p>

          <?php if ($error): ?>
              <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>

          <form method="POST">
              <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
              <input type="hidden" name="property_id" value="<?php echo (int)$defaultPropertyId; ?>">
              <input type="hidden" name="contact_id"  value="<?php echo (int)$defaultContactId; ?>">
              <input type="hidden" name="quote_id"    value="<?php echo (int)$quoteId; ?>">

              <div class="row">
                  <div class="col-md-8">
                      <div class="card mb-3">
                          <div class="card-header"><h5 class="card-title mb-0">Contract Details</h5></div>
                          <div class="card-body">

                              <?php if ($quote): ?>
                                  <div class="mw-form-row">
                                      <label class="mw-form-label">Property</label>
                                      <div class="mw-form-value text-muted"><?php echo htmlspecialchars($propertyAddress); ?></div>
                                  </div>
                                  <div class="mw-form-row">
                                      <label class="mw-form-label">Client</label>
                                      <div class="mw-form-value text-muted"><?php echo htmlspecialchars($contactName); ?></div>
                                  </div>
                              <?php endif; ?>

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="title">Contract Title <span class="text-muted">(optional)</span></label>
                                  <input type="text" id="title" name="title" class="form-control"
                                         value="<?php echo htmlspecialchars($defaultTitle); ?>"
                                         placeholder="e.g. Full-Service Landscaping 2026">
                              </div>

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="start_date">Start Date <span class="text-danger">*</span></label>
                                  <input type="date" id="start_date" name="start_date" class="form-control"
                                         value="<?php echo htmlspecialchars($defaultStartDate); ?>" required>
                              </div>

                              <div class="row">
                                  <div class="col-sm-6">
                                      <div class="mw-form-row">
                                          <label class="mw-form-label" for="end_date">End Date <span class="text-muted">(optional)</span></label>
                                          <input type="date" id="end_date" name="end_date" class="form-control">
                                      </div>
                                  </div>
                                  <div class="col-sm-6">
                                      <div class="mw-form-row">
                                          <label class="mw-form-label" for="renewal_date">Renewal Date <span class="text-muted">(optional)</span></label>
                                          <input type="date" id="renewal_date" name="renewal_date" class="form-control">
                                      </div>
                                  </div>
                              </div>

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="notes">Notes <span class="text-muted">(optional)</span></label>
                                  <textarea id="notes" name="notes" class="form-control" rows="3"
                                            placeholder="Internal notes about this contract..."></textarea>
                              </div>

                          </div>
                      </div>
                  </div>

                  <div class="col-md-4">
                      <div class="card mb-3">
                          <div class="card-header"><h5 class="card-title mb-0">Billing</h5></div>
                          <div class="card-body">

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="billing_cycle">Billing Cycle</label>
                                  <select id="billing_cycle" name="billing_cycle" class="form-control">
                                      <option value="monthly">Monthly</option>
                                      <option value="per_visit">Per Visit</option>
                                      <option value="seasonal">Seasonal</option>
                                      <option value="annual">Annual</option>
                                      <option value="custom">Custom</option>
                                  </select>
                              </div>

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="billing_amount">Contract Value</label>
                                  <div class="input-group">
                                      <div class="input-group-prepend">
                                          <span class="input-group-text">$</span>
                                      </div>
                                      <input type="number" id="billing_amount" name="billing_amount"
                                             class="form-control" min="0" step="0.01"
                                             value="<?php echo htmlspecialchars($defaultBillingAmt); ?>"
                                             placeholder="0.00">
                                  </div>
                                  <small class="form-text text-muted">What the client pays per billing cycle.</small>
                              </div>

                          </div>
                      </div>

                      <div class="d-grid">
                          <button type="submit" class="btn btn-primary btn-block">
                              <i data-feather="file-check" style="width:14px;height:14px;"></i>
                              Create Contract<?php echo $quoteId ? ' &amp; Add Plans' : ''; ?>
                          </button>
                      </div>
                      <p class="text-muted text-center small mt-2">
                          <?php if ($quoteId): ?>
                              You'll be taken to the plan builder next.
                          <?php else: ?>
                              You can add service plans after creating the contract.
                          <?php endif; ?>
                      </p>
                  </div>
              </div>
          </form>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
