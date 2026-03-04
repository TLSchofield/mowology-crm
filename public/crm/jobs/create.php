<?php
/**
 * Create Job Plan - Manual or from Quote
 *
 * Creates a job_plan in the job_plans table using createJobPlan().
 * Replaces the legacy job creation flow.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';
require_once dirname(__DIR__) . '/includes/roi-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.edit');

$db = getDB();

// ─── AJAX: Search contacts & properties ───
if (isset($_GET['action']) && $_GET['action'] === 'search_contacts' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $query = trim($_GET['q'] ?? '');

    if (strlen($query) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    $searchTerm = '%' . $query . '%';
    $results = [];

    // Search contacts by name, email, or phone
    $stmt = $db->prepare("
        SELECT ct.id, ct.first_name, ct.last_name, ct.email, ct.phone,
               c.id as company_id, c.company_name
        FROM contacts ct
        LEFT JOIN companies c ON c.primary_contact_id = ct.id
        WHERE ct.is_active = 1
          AND (ct.first_name LIKE ? OR ct.last_name LIKE ?
               OR ct.email LIKE ? OR ct.phone LIKE ?
               OR CONCAT(ct.first_name, ' ', ct.last_name) LIKE ?)
        ORDER BY ct.last_name, ct.first_name ASC
        LIMIT 10
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($contacts as $ct) {
        $name = trim(($ct['first_name'] ?? '') . ' ' . ($ct['last_name'] ?? ''));
        $subtitle = $ct['email'] ?: ($ct['phone'] ?: '');
        if ($ct['company_name']) {
            $subtitle = $ct['company_name'] . ($subtitle ? ' · ' . $subtitle : '');
        }
        $results[] = [
            'contact_id' => (int)$ct['id'],
            'company_id' => $ct['company_id'] ? (int)$ct['company_id'] : null,
            'label'      => $name ?: ($ct['email'] ?: 'Contact #' . $ct['id']),
            'subtitle'   => $subtitle,
            'type'       => 'contact',
        ];
    }

    // Also search companies by name (if any exist)
    $stmt2 = $db->prepare("
        SELECT c.id, c.company_name, c.company_type,
               ct.first_name, ct.last_name
        FROM companies c
        LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
        WHERE (c.account_status IS NULL OR c.account_status != 'suspended')
          AND c.company_name LIKE ?
        ORDER BY c.company_name ASC
        LIMIT 5
    ");
    $stmt2->execute([$searchTerm]);
    $companies = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    foreach ($companies as $co) {
        // Avoid duplicating if we already have this company's contact
        $contactName = trim(($co['first_name'] ?? '') . ' ' . ($co['last_name'] ?? ''));
        $results[] = [
            'contact_id' => null,
            'company_id' => (int)$co['id'],
            'label'      => $co['company_name'],
            'subtitle'   => $contactName ?: ($co['company_type'] ?? ''),
            'type'       => 'company',
        ];
    }

    echo json_encode(['results' => $results]);
    exit;
}

// ─── AJAX: Get properties for a contact or company ───
if (isset($_GET['action']) && $_GET['action'] === 'get_properties' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $contactId = intval($_GET['contact_id'] ?? 0);
    $companyId = intval($_GET['company_id'] ?? 0);

    if (!$contactId && !$companyId) {
        echo json_encode(['properties' => []]);
        exit;
    }

    $properties = [];

    // Get properties linked to this contact via site_contact_id
    if ($contactId) {
        $stmt = $db->prepare("
            SELECT p.id, p.address, p.city, p.property_type, p.postal_code
            FROM properties p
            WHERE p.site_contact_id = ?
              AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.address ASC
        ");
        $stmt->execute([$contactId]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // If also have company, add properties via company_properties junction
    if ($companyId && empty($properties)) {
        $stmt = $db->prepare("
            SELECT DISTINCT p.id, p.address, p.city, p.property_type, p.postal_code
            FROM properties p
            JOIN company_properties cp ON p.id = cp.property_id
            WHERE cp.company_id = ?
              AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.address ASC
        ");
        $stmt->execute([$companyId]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // If still no properties and we have a contact, check if there are ANY properties
    // (for small DBs where contact might not be linked yet but user wants to pick)
    if (empty($properties)) {
        $stmt = $db->query("
            SELECT p.id, p.address, p.city, p.property_type, p.postal_code
            FROM properties p
            WHERE (p.status IS NULL OR p.status = 'active')
            ORDER BY p.address ASC
            LIMIT 20
        ");
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['properties' => $properties]);
    exit;
}

$error = '';
$prefill = [];

// Pre-fill from URL params (contact_id + property_id from client page, contract_id from contract)
$urlContactId  = isset($_GET['contact_id'])  ? intval($_GET['contact_id'])  : 0;
$urlPropertyId = isset($_GET['property_id']) ? intval($_GET['property_id']) : 0;
$urlContractId = isset($_GET['contract_id']) ? intval($_GET['contract_id']) : 0;

if ($urlContactId && $urlPropertyId) {
    $cpStmt = $db->prepare("
        SELECT c.id AS contact_id, c.first_name, c.last_name, c.email,
               p.id AS property_id, p.address, p.city
        FROM contacts c, properties p
        WHERE c.id = ? AND p.id = ?
    ");
    $cpStmt->execute([$urlContactId, $urlPropertyId]);
    $cpData = $cpStmt->fetch(PDO::FETCH_ASSOC);
    if ($cpData) {
        $prefill = [
            'contact_id'       => $cpData['contact_id'],
            'contact_name'     => trim($cpData['first_name'] . ' ' . $cpData['last_name']),
            'property_id'      => $cpData['property_id'],
            'property_address' => $cpData['address'] . ', ' . $cpData['city'],
        ];
    }
}

// Check if creating from quote
$quoteId = isset($_GET['quote_id']) ? intval($_GET['quote_id']) : 0;

if ($quoteId) {
    // Fast path: create plan directly from accepted quote
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $quoteId) {
        $result = createPlanFromQuote($quoteId, (int)$user['id']);

        if ($result['success']) {
            header("Location: view.php?id={$result['plan_id']}&created=1");
            exit;
        }

        // If createPlanFromQuote failed, fall through to show form with prefill
        $error = implode(' ', $result['errors']);
    }

    // Prefill from accepted quote for form display
    $stmt = $db->prepare("
        SELECT q.*, q.company_id, p.address, p.city, c.company_name
        FROM quotes q
        JOIN properties p ON q.property_id = p.id
        LEFT JOIN companies c ON q.company_id = c.id
        WHERE q.id = ? AND q.status = 'accepted'
    ");
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($quote) {
        $prefill = [
            'property_id'      => $quote['property_id'],
            'company_id'       => $quote['company_id'],
            'company_name'     => $quote['company_name'],
            'property_address' => $quote['address'] . ', ' . $quote['city'],
            'title'            => $quote['title'] ?: 'Plan from ' . $quote['quote_number'],
            'description'      => $quote['description'],
            'service_type'     => $quote['service_type'],
            'price_per_visit'  => $quote['amount'],
            'estimated_amount' => $quote['amount'],
            'quote_id'         => $quoteId,
        ];
    }
}

// Get staff for crew assignment
$staff = getStaffMembers();

// Get active products for service type dropdowns
$products = getActiveProducts();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        // Collect form data
        $propertyId          = intval($_POST['property_id'] ?? 0);
        $title               = trim($_POST['title'] ?? '');
        $description         = trim($_POST['description'] ?? '');
        $serviceType         = $_POST['service_type'] ?? 'landscaping';
        $planType            = $_POST['plan_type'] ?? 'one_time';
        $pricingModel        = $_POST['pricing_model'] ?? 'per_visit';
        $pricePerVisit       = floatval($_POST['price_per_visit'] ?? 0);
        $estimatedAmount     = floatval($_POST['estimated_amount'] ?? 0);
        $planStartDate       = $_POST['plan_start_date'] ?? date('Y-m-d');
        $planEndDate         = !empty($_POST['plan_end_date']) ? $_POST['plan_end_date'] : null;
        $defaultTimeStart    = !empty($_POST['default_time_start']) ? $_POST['default_time_start'] : null;
        $defaultTimeEnd      = !empty($_POST['default_time_end']) ? $_POST['default_time_end'] : null;
        $estimatedDuration   = intval($_POST['estimated_duration'] ?? 60);
        $defaultCrewId       = !empty($_POST['default_crew_id']) ? intval($_POST['default_crew_id']) : null;
        $horizonDays         = intval($_POST['horizon_days'] ?? 28);
        $linkedQuoteId       = intval($_POST['quote_id'] ?? 0);
        $linkedContractId    = intval($_POST['contract_id'] ?? 0);

        // Multi-crew assignment
        $crewIds = [];
        if (!empty($_POST['crew_ids']) && is_array($_POST['crew_ids'])) {
            $crewIds = array_map('intval', $_POST['crew_ids']);
            $crewIds = array_filter($crewIds, function($id) { return $id > 0; });
        }

        // Recurring fields
        $isRecurring         = ($planType === 'recurring') ? 1 : 0;
        $recurrencePattern   = $isRecurring ? ($_POST['recurrence_pattern'] ?? 'weekly') : null;
        $recurrenceDayOfWeek = $isRecurring && isset($_POST['recurrence_day_of_week']) && $_POST['recurrence_day_of_week'] !== ''
                               ? intval($_POST['recurrence_day_of_week']) : null;
        $recurrenceEndDate   = $isRecurring && !empty($_POST['plan_end_date']) ? $_POST['plan_end_date'] : null;
        $recurrenceInterval     = $isRecurring ? max(1, intval($_POST['recurrence_interval'] ?? 1)) : 1;
        $recurrenceIntervalUnit = $isRecurring ? ($_POST['recurrence_interval_unit'] ?? 'weeks') : 'weeks';

        // Map quick-pick presets to their interval values
        if ($recurrencePattern === 'daily') {
            $recurrencePattern = 'custom';
            $recurrenceInterval = 1;
            $recurrenceIntervalUnit = 'days';
        } elseif ($recurrencePattern === 'biweekly') {
            $recurrenceInterval = 2;
            $recurrenceIntervalUnit = 'weeks';
        } elseif ($recurrencePattern === 'yearly') {
            $recurrenceInterval = max(1, $recurrenceInterval);
            $recurrenceIntervalUnit = 'years';
        }

        // Validate custom interval unit
        if (!in_array($recurrenceIntervalUnit, ['days', 'weeks', 'months', 'years'], true)) {
            $recurrenceIntervalUnit = 'weeks';
        }

        // Parse line items from form
        $formItems = [];
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (empty($item['service_type'])) continue;
                $formItems[] = [
                    'quote_line_item_id' => null,
                    'product_id'         => !empty($item['product_id']) ? (int)$item['product_id'] : null,
                    'service_type'       => $item['service_type'],
                    'description'        => $item['description'] ?? '',
                    'quantity'           => floatval($item['quantity'] ?? 1),
                    'unit_type'          => $item['unit_type'] ?? 'visit',
                    'unit_price'         => floatval($item['unit_price'] ?? 0),
                    'line_total'         => floatval($item['line_total'] ?? 0),
                ];
            }
        }

        // Build plan data array
        $planData = [
            'property_id'              => $propertyId,
            'title'                    => $title,
            'description'              => $description,
            'service_type'             => $serviceType,
            'quote_id'                 => $linkedQuoteId ?: null,
            'contract_id'              => $linkedContractId ?: null,
            'is_recurring'             => $isRecurring,
            'recurrence_pattern'       => $recurrencePattern,
            'recurrence_interval'      => $recurrenceInterval,
            'recurrence_interval_unit' => $recurrenceIntervalUnit,
            'recurrence_day_of_week'   => $recurrenceDayOfWeek,
            'plan_start_date'          => $planStartDate,
            'plan_end_date'            => $planEndDate ?: $recurrenceEndDate,
            'pricing_model'            => $pricingModel,
            'price_per_visit'          => $pricePerVisit ?: null,
            'estimated_amount'         => $estimatedAmount ?: null,
            'default_crew_id'          => !empty($crewIds) ? $crewIds[0] : $defaultCrewId,
            'estimated_duration_minutes' => $estimatedDuration,
            'default_time_start'       => $defaultTimeStart,
            'default_time_end'         => $defaultTimeEnd,
            'horizon_days'             => $horizonDays,
            'line_items'               => !empty($formItems) ? $formItems : null,
            'crew_ids'                 => $crewIds,
        ];

        // Client validation
        if (!$propertyId) {
            $error = 'Please select a property.';
        } elseif (empty($title)) {
            $error = 'Please enter a plan title.';
        }

        if (empty($error)) {
            $result = createJobPlan($planData, (int)$user['id']);

            if ($result['success']) {
                if ($linkedContractId) {
                    header("Location: ../contracts/view.php?id={$linkedContractId}&plan_added=1");
                } else {
                    header("Location: view.php?id={$result['plan_id']}&created=1");
                }
                exit;
            } else {
                $error = implode(' ', $result['errors']);
            }
        }
    }
}

$csrfToken = generateCSRFToken();

$pageTitle = 'Create Job Plan';
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="index.php" class="mw-back-link">&larr; Back to Jobs</a>

          <h1 class="h3 mb-3">Create Job Plan</h1>
          <p class="text-muted mb-4"><?php echo $quoteId && isset($quote) ? 'Creating plan from accepted quote' : 'Set up a new service plan'; ?></p>

          <?php if ($error): ?>
              <div class="mw-error-message"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>

          <?php if ($quoteId && isset($quote)): ?>
              <div class="mw-info-banner">
                  <strong>Creating from Quote <?php echo htmlspecialchars($quote['quote_number']); ?></strong><br>
                  <?php echo htmlspecialchars($quote['company_name']); ?> &mdash; <?php echo htmlspecialchars($quote['address']); ?>
              </div>
          <?php elseif ($urlContractId): ?>
              <?php
              $ctrStmt = $db->prepare("SELECT contract_number FROM contracts WHERE id = ?");
              $ctrStmt->execute([$urlContractId]);
              $ctrRow = $ctrStmt->fetch(PDO::FETCH_ASSOC);
              ?>
              <?php if ($ctrRow): ?>
              <div class="mw-info-banner">
                  <strong>Adding plan to contract <?php echo htmlspecialchars($ctrRow['contract_number']); ?></strong><br>
                  <small class="text-muted">After saving, you'll return to the contract.</small>
              </div>
              <?php endif; ?>
          <?php endif; ?>

          <form method="POST" id="createPlanForm">
              <input type="hidden" name="csrf_token"   value="<?php echo $csrfToken; ?>">
              <input type="hidden" name="quote_id"    value="<?php echo $prefill['quote_id'] ?? ''; ?>">
              <input type="hidden" name="contract_id" value="<?php echo $urlContractId ?: ''; ?>">
              <input type="hidden" name="property_id" id="propertyIdInput" value="<?php echo $prefill['property_id'] ?? ''; ?>">

              <!-- Client / Property Selection Card -->
              <div class="card">
                  <div class="card-header">
                      <h5 class="card-title mb-0">Client &amp; Property</h5>
                  </div>
                  <div class="card-body">

                      <!-- Step 1: Contact / Company Search -->
                      <div class="mw-form-group">
                          <label class="form-label">Contact or Company *</label>
                          <div class="mw-search-wrapper">
                              <input type="text" id="clientSearch" class="form-control"
                                     placeholder="Start typing a name, company, email, or phone..."
                                     autocomplete="off"
                                     value="<?php echo htmlspecialchars($prefill['company_name'] ?? $prefill['contact_name'] ?? ''); ?>"
                                     <?php echo (!empty($prefill['company_id']) || !empty($prefill['contact_id'])) ? 'readonly' : ''; ?>>
                              <div id="clientSearchResults" class="mw-search-results"></div>
                          </div>
                          <div class="mw-search-actions">
                              <?php if (!empty($prefill['company_id']) || !empty($prefill['contact_id'])): ?>
                                  <a href="#" id="clearClient" class="mw-search-clear">Change client</a>
                              <?php endif; ?>
                              <a href="/crm/clients_appstack.php?action=create" target="_blank" class="mw-new-contact-btn">
                                  <i data-feather="user-plus"></i> New Contact
                              </a>
                          </div>
                          <input type="hidden" id="selectedContactId" value="<?php echo $prefill['contact_id'] ?? ''; ?>">
                          <input type="hidden" id="selectedCompanyId" value="<?php echo $prefill['company_id'] ?? ''; ?>">
                      </div>

                      <!-- Step 2: Property Selection (shown after client selected) -->
                      <div class="mw-form-group" id="propertySection" style="<?php echo !empty($prefill['property_id']) ? '' : 'display:none;'; ?>">
                          <label class="form-label">Property *</label>
                          <div id="propertyList" class="mw-property-list">
                              <?php if (!empty($prefill['property_id'])): ?>
                                  <div class="mw-property-card selected" data-property-id="<?php echo $prefill['property_id']; ?>">
                                      <i data-feather="map-pin"></i>
                                      <span><?php echo htmlspecialchars($prefill['property_address'] ?? ''); ?></span>
                                  </div>
                              <?php endif; ?>
                          </div>
                          <div id="noProperties" class="mw-no-properties" style="display:none;">
                              No properties found for this client.
                          </div>
                      </div>

                  </div>
              </div>

              <!-- Plan Details Card -->
              <div class="card">
                  <div class="card-header">
                      <h5 class="card-title mb-0">Plan Details</h5>
                  </div>
                  <div class="card-body">

                      <div class="mw-form-group">
                          <label class="form-label">Plan Title *</label>
                          <input type="text" name="title" class="form-control" required
                                 value="<?php echo htmlspecialchars($prefill['title'] ?? ''); ?>"
                                 placeholder="e.g., Weekly Lawn Mowing">
                      </div>

                      <div class="mw-form-row">
                          <div class="mw-form-group">
                              <label class="form-label">Service Type</label>
                              <select name="service_type" class="form-control">
                                  <option value="">-- Select Service --</option>
                                  <?php
                                  $currentCat = '';
                                  foreach ($products as $prod):
                                      $cat = $prod['category_name'] ?: 'Uncategorized';
                                      if ($cat !== $currentCat):
                                          if ($currentCat !== '') echo '</optgroup>';
                                          $currentCat = $cat;
                                  ?>
                                  <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                                  <?php endif; ?>
                                      <option value="<?php echo htmlspecialchars($prod['name']); ?>"
                                          <?php echo ($prefill['service_type'] ?? '') === $prod['name'] ? 'selected' : ''; ?>>
                                          <?php echo htmlspecialchars($prod['name']); ?>
                                      </option>
                                  <?php endforeach; ?>
                                  <?php if ($currentCat !== '') echo '</optgroup>'; ?>
                              </select>
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Plan Type</label>
                              <select name="plan_type" id="planType" class="form-control" onchange="toggleRecurring()">
                                  <option value="one_time">One-Time</option>
                                  <option value="recurring">Recurring</option>
                              </select>
                          </div>
                      </div>

                      <div class="mw-form-group">
                          <label class="form-label">Description</label>
                          <textarea name="description" class="form-control" rows="4"
                                    placeholder="Service details, special instructions..."><?php echo htmlspecialchars($prefill['description'] ?? ''); ?></textarea>
                      </div>

                  </div>
              </div>

              <!-- Plan Items Card (optional) -->
              <div class="card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                      <h5 class="card-title mb-0">Plan Items <small class="text-muted">(optional)</small></h5>
                      <div>
                          <a href="#" id="measurePropertyBtn" class="btn btn-sm btn-outline-primary mr-1" style="display:none;" target="_blank" title="Measure property areas">
                              <i data-feather="maximize-2" style="width:14px;height:14px;"></i> Measure Property
                          </a>
                          <a href="#" id="createQuoteBtn" class="btn btn-sm btn-primary mr-1" style="display:none;" title="Create a quote with measurement-based pricing">
                              <i data-feather="file-text" style="width:14px;height:14px;"></i> Quote & Measure
                          </a>
                          <button type="button" class="btn btn-sm btn-outline-success mr-1" id="autoFillBtn" onclick="autoFillFromMeasurements()" style="display:none;" title="Auto-fill items from property measurements">
                              <i data-feather="zap" style="width:14px;height:14px;"></i> Auto-Fill from Measurements
                          </button>
                          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addManualItem()">
                              <i data-feather="plus" style="width:14px;height:14px;"></i> Add Item
                          </button>
                      </div>
                  </div>
                  <div class="card-body">
                      <!-- No measurements message (hidden by default) -->
                      <div id="noMeasurementsMsg" style="display:none;" class="text-center py-3 mb-3" style="background:#f8f9fa; border-radius:6px;">
                          <i data-feather="maximize-2" style="width: 28px; height: 28px; color: #ccc;"></i>
                          <p class="mb-2 mt-2 text-muted">This property hasn't been measured yet. Measure it first, then use the Quote & Measure flow for automatic pricing.</p>
                          <a href="#" id="noMeasMeasureBtn" class="btn btn-sm btn-outline-primary mr-2" target="_blank">
                              <i data-feather="maximize-2" style="width:14px;height:14px;"></i> Measure Property
                          </a>
                          <a href="#" id="noMeasQuoteBtn" class="btn btn-sm btn-primary">
                              <i data-feather="file-text" style="width:14px;height:14px;"></i> Quote & Measure
                          </a>
                      </div>
                      <p class="text-muted small mb-3" id="itemsHint">Add service items to break down what's included in each visit. If no items are added, the plan will use the price per visit from the Pricing section below.</p>
                      <table class="mw-line-items-table" id="planItemsTable" style="display:none;">
                          <thead>
                              <tr>
                                  <th>Service</th>
                                  <th>Description</th>
                                  <th>Qty</th>
                                  <th class="text-right">Price</th>
                                  <th class="text-right">Total</th>
                                  <th></th>
                              </tr>
                          </thead>
                          <tbody id="planItemsBody"></tbody>
                          <tfoot>
                              <tr>
                                  <td colspan="4" class="text-right"><strong>Per Visit Total</strong></td>
                                  <td class="text-right"><strong id="planItemsTotal">$0.00</strong></td>
                                  <td></td>
                              </tr>
                          </tfoot>
                      </table>
                  </div>
              </div>

              <!-- Scheduling Card -->
              <div class="card">
                  <div class="card-header">
                      <h5 class="card-title mb-0">Scheduling</h5>
                  </div>
                  <div class="card-body">

                      <div class="mw-form-row three">
                          <div class="mw-form-group">
                              <label class="form-label">Plan Start Date</label>
                              <input type="date" name="plan_start_date" class="form-control"
                                     value="<?php echo date('Y-m-d'); ?>">
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Plan End Date</label>
                              <input type="date" name="plan_end_date" class="form-control"
                                     value=""
                                     placeholder="Leave blank for ongoing">
                              <small class="text-muted">Leave blank for ongoing plans</small>
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Horizon Days</label>
                              <input type="number" name="horizon_days" class="form-control" value="28" min="7" max="90" step="1">
                              <small class="text-muted">How far ahead to generate visits</small>
                          </div>
                      </div>

                      <div class="mw-form-row three">
                          <div class="mw-form-group">
                              <label class="form-label">Default Start Time</label>
                              <input type="time" name="default_time_start" class="form-control" value="09:00">
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Default End Time</label>
                              <input type="time" name="default_time_end" class="form-control" value="10:00">
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Estimated Duration (minutes)</label>
                              <input type="number" name="estimated_duration" class="form-control" value="60" min="15" step="15">
                          </div>
                      </div>

                      <div class="mw-form-group">
                          <label class="form-label">Crew Assignment</label>
                          <div class="mw-crew-wrapper">
                              <div class="mw-crew-chips" id="crewChips">
                                  <button type="button" class="mw-crew-add-btn" onclick="toggleCrewDropdown()">+ Assign</button>
                              </div>
                              <div class="mw-crew-dropdown" id="crewDropdown">
                                  <?php foreach ($staff as $s): ?>
                                      <div class="mw-crew-dropdown-item"
                                           data-id="<?php echo (int)$s['id']; ?>"
                                           data-name="<?php echo htmlspecialchars($s['full_name'], ENT_QUOTES); ?>"
                                           onclick="assignCrew(<?php echo (int)$s['id']; ?>, this.dataset.name)">
                                          <?php echo htmlspecialchars($s['full_name']); ?>
                                          <small class="text-muted"><?php echo htmlspecialchars($s['role']); ?></small>
                                      </div>
                                  <?php endforeach; ?>
                              </div>
                          </div>
                          <input type="hidden" name="default_crew_id" id="defaultCrewIdHidden" value="">
                          <small class="text-muted">First person assigned is the crew lead.</small>
                      </div>

                      <!-- Recurring options — Jobber-style (hidden by default) -->
                      <div class="mw-recurring-options" id="recurringOptions">
                          <h6 class="mb-3">Recurrence Settings</h6>

                          <div class="mw-freq-picker" id="freqPicker">
                              <button type="button" class="mw-freq-btn" data-freq="daily">Daily</button>
                              <button type="button" class="mw-freq-btn active" data-freq="weekly">Weekly</button>
                              <button type="button" class="mw-freq-btn" data-freq="monthly">Monthly</button>
                              <button type="button" class="mw-freq-btn" data-freq="yearly">Yearly</button>
                              <button type="button" class="mw-freq-btn" data-freq="custom">Custom</button>
                          </div>

                          <div class="mw-interval-row" id="intervalRow">
                              <span class="mw-interval-label">Every</span>
                              <input type="number" name="recurrence_interval" id="recurrenceInterval"
                                     class="form-control form-control-sm" value="1" min="1" max="365">
                              <span class="mw-interval-label" id="intervalUnitLabel">week(s)</span>
                          </div>

                          <div id="dowPickerWrap">
                              <label class="form-label mb-2">On</label>
                              <div class="mw-dow-picker" id="dowPicker">
                                  <button type="button" class="mw-dow-btn" data-dow="0">S</button>
                                  <button type="button" class="mw-dow-btn" data-dow="1">M</button>
                                  <button type="button" class="mw-dow-btn" data-dow="2">T</button>
                                  <button type="button" class="mw-dow-btn" data-dow="3">W</button>
                                  <button type="button" class="mw-dow-btn" data-dow="4">T</button>
                                  <button type="button" class="mw-dow-btn" data-dow="5">F</button>
                                  <button type="button" class="mw-dow-btn" data-dow="6">S</button>
                              </div>
                          </div>

                          <div id="customUnitWrap" style="display:none;" class="mb-2">
                              <select name="recurrence_interval_unit" id="recurrenceUnit" class="form-control form-control-sm" style="width:140px;">
                                  <option value="days">Days</option>
                                  <option value="weeks" selected>Weeks</option>
                                  <option value="months">Months</option>
                                  <option value="years">Years</option>
                              </select>
                          </div>

                          <input type="hidden" name="recurrence_pattern" id="recurrencePatternHidden" value="weekly">
                          <input type="hidden" name="recurrence_day_of_week" id="recurrenceDowHidden" value="">

                          <div class="mw-recurrence-summary" id="recurrenceSummary">
                              <i data-feather="repeat" style="width:14px;height:14px;"></i>
                              <span id="recurrenceSummaryText">Repeats every week</span>
                          </div>
                      </div>

                  </div>
              </div>

              <!-- Pricing Card -->
              <div class="card">
                  <div class="card-header">
                      <h5 class="card-title mb-0">Pricing</h5>
                  </div>
                  <div class="card-body">

                      <div class="mw-form-row">
                          <div class="mw-form-group">
                              <label class="form-label">Pricing Model</label>
                              <select name="pricing_model" class="form-control">
                                  <option value="per_visit">Per Visit</option>
                                  <option value="monthly_flat">Monthly Flat Rate</option>
                                  <option value="seasonal">Seasonal</option>
                                  <option value="custom">Custom</option>
                              </select>
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Price Per Visit</label>
                              <input type="number" name="price_per_visit" class="form-control" step="0.01" min="0"
                                     value="<?php echo htmlspecialchars($prefill['price_per_visit'] ?? '0'); ?>"
                                     placeholder="0.00">
                          </div>
                      </div>

                      <div class="mw-form-group">
                          <label class="form-label">Estimated Total Amount</label>
                          <input type="number" name="estimated_amount" class="form-control" step="0.01" min="0"
                                 value="<?php echo htmlspecialchars($prefill['estimated_amount'] ?? '0'); ?>"
                                 placeholder="0.00">
                          <small class="text-muted">Total estimated value of this plan (optional)</small>
                      </div>

                  </div>
              </div>

              <div class="mw-form-actions">
                  <button type="submit" class="btn btn-primary">Create Plan</button>
                  <a href="index.php" class="btn btn-secondary">Cancel</a>
              </div>
          </form>

          <script>
          var availableProducts = <?php echo json_encode(array_map(function($p) {
              return ['id' => (int)$p['id'], 'name' => $p['name'], 'category' => $p['category_name'] ?: 'Uncategorized', 'price' => (float)$p['base_price']];
          }, $products)); ?>;

          (function() {
              var searchInput = document.getElementById('clientSearch');
              var resultsDiv = document.getElementById('clientSearchResults');
              var contactIdInput = document.getElementById('selectedContactId');
              var companyIdInput = document.getElementById('selectedCompanyId');
              var propertyIdInput = document.getElementById('propertyIdInput');
              var propertySection = document.getElementById('propertySection');
              var propertyList = document.getElementById('propertyList');
              var noProperties = document.getElementById('noProperties');
              var debounceTimer = null;

              // ── Typeahead search ──
              searchInput.addEventListener('input', function() {
                  var query = this.value.trim();
                  clearTimeout(debounceTimer);

                  if (query.length < 2) {
                      resultsDiv.style.display = 'none';
                      resultsDiv.innerHTML = '';
                      return;
                  }

                  debounceTimer = setTimeout(function() {
                      fetch('create.php?action=search_contacts&q=' + encodeURIComponent(query))
                          .then(function(r) { return r.json(); })
                          .then(function(data) {
                              if (!data.results || data.results.length === 0) {
                                  resultsDiv.innerHTML = '<div class="mw-search-empty">No results found</div>';
                                  resultsDiv.style.display = 'block';
                                  return;
                              }

                              var html = '';
                              data.results.forEach(function(item) {
                                  html += '<div class="mw-search-item"'
                                      + ' data-contact-id="' + (item.contact_id || '') + '"'
                                      + ' data-company-id="' + (item.company_id || '') + '"'
                                      + ' data-label="' + escapeAttr(item.label) + '"'
                                      + ' data-type="' + (item.type || '') + '">';
                                  html += '<div class="mw-search-item-name">' + escapeHtml(item.label) + '</div>';
                                  if (item.subtitle) {
                                      html += '<div class="mw-search-item-sub">' + escapeHtml(item.subtitle) + '</div>';
                                  }
                                  html += '</div>';
                              });
                              resultsDiv.innerHTML = html;
                              resultsDiv.style.display = 'block';

                              // Attach click handlers
                              var items = resultsDiv.querySelectorAll('.mw-search-item');
                              items.forEach(function(el) {
                                  el.addEventListener('click', function() {
                                      selectClient(
                                          this.dataset.contactId,
                                          this.dataset.companyId,
                                          this.dataset.label
                                      );
                                  });
                              });
                          })
                          .catch(function() {
                              resultsDiv.innerHTML = '<div class="mw-search-empty">Search error</div>';
                              resultsDiv.style.display = 'block';
                          });
                  }, 250);
              });

              // Close results on outside click
              document.addEventListener('click', function(e) {
                  if (!e.target.closest('.mw-search-wrapper')) {
                      resultsDiv.style.display = 'none';
                  }
              });

              // ── Select a client (contact or company) ──
              function selectClient(contactId, companyId, label) {
                  contactIdInput.value = contactId || '';
                  companyIdInput.value = companyId || '';
                  searchInput.value = label;
                  searchInput.readOnly = true;
                  resultsDiv.style.display = 'none';

                  // Show "Change client" link
                  showClearLink();

                  // Load properties for this contact/company
                  loadProperties(contactId, companyId);
              }

              // ── Clear client selection ──
              function clearClient(e) {
                  if (e) e.preventDefault();
                  contactIdInput.value = '';
                  companyIdInput.value = '';
                  propertyIdInput.value = '';
                  searchInput.value = '';
                  searchInput.readOnly = false;
                  searchInput.focus();
                  propertySection.style.display = 'none';
                  propertyList.innerHTML = '';
                  noProperties.style.display = 'none';
                  removeClearLink();
              }

              function showClearLink() {
                  var actions = document.querySelector('.mw-search-actions');
                  if (!actions.querySelector('#clearClient')) {
                      var link = document.createElement('a');
                      link.href = '#';
                      link.id = 'clearClient';
                      link.className = 'mw-search-clear';
                      link.textContent = 'Change client';
                      link.addEventListener('click', clearClient);
                      actions.insertBefore(link, actions.firstChild);
                  }
              }

              function removeClearLink() {
                  var link = document.getElementById('clearClient');
                  if (link) link.remove();
              }

              // Wire up existing clear link (for prefill)
              var existingClear = document.getElementById('clearClient');
              if (existingClear) {
                  existingClear.addEventListener('click', clearClient);
              }

              // ── Load properties for contact/company ──
              function loadProperties(contactId, companyId) {
                  propertySection.style.display = '';
                  propertyList.innerHTML = '<div class="mw-property-loading">Loading properties...</div>';
                  noProperties.style.display = 'none';

                  var params = [];
                  if (contactId) params.push('contact_id=' + contactId);
                  if (companyId) params.push('company_id=' + companyId);

                  fetch('create.php?action=get_properties&' + params.join('&'))
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          propertyList.innerHTML = '';

                          if (!data.properties || data.properties.length === 0) {
                              noProperties.style.display = '';
                              return;
                          }

                          data.properties.forEach(function(prop) {
                              var card = document.createElement('div');
                              card.className = 'mw-property-card';
                              card.dataset.propertyId = prop.id;

                              var typeLabel = (prop.property_type || 'property').replace('_', ' ');
                              var addrParts = [prop.address];
                              if (prop.city) addrParts.push(prop.city);

                              card.innerHTML = '<i data-feather="map-pin"></i>'
                                  + '<div class="mw-property-card-info">'
                                  + '<span class="mw-property-card-addr">' + escapeHtml(addrParts.join(', ')) + '</span>'
                                  + '<span class="mw-property-card-type">' + escapeHtml(typeLabel) + '</span>'
                                  + '</div>';

                              card.addEventListener('click', function() {
                                  selectProperty(this);
                              });

                              propertyList.appendChild(card);
                          });

                          // Render feather icons in new cards
                          if (typeof feather !== 'undefined') feather.replace();

                          // Auto-select if only one property
                          if (data.properties.length === 1) {
                              selectProperty(propertyList.querySelector('.mw-property-card'));
                          }
                      })
                      .catch(function() {
                          propertyList.innerHTML = '<div class="mw-property-loading">Error loading properties</div>';
                      });
              }

              // ── Select a property ──
              function selectProperty(card) {
                  // Deselect all
                  var cards = propertyList.querySelectorAll('.mw-property-card');
                  cards.forEach(function(c) { c.classList.remove('selected'); });

                  // Select this one
                  card.classList.add('selected');
                  var propId = card.dataset.propertyId;
                  propertyIdInput.value = propId;

                  // Show auto-fill button now that a property is selected
                  var afBtn = document.getElementById('autoFillBtn');
                  if (afBtn) afBtn.style.display = '';

                  // Set measurement and quote links
                  var contactId = contactIdInput.value;
                  var measureUrl = '/crm/products/area-measurement.php?property_id=' + propId;
                  var quoteUrl = '/crm/quote-workflow.php?contact_id=' + contactId + '&property_id=' + propId;

                  var measureBtn = document.getElementById('measurePropertyBtn');
                  var quoteBtn = document.getElementById('createQuoteBtn');
                  var noMeasMeasure = document.getElementById('noMeasMeasureBtn');
                  var noMeasQuote = document.getElementById('noMeasQuoteBtn');
                  if (measureBtn) { measureBtn.href = measureUrl; measureBtn.style.display = ''; }
                  if (quoteBtn) { quoteBtn.href = quoteUrl; quoteBtn.style.display = ''; }
                  if (noMeasMeasure) noMeasMeasure.href = measureUrl;
                  if (noMeasQuote) noMeasQuote.href = quoteUrl;
                  if (typeof feather !== 'undefined') feather.replace();

                  // Hide the no-measurements message when switching properties
                  var noMeasMsg = document.getElementById('noMeasurementsMsg');
                  if (noMeasMsg) noMeasMsg.style.display = 'none';
              }

              // ── If prefilled, load properties on page load ──
              var prefillContactId = contactIdInput.value;
              var prefillCompanyId = companyIdInput.value;
              if ((prefillContactId || prefillCompanyId) && !propertyList.querySelector('.mw-property-card')) {
                  loadProperties(prefillContactId, prefillCompanyId);
              }

              // ── Helpers ──
              function escapeHtml(str) {
                  var div = document.createElement('div');
                  div.textContent = str;
                  return div.innerHTML;
              }
              function escapeAttr(str) {
                  return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
              }

              // ── Plan Items Management ──
              var itemIndex = 0;

              function buildProductOptions(selectedVal) {
                  var html = '<option value="">-- Select --</option>';
                  var currentCat = '';
                  availableProducts.forEach(function(p) {
                      if (p.category !== currentCat) {
                          if (currentCat !== '') html += '</optgroup>';
                          currentCat = p.category;
                          html += '<optgroup label="' + escapeAttr(currentCat) + '">';
                      }
                      var sel = (selectedVal && selectedVal === p.name) ? ' selected' : '';
                      html += '<option value="' + escapeAttr(p.name) + '" data-price="' + p.price + '" data-product-id="' + p.id + '"' + sel + '>' + escapeHtml(p.name) + '</option>';
                  });
                  if (currentCat !== '') html += '</optgroup>';
                  return html;
              }

              window.addManualItem = function() {
                  var table = document.getElementById('planItemsTable');
                  var body = document.getElementById('planItemsBody');
                  var hint = document.getElementById('itemsHint');

                  table.style.display = '';
                  if (hint) hint.style.display = 'none';

                  var idx = itemIndex++;
                  var tr = document.createElement('tr');
                  tr.innerHTML =
                      '<td><select name="items[' + idx + '][service_type]" class="form-control form-control-sm mw-item-service" onchange="onItemServiceChange(this)" required>' + buildProductOptions('') + '</select>' +
                          '<input type="hidden" name="items[' + idx + '][product_id]" class="mw-item-product-id" value=""></td>' +
                      '<td><input type="text" name="items[' + idx + '][description]" class="form-control form-control-sm" placeholder="Description"></td>' +
                      '<td><input type="number" name="items[' + idx + '][quantity]" class="form-control form-control-sm mw-cfq-qty" value="1" min="0.01" step="0.01" onchange="recalcItemRow(this)" style="width:70px;"></td>' +
                      '<td><input type="number" name="items[' + idx + '][unit_price]" class="form-control form-control-sm mw-cfq-price text-right" value="0" min="0" step="0.01" onchange="recalcItemRow(this)" style="width:90px;">' +
                          '<input type="hidden" name="items[' + idx + '][unit_type]" value="visit"></td>' +
                      '<td class="text-right"><span class="mw-cfq-row-total">$0.00</span>' +
                          '<input type="hidden" name="items[' + idx + '][line_total]" class="mw-cfq-total-input" value="0"></td>' +
                      '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItemRow(this)" title="Remove">&times;</button></td>';
                  body.appendChild(tr);
              };

              window.recalcItemRow = function(input) {
                  var tr = input.closest('tr');
                  var qty = parseFloat(tr.querySelector('.mw-cfq-qty').value) || 0;
                  var price = parseFloat(tr.querySelector('.mw-cfq-price').value) || 0;
                  var total = qty * price;
                  tr.querySelector('.mw-cfq-row-total').textContent = '$' + total.toFixed(2);
                  tr.querySelector('.mw-cfq-total-input').value = total.toFixed(2);
                  updateItemTotals();
              };

              window.onItemServiceChange = function(select) {
                  var opt = select.options[select.selectedIndex];
                  var tr = select.closest('tr');
                  // Set hidden product_id from selected option
                  var pidInput = tr.querySelector('.mw-item-product-id');
                  if (pidInput) pidInput.value = opt.dataset.productId || '';
                  var price = parseFloat(opt.dataset.price) || 0;
                  if (price > 0) {
                      var priceInput = tr.querySelector('.mw-cfq-price');
                      if (priceInput && (parseFloat(priceInput.value) === 0 || priceInput.value === '')) {
                          priceInput.value = price.toFixed(2);
                          recalcItemRow(priceInput);
                      }
                  }
              };

              window.removeItemRow = function(btn) {
                  btn.closest('tr').remove();
                  updateItemTotals();
                  var body = document.getElementById('planItemsBody');
                  if (body.children.length === 0) {
                      document.getElementById('planItemsTable').style.display = 'none';
                      var hint = document.getElementById('itemsHint');
                      if (hint) hint.style.display = '';
                  }
              };

              function updateItemTotals() {
                  var inputs = document.querySelectorAll('.mw-cfq-total-input');
                  var sum = 0;
                  inputs.forEach(function(inp) { sum += parseFloat(inp.value) || 0; });
                  var totalEl = document.getElementById('planItemsTotal');
                  if (totalEl) totalEl.textContent = '$' + sum.toFixed(2);

                  // Auto-sync to price_per_visit if items exist
                  if (inputs.length > 0 && sum > 0) {
                      var ppv = document.querySelector('[name="price_per_visit"]');
                      var ea = document.querySelector('[name="estimated_amount"]');
                      if (ppv) ppv.value = sum.toFixed(2);
                      if (ea) ea.value = sum.toFixed(2);
                  }
              }

              // ── Auto-fill from measurements ──
              window.autoFillFromMeasurements = function() {
                  var propId = propertyIdInput.value;
                  if (!propId) {
                      alert('Please select a property first.');
                      return;
                  }

                  var btn = document.getElementById('autoFillBtn');
                  var origText = btn.innerHTML;
                  btn.disabled = true;
                  btn.innerHTML = '<i data-feather="loader" style="width:14px;height:14px;"></i> Loading...';
                  if (typeof feather !== 'undefined') feather.replace();

                  // Call the existing auto-fill API
                  fetch('/crm/api/quote-autofill.php?action=auto-fill', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                      body: 'property_id=' + propId
                  })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      btn.disabled = false;
                      btn.innerHTML = origText;
                      if (typeof feather !== 'undefined') feather.replace();

                      if (!data.success || !data.items || data.items.length === 0) {
                          // Show inline message with measure/quote links
                          var noMeasMsg = document.getElementById('noMeasurementsMsg');
                          if (noMeasMsg) {
                              noMeasMsg.style.display = '';
                              if (typeof feather !== 'undefined') feather.replace();
                          }
                          return;
                      }

                      // Clear existing items
                      var body = document.getElementById('planItemsBody');
                      body.innerHTML = '';
                      itemIndex = 0;

                      var table = document.getElementById('planItemsTable');
                      var hint = document.getElementById('itemsHint');
                      table.style.display = '';
                      if (hint) hint.style.display = 'none';

                      // Insert auto-filled items
                      data.items.forEach(function(item) {
                          var idx = itemIndex++;
                          var lineTotal = parseFloat(item.line_total) || 0;
                          var tr = document.createElement('tr');
                          // Find product_id from the selected option
                          var tempDiv = document.createElement('div');
                          tempDiv.innerHTML = '<select>' + buildProductOptions(item.service_type || '') + '</select>';
                          var selOpt = tempDiv.querySelector('option[selected]');
                          var itemProductId = selOpt ? (selOpt.dataset.productId || '') : '';
                          tr.innerHTML =
                              '<td><select name="items[' + idx + '][service_type]" class="form-control form-control-sm mw-item-service" onchange="onItemServiceChange(this)" required>' + buildProductOptions(item.service_type || '') + '</select>' +
                                  '<input type="hidden" name="items[' + idx + '][product_id]" class="mw-item-product-id" value="' + itemProductId + '"></td>' +
                              '<td><input type="text" name="items[' + idx + '][description]" class="form-control form-control-sm" value="' + escapeAttr(item.description || '') + '"></td>' +
                              '<td><input type="number" name="items[' + idx + '][quantity]" class="form-control form-control-sm mw-cfq-qty" value="' + (item.quantity || 1) + '" min="0.01" step="0.01" onchange="recalcItemRow(this)" style="width:70px;"></td>' +
                              '<td><input type="number" name="items[' + idx + '][unit_price]" class="form-control form-control-sm mw-cfq-price text-right" value="' + (parseFloat(item.unit_price) || 0).toFixed(2) + '" min="0" step="0.01" onchange="recalcItemRow(this)" style="width:90px;">' +
                                  '<input type="hidden" name="items[' + idx + '][unit_type]" value="visit"></td>' +
                              '<td class="text-right"><span class="mw-cfq-row-total">$' + lineTotal.toFixed(2) + '</span>' +
                                  '<input type="hidden" name="items[' + idx + '][line_total]" class="mw-cfq-total-input" value="' + lineTotal.toFixed(2) + '"></td>' +
                              '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItemRow(this)" title="Remove">&times;</button></td>';
                          body.appendChild(tr);
                      });

                      updateItemTotals();

                      // Show warnings if any
                      if (data.warnings && data.warnings.length > 0) {
                          alert('Auto-filled ' + data.items.length + ' item(s). Note: ' + data.warnings.join('; '));
                      }
                  })
                  .catch(function(err) {
                      btn.disabled = false;
                      btn.innerHTML = origText;
                      if (typeof feather !== 'undefined') feather.replace();
                      alert('Error connecting to pricing API. Please try again.');
                  });
              };

              // Show auto-fill button and set links if property is already selected (prefill)
              if (propertyIdInput.value) {
                  var afBtn = document.getElementById('autoFillBtn');
                  if (afBtn) afBtn.style.display = '';

                  var propId = propertyIdInput.value;
                  var contactId = contactIdInput.value;
                  var measureUrl = '/crm/products/area-measurement.php?property_id=' + propId;
                  var quoteUrl = '/crm/quote-workflow.php?contact_id=' + contactId + '&property_id=' + propId;

                  var measureBtn = document.getElementById('measurePropertyBtn');
                  var quoteBtn = document.getElementById('createQuoteBtn');
                  var noMeasMeasure = document.getElementById('noMeasMeasureBtn');
                  var noMeasQuote = document.getElementById('noMeasQuoteBtn');
                  if (measureBtn) { measureBtn.href = measureUrl; measureBtn.style.display = ''; }
                  if (quoteBtn) { quoteBtn.href = quoteUrl; quoteBtn.style.display = ''; }
                  if (noMeasMeasure) noMeasMeasure.href = measureUrl;
                  if (noMeasQuote) noMeasQuote.href = quoteUrl;
              }

              // ── Recurring toggle ──
              window.toggleRecurring = function() {
                  var planType = document.getElementById('planType').value;
                  var recurringOptions = document.getElementById('recurringOptions');
                  if (planType === 'recurring') {
                      recurringOptions.classList.add('show');
                  } else {
                      recurringOptions.classList.remove('show');
                  }
              };

              // ── Jobber-Style Recurrence Controls ──────────────
              var currentFreq = 'weekly';
              var selectedDow = null;
              var dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

              document.querySelectorAll('.mw-freq-btn').forEach(function(btn) {
                  btn.addEventListener('click', function() {
                      document.querySelectorAll('.mw-freq-btn').forEach(function(b) { b.classList.remove('active'); });
                      this.classList.add('active');
                      currentFreq = this.dataset.freq;
                      updateRecurrenceUI();
                  });
              });

              document.querySelectorAll('.mw-dow-btn').forEach(function(btn) {
                  btn.addEventListener('click', function() {
                      document.querySelectorAll('.mw-dow-btn').forEach(function(b) { b.classList.remove('active'); });
                      this.classList.add('active');
                      selectedDow = parseInt(this.dataset.dow);
                      updateHiddenFields();
                      updateSummaryText();
                  });
              });

              document.getElementById('recurrenceInterval').addEventListener('input', function() {
                  updateHiddenFields();
                  updateSummaryText();
              });

              var unitSelect = document.getElementById('recurrenceUnit');
              if (unitSelect) unitSelect.addEventListener('change', function() { updateSummaryText(); });

              function updateRecurrenceUI() {
                  var dowWrap = document.getElementById('dowPickerWrap');
                  var customUnitWrap = document.getElementById('customUnitWrap');
                  var unitLabel = document.getElementById('intervalUnitLabel');
                  dowWrap.style.display = 'none';
                  customUnitWrap.style.display = 'none';
                  unitLabel.style.display = '';
                  switch (currentFreq) {
                      case 'daily': unitLabel.textContent = 'day(s)'; break;
                      case 'weekly': unitLabel.textContent = 'week(s)'; dowWrap.style.display = ''; break;
                      case 'monthly': unitLabel.textContent = 'month(s)'; break;
                      case 'yearly': unitLabel.textContent = 'year(s)'; break;
                      case 'custom': customUnitWrap.style.display = ''; unitLabel.style.display = 'none'; break;
                  }
                  updateHiddenFields();
                  updateSummaryText();
                  if (typeof feather !== 'undefined') feather.replace();
              }

              function updateHiddenFields() {
                  document.getElementById('recurrencePatternHidden').value = currentFreq;
                  document.getElementById('recurrenceDowHidden').value = (selectedDow !== null) ? selectedDow : '';
              }

              function updateSummaryText() {
                  var interval = parseInt(document.getElementById('recurrenceInterval').value) || 1;
                  var text = 'Repeats ';
                  switch (currentFreq) {
                      case 'daily': text += interval === 1 ? 'every day' : 'every ' + interval + ' days'; break;
                      case 'weekly':
                          text += interval === 1 ? 'every week' : 'every ' + interval + ' weeks';
                          if (selectedDow !== null) text += ' on ' + dayNames[selectedDow]; break;
                      case 'monthly': text += interval === 1 ? 'every month' : 'every ' + interval + ' months'; break;
                      case 'yearly': text += interval === 1 ? 'every year' : 'every ' + interval + ' years'; break;
                      case 'custom':
                          var unit = document.getElementById('recurrenceUnit').value;
                          text += 'every ' + interval + ' ' + unit; break;
                  }
                  document.getElementById('recurrenceSummaryText').textContent = text;
              }

              // ── Multi-Crew Assignment ─────────────────────────
              var assignedCrew = [];

              window.toggleCrewDropdown = function() {
                  var dd = document.getElementById('crewDropdown');
                  dd.classList.toggle('show');
                  dd.querySelectorAll('.mw-crew-dropdown-item').forEach(function(item) {
                      var id = parseInt(item.dataset.id);
                      item.classList.toggle('disabled', assignedCrew.some(function(c) { return c.id === id; }));
                  });
              };

              window.assignCrew = function(id, name) {
                  if (assignedCrew.some(function(c) { return c.id === id; })) return;
                  assignedCrew.push({ id: id, name: name });
                  renderCrewChips();
                  document.getElementById('crewDropdown').classList.remove('show');
              };

              window.removeCrew = function(id) {
                  assignedCrew = assignedCrew.filter(function(c) { return c.id !== id; });
                  renderCrewChips();
              };

              function renderCrewChips() {
                  var container = document.getElementById('crewChips');
                  var html = '';
                  assignedCrew.forEach(function(c, idx) {
                      var isLead = (idx === 0);
                      html += '<span class="mw-crew-chip ' + (isLead ? 'mw-crew-lead' : '') + '">' +
                          escHtml(c.name) + (isLead ? ' (Lead)' : '') +
                          '<button type="button" class="mw-crew-chip-remove" onclick="removeCrew(' + c.id + ')">&times;</button>' +
                          '<input type="hidden" name="crew_ids[]" value="' + c.id + '">' +
                          '</span>';
                  });
                  html += '<button type="button" class="mw-crew-add-btn" onclick="toggleCrewDropdown()">+ Assign</button>';
                  container.innerHTML = html;
                  document.getElementById('defaultCrewIdHidden').value = assignedCrew.length > 0 ? assignedCrew[0].id : '';
              }

              function escHtml(str) {
                  if (!str) return '';
                  var d = document.createElement('div');
                  d.textContent = str;
                  return d.innerHTML;
              }

              document.addEventListener('click', function(e) {
                  if (!e.target.closest('.mw-crew-wrapper')) {
                      var dd = document.getElementById('crewDropdown');
                      if (dd) dd.classList.remove('show');
                  }
              });

              // ── Form validation ──
              document.getElementById('createPlanForm').addEventListener('submit', function(e) {
                  if (!propertyIdInput.value) {
                      e.preventDefault();
                      alert('Please select a client and property before creating a plan.');
                  }
              });
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
