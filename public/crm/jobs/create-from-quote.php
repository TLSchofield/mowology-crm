<?php
/**
 * Create Plans from Accepted Quote
 *
 * Split-panel page: left = plan creation form, right = quote reference with items.
 * User clicks quote items to add them to the plan, sets recurrence, submits.
 * After creating a plan, returns here if items remain unconverted.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.edit');

$db = getDB();

$quoteId = intval($_GET['quote_id'] ?? 0);
if (!$quoteId) {
    header('Location: ../quotes/index.php');
    exit;
}

// ─── AJAX: Get next scheduled visit at property ───
if (isset($_GET['action']) && $_GET['action'] === 'next_visit_date') {
    header('Content-Type: application/json');
    $propId = intval($_GET['property_id'] ?? 0);
    $nextDate = $propId ? getNextScheduledVisitDate($propId) : null;
    echo json_encode(['date' => $nextDate]);
    exit;
}

// ─── Load quote ───
$stmt = $db->prepare("
    SELECT q.*,
           p.address AS property_address, p.city AS property_city, p.id AS prop_id,
           c.company_name,
           ct.first_name AS contact_first, ct.last_name AS contact_last,
           qr.contact_id AS qr_contact_id,
           qrc.first_name AS qr_first_name, qrc.last_name AS qr_last_name
    FROM quotes q
    JOIN properties p ON q.property_id = p.id
    LEFT JOIN companies c ON q.company_id = c.id
    LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
    LEFT JOIN quote_requests qr ON qr.quote_id = q.id
    LEFT JOIN contacts qrc ON qr.contact_id = qrc.id
    WHERE q.id = ? AND q.status = 'accepted'
");
$stmt->execute([$quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    $_SESSION['alert'] = ['type' => 'error', 'message' => 'Quote not found or not accepted.'];
    header('Location: ../quotes/index.php');
    exit;
}

// ─── Load quote line items with conversion status ───
$lineItems = getQuoteLineItemsWithStatus($quoteId);

// Count unconverted items
$unconvertedCount = 0;
foreach ($lineItems as $li) {
    if (empty($li['plan_id'])) {
        $unconvertedCount++;
    }
}

// If all items are already converted, redirect to quote view
if ($unconvertedCount === 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['alert'] = ['type' => 'success', 'message' => 'All quote items have been converted to plans.'];
    header("Location: ../quotes/view.php?id={$quoteId}");
    exit;
}

// Resolve contact name
$contactName = trim(($quote['qr_first_name'] ?? '') . ' ' . ($quote['qr_last_name'] ?? ''));
if (!$contactName) {
    $contactName = trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''));
}
if (!$contactName) {
    $contactName = $quote['company_name'] ?? 'Unknown';
}

// Get staff for crew dropdown
$staff = getStaffMembers();

// Check if property has existing visits (for "align" option)
$nextVisitDate = getNextScheduledVisitDate((int)$quote['property_id']);

// ─── Handle form submission ───
$error = '';
$justCreatedPlan = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $title            = trim($_POST['title'] ?? '');
    $serviceType      = $_POST['service_type'] ?? 'landscaping';
    $planType         = $_POST['plan_type'] ?? 'one_time';
    $planStartDate    = $_POST['plan_start_date'] ?? date('Y-m-d');
    $planEndDate      = !empty($_POST['plan_end_date']) ? $_POST['plan_end_date'] : null;
    $defaultTimeStart = !empty($_POST['default_time_start']) ? $_POST['default_time_start'] : null;
    $defaultTimeEnd   = !empty($_POST['default_time_end']) ? $_POST['default_time_end'] : null;
    $estimatedDuration = intval($_POST['estimated_duration'] ?? 60);
    $defaultCrewId    = !empty($_POST['default_crew_id']) ? intval($_POST['default_crew_id']) : null;
    $horizonDays      = intval($_POST['horizon_days'] ?? 28);
    $description      = trim($_POST['description'] ?? '');

    // Recurring fields
    $isRecurring       = ($planType === 'recurring') ? 1 : 0;
    $recurrencePattern = $isRecurring ? ($_POST['recurrence_pattern'] ?? 'weekly') : null;
    $recurrenceDow     = $isRecurring && isset($_POST['recurrence_day_of_week']) && $_POST['recurrence_day_of_week'] !== ''
                         ? intval($_POST['recurrence_day_of_week']) : null;
    $recurrenceInterval = $isRecurring ? max(1, intval($_POST['recurrence_interval'] ?? 1)) : 1;
    $recurrenceUnit    = $isRecurring ? ($_POST['recurrence_interval_unit'] ?? 'weeks') : 'weeks';

    // Map presets
    if ($recurrencePattern === 'daily') {
        $recurrencePattern = 'custom';
        $recurrenceInterval = 1;
        $recurrenceUnit = 'days';
    } elseif ($recurrencePattern === 'biweekly') {
        $recurrenceInterval = 2;
        $recurrenceUnit = 'weeks';
    }

    if (!in_array($recurrenceUnit, ['days', 'weeks', 'months'], true)) {
        $recurrenceUnit = 'weeks';
    }

    // Align with existing visit
    if (!empty($_POST['align_with_existing']) && $nextVisitDate) {
        $planStartDate = $nextVisitDate;
    }

    // Parse line items from form
    $formItems = [];
    if (!empty($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $item) {
            if (empty($item['service_type'])) continue;
            $formItems[] = [
                'quote_line_item_id' => intval($item['quote_line_item_id'] ?? 0) ?: null,
                'service_type'       => $item['service_type'],
                'description'        => $item['description'] ?? '',
                'quantity'           => floatval($item['quantity'] ?? 1),
                'unit_type'          => $item['unit_type'] ?? 'visit',
                'unit_price'         => floatval($item['unit_price'] ?? 0),
                'line_total'         => floatval($item['line_total'] ?? 0),
            ];
        }
    }

    // Validation
    if (empty($title)) {
        $error = 'Please enter a plan title.';
    } elseif (empty($formItems)) {
        $error = 'Please add at least one service item to the plan.';
    }

    if (!$error) {
        $planData = [
            'property_id'              => $quote['property_id'],
            'company_id'               => $quote['company_id'],
            'quote_id'                 => $quoteId,
            'title'                    => $title,
            'description'              => $description,
            'service_type'             => $serviceType,
            'is_recurring'             => $isRecurring,
            'recurrence_pattern'       => $recurrencePattern,
            'recurrence_interval'      => $recurrenceInterval,
            'recurrence_interval_unit' => $recurrenceUnit,
            'recurrence_day_of_week'   => $recurrenceDow,
            'plan_start_date'          => $planStartDate,
            'plan_end_date'            => $planEndDate,
            'pricing_model'            => 'per_visit',
            'default_crew_id'          => $defaultCrewId,
            'estimated_duration_minutes' => $estimatedDuration,
            'default_time_start'       => $defaultTimeStart,
            'default_time_end'         => $defaultTimeEnd,
            'horizon_days'             => $horizonDays,
            'line_items'               => $formItems,
        ];

        $result = createJobPlan($planData, (int)$user['id']);

        if ($result['success']) {
            // Log activity
            if (function_exists('logActivityExtended')) {
                logActivityExtended(
                    $user['id'],
                    'Plan created from quote',
                    "Plan {$result['plan_number']} created from quote {$quote['quote_number']} with " . count($formItems) . " item(s)",
                    $quote['company_id'],
                    null, $quoteId, null,
                    $result['plan_id']
                );
            }

            // Reload line items to check if any remain
            $lineItems = getQuoteLineItemsWithStatus($quoteId);
            $remaining = 0;
            foreach ($lineItems as $li) {
                if (empty($li['plan_id'])) $remaining++;
            }

            if ($remaining > 0) {
                // More items to convert — stay on this page
                $justCreatedPlan = $result;
                $unconvertedCount = $remaining;
            } else {
                // All done — go to quote view
                $_SESSION['alert'] = ['type' => 'success', 'message' => "All quote items converted. Last plan: {$result['plan_number']}"];
                header("Location: ../quotes/view.php?id={$quoteId}");
                exit;
            }
        } else {
            $error = implode(' ', $result['errors']);
        }
    }
}

$csrfToken = generateCSRFToken();

$pageTitle = 'Create Plans from ' . htmlspecialchars($quote['quote_number']);
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="../quotes/view.php?id=<?php echo $quoteId; ?>" class="mw-back-link">&larr; Back to Quote <?php echo htmlspecialchars($quote['quote_number']); ?></a>

          <h1 class="h3 mb-1">Create Plans from Quote</h1>
          <p class="text-muted mb-4">
              <?php echo htmlspecialchars($quote['quote_number']); ?> &mdash;
              <?php echo htmlspecialchars($contactName); ?> &mdash;
              <?php echo htmlspecialchars($quote['property_address'] . ', ' . $quote['property_city']); ?>
          </p>

          <?php if ($justCreatedPlan): ?>
              <div class="mw-message success">
                  Plan <strong><?php echo htmlspecialchars($justCreatedPlan['plan_number']); ?></strong> created successfully!
                  <a href="view.php?id=<?php echo $justCreatedPlan['plan_id']; ?>">View Plan</a>
                  &mdash; <?php echo $unconvertedCount; ?> item(s) remaining.
              </div>
          <?php endif; ?>

          <?php if ($error): ?>
              <div class="mw-error-message"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>

          <div class="row">
              <!-- ═══ LEFT: Plan Creation Form ═══ -->
              <div class="col-lg-7">
                  <form method="POST" id="createPlanForm">
                      <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                      <!-- Plan Items (populated from quote items) -->
                      <div class="card">
                          <div class="card-header d-flex justify-content-between align-items-center">
                              <h5 class="card-title mb-0">Plan Items</h5>
                              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addManualItem()">
                                  <i data-feather="plus" style="width:14px;height:14px;"></i> Add Item
                              </button>
                          </div>
                          <div class="card-body">
                              <div class="mw-cfq-items-empty" id="itemsEmpty">
                                  Click items from the quote panel on the right to add them here.
                              </div>
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

                      <!-- Plan Details -->
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Plan Details</h5>
                          </div>
                          <div class="card-body">
                              <div class="mw-form-group">
                                  <label class="form-label">Plan Title *</label>
                                  <input type="text" name="title" id="planTitle" class="form-control" required
                                         placeholder="e.g., Weekly Lawn Care">
                              </div>

                              <div class="mw-form-row">
                                  <div class="mw-form-group">
                                      <label class="form-label">Service Type</label>
                                      <select name="service_type" id="serviceTypeSelect" class="form-control">
                                          <option value="landscaping">Landscaping</option>
                                          <option value="lawn_care">Lawn Care</option>
                                          <option value="snow_removal">Snow Removal</option>
                                          <option value="hedge_trimming">Hedge Trimming</option>
                                          <option value="garden_maintenance">Garden Maintenance</option>
                                          <option value="seasonal_cleanup">Seasonal Cleanup</option>
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
                                  <textarea name="description" class="form-control" rows="2"
                                            placeholder="Service details, special instructions..."></textarea>
                              </div>
                          </div>
                      </div>

                      <!-- Scheduling -->
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Scheduling</h5>
                          </div>
                          <div class="card-body">

                              <?php if ($nextVisitDate): ?>
                              <div class="mw-cfq-align-option mb-3">
                                  <label class="d-flex align-items-center" style="gap: 8px; cursor: pointer;">
                                      <input type="checkbox" name="align_with_existing" value="1" id="alignCheckbox"
                                             onchange="toggleAlignDate()">
                                      <span>
                                          Align with existing visit on
                                          <strong><?php echo date('l, M j', strtotime($nextVisitDate)); ?></strong>
                                          <small class="text-muted">(combine into one trip)</small>
                                      </span>
                                  </label>
                              </div>
                              <?php endif; ?>

                              <div class="mw-form-row three">
                                  <div class="mw-form-group">
                                      <label class="form-label">Start Date</label>
                                      <input type="date" name="plan_start_date" id="startDateInput" class="form-control"
                                             value="<?php echo date('Y-m-d'); ?>">
                                  </div>
                                  <div class="mw-form-group">
                                      <label class="form-label">End Date</label>
                                      <input type="date" name="plan_end_date" class="form-control">
                                      <small class="text-muted">Blank = ongoing</small>
                                  </div>
                                  <div class="mw-form-group">
                                      <label class="form-label">Horizon (days)</label>
                                      <input type="number" name="horizon_days" class="form-control" value="28" min="7" max="90">
                                  </div>
                              </div>

                              <div class="mw-form-row three">
                                  <div class="mw-form-group">
                                      <label class="form-label">Start Time</label>
                                      <input type="time" name="default_time_start" class="form-control" value="09:00">
                                  </div>
                                  <div class="mw-form-group">
                                      <label class="form-label">End Time</label>
                                      <input type="time" name="default_time_end" class="form-control" value="10:00">
                                  </div>
                                  <div class="mw-form-group">
                                      <label class="form-label">Duration (min)</label>
                                      <input type="number" name="estimated_duration" class="form-control" value="60" min="15" step="15">
                                  </div>
                              </div>

                              <div class="mw-form-group">
                                  <label class="form-label">Default Crew</label>
                                  <select name="default_crew_id" class="form-control">
                                      <option value="">Unassigned</option>
                                      <?php foreach ($staff as $s): ?>
                                          <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['full_name']); ?></option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>

                              <!-- Recurring options -->
                              <div class="mw-recurring-options" id="recurringOptions">
                                  <h6 class="mb-3">Recurrence Settings</h6>
                                  <div class="mw-form-row">
                                      <div class="mw-form-group">
                                          <label class="form-label">Repeat Pattern</label>
                                          <select name="recurrence_pattern" id="recurrencePattern" class="form-control" onchange="toggleCustomInterval()">
                                              <option value="weekly" selected>Weekly</option>
                                              <option value="biweekly">Every 2 Weeks</option>
                                              <option value="monthly">Monthly</option>
                                              <option value="custom">Custom...</option>
                                          </select>
                                      </div>
                                      <div class="mw-form-group">
                                          <label class="form-label">Day of Week</label>
                                          <select name="recurrence_day_of_week" class="form-control">
                                              <option value="">Same as start date</option>
                                              <option value="0">Sunday</option>
                                              <option value="1">Monday</option>
                                              <option value="2">Tuesday</option>
                                              <option value="3">Wednesday</option>
                                              <option value="4">Thursday</option>
                                              <option value="5">Friday</option>
                                              <option value="6">Saturday</option>
                                          </select>
                                      </div>
                                  </div>
                                  <div class="mw-form-row" id="customIntervalRow" style="display:none;">
                                      <div class="mw-form-group">
                                          <label class="form-label">Repeat Every</label>
                                          <input type="number" name="recurrence_interval" class="form-control" value="1" min="1" max="365">
                                      </div>
                                      <div class="mw-form-group">
                                          <label class="form-label">Unit</label>
                                          <select name="recurrence_interval_unit" class="form-control">
                                              <option value="days">Days</option>
                                              <option value="weeks" selected>Weeks</option>
                                              <option value="months">Months</option>
                                          </select>
                                      </div>
                                  </div>
                              </div>

                          </div>
                      </div>

                      <div class="mw-form-actions">
                          <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                              <i data-feather="check" class="mr-1"></i> Create Plan
                          </button>
                          <a href="../quotes/view.php?id=<?php echo $quoteId; ?>" class="btn btn-secondary">Cancel</a>
                      </div>
                  </form>
              </div>

              <!-- ═══ RIGHT: Quote Reference Panel ═══ -->
              <div class="col-lg-5">
                  <div class="mw-cfq-quote-panel">
                      <div class="card">
                          <div class="card-header" style="background: var(--mw-light);">
                              <h5 class="card-title mb-0">
                                  <?php echo htmlspecialchars($quote['quote_number']); ?>
                                  <span class="mw-badge-status" style="background: var(--mw-green); color: #fff; font-size: 0.7rem; vertical-align: middle;">Accepted</span>
                              </h5>
                          </div>
                          <div class="card-body">
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Client</span>
                                  <span class="mw-detail-value"><?php echo htmlspecialchars($contactName); ?></span>
                              </div>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Property</span>
                                  <span class="mw-detail-value"><?php echo htmlspecialchars($quote['property_address'] . ', ' . $quote['property_city']); ?></span>
                              </div>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Total</span>
                                  <span class="mw-detail-value"><strong><?php echo formatCurrency($quote['amount']); ?></strong></span>
                              </div>

                              <hr class="my-3">

                              <h6 class="mb-2">Line Items</h6>
                              <p class="text-muted small mb-3">Click available items to add them to the plan.</p>

                              <?php foreach ($lineItems as $li): ?>
                                  <?php $isConverted = !empty($li['plan_id']); ?>
                                  <div class="mw-cfq-quote-item <?php echo $isConverted ? 'mw-cfq-converted' : 'mw-cfq-available'; ?>"
                                       <?php if (!$isConverted): ?>
                                           onclick="addQuoteItem(this)"
                                           data-id="<?php echo (int)$li['id']; ?>"
                                           data-service="<?php echo htmlspecialchars($li['service_type']); ?>"
                                           data-desc="<?php echo htmlspecialchars($li['description'] ?? ''); ?>"
                                           data-qty="<?php echo floatval($li['quantity']); ?>"
                                           data-unit="<?php echo htmlspecialchars($li['unit_type'] ?? 'visit'); ?>"
                                           data-price="<?php echo floatval($li['unit_price']); ?>"
                                           data-total="<?php echo floatval($li['line_total']); ?>"
                                       <?php endif; ?>>
                                      <div class="mw-cfq-item-main">
                                          <div class="mw-cfq-item-service"><?php echo htmlspecialchars($li['service_type']); ?></div>
                                          <?php if ($li['description']): ?>
                                              <div class="mw-cfq-item-desc"><?php echo htmlspecialchars($li['description']); ?></div>
                                          <?php endif; ?>
                                      </div>
                                      <div class="mw-cfq-item-price">
                                          <?php echo formatCurrency($li['line_total']); ?>
                                      </div>
                                      <div class="mw-cfq-item-status">
                                          <?php if ($isConverted): ?>
                                              <span class="mw-cfq-badge-converted">
                                                  <?php echo htmlspecialchars($li['plan_number']); ?>
                                              </span>
                                          <?php else: ?>
                                              <span class="mw-cfq-badge-available">Available</span>
                                          <?php endif; ?>
                                      </div>
                                  </div>
                              <?php endforeach; ?>

                              <div class="mw-cfq-progress mt-3">
                                  <?php
                                      $totalItems = count($lineItems);
                                      $convertedItems = $totalItems - $unconvertedCount;
                                      $pct = $totalItems > 0 ? round(($convertedItems / $totalItems) * 100) : 0;
                                  ?>
                                  <div class="d-flex justify-content-between mb-1">
                                      <small class="text-muted"><?php echo $convertedItems; ?>/<?php echo $totalItems; ?> items converted</small>
                                      <small class="text-muted"><?php echo $pct; ?>%</small>
                                  </div>
                                  <div class="progress" style="height: 6px;">
                                      <div class="progress-bar" style="width: <?php echo $pct; ?>%; background: var(--mw-green);"></div>
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <script>
          (function() {
              var itemIndex = 0;
              var addedQuoteItemIds = {};
              var nextVisitDate = <?php echo json_encode($nextVisitDate); ?>;

              /**
               * Add a quote line item to the plan form
               */
              window.addQuoteItem = function(el) {
                  var id = el.dataset.id;
                  if (addedQuoteItemIds[id]) return; // already added

                  var data = {
                      quoteLineItemId: id,
                      service: el.dataset.service,
                      desc: el.dataset.desc,
                      qty: el.dataset.qty,
                      unit: el.dataset.unit,
                      price: el.dataset.price,
                      total: el.dataset.total
                  };

                  addItemRow(data);
                  addedQuoteItemIds[id] = true;

                  // Mark as added visually
                  el.classList.remove('mw-cfq-available');
                  el.classList.add('mw-cfq-added');
                  el.querySelector('.mw-cfq-badge-available').textContent = 'Added';
                  el.onclick = null;

                  // Auto-set title from first item
                  var titleInput = document.getElementById('planTitle');
                  if (!titleInput.value) {
                      titleInput.value = data.service;
                  }

                  updateTotals();
              };

              /**
               * Add a manual (blank) item row
               */
              window.addManualItem = function() {
                  addItemRow({
                      quoteLineItemId: '',
                      service: '',
                      desc: '',
                      qty: '1',
                      unit: 'visit',
                      price: '0',
                      total: '0'
                  });
                  updateTotals();
              };

              /**
               * Insert an item row into the table
               */
              function addItemRow(data) {
                  var table = document.getElementById('planItemsTable');
                  var body = document.getElementById('planItemsBody');
                  var empty = document.getElementById('itemsEmpty');

                  table.style.display = '';
                  empty.style.display = 'none';

                  var idx = itemIndex++;
                  var tr = document.createElement('tr');
                  tr.dataset.itemIndex = idx;
                  tr.innerHTML =
                      '<td>' +
                          '<input type="hidden" name="items[' + idx + '][quote_line_item_id]" value="' + esc(data.quoteLineItemId) + '">' +
                          '<input type="text" name="items[' + idx + '][service_type]" class="form-control form-control-sm" value="' + esc(data.service) + '" placeholder="Service type" required>' +
                      '</td>' +
                      '<td><input type="text" name="items[' + idx + '][description]" class="form-control form-control-sm" value="' + esc(data.desc) + '" placeholder="Description"></td>' +
                      '<td><input type="number" name="items[' + idx + '][quantity]" class="form-control form-control-sm mw-cfq-qty" value="' + esc(data.qty) + '" min="0.01" step="0.01" onchange="recalcRow(this)" style="width:70px;"></td>' +
                      '<td><input type="number" name="items[' + idx + '][unit_price]" class="form-control form-control-sm mw-cfq-price text-right" value="' + esc(data.price) + '" min="0" step="0.01" onchange="recalcRow(this)" style="width:90px;">' +
                          '<input type="hidden" name="items[' + idx + '][unit_type]" value="' + esc(data.unit) + '">' +
                      '</td>' +
                      '<td class="text-right"><span class="mw-cfq-row-total">' + formatMoney(parseFloat(data.total) || 0) + '</span>' +
                          '<input type="hidden" name="items[' + idx + '][line_total]" class="mw-cfq-total-input" value="' + esc(data.total) + '">' +
                      '</td>' +
                      '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(this)" title="Remove">&times;</button></td>';

                  body.appendChild(tr);
                  document.getElementById('submitBtn').disabled = false;
              }

              /**
               * Recalculate row total when qty or price changes
               */
              window.recalcRow = function(input) {
                  var tr = input.closest('tr');
                  var qty = parseFloat(tr.querySelector('.mw-cfq-qty').value) || 0;
                  var price = parseFloat(tr.querySelector('.mw-cfq-price').value) || 0;
                  var total = qty * price;
                  tr.querySelector('.mw-cfq-row-total').textContent = formatMoney(total);
                  tr.querySelector('.mw-cfq-total-input').value = total.toFixed(2);
                  updateTotals();
              };

              /**
               * Remove an item row
               */
              window.removeItem = function(btn) {
                  var tr = btn.closest('tr');
                  var hiddenInput = tr.querySelector('[name*="quote_line_item_id"]');
                  var qliId = hiddenInput ? hiddenInput.value : '';

                  tr.remove();

                  // Un-mark the quote item on the right
                  if (qliId && addedQuoteItemIds[qliId]) {
                      delete addedQuoteItemIds[qliId];
                      var quoteEl = document.querySelector('.mw-cfq-quote-item[data-id="' + qliId + '"]');
                      if (quoteEl) {
                          quoteEl.classList.remove('mw-cfq-added');
                          quoteEl.classList.add('mw-cfq-available');
                          quoteEl.querySelector('.mw-cfq-badge-available, .mw-cfq-badge-converted').textContent = 'Available';
                          quoteEl.onclick = function() { window.addQuoteItem(this); };
                      }
                  }

                  updateTotals();

                  // Check if table is empty
                  var body = document.getElementById('planItemsBody');
                  if (body.children.length === 0) {
                      document.getElementById('planItemsTable').style.display = 'none';
                      document.getElementById('itemsEmpty').style.display = '';
                      document.getElementById('submitBtn').disabled = true;
                  }
              };

              /**
               * Update the total display
               */
              function updateTotals() {
                  var inputs = document.querySelectorAll('.mw-cfq-total-input');
                  var sum = 0;
                  inputs.forEach(function(inp) { sum += parseFloat(inp.value) || 0; });
                  document.getElementById('planItemsTotal').textContent = formatMoney(sum);
              }

              /**
               * Align date toggle
               */
              window.toggleAlignDate = function() {
                  var cb = document.getElementById('alignCheckbox');
                  var dateInput = document.getElementById('startDateInput');
                  if (cb && cb.checked && nextVisitDate) {
                      dateInput.value = nextVisitDate;
                      dateInput.readOnly = true;
                  } else {
                      dateInput.readOnly = false;
                  }
              };

              /**
               * Recurring options toggle
               */
              window.toggleRecurring = function() {
                  var planType = document.getElementById('planType').value;
                  var opts = document.getElementById('recurringOptions');
                  if (planType === 'recurring') {
                      opts.classList.add('show');
                  } else {
                      opts.classList.remove('show');
                  }
              };

              window.toggleCustomInterval = function() {
                  var pattern = document.getElementById('recurrencePattern').value;
                  document.getElementById('customIntervalRow').style.display = (pattern === 'custom') ? '' : 'none';
              };

              /**
               * Form validation
               */
              document.getElementById('createPlanForm').addEventListener('submit', function(e) {
                  var body = document.getElementById('planItemsBody');
                  if (body.children.length === 0) {
                      e.preventDefault();
                      alert('Please add at least one service item.');
                  }
              });

              // Helpers
              function esc(str) {
                  if (!str) return '';
                  return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
              }

              function formatMoney(n) {
                  return '$' + n.toFixed(2);
              }
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
