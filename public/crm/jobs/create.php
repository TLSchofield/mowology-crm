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

$db = getDB();
$error = '';
$prefill = [];

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
            'title'            => $quote['title'] ?: 'Plan from ' . $quote['quote_number'],
            'description'      => $quote['description'],
            'service_type'     => $quote['service_type'],
            'price_per_visit'  => $quote['amount'],
            'estimated_amount' => $quote['amount'],
            'quote_id'         => $quoteId,
        ];
    }
}

// Get properties for dropdown
$properties = $db->query("
    SELECT DISTINCT p.id, p.address, p.city, p.property_type, c.company_name, c.id as company_id
    FROM properties p
    LEFT JOIN company_properties cp ON p.id = cp.property_id
    LEFT JOIN companies c ON cp.company_id = c.id
    ORDER BY c.company_name, p.address
")->fetchAll(PDO::FETCH_ASSOC);

// Get staff for crew assignment
$staff = getStaffMembers();

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

        // Recurring fields
        $isRecurring         = ($planType === 'recurring') ? 1 : 0;
        $recurrencePattern   = $isRecurring ? ($_POST['recurrence_pattern'] ?? 'weekly') : null;
        $recurrenceDayOfWeek = $isRecurring && isset($_POST['recurrence_day_of_week']) && $_POST['recurrence_day_of_week'] !== ''
                               ? intval($_POST['recurrence_day_of_week']) : null;
        $recurrenceEndDate   = $isRecurring && !empty($_POST['plan_end_date']) ? $_POST['plan_end_date'] : null;

        // Build plan data array
        $planData = [
            'property_id'              => $propertyId,
            'title'                    => $title,
            'description'              => $description,
            'service_type'             => $serviceType,
            'quote_id'                 => $linkedQuoteId ?: null,
            'is_recurring'             => $isRecurring,
            'recurrence_pattern'       => $recurrencePattern,
            'recurrence_day_of_week'   => $recurrenceDayOfWeek,
            'plan_start_date'          => $planStartDate,
            'plan_end_date'            => $planEndDate ?: $recurrenceEndDate,
            'pricing_model'            => $pricingModel,
            'price_per_visit'          => $pricePerVisit ?: null,
            'estimated_amount'         => $estimatedAmount ?: null,
            'default_crew_id'          => $defaultCrewId,
            'estimated_duration_minutes' => $estimatedDuration,
            'default_time_start'       => $defaultTimeStart,
            'default_time_end'         => $defaultTimeEnd,
            'horizon_days'             => $horizonDays,
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
                header("Location: view.php?id={$result['plan_id']}&created=1");
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
          <?php endif; ?>

          <form method="POST">
              <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
              <input type="hidden" name="quote_id" value="<?php echo $prefill['quote_id'] ?? ''; ?>">

              <!-- Plan Details Card -->
              <div class="card">
                  <div class="card-header">
                      <h5 class="card-title mb-0">Plan Details</h5>
                  </div>
                  <div class="card-body">

                      <div class="mw-form-group">
                          <label class="form-label">Property *</label>
                          <select name="property_id" class="form-control" required>
                              <option value="">Select property...</option>
                              <?php foreach ($properties as $prop): ?>
                                  <option value="<?php echo $prop['id']; ?>"
                                      <?php echo ($prefill['property_id'] ?? '') == $prop['id'] ? 'selected' : ''; ?>>
                                      <?php echo htmlspecialchars($prop['company_name'] . ' - ' . $prop['address'] . ', ' . $prop['city']); ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>

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
                                  <option value="landscaping" <?php echo ($prefill['service_type'] ?? '') === 'landscaping' ? 'selected' : ''; ?>>Landscaping</option>
                                  <option value="lawn_care" <?php echo ($prefill['service_type'] ?? '') === 'lawn_care' ? 'selected' : ''; ?>>Lawn Care</option>
                                  <option value="snow_removal" <?php echo ($prefill['service_type'] ?? '') === 'snow_removal' ? 'selected' : ''; ?>>Snow Removal</option>
                                  <option value="hedge_trimming" <?php echo ($prefill['service_type'] ?? '') === 'hedge_trimming' ? 'selected' : ''; ?>>Hedge Trimming</option>
                                  <option value="garden_maintenance" <?php echo ($prefill['service_type'] ?? '') === 'garden_maintenance' ? 'selected' : ''; ?>>Garden Maintenance</option>
                                  <option value="seasonal_cleanup" <?php echo ($prefill['service_type'] ?? '') === 'seasonal_cleanup' ? 'selected' : ''; ?>>Seasonal Cleanup</option>
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
                          <label class="form-label">Assign Default Crew</label>
                          <select name="default_crew_id" class="form-control">
                              <option value="">Unassigned</option>
                              <?php foreach ($staff as $s): ?>
                                  <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['full_name']); ?></option>
                              <?php endforeach; ?>
                          </select>
                      </div>

                      <!-- Recurring options (hidden by default) -->
                      <div class="mw-recurring-options" id="recurringOptions">
                          <h6 class="mb-3">Recurrence Settings</h6>
                          <div class="mw-form-row">
                              <div class="mw-form-group">
                                  <label class="form-label">Repeat Pattern</label>
                                  <select name="recurrence_pattern" class="form-control">
                                      <option value="weekly">Weekly</option>
                                      <option value="biweekly">Every 2 Weeks</option>
                                      <option value="monthly">Monthly</option>
                                      <option value="custom">Custom</option>
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
              function toggleRecurring() {
                  var planType = document.getElementById('planType').value;
                  var recurringOptions = document.getElementById('recurringOptions');
                  if (planType === 'recurring') {
                      recurringOptions.classList.add('show');
                  } else {
                      recurringOptions.classList.remove('show');
                  }
              }
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
