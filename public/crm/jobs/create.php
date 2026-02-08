<?php
/**
 * Create Job - Manual or from Quote
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/roi-functions.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();
$error = '';
$prefill = [];

// Check if creating from quote
$quoteId = isset($_GET['quote_id']) ? intval($_GET['quote_id']) : 0;

if ($quoteId) {
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
            'property_id' => $quote['property_id'],
            'company_id' => $quote['company_id'],
            'title' => $quote['title'] ?: 'Job from ' . $quote['quote_number'],
            'description' => $quote['description'],
            'service_type' => $quote['service_type'],
            'estimated_amount' => $quote['amount'],
            'quote_id' => $quoteId
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

// Get staff for assignment
$staff = getStaffMembers();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $propertyId = intval($_POST['property_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $serviceType = $_POST['service_type'] ?? 'landscaping';
        $jobType = $_POST['job_type'] ?? 'one_time';
        $scheduledDate = $_POST['scheduled_date'] ?? null;
        $scheduledTimeStart = $_POST['scheduled_time_start'] ?? null;
        $scheduledTimeEnd = $_POST['scheduled_time_end'] ?? null;
        $estimatedDuration = intval($_POST['estimated_duration'] ?? 60);
        $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
        $estimatedAmount = floatval($_POST['estimated_amount'] ?? 0);
        $linkedQuoteId = intval($_POST['quote_id'] ?? 0);

        // Recurring fields
        $recurrencePattern = ($jobType === 'recurring') ? ($_POST['recurrence_pattern'] ?? null) : null;
        $recurrenceEndDate = ($jobType === 'recurring') ? ($_POST['recurrence_end_date'] ?? null) : null;

        // Validate
        if (!$propertyId) {
            $error = 'Please select a property.';
        } elseif (empty($title)) {
            $error = 'Please enter a job title.';
        } else {
            try {
                $db->beginTransaction();

                // Get company_id from property
                $stmt = $db->prepare("SELECT company_id FROM properties WHERE id = ?");
                $stmt->execute([$propertyId]);
                $companyId = $stmt->fetchColumn();

                $jobNumber = generateJobNumber();

                $stmt = $db->prepare("
                    INSERT INTO jobs (
                        job_number, quote_id, property_id, company_id, title, description,
                        service_type, job_type, scheduled_date, scheduled_time_start, scheduled_time_end,
                        estimated_duration_minutes, recurrence_pattern, recurrence_end_date,
                        assigned_to, assigned_at, estimated_amount, status, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)
                ");

                $stmt->execute([
                    $jobNumber,
                    $linkedQuoteId ?: null,
                    $propertyId,
                    $companyId,
                    $title,
                    $description,
                    $serviceType,
                    $jobType,
                    $scheduledDate ?: null,
                    $scheduledTimeStart ?: null,
                    $scheduledTimeEnd ?: null,
                    $estimatedDuration,
                    $recurrencePattern,
                    $recurrenceEndDate ?: null,
                    $assignedTo,
                    $assignedTo ? date('Y-m-d H:i:s') : null,
                    $estimatedAmount,
                    $user['id']
                ]);

                $jobId = $db->lastInsertId();

                // Update property status to active
                $stmt = $db->prepare("UPDATE properties SET status = 'active' WHERE id = ?");
                $stmt->execute([$propertyId]);

                // Log ROI attribution: Link job to quote's lead source (if created from quote)
                if ($linkedQuoteId > 0) {
                    // Get the quote request source and lead event if available
                    $quoteStmt = $db->prepare("
                        SELECT source FROM quote_requests
                        WHERE id IN (SELECT quote_request_id FROM quotes WHERE id = ?)
                        LIMIT 1
                    ");
                    $quoteStmt->execute([$linkedQuoteId]);
                    $quoteSource = $quoteStmt->fetchColumn();

                    // Create ROI attribution record
                    createROIAttribution($jobId, null, $quoteSource ?: 'website', $estimatedAmount ?: null);

                    // Log conversion event for the job
                    logConversionEvent(0, 'job_created', $jobId); // 0 = no specific lead event, just log the conversion
                }

                logActivityExtended(
                    $user['id'],
                    'Job created',
                    "Job {$jobNumber} created" . ($linkedQuoteId ? " from quote" : ""),
                    $companyId,
                    $jobId,
                    $linkedQuoteId ?: null
                );

                $db->commit();

                header("Location: view.php?id={$jobId}&created=1");
                exit;

            } catch (Exception $e) {
                $db->rollBack();
                error_log("Job creation error: " . $e->getMessage());
                $error = 'Error creating job. Please try again.';
            }
        }
    }
}

$csrfToken = generateCSRFToken();

$pageTitle = 'Create Job';
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="index.php" class="mw-back-link">&larr; Back to Jobs</a>

          <h1 class="h3 mb-3">Create Job</h1>
          <p class="text-muted mb-4"><?php echo $quoteId ? 'Creating job from accepted quote' : 'Schedule a new job'; ?></p>

          <?php if ($error): ?>
              <div class="mw-error-message"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>

          <?php if ($quoteId && isset($quote)): ?>
              <div class="mw-info-banner">
                  <strong>Creating from Quote <?php echo htmlspecialchars($quote['quote_number']); ?></strong><br>
                  <?php echo htmlspecialchars($quote['company_name']); ?> - <?php echo htmlspecialchars($quote['address']); ?>
              </div>
          <?php endif; ?>

          <form method="POST">
              <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
              <input type="hidden" name="quote_id" value="<?php echo $prefill['quote_id'] ?? ''; ?>">

              <div class="card">
                  <div class="card-header">
                      <h5 class="card-title mb-0">Job Details</h5>
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
                          <label class="form-label">Job Title *</label>
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
                              <label class="form-label">Job Type</label>
                              <select name="job_type" id="jobType" class="form-control" onchange="toggleRecurring()">
                                  <option value="one_time">One-Time</option>
                                  <option value="recurring">Recurring</option>
                              </select>
                          </div>
                      </div>

                      <div class="mw-form-group">
                          <label class="form-label">Description</label>
                          <textarea name="description" class="form-control" rows="4"
                                    placeholder="Job details, special instructions..."><?php echo htmlspecialchars($prefill['description'] ?? ''); ?></textarea>
                      </div>

                  </div>
              </div>

              <div class="card">
                  <div class="card-header">
                      <h5 class="card-title mb-0">Scheduling</h5>
                  </div>
                  <div class="card-body">

                      <div class="mw-form-row three">
                          <div class="mw-form-group">
                              <label class="form-label">Date</label>
                              <input type="date" name="scheduled_date" class="form-control"
                                     value="<?php echo date('Y-m-d'); ?>">
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Start Time</label>
                              <input type="time" name="scheduled_time_start" class="form-control" value="09:00">
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">End Time</label>
                              <input type="time" name="scheduled_time_end" class="form-control" value="10:00">
                          </div>
                      </div>

                      <div class="mw-form-row">
                          <div class="mw-form-group">
                              <label class="form-label">Estimated Duration (minutes)</label>
                              <input type="number" name="estimated_duration" class="form-control" value="60" min="15" step="15">
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Assign To</label>
                              <select name="assigned_to" class="form-control">
                                  <option value="">Unassigned</option>
                                  <?php foreach ($staff as $s): ?>
                                      <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['full_name']); ?></option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                      </div>

                      <div class="mw-recurring-options" id="recurringOptions">
                          <div class="mw-form-row">
                              <div class="mw-form-group">
                                  <label class="form-label">Repeat</label>
                                  <select name="recurrence_pattern" class="form-control">
                                      <option value="weekly">Weekly</option>
                                      <option value="biweekly">Every 2 Weeks</option>
                                      <option value="monthly">Monthly</option>
                                  </select>
                              </div>
                              <div class="mw-form-group">
                                  <label class="form-label">End Date</label>
                                  <input type="date" name="recurrence_end_date" class="form-control"
                                         value="<?php echo date('Y-m-d', strtotime('+3 months')); ?>">
                              </div>
                          </div>
                      </div>

                  </div>
              </div>

              <div class="card">
                  <div class="card-header">
                      <h5 class="card-title mb-0">Pricing</h5>
                  </div>
                  <div class="card-body">

                      <div class="mw-form-group" style="max-width: 300px;">
                          <label class="form-label">Estimated Amount</label>
                          <input type="number" name="estimated_amount" class="form-control" step="0.01" min="0"
                                 value="<?php echo htmlspecialchars($prefill['estimated_amount'] ?? '0'); ?>"
                                 placeholder="0.00">
                      </div>

                  </div>
              </div>

              <div class="mw-form-actions">
                  <button type="submit" class="btn btn-primary">Create Job</button>
                  <a href="index.php" class="btn btn-secondary">Cancel</a>
              </div>
          </form>

          <script>
              function toggleRecurring() {
                  var jobType = document.getElementById('jobType').value;
                  var recurringOptions = document.getElementById('recurringOptions');
                  if (jobType === 'recurring') {
                      recurringOptions.classList.add('show');
                  } else {
                      recurringOptions.classList.remove('show');
                  }
              }
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
