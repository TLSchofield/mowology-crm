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

// ─── Scheduling Intelligence: nearby recurring plans ───
$propCoords = $db->prepare("SELECT latitude, longitude FROM properties WHERE id = ?");
$propCoords->execute([$quote['property_id']]);
$coordsRow = $propCoords->fetch(PDO::FETCH_ASSOC);
$propLat = $coordsRow ? (float)$coordsRow['latitude'] : 0;
$propLng = $coordsRow ? (float)$coordsRow['longitude'] : 0;

$nearbyPlans = [];
$dayStats = [];
$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$bestDay = null;

if ($propLat && $propLng) {
    $nearbyStmt = $db->prepare("
        SELECT
            p.id AS property_id, p.address, p.city, p.latitude, p.longitude,
            jp.id AS plan_id, jp.title AS plan_title, jp.service_type, jp.price_per_visit,
            jp.recurrence_day_of_week, jp.recurrence_pattern,
            jp.default_time_start, jp.estimated_duration_minutes,
            jp.default_crew_id, u.full_name AS crew_name,
            c.first_name, c.last_name,
            (6371 * ACOS(
                LEAST(1, COS(RADIANS(?)) * COS(RADIANS(p.latitude)) *
                COS(RADIANS(p.longitude) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(p.latitude)))
            )) AS distance_km
        FROM job_plans jp
        JOIN properties p ON jp.property_id = p.id
        LEFT JOIN contacts c ON p.site_contact_id = c.id
        LEFT JOIN users u ON jp.default_crew_id = u.id
        WHERE jp.status = 'active'
          AND jp.is_recurring = 1
          AND p.latitude IS NOT NULL AND p.latitude != 0
          AND p.longitude IS NOT NULL AND p.longitude != 0
          AND p.id != ?
        HAVING distance_km <= 5
        ORDER BY distance_km ASC
        LIMIT 25
    ");
    $nearbyStmt->execute([$propLat, $propLng, $propLat, $quote['property_id']]);
    $nearbyPlans = $nearbyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute day-of-week statistics
    for ($d = 0; $d <= 6; $d++) {
        $dayStats[$d] = ['count' => 0, 'total_distance' => 0, 'avg_distance' => 0];
    }
    foreach ($nearbyPlans as $np) {
        $dow = $np['recurrence_day_of_week'];
        if ($dow !== null && isset($dayStats[(int)$dow])) {
            $dayStats[(int)$dow]['count']++;
            $dayStats[(int)$dow]['total_distance'] += (float)$np['distance_km'];
        }
    }
    foreach ($dayStats as $d => &$stat) {
        $stat['avg_distance'] = $stat['count'] > 0 ? round($stat['total_distance'] / $stat['count'], 1) : 0;
    }
    unset($stat);

    // Best day = most nearby clients (Mon-Sat only, skip Sunday)
    $bestCount = 0;
    for ($d = 1; $d <= 6; $d++) {
        if ($dayStats[$d]['count'] > $bestCount) {
            $bestCount = $dayStats[$d]['count'];
            $bestDay = $d;
        }
    }
    if ($bestCount === 0) $bestDay = null;
}

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

    // Crew assignments (multi-staff)
    $crewIds = [];
    if (!empty($_POST['crew_ids']) && is_array($_POST['crew_ids'])) {
        $crewIds = array_map('intval', $_POST['crew_ids']);
        $crewIds = array_filter($crewIds, function($id) { return $id > 0; });
    }

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
    } elseif ($recurrencePattern === 'yearly') {
        $recurrenceInterval = max(1, $recurrenceInterval);
        $recurrenceUnit = 'years';
    }

    if (!in_array($recurrenceUnit, ['days', 'weeks', 'months', 'years'], true)) {
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
            'crew_ids'                 => $crewIds,
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

$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
if ($propLat && $propLng && $apiKey) {
    $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '&libraries=geometry" defer></script>';
}
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
                                  <label class="form-label">Crew Assignment</label>
                                  <div class="mw-crew-wrapper">
                                      <div class="mw-crew-chips" id="crewChips">
                                          <button type="button" class="mw-crew-add-btn" id="crewAddBtn" onclick="toggleCrewDropdown()">+ Assign</button>
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

                              <!-- Recurring options — Jobber-style -->
                              <div class="mw-recurring-options" id="recurringOptions">
                                  <h6 class="mb-3">Recurrence Settings</h6>

                                  <!-- Frequency pill buttons -->
                                  <div class="mw-freq-picker" id="freqPicker">
                                      <button type="button" class="mw-freq-btn" data-freq="daily">Daily</button>
                                      <button type="button" class="mw-freq-btn active" data-freq="weekly">Weekly</button>
                                      <button type="button" class="mw-freq-btn" data-freq="monthly">Monthly</button>
                                      <button type="button" class="mw-freq-btn" data-freq="yearly">Yearly</button>
                                      <button type="button" class="mw-freq-btn" data-freq="custom">Custom</button>
                                  </div>

                                  <!-- Interval row -->
                                  <div class="mw-interval-row" id="intervalRow">
                                      <span class="mw-interval-label">Every</span>
                                      <input type="number" name="recurrence_interval" id="recurrenceInterval"
                                             class="form-control form-control-sm" value="1" min="1" max="365">
                                      <span class="mw-interval-label" id="intervalUnitLabel">week(s)</span>
                                  </div>

                                  <!-- Day-of-week picker (weekly) -->
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

                                  <!-- Custom unit picker (custom only) -->
                                  <div id="customUnitWrap" style="display:none;" class="mb-2">
                                      <select name="recurrence_interval_unit" id="recurrenceUnit" class="form-control form-control-sm" style="width:140px;">
                                          <option value="days">Days</option>
                                          <option value="weeks" selected>Weeks</option>
                                          <option value="months">Months</option>
                                          <option value="years">Years</option>
                                      </select>
                                  </div>

                                  <!-- Hidden fields synced by JS -->
                                  <input type="hidden" name="recurrence_pattern" id="recurrencePatternHidden" value="weekly">
                                  <input type="hidden" name="recurrence_day_of_week" id="recurrenceDowHidden" value="">

                                  <!-- Summary -->
                                  <div class="mw-recurrence-summary" id="recurrenceSummary">
                                      <i data-feather="repeat" style="width:14px;height:14px;"></i>
                                      <span id="recurrenceSummaryText">Repeats every week</span>
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

                  <!-- ═══ Scheduling Intelligence Panel ═══ -->
                  <?php if ($propLat && $propLng): ?>
                  <div class="card mt-3 mw-sched-intel">
                      <div class="card-header" style="background: var(--mw-light);">
                          <h5 class="card-title mb-0" style="display:flex;align-items:center;gap:6px;font-size:0.95rem;">
                              <i data-feather="map-pin" style="width:16px;height:16px;"></i>
                              Scheduling Intelligence
                          </h5>
                      </div>
                      <div class="card-body p-0">
                          <!-- Day Recommendation Grid -->
                          <div class="mw-si-day-grid">
                              <?php
                              $maxCount = 1;
                              if (!empty($dayStats)) {
                                  foreach ($dayStats as $ds) {
                                      if ($ds['count'] > $maxCount) $maxCount = $ds['count'];
                                  }
                              }
                              for ($d = 1; $d <= 6; $d++):
                                  $stat = isset($dayStats[$d]) ? $dayStats[$d] : ['count' => 0, 'avg_distance' => 0];
                                  $isBest = ($d === $bestDay && $bestDay !== null);
                                  $barPct = $stat['count'] > 0 ? round(($stat['count'] / $maxCount) * 100) : 0;
                              ?>
                              <div class="mw-si-day-col <?php echo $isBest ? 'mw-si-best' : ''; ?>"
                                   data-dow="<?php echo $d; ?>"
                                   onclick="selectScheduleDay(<?php echo $d; ?>)">
                                  <div class="mw-si-day-name"><?php echo $dayNames[$d]; ?></div>
                                  <div class="mw-si-day-bar-wrap">
                                      <div class="mw-si-day-bar" style="height: <?php echo max(4, $barPct); ?>%;"></div>
                                  </div>
                                  <div class="mw-si-day-count"><?php echo $stat['count']; ?></div>
                                  <?php if ($stat['count'] > 0): ?>
                                  <div class="mw-si-day-dist"><?php echo $stat['avg_distance']; ?>km</div>
                                  <?php endif; ?>
                                  <?php if ($isBest): ?>
                                  <div class="mw-si-best-badge">Best</div>
                                  <?php endif; ?>
                              </div>
                              <?php endfor; ?>
                          </div>

                          <!-- Map -->
                          <div id="schedIntelMap" class="mw-si-map"></div>

                          <!-- Nearby Clients List -->
                          <?php if (!empty($nearbyPlans)): ?>
                          <div class="mw-si-nearby-list">
                              <div class="mw-si-list-header">
                                  <small class="text-muted"><?php echo count($nearbyPlans); ?> nearby recurring client<?php echo count($nearbyPlans) !== 1 ? 's' : ''; ?> within 5km</small>
                                  <a href="#" onclick="selectScheduleDay(null); return false;" class="mw-si-show-all" style="font-size:0.7rem;">Show all</a>
                              </div>
                              <?php foreach ($nearbyPlans as $np): ?>
                              <div class="mw-si-nearby-item"
                                   data-dow="<?php echo $np['recurrence_day_of_week'] !== null ? (int)$np['recurrence_day_of_week'] : ''; ?>"
                                   data-lat="<?php echo (float)$np['latitude']; ?>"
                                   data-lng="<?php echo (float)$np['longitude']; ?>"
                                   onclick="highlightOnMap(<?php echo (float)$np['latitude']; ?>, <?php echo (float)$np['longitude']; ?>)">
                                  <div class="mw-si-nearby-main">
                                      <div class="mw-si-nearby-address"><?php echo htmlspecialchars($np['address']); ?></div>
                                      <div class="mw-si-nearby-meta">
                                          <?php echo htmlspecialchars($np['service_type']); ?>
                                          &middot; <?php echo round((float)$np['distance_km'], 1); ?>km
                                          <?php if ($np['crew_name']): ?>
                                          &middot; <?php echo htmlspecialchars($np['crew_name']); ?>
                                          <?php endif; ?>
                                      </div>
                                  </div>
                                  <div class="mw-si-nearby-day">
                                      <?php
                                      $dowVal = $np['recurrence_day_of_week'];
                                      echo ($dowVal !== null) ? $dayNames[(int)$dowVal] : '?';
                                      ?>
                                      <?php if ($np['default_time_start']): ?>
                                      <div class="mw-si-nearby-time"><?php echo date('g:ia', strtotime($np['default_time_start'])); ?></div>
                                      <?php endif; ?>
                                  </div>
                              </div>
                              <?php endforeach; ?>
                          </div>
                          <?php else: ?>
                          <div class="mw-si-no-data">
                              <p class="mb-1">No nearby recurring clients within 5km.</p>
                              <small class="text-muted">Data will appear as you add more recurring plans.</small>
                          </div>
                          <?php endif; ?>
                      </div>
                  </div>
                  <?php elseif (!$propLat || !$propLng): ?>
                  <div class="card mt-3">
                      <div class="card-body text-center text-muted py-4">
                          <i data-feather="map-pin" style="width:24px;height:24px;opacity:0.4;"></i>
                          <p class="mb-0 mt-2 small">Property has no GPS coordinates.<br>Add them to enable scheduling intelligence.</p>
                      </div>
                  </div>
                  <?php endif; ?>

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

              // ── Jobber-Style Recurrence Controls ──────────────
              // Exposed on window so the Scheduling Intelligence script can access them
              window._recurrenceState = { freq: 'weekly', dow: null };
              var currentFreq = 'weekly';
              var selectedDow = null;
              var dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

              // Frequency picker
              document.querySelectorAll('.mw-freq-btn').forEach(function(btn) {
                  btn.addEventListener('click', function() {
                      document.querySelectorAll('.mw-freq-btn').forEach(function(b) { b.classList.remove('active'); });
                      this.classList.add('active');
                      currentFreq = this.dataset.freq;
                      updateRecurrenceUI();
                  });
              });

              // Day-of-week picker
              document.querySelectorAll('.mw-dow-btn').forEach(function(btn) {
                  btn.addEventListener('click', function() {
                      document.querySelectorAll('.mw-dow-btn').forEach(function(b) { b.classList.remove('active'); });
                      this.classList.add('active');
                      selectedDow = parseInt(this.dataset.dow);
                      window._recurrenceState.dow = selectedDow;
                      updateHiddenFields();
                      updateSummaryText();
                  });
              });

              // Interval input change
              document.getElementById('recurrenceInterval').addEventListener('input', function() {
                  updateHiddenFields();
                  updateSummaryText();
              });

              // Custom unit change
              var unitSelect = document.getElementById('recurrenceUnit');
              if (unitSelect) {
                  unitSelect.addEventListener('change', function() {
                      updateSummaryText();
                  });
              }

              function updateRecurrenceUI() {
                  var dowWrap = document.getElementById('dowPickerWrap');
                  var customUnitWrap = document.getElementById('customUnitWrap');
                  var intervalInput = document.getElementById('recurrenceInterval');
                  var unitLabel = document.getElementById('intervalUnitLabel');

                  dowWrap.style.display = 'none';
                  customUnitWrap.style.display = 'none';
                  unitLabel.style.display = '';

                  switch (currentFreq) {
                      case 'daily':
                          unitLabel.textContent = 'day(s)';
                          break;
                      case 'weekly':
                          unitLabel.textContent = 'week(s)';
                          dowWrap.style.display = '';
                          break;
                      case 'monthly':
                          unitLabel.textContent = 'month(s)';
                          break;
                      case 'yearly':
                          unitLabel.textContent = 'year(s)';
                          break;
                      case 'custom':
                          customUnitWrap.style.display = '';
                          unitLabel.style.display = 'none';
                          break;
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
                      case 'daily':
                          text += interval === 1 ? 'every day' : 'every ' + interval + ' days';
                          break;
                      case 'weekly':
                          text += interval === 1 ? 'every week' : 'every ' + interval + ' weeks';
                          if (selectedDow !== null) text += ' on ' + dayNames[selectedDow];
                          break;
                      case 'monthly':
                          text += interval === 1 ? 'every month' : 'every ' + interval + ' months';
                          break;
                      case 'yearly':
                          text += interval === 1 ? 'every year' : 'every ' + interval + ' years';
                          break;
                      case 'custom':
                          var unit = document.getElementById('recurrenceUnit').value;
                          text += 'every ' + interval + ' ' + unit;
                          if (selectedDow !== null && unit === 'weeks') text += ' on ' + dayNames[selectedDow];
                          break;
                  }

                  document.getElementById('recurrenceSummaryText').textContent = text;
              }

              // ── Multi-Crew Assignment ────────────────────────
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
                          esc(c.name) + (isLead ? ' (Lead)' : '') +
                          '<button type="button" class="mw-crew-chip-remove" onclick="removeCrew(' + c.id + ')">&times;</button>' +
                          '<input type="hidden" name="crew_ids[]" value="' + c.id + '">' +
                          '</span>';
                  });

                  html += '<button type="button" class="mw-crew-add-btn" onclick="toggleCrewDropdown()">+ Assign</button>';
                  container.innerHTML = html;

                  document.getElementById('defaultCrewIdHidden').value = assignedCrew.length > 0 ? assignedCrew[0].id : '';
              }

              // Close crew dropdown on outside click
              document.addEventListener('click', function(e) {
                  if (!e.target.closest('.mw-crew-wrapper')) {
                      var dd = document.getElementById('crewDropdown');
                      if (dd) dd.classList.remove('show');
                  }
              });

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

          <?php if ($propLat && $propLng && $apiKey): ?>
          <script>
          (function() {
              var siMap = null;
              var siMarkers = [];
              var siInfoWindow = null;
              var currentPropertyLat = <?php echo $propLat; ?>;
              var currentPropertyLng = <?php echo $propLng; ?>;
              var nearbyData = <?php echo json_encode($nearbyPlans); ?>;
              var activeFilter = null;

              var dayColors = {
                  0: '#9CA3AF',
                  1: '#3B82F6',
                  2: '#10B981',
                  3: '#F59E0B',
                  4: '#8B5CF6',
                  5: '#EF4444',
                  6: '#D97706'
              };
              var dayLabels = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

              function initScheduleMap() {
                  var container = document.getElementById('schedIntelMap');
                  if (!container) return;

                  var center = { lat: currentPropertyLat, lng: currentPropertyLng };
                  siMap = new google.maps.Map(container, {
                      center: center,
                      zoom: 14,
                      mapTypeControl: false,
                      streetViewControl: false,
                      fullscreenControl: false,
                      styles: [{ featureType: 'poi', stylers: [{ visibility: 'off' }] }]
                  });

                  // Current property marker (orange, larger)
                  new google.maps.Marker({
                      position: center,
                      map: siMap,
                      title: 'Current Property',
                      icon: {
                          path: 'M12 0C7.58 0 4 3.58 4 8c0 5.25 8 16 8 16s8-10.75 8-16c0-4.42-3.58-8-8-8zm0 11c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3z',
                          fillColor: '#e85d04',
                          fillOpacity: 1,
                          scale: 2,
                          strokeColor: '#fff',
                          strokeWeight: 2.5,
                          anchor: new google.maps.Point(12, 24)
                      },
                      zIndex: 999
                  });

                  siInfoWindow = new google.maps.InfoWindow();

                  // Nearby property markers
                  nearbyData.forEach(function(np) {
                      var lat = parseFloat(np.latitude), lng = parseFloat(np.longitude);
                      if (!lat || !lng) return;

                      var dow = np.recurrence_day_of_week;
                      var dowInt = dow !== null ? parseInt(dow) : null;
                      var color = dowInt !== null ? (dayColors[dowInt] || '#6B7280') : '#6B7280';
                      var dayLabel = dowInt !== null ? dayLabels[dowInt] : '?';

                      var marker = new google.maps.Marker({
                          position: { lat: lat, lng: lng },
                          map: siMap,
                          title: np.address + ' (' + dayLabel + ')',
                          icon: {
                              path: 'M12 0C7.58 0 4 3.58 4 8c0 5.25 8 16 8 16s8-10.75 8-16c0-4.42-3.58-8-8-8zm0 11c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3z',
                              fillColor: color,
                              fillOpacity: 0.85,
                              scale: 1.3,
                              strokeColor: '#fff',
                              strokeWeight: 1.5,
                              anchor: new google.maps.Point(12, 24)
                          },
                          _dow: dowInt
                      });

                      marker.addListener('click', function() {
                          var timeStr = '';
                          if (np.default_time_start) {
                              var parts = np.default_time_start.split(':');
                              var h = parseInt(parts[0]), m = parts[1];
                              var ampm = h >= 12 ? 'pm' : 'am';
                              h = h % 12 || 12;
                              timeStr = h + ':' + m + ampm;
                          }
                          siInfoWindow.setContent(
                              '<div style="padding:8px;min-width:180px;">' +
                              '<strong style="color:' + color + ';">' + dayLabel + '</strong>' +
                              (timeStr ? ' ' + timeStr : '') +
                              '<br><span style="font-size:12px;">' + escHtml(np.address) + '</span>' +
                              '<br><span style="font-size:11px;color:#666;">' + escHtml(np.service_type) +
                              ' &middot; ' + parseFloat(np.distance_km).toFixed(1) + 'km</span>' +
                              (np.crew_name ? '<br><span style="font-size:11px;color:#888;">Crew: ' + escHtml(np.crew_name) + '</span>' : '') +
                              '</div>'
                          );
                          siInfoWindow.open(siMap, marker);
                      });

                      siMarkers.push(marker);
                  });
              }

              function filterMapByDay(dow) {
                  siMarkers.forEach(function(m) {
                      m.setMap((dow === null || m._dow === dow) ? siMap : null);
                  });
              }

              window.selectScheduleDay = function(dow) {
                  // Toggle if same day clicked again
                  if (dow === activeFilter) {
                      dow = null;
                  }
                  activeFilter = dow;

                  if (dow !== null) {
                      // Activate the DOW circle button
                      document.querySelectorAll('.mw-dow-btn').forEach(function(b) {
                          b.classList.toggle('active', parseInt(b.dataset.dow) === dow);
                      });
                      // Sync state with main recurrence IIFE
                      window._recurrenceState.dow = dow;
                      // Set hidden fields directly
                      var dowHidden = document.getElementById('recurrenceDowHidden');
                      if (dowHidden) dowHidden.value = dow;

                      // Switch plan_type to recurring if not already
                      var planType = document.getElementById('planType');
                      if (planType && planType.value !== 'recurring') {
                          planType.value = 'recurring';
                          if (typeof toggleRecurring === 'function') toggleRecurring();
                      }

                      // Set frequency to weekly if not already set
                      var patternHidden = document.getElementById('recurrencePatternHidden');
                      if (patternHidden && patternHidden.value !== 'weekly') {
                          patternHidden.value = 'weekly';
                          document.querySelectorAll('.mw-freq-btn').forEach(function(b) {
                              b.classList.toggle('active', b.dataset.freq === 'weekly');
                          });
                          var dowWrap = document.getElementById('dowPickerWrap');
                          if (dowWrap) dowWrap.style.display = '';
                          var unitLabel = document.getElementById('intervalUnitLabel');
                          if (unitLabel) { unitLabel.textContent = 'week(s)'; unitLabel.style.display = ''; }
                      }

                      // Update summary text
                      var summaryEl = document.getElementById('recurrenceSummaryText');
                      if (summaryEl) {
                          var interval = parseInt(document.getElementById('recurrenceInterval').value) || 1;
                          var dNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                          var text = interval === 1 ? 'Repeats every week' : 'Repeats every ' + interval + ' weeks';
                          text += ' on ' + dNames[dow];
                          summaryEl.textContent = text;
                      }
                  }

                  // Highlight selected day column
                  document.querySelectorAll('.mw-si-day-col').forEach(function(el) {
                      el.classList.toggle('mw-si-selected', dow !== null && parseInt(el.dataset.dow) === dow);
                  });

                  // Filter map markers
                  filterMapByDay(dow);

                  // Filter nearby list
                  document.querySelectorAll('.mw-si-nearby-item').forEach(function(el) {
                      var itemDow = el.dataset.dow !== '' ? parseInt(el.dataset.dow) : null;
                      el.style.display = (dow === null || itemDow === dow) ? '' : 'none';
                  });
              };

              window.highlightOnMap = function(lat, lng) {
                  if (!siMap) return;
                  siMap.panTo({ lat: lat, lng: lng });
                  siMap.setZoom(16);
                  siMarkers.forEach(function(m) {
                      var pos = m.getPosition();
                      if (Math.abs(pos.lat() - lat) < 0.0001 && Math.abs(pos.lng() - lng) < 0.0001) {
                          google.maps.event.trigger(m, 'click');
                      }
                  });
              };

              function escHtml(str) {
                  if (!str) return '';
                  var d = document.createElement('div');
                  d.textContent = str;
                  return d.innerHTML;
              }

              // Init when Google Maps loads
              if (typeof google !== 'undefined' && google.maps) {
                  initScheduleMap();
              } else {
                  var checkInterval = setInterval(function() {
                      if (typeof google !== 'undefined' && google.maps) {
                          clearInterval(checkInterval);
                          initScheduleMap();
                      }
                  }, 200);
                  setTimeout(function() { clearInterval(checkInterval); }, 10000);
              }
          })();
          </script>
          <?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
