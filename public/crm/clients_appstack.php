<?php
/**
 * Client Management - List, Create, Edit, Delete
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();
$pageTitle = 'Clients';
$activePage = 'clients';

// Handle form submissions
$action = $_GET['action'] ?? null;
$clientId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$messageType = '';

// Handle JSON requests (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $requestAction = $_GET['action'] ?? null;

    if ($requestAction === 'convert_to_prospect') {
        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $requestId = intval($jsonData['request_id'] ?? 0);
        if (!$requestId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid request ID']);
            exit;
        }

        try {
            // Get quote request details
            $stmt = $db->prepare("
                SELECT qr.*, c.first_name, c.last_name, c.email, p.address, p.city
                FROM quote_requests qr
                LEFT JOIN contacts c ON qr.contact_id = c.id
                LEFT JOIN properties p ON qr.property_id = p.id
                WHERE qr.id = ?
            ");
            $stmt->execute([$requestId]);
            $qr = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$qr) {
                throw new Exception('Quote request not found');
            }

            // Create company for prospect
            $companyName = trim(($qr['first_name'] ?? '') . ' ' . ($qr['last_name'] ?? '')) ?: 'Prospect from ' . formatDate($qr['created_at']);
            $billingEmail = $qr['email'] ?? null;
            $billingAddress = $qr['address'] ?? null;
            $billingCity = $qr['city'] ?? 'Vancouver';

            $stmt = $db->prepare("
                INSERT INTO companies (
                    company_name, company_type, billing_email, billing_address, billing_city,
                    billing_province, account_status, pref_attach_pdf
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyName,
                'individual',
                $billingEmail,
                $billingAddress,
                $billingCity,
                'BC',
                'active',
                1  // Default to attach PDF
            ]);

            $companyId = $db->lastInsertId();

            // Link quote request to new company
            $stmt = $db->prepare("UPDATE quote_requests SET company_id = ? WHERE id = ?");
            $stmt->execute([$companyId, $requestId]);

            http_response_code(200);
            echo json_encode(['success' => true, 'company_id' => $companyId]);
            exit;

        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => htmlspecialchars($e->getMessage())]);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request. Please try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? null;

        if ($action === 'save_client') {
            // Save new or existing client with contact
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $companyMode = $_POST['company_mode'] ?? 'existing';  // 'existing' or 'new'
            $companyId = isset($_POST['company_id']) ? intval($_POST['company_id']) : 0;
            $companyName = trim($_POST['company_name'] ?? '');
            $companyType = $_POST['company_type'] ?? 'individual';
            $billingEmail = trim($_POST['billing_email'] ?? '');
            $billingPhone = trim($_POST['billing_phone'] ?? '');
            $billingAddress = trim($_POST['billing_address'] ?? '');
            $billingCity = trim($_POST['billing_city'] ?? 'Vancouver');
            $billingProvince = trim($_POST['billing_province'] ?? 'BC');
            $billingPostalCode = trim($_POST['billing_postal_code'] ?? '');
            $accountStatus = $_POST['account_status'] ?? 'active';
            $paymentTerms = $_POST['payment_terms'] ?? 'Net 30';
            $notes = trim($_POST['notes'] ?? '');
            $prefAttachPdf = isset($_POST['pref_attach_pdf']) ? 1 : 0;

            // Validate contact fields
            if (empty($firstName)) {
                $message = 'Please enter a first name.';
                $messageType = 'error';
            } elseif (empty($lastName)) {
                $message = 'Please enter a last name.';
                $messageType = 'error';
            } elseif ($companyMode === 'new' && empty($companyName)) {
                $message = 'Please enter a company/client name.';
                $messageType = 'error';
            } elseif ($companyMode === 'existing' && !$companyId) {
                $message = 'Please select a company or create a new one.';
                $messageType = 'error';
            } else {
                try {
                    $db->beginTransaction();

                    // Create or update contact
                    $stmt = $db->prepare("
                        INSERT INTO contacts (first_name, last_name, email, phone, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$firstName, $lastName, $billingEmail, $billingPhone]);
                    $contactId = $db->lastInsertId();

                    if ($companyMode === 'new') {
                        // Create new company linked to contact
                        $stmt = $db->prepare("
                            INSERT INTO companies (
                                company_name, company_type, primary_contact_id, billing_contact_id,
                                billing_email, billing_phone, billing_address, billing_city,
                                billing_province, billing_postal_code, account_status, payment_terms,
                                notes, pref_attach_pdf
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $companyName, $companyType, $contactId, $contactId,
                            $billingEmail, $billingPhone, $billingAddress, $billingCity,
                            $billingProvince, $billingPostalCode, $accountStatus, $paymentTerms,
                            $notes, $prefAttachPdf
                        ]);
                        $clientId = $db->lastInsertId();
                        $message = 'Client and contact created successfully!';
                        $messageType = 'success';
                    } else {
                        // Add contact to existing company as primary contact
                        $stmt = $db->prepare("
                            UPDATE companies SET primary_contact_id = ?, billing_contact_id = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$contactId, $contactId, $companyId]);
                        $clientId = $companyId;
                        $message = 'Contact added to company successfully!';
                        $messageType = 'success';
                    }

                    $db->commit();
                } catch (PDOException $e) {
                    $db->rollBack();
                    $message = 'Error: ' . htmlspecialchars($e->getMessage());
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'delete_client') {
            if ($clientId) {
                try {
                    $db->prepare("DELETE FROM companies WHERE id = ?")->execute([$clientId]);
                    $message = 'Client deleted successfully!';
                    $messageType = 'success';
                    $action = null;
                    $clientId = 0;
                } catch (PDOException $e) {
                    $message = 'Error: ' . htmlspecialchars($e->getMessage());
                    $messageType = 'error';
                }
            }
        }
    }
}

// Get client data if editing
$client = null;
if ($action === 'edit' && $clientId) {
    $stmt = $db->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all clients and prospects
// Prospects are created from quote_requests
$clients = $db->query("
    SELECT
        c.id,
        c.company_name,
        c.company_type,
        c.billing_email,
        c.account_status,
        c.created_at,
        IF(qr.id IS NOT NULL, 'prospect', 'client') as source_type,
        qr.urgency,
        qr.status as qr_status
    FROM companies c
    LEFT JOIN quote_requests qr ON c.id = qr.company_id AND qr.status IN ('new', 'reviewing')
    ORDER BY c.company_name
")->fetchAll(PDO::FETCH_ASSOC);

// Also get quote_requests without companies (not yet converted to prospects)
$unconvertedRequests = $db->query("
    SELECT
        qr.id,
        CONCAT(c.first_name, ' ', c.last_name) as contact_name,
        p.address as property_address,
        p.city,
        qr.urgency,
        qr.status,
        qr.service_types,
        qr.created_at,
        qr.company_id
    FROM quote_requests qr
    LEFT JOIN contacts c ON qr.contact_id = c.id
    LEFT JOIN properties p ON qr.property_id = p.id
    WHERE qr.company_id IS NULL AND qr.status IN ('new', 'reviewing')
    ORDER BY
        CASE qr.urgency WHEN 'asap' THEN 1 WHEN 'soon' THEN 2 ELSE 3 END,
        qr.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Client Management</h1>
            <?php if ($action !== 'edit' && $action !== 'new'): ?>
              <button class="btn btn-primary" onclick="location.href='?action=new'">
                <i data-feather="plus"></i> Add New Client
              </button>
            <?php endif; ?>
          </div>

          <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
              <?php echo htmlspecialchars($message); ?>
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
          <?php endif; ?>

          <?php if ($action === 'edit' || $action === 'new'): ?>
            <!-- Client Form -->
            <div class="card mb-4">
              <div class="card-header">
                <h5 class="card-title mb-0">
                  <?php echo $clientId ? 'Add Contact to Client' : 'New Client & Contact'; ?>
                </h5>
              </div>
              <div class="card-body">
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                  <input type="hidden" name="action" value="save_client">

                  <!-- Contact Information (Required) -->
                  <h6 class="mb-3"><strong>Contact Person Information</strong></h6>
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" class="form-control" name="first_name" required
                          value="<?php echo h($_POST['first_name'] ?? ''); ?>">
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" class="form-control" name="last_name" required
                          value="<?php echo h($_POST['last_name'] ?? ''); ?>">
                      </div>
                    </div>
                  </div>

                  <hr class="my-4">

                  <!-- Company Selection or Creation -->
                  <h6 class="mb-3"><strong>Company/Client</strong></h6>
                  <div class="row mb-3">
                    <div class="col-md-12">
                      <div class="btn-group btn-block" role="group">
                        <input type="radio" class="btn-check" name="company_mode" id="company_mode_existing" value="existing" checked>
                        <label class="btn btn-outline-secondary" for="company_mode_existing">
                          Link to Existing Company
                        </label>
                        <input type="radio" class="btn-check" name="company_mode" id="company_mode_new" value="new">
                        <label class="btn btn-outline-secondary" for="company_mode_new">
                          Create New Company
                        </label>
                      </div>
                    </div>
                  </div>

                  <!-- Existing Company Selection -->
                  <div id="existing-company-section">
                    <div class="row">
                      <div class="col-md-12">
                        <div class="form-group">
                          <label>Select Company *</label>
                          <select class="form-control" name="company_id" id="company_id">
                            <option value="">-- Select a company --</option>
                            <?php
                              $companies = $db->query("SELECT id, company_name, company_type FROM companies ORDER BY company_name")->fetchAll();
                              foreach ($companies as $comp): ?>
                              <option value="<?php echo (int)$comp['id']; ?>">
                                <?php echo h($comp['company_name']); ?> (<?php echo ucwords(str_replace('_', ' ', $comp['company_type'])); ?>)
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- New Company Information -->
                  <div id="new-company-section" style="display: none;">
                    <div class="row">
                      <div class="col-md-6">
                        <div class="form-group">
                          <label>Company/Client Name *</label>
                          <input type="text" class="form-control" name="company_name"
                            value="<?php echo h($_POST['company_name'] ?? ''); ?>">
                        </div>
                      </div>
                      <div class="col-md-6">
                        <div class="form-group">
                          <label>Type</label>
                          <select class="form-control" name="company_type">
                            <option value="individual">Individual</option>
                            <option value="business">Business</option>
                            <option value="strata">Strata</option>
                            <option value="property_manager">Property Manager</option>
                          </select>
                        </div>
                      </div>
                    </div>
                  </div>

                  <hr class="my-4">

                  <!-- Billing Information -->
                  <h6 class="mb-3"><strong>Billing Information</strong></h6>
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Billing Email</label>
                        <input type="email" class="form-control" name="billing_email"
                          value="<?php echo h($_POST['billing_email'] ?? ''); ?>">
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Billing Phone</label>
                        <input type="tel" class="form-control" name="billing_phone"
                          value="<?php echo h($_POST['billing_phone'] ?? ''); ?>">
                      </div>
                    </div>
                  </div>

                  <div class="form-group">
                    <label>Billing Address</label>
                    <input type="text" class="form-control" name="billing_address"
                      value="<?php echo h($_POST['billing_address'] ?? ''); ?>">
                  </div>

                  <div class="row">
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>City</label>
                        <input type="text" class="form-control" name="billing_city"
                          value="<?php echo h($_POST['billing_city'] ?? 'Vancouver'); ?>">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Province</label>
                        <input type="text" class="form-control" name="billing_province" maxlength="2"
                          value="<?php echo h($_POST['billing_province'] ?? 'BC'); ?>">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label>Postal Code</label>
                        <input type="text" class="form-control" name="billing_postal_code"
                          value="<?php echo h($_POST['billing_postal_code'] ?? ''); ?>">
                      </div>
                    </div>
                  </div>

                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Account Status</label>
                        <select class="form-control" name="account_status">
                          <option value="active">Active</option>
                          <option value="inactive">Inactive</option>
                          <option value="suspended">Suspended</option>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group">
                        <label>Payment Terms</label>
                        <input type="text" class="form-control" name="payment_terms"
                          value="<?php echo h($_POST['payment_terms'] ?? 'Net 30'); ?>">
                      </div>
                    </div>
                  </div>

                  <div class="form-group">
                    <label>Notes</label>
                    <textarea class="form-control" name="notes" rows="3"><?php echo h($_POST['notes'] ?? ''); ?></textarea>
                  </div>

                  <hr>

                  <div class="form-group">
                    <h5>Email Preferences</h5>
                    <div class="custom-control custom-checkbox">
                      <input type="checkbox" class="custom-control-input" id="prefAttachPdf" name="pref_attach_pdf"
                        <?php echo ($client['pref_attach_pdf'] ?? 1) ? 'checked' : ''; ?>>
                      <label class="custom-control-label" for="prefAttachPdf">
                        Attach PDF to quote emails
                        <small class="d-block text-muted mt-1">When enabled, quote PDFs are automatically attached to email sent to this client</small>
                      </label>
                    </div>
                  </div>

                  <div class="form-group mt-4">
                    <button type="submit" class="btn btn-primary">
                      <i data-feather="save"></i> Save Client
                    </button>
                    <a href="clients_appstack.php" class="btn btn-secondary">
                      <i data-feather="x"></i> Cancel
                    </a>
                    <?php if ($clientId): ?>
                      <button type="button" class="btn btn-danger float-right" onclick="if(confirm('Delete this client?')) { location.href='?action=delete_client&id=<?php echo $clientId; ?>'; }">
                        <i data-feather="trash-2"></i> Delete
                      </button>
                    <?php endif; ?>
                  </div>
                </form>
              </div>
            </div>

          <?php else: ?>
            <!-- Client List -->
            <div class="card">
              <div class="card-header">
                <h5 class="card-title mb-0">
                  All Clients
                  <span class="badge badge-primary ml-2"><?php echo count($clients); ?></span>
                </h5>
              </div>
              <div class="card-body">
                <?php if (empty($clients) && empty($unconvertedRequests)): ?>
                  <div class="text-center text-muted py-5">
                    <i data-feather="inbox" style="width: 48px; height: 48px;"></i>
                    <p class="mt-3 mb-0">No clients yet. <a href="?action=new">Create one now</a></p>
                  </div>
                <?php else: ?>
                  <!-- Clients & Prospects Table -->
                  <?php if (!empty($clients)): ?>
                    <div class="table-responsive mb-4">
                      <table class="table table-hover">
                        <thead>
                          <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($clients as $c): ?>
                          <tr <?php echo $c['source_type'] === 'prospect' ? 'style="background: #fef3c7; opacity: 0.9;"' : ''; ?>>
                            <td>
                              <strong><?php echo h($c['company_name']); ?></strong>
                              <?php if ($c['source_type'] === 'prospect'): ?>
                                <br><small class="text-warning">🔵 Prospect</small>
                              <?php endif; ?>
                            </td>
                            <td>
                              <span class="badge badge-light">
                                <?php echo ucwords(str_replace('_', ' ', $c['company_type'])); ?>
                              </span>
                            </td>
                            <td><?php echo h($c['billing_email'] ?? '—'); ?></td>
                            <td>
                              <?php
                                if ($c['source_type'] === 'prospect') {
                                  $statusColor = 'info';
                                  $statusText = 'Prospect - ' . ucfirst($c['qr_status'] ?? 'new');
                                } else {
                                  $statusColor = $c['account_status'] === 'active' ? 'success' : ($c['account_status'] === 'inactive' ? 'secondary' : 'danger');
                                  $statusText = ucfirst($c['account_status']);
                                }
                              ?>
                              <span class="badge badge-<?php echo $statusColor; ?>">
                                <?php echo $statusText; ?>
                              </span>
                            </td>
                            <td>
                              <small class="text-muted">
                                <?php echo formatDate($c['created_at']); ?>
                              </small>
                            </td>
                            <td>
                              <a href="?action=edit&id=<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-primary">
                                <i data-feather="edit"></i> Edit
                              </a>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>

                  <!-- Unconverted Quote Requests -->
                  <?php if (!empty($unconvertedRequests)): ?>
                    <div class="alert alert-info">
                      <h6 class="mb-2">
                        <i data-feather="inbox" style="width: 18px; height: 18px; display: inline; margin-right: 4px;"></i>
                        New Quote Requests (<?php echo count($unconvertedRequests); ?>)
                      </h6>
                      <p class="mb-2 small">These are new inquiries that haven't been converted to clients yet.</p>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover">
                        <thead>
                          <tr>
                            <th>Contact</th>
                            <th>Property</th>
                            <th>Services</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Received</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($unconvertedRequests as $req): ?>
                          <tr style="background: #fef9e7;">
                            <td>
                              <strong><?php echo h($req['contact_name'] ?? 'Unknown'); ?></strong>
                            </td>
                            <td>
                              <small><?php echo h($req['property_address'] ?? '—'); ?></small>
                              <?php if ($req['city']): ?>
                                <br><small class="text-muted"><?php echo h($req['city']); ?></small>
                              <?php endif; ?>
                            </td>
                            <td>
                              <small><?php echo h(substr($req['service_types'] ?? 'Not specified', 0, 40)); ?></small>
                            </td>
                            <td>
                              <span class="badge badge-<?php echo $req['urgency'] === 'asap' ? 'danger' : ($req['urgency'] === 'soon' ? 'warning' : 'info'); ?>">
                                <?php echo ucfirst($req['urgency']); ?>
                              </span>
                            </td>
                            <td>
                              <span class="badge badge-light"><?php echo ucfirst($req['status']); ?></span>
                            </td>
                            <td>
                              <small class="text-muted"><?php echo formatDate($req['created_at']); ?></small>
                            </td>
                            <td>
                              <a href="products/quote-requests.php?id=<?php echo (int)$req['id']; ?>" class="btn btn-sm btn-outline-primary" title="View request">
                                <i data-feather="eye"></i>
                              </a>
                              <button class="btn btn-sm btn-outline-success" onclick="convertToProspect(<?php echo (int)$req['id']; ?>, '<?php echo h(addslashes($req['contact_name'] ?? 'New Prospect')); ?>')" title="Convert to prospect">
                                <i data-feather="plus"></i>
                              </button>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <script>
            // Toggle between existing and new company modes
            document.addEventListener('DOMContentLoaded', function() {
              const existingRadio = document.getElementById('company_mode_existing');
              const newRadio = document.getElementById('company_mode_new');
              const existingSection = document.getElementById('existing-company-section');
              const newSection = document.getElementById('new-company-section');
              const companyIdSelect = document.getElementById('company_id');
              const companyNameInput = document.querySelector('input[name="company_name"]');

              function updateCompanyMode() {
                if (newRadio.checked) {
                  existingSection.style.display = 'none';
                  newSection.style.display = 'block';
                  companyIdSelect.removeAttribute('required');
                  companyNameInput.setAttribute('required', 'required');
                } else {
                  existingSection.style.display = 'block';
                  newSection.style.display = 'none';
                  companyIdSelect.setAttribute('required', 'required');
                  companyNameInput.removeAttribute('required');
                }
              }

              existingRadio.addEventListener('change', updateCompanyMode);
              newRadio.addEventListener('change', updateCompanyMode);
            });

            function convertToProspect(requestId, contactName) {
              if (!confirm('Convert "' + contactName + '" to a prospect client?')) return;

              fetch('clients_appstack.php?action=convert_to_prospect', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  request_id: requestId,
                  csrf_token: '<?php echo csrf_token(); ?>'
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  alert('Prospect created! Reloading...');
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }
          </script>

<?php include 'includes/appstack_footer.php'; ?>
