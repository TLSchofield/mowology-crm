<?php
/**
 * Job View & Management
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$jobId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$jobId) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Get job with related data
$stmt = $db->prepare("
    SELECT
        j.*,
        p.address as property_address,
        p.city as property_city,
        p.postal_code as property_postal,
        p.latitude,
        p.longitude,
        c.company_name,
        c.billing_email,
        c.billing_phone,
        ct.first_name as contact_first,
        ct.last_name as contact_last,
        ct.email as contact_email,
        ct.phone as contact_phone,
        u.full_name as assigned_to_name,
        creator.full_name as created_by_name,
        q.quote_number
    FROM jobs j
    LEFT JOIN properties p ON j.property_id = p.id
    LEFT JOIN companies c ON j.company_id = c.id
    LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
    LEFT JOIN users u ON j.assigned_to = u.id
    LEFT JOIN users creator ON j.created_by = creator.id
    LEFT JOIN quotes q ON j.quote_id = q.id
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    header('Location: index.php');
    exit;
}

// Get job notes
$stmt = $db->prepare("
    SELECT n.*, u.full_name
    FROM job_notes n
    LEFT JOIN users u ON n.created_by = u.id
    WHERE n.job_id = ?
    ORDER BY n.created_at DESC
");
$stmt->execute([$jobId]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get job photos
$stmt = $db->prepare("SELECT * FROM job_photos WHERE job_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$jobId]);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get staff for assignment
$staff = getStaffMembers();

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'start') {
        updateJobStatus($jobId, 'in_progress', $user['id']);
        $job['status'] = 'in_progress';
        $message = 'Job started!';
        $messageType = 'success';
    }

    if ($action === 'complete') {
        $completionNotes = trim($_POST['completion_notes'] ?? '');
        $actualAmount = floatval($_POST['actual_amount'] ?? $job['estimated_amount']);

        $stmt = $db->prepare("UPDATE jobs SET actual_amount = ? WHERE id = ?");
        $stmt->execute([$actualAmount, $jobId]);

        updateJobStatus($jobId, 'completed', $user['id'], $completionNotes);
        $job['status'] = 'completed';
        $message = 'Job completed!';
        $messageType = 'success';
    }

    if ($action === 'assign') {
        $assignTo = intval($_POST['assign_to'] ?? 0);
        $stmt = $db->prepare("UPDATE jobs SET assigned_to = ?, assigned_at = NOW() WHERE id = ?");
        $stmt->execute([$assignTo ?: null, $jobId]);
        logActivityExtended($user['id'], 'Job assigned', null, $job['company_id'], $jobId);
        header("Location: view.php?id={$jobId}&assigned=1");
        exit;
    }

    if ($action === 'reschedule') {
        $newDate = $_POST['scheduled_date'] ?? '';
        $newTimeStart = $_POST['scheduled_time_start'] ?? '';
        $newTimeEnd = $_POST['scheduled_time_end'] ?? '';

        $stmt = $db->prepare("
            UPDATE jobs SET scheduled_date = ?, scheduled_time_start = ?, scheduled_time_end = ?
            WHERE id = ?
        ");
        $stmt->execute([$newDate ?: null, $newTimeStart ?: null, $newTimeEnd ?: null, $jobId]);
        logActivityExtended($user['id'], 'Job rescheduled', "Rescheduled to {$newDate}", $job['company_id'], $jobId);
        header("Location: view.php?id={$jobId}&rescheduled=1");
        exit;
    }

    if ($action === 'add_note') {
        $noteContent = trim($_POST['note_content'] ?? '');
        $noteType = $_POST['note_type'] ?? 'general';

        if ($noteContent) {
            $stmt = $db->prepare("INSERT INTO job_notes (job_id, note_type, content, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$jobId, $noteType, $noteContent, $user['id']]);
            header("Location: view.php?id={$jobId}&note_added=1");
            exit;
        }
    }

    if ($action === 'upload_photo') {
        $photoType = $_POST['photo_type'] ?? 'after';
        $caption = trim($_POST['photo_caption'] ?? '');

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $maxSize = 10 * 1024 * 1024; // 10MB

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);

            if (!in_array($mimeType, $allowedTypes)) {
                $message = 'Invalid file type. Only JPG, PNG, and WebP allowed.';
                $messageType = 'error';
            } elseif ($file['size'] > $maxSize) {
                $message = 'File too large. Maximum 10MB.';
                $messageType = 'error';
            } else {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $newFilename = 'job_' . $jobId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $uploadPath = '../uploads/photos/' . $newFilename;

                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    $stmt = $db->prepare("
                        INSERT INTO job_photos (job_id, photo_type, filename, original_filename, file_size, mime_type, caption, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$jobId, $photoType, $newFilename, $file['name'], $file['size'], $mimeType, $caption, $user['id']]);
                    header("Location: view.php?id={$jobId}&photo_added=1");
                    exit;
                } else {
                    $message = 'Error uploading file.';
                    $messageType = 'error';
                }
            }
        }
    }

    if ($action === 'edit_job') {
        // Update job details
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $serviceType = trim($_POST['service_type'] ?? '');
        $estimatedAmount = floatval($_POST['estimated_amount'] ?? 0);

        // Update scheduling
        $jobType = $_POST['job_type'] ?? 'one_time';
        $scheduledDate = $_POST['scheduled_date'] ?? '';
        $scheduledTimeStart = $_POST['scheduled_time_start'] ?? '';
        $scheduledTimeEnd = $_POST['scheduled_time_end'] ?? '';
        $estimatedDurationMinutes = intval($_POST['estimated_duration_minutes'] ?? 60);

        // Validate required fields
        if (!$title) {
            $message = 'Job title is required.';
            $messageType = 'error';
        } else {
            // Update main job fields
            $stmt = $db->prepare("
                UPDATE jobs SET
                    title = ?,
                    description = ?,
                    service_type = ?,
                    estimated_amount = ?,
                    job_type = ?,
                    scheduled_date = ?,
                    scheduled_time_start = ?,
                    scheduled_time_end = ?,
                    estimated_duration_minutes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $title,
                $description ?: null,
                $serviceType,
                $estimatedAmount,
                $jobType,
                $scheduledDate ?: null,
                $scheduledTimeStart ?: null,
                $scheduledTimeEnd ?: null,
                $estimatedDurationMinutes,
                $jobId
            ]);

            // If recurring, update recurrence fields and generate instances
            if ($jobType === 'recurring') {
                $recurrencePattern = $_POST['recurrence_pattern'] ?? 'weekly';
                $recurrenceEndDate = $_POST['recurrence_end_date'] ?? '';
                $recurrenceInterval = intval($_POST['recurrence_interval'] ?? 1);
                $recurrenceIntervalUnit = $_POST['recurrence_interval_unit'] ?? 'weeks';

                // Calculate recurrence_day_of_week from scheduled_date
                $recurrenceDayOfWeek = null;
                if ($scheduledDate) {
                    $dayNum = intval(date('w', strtotime($scheduledDate))); // 0=Sunday, 6=Saturday
                    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    $recurrenceDayOfWeek = $dayNames[$dayNum];
                }

                $stmt = $db->prepare("
                    UPDATE jobs SET
                        recurrence_pattern = ?,
                        recurrence_end_date = ?,
                        recurrence_interval = ?,
                        recurrence_interval_unit = ?,
                        recurrence_day_of_week = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $recurrencePattern,
                    $recurrenceEndDate ?: null,
                    $recurrenceInterval,
                    $recurrenceIntervalUnit,
                    $recurrenceDayOfWeek,
                    $jobId
                ]);

                // Generate recurring job instances for calendar
                generateRecurringJobInstancesForParent(
                    $jobId,
                    $job['company_id'],
                    $job['property_id'],
                    $scheduledDate,
                    $recurrenceEndDate ?: date('Y-m-d', strtotime('+1 year')),
                    $recurrencePattern,
                    $recurrenceInterval,
                    $recurrenceIntervalUnit,
                    $scheduledDate ? intval(date('w', strtotime($scheduledDate))) : 3, // 3 = Wednesday
                    $scheduledTimeStart,
                    $scheduledTimeEnd,
                    $estimatedDurationMinutes,
                    $user['id']
                );
            } else {
                // Clear recurrence fields for one-time jobs
                $stmt = $db->prepare("
                    UPDATE jobs SET
                        recurrence_pattern = NULL,
                        recurrence_end_date = NULL,
                        recurrence_interval = NULL,
                        recurrence_interval_unit = NULL,
                        recurrence_day_of_week = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$jobId]);

                // Delete any child instances if job was converted from recurring to one-time
                $stmt = $db->prepare("DELETE FROM jobs WHERE parent_job_id = ?");
                $stmt->execute([$jobId]);
            }

            // Log the activity
            $changeDetails = "Updated job details";
            logActivityExtended($user['id'], 'Job updated', $changeDetails, $job['company_id'], $jobId);

            // Refresh job data
            $stmt = $db->prepare("
                SELECT
                    j.*,
                    p.address as property_address,
                    p.city as property_city,
                    p.postal_code as property_postal,
                    p.latitude,
                    p.longitude,
                    c.company_name,
                    c.billing_email,
                    c.billing_phone,
                    ct.first_name as contact_first,
                    ct.last_name as contact_last,
                    ct.email as contact_email,
                    ct.phone as contact_phone,
                    u.full_name as assigned_to_name,
                    creator.full_name as created_by_name,
                    q.quote_number
                FROM jobs j
                LEFT JOIN properties p ON j.property_id = p.id
                LEFT JOIN companies c ON j.company_id = c.id
                LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
                LEFT JOIN users u ON j.assigned_to = u.id
                LEFT JOIN users creator ON j.created_by = creator.id
                LEFT JOIN quotes q ON j.quote_id = q.id
                WHERE j.id = ?
            ");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            $message = 'Job updated successfully!';
            $messageType = 'success';
        }
    }
}

// Handle URL action parameter
if (isset($_GET['action']) && $_GET['action'] === 'start' && $job['status'] === 'scheduled') {
    // Show start confirmation
}

$csrfToken = generateCSRFToken();

// Check for messages
if (isset($_GET['created'])) { $message = 'Job created successfully!'; $messageType = 'success'; }
if (isset($_GET['assigned'])) { $message = 'Job assigned successfully!'; $messageType = 'success'; }
if (isset($_GET['rescheduled'])) { $message = 'Job rescheduled!'; $messageType = 'success'; }
if (isset($_GET['note_added'])) { $message = 'Note added!'; $messageType = 'success'; }
if (isset($_GET['photo_added'])) { $message = 'Photo uploaded!'; $messageType = 'success'; }

$pageTitle = 'Job ' . htmlspecialchars($job['job_number'] ?? 'Unknown');
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <a href="index.php" class="mw-back-link">&larr; Back to Jobs</a>

            <?php if ($message): ?>
                <div class="mw-message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <div class="mw-page-header">
                <div>
                    <h1 class="h3 mb-0"><?php echo htmlspecialchars($job['job_number']); ?></h1>
                    <div class="mt-2">
                        <?php echo getStatusBadge($job['status'], 'job'); ?>
                        <span class="ml-3 text-muted">
                            <?php echo htmlspecialchars($job['title'] ?? ''); ?>
                        </span>
                    </div>
                </div>
                <div class="mw-header-actions">
                    <button type="button" class="btn btn-primary" onclick="showModal('editJobModal')">
                        ✎ Edit Job
                    </button>
                    <?php if ($job['status'] === 'scheduled'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <button type="submit" name="action" value="start" class="btn btn-info">
                                &#9654; Start Job
                            </button>
                        </form>
                        <button type="button" class="btn btn-secondary" onclick="showModal('rescheduleModal')">
                            Reschedule
                        </button>
                    <?php elseif ($job['status'] === 'in_progress'): ?>
                        <button type="button" class="btn btn-success" onclick="showModal('completeModal')">
                            &#10003; Complete Job
                        </button>
                    <?php elseif ($job['status'] === 'completed'): ?>
                        <a href="../invoices/create.php?job_id=<?php echo $jobId; ?>" class="btn btn-primary">
                            Create Invoice
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mw-content-grid">
                <div>
                    <!-- Job Details -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Job Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Service Type</span>
                                <span class="mw-detail-value"><?php echo ucfirst(str_replace('_', ' ', $job['service_type'])); ?></span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Job Type</span>
                                <span class="mw-detail-value"><?php echo ucfirst(str_replace('_', ' ', $job['job_type'])); ?></span>
                            </div>
                            <?php if ($job['description']): ?>
                                <div class="mt-3">
                                    <span class="mw-detail-label">Description</span>
                                    <p class="mt-2" style="white-space: pre-line;"><?php echo htmlspecialchars($job['description']); ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if ($job['quote_number']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">From Quote</span>
                                    <span class="mw-detail-value">
                                        <a href="../quotes/view.php?id=<?php echo $job['quote_id']; ?>"><?php echo htmlspecialchars($job['quote_number']); ?></a>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Customer Info -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Customer</h5>
                        </div>
                        <div class="card-body">
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Company</span>
                                <span class="mw-detail-value"><?php echo htmlspecialchars($job['company_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Contact</span>
                                <span class="mw-detail-value">
                                    <?php echo htmlspecialchars(trim(($job['contact_first'] ?? '') . ' ' . ($job['contact_last'] ?? '')) ?: 'N/A'); ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Phone</span>
                                <span class="mw-detail-value">
                                    <?php $phone = $job['contact_phone'] ?: $job['billing_phone']; ?>
                                    <?php if ($phone): ?>
                                        <a href="tel:<?php echo htmlspecialchars($phone); ?>">
                                            <?php echo htmlspecialchars($phone); ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Property</span>
                                <span class="mw-detail-value">
                                    <?php
                                        $propertyDisplay = trim(($job['property_address'] ?? '') . ', ' . ($job['property_city'] ?? ''));
                                        echo htmlspecialchars($propertyDisplay ?: 'N/A');
                                    ?>
                                </span>
                            </div>

                            <?php if ($job['latitude'] && $job['longitude']): ?>
                                <div class="mw-map-placeholder" id="mapContainer">
                                    <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $job['latitude']; ?>,<?php echo $job['longitude']; ?>"
                                       target="_blank" class="btn btn-secondary">
                                       Get Directions
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Notes</h5>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="showModal('noteModal')">+ Add Note</button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($notes)): ?>
                                <p class="text-muted small">No notes yet.</p>
                            <?php else: ?>
                                <?php foreach ($notes as $note): ?>
                                    <div class="mw-note-item">
                                        <div class="mw-note-header">
                                            <span class="mw-note-type"><?php echo $note['note_type']; ?></span>
                                            <span><?php echo htmlspecialchars($note['full_name'] ?? 'System'); ?> - <?php echo formatDateTime($note['created_at']); ?></span>
                                        </div>
                                        <div class="mw-note-content"><?php echo nl2br(htmlspecialchars($note['content'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Photos -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Photos</h5>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="showModal('photoModal')">+ Upload Photo</button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($photos)): ?>
                                <p class="text-muted small">No photos yet.</p>
                            <?php else: ?>
                                <div class="mw-photos-grid">
                                    <?php foreach ($photos as $photo): ?>
                                        <div class="mw-photo-item">
                                            <img src="../uploads/photos/<?php echo htmlspecialchars($photo['filename']); ?>"
                                                 alt="<?php echo htmlspecialchars($photo['caption'] ?: 'Job photo'); ?>">
                                            <span class="mw-photo-type-badge"><?php echo $photo['photo_type']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div>
                    <!-- Scheduling -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Schedule</h5>
                        </div>
                        <div class="card-body">
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Date</span>
                                <span class="mw-detail-value">
                                    <?php echo $job['scheduled_date'] ? formatDate($job['scheduled_date']) : 'Not scheduled'; ?>
                                </span>
                            </div>
                            <?php if ($job['scheduled_time_start']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Time</span>
                                    <span class="mw-detail-value">
                                        <?php echo date('g:i A', strtotime($job['scheduled_time_start'])); ?>
                                        <?php if ($job['scheduled_time_end']): ?>
                                            - <?php echo date('g:i A', strtotime($job['scheduled_time_end'])); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Duration</span>
                                <span class="mw-detail-value"><?php echo $job['estimated_duration_minutes']; ?> min</span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Type</span>
                                <span class="mw-detail-value">
                                    <?php if ($job['job_type'] === 'recurring'): ?>
                                        <span class="mw-badge" style="background: var(--mw-lime); color: #000;">Recurring</span>
                                    <?php else: ?>
                                        One-Time
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($job['job_type'] === 'recurring' && $job['recurrence_pattern']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Frequency</span>
                                    <span class="mw-detail-value">
                                        <?php
                                            // Display custom or preset recurrence pattern
                                            if ($job['recurrence_pattern'] === 'weekly') {
                                                $pattern = 'Weekly';
                                            } elseif ($job['recurrence_pattern'] === 'biweekly') {
                                                $pattern = 'Every 2 Weeks';
                                            } elseif ($job['recurrence_pattern'] === 'monthly') {
                                                $pattern = 'Monthly';
                                            } else {
                                                // Fallback for unknown patterns
                                                $pattern = ucfirst(str_replace('_', ' ', $job['recurrence_pattern']));
                                            }
                                            echo htmlspecialchars($pattern);
                                        ?>
                                    </span>
                                </div>
                                <?php if ($job['recurrence_end_date']): ?>
                                    <div class="mw-detail-row">
                                        <span class="mw-detail-label">Until</span>
                                        <span class="mw-detail-value"><?php echo formatDate($job['recurrence_end_date']); ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Assignment -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Assignment</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="assign">

                                <div class="form-group">
                                    <label class="form-label">Assigned To</label>
                                    <select name="assign_to" class="form-control" onchange="this.form.submit()">
                                        <option value="">Unassigned</option>
                                        <?php foreach ($staff as $s): ?>
                                            <option value="<?php echo $s['id']; ?>" <?php echo $job['assigned_to'] == $s['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($s['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Pricing</h5>
                        </div>
                        <div class="card-body">
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Estimated</span>
                                <span class="mw-detail-value"><?php echo formatCurrency($job['estimated_amount']); ?></span>
                            </div>
                            <?php if ($job['actual_amount']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Actual</span>
                                    <span class="mw-detail-value"><?php echo formatCurrency($job['actual_amount']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Timeline -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Timeline</h5>
                        </div>
                        <div class="card-body">
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Created</span>
                                <span class="mw-detail-value"><?php echo formatDateTime($job['created_at']); ?></span>
                            </div>
                            <?php if ($job['started_at']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Started</span>
                                    <span class="mw-detail-value"><?php echo formatDateTime($job['started_at']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($job['completed_at']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Completed</span>
                                    <span class="mw-detail-value"><?php echo formatDateTime($job['completed_at']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Created By</span>
                                <span class="mw-detail-value"><?php echo htmlspecialchars($job['created_by_name'] ?? 'Unknown'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

    <!-- Edit Job Modal -->
    <div class="mw-modal-overlay" id="editJobModal">
        <div class="mw-modal" style="max-width: 600px;">
            <h3 class="mw-modal-title">Edit Job</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="edit_job">

                <!-- Job Details Section -->
                <div style="border-bottom: 1px solid #e0e0e0; padding-bottom: 16px; margin-bottom: 16px;">
                    <h5 style="margin: 0 0 12px 0; font-weight: 600; color: #333;">Job Details</h5>

                    <div class="form-group">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" required
                               value="<?php echo htmlspecialchars($job['title'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Job description..."><?php echo htmlspecialchars($job['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Service Type</label>
                        <input type="text" name="service_type" class="form-control"
                               value="<?php echo htmlspecialchars($job['service_type'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Estimated Amount ($)</label>
                        <input type="number" name="estimated_amount" class="form-control" step="0.01"
                               value="<?php echo htmlspecialchars($job['estimated_amount'] ?? 0); ?>">
                    </div>
                </div>

                <!-- Scheduling Section -->
                <div style="border-bottom: 1px solid #e0e0e0; padding-bottom: 16px; margin-bottom: 16px;">
                    <h5 style="margin: 0 0 12px 0; font-weight: 600; color: #333;">Scheduling</h5>

                    <div class="form-group">
                        <label class="form-label">Job Type</label>
                        <select name="job_type" id="editJobType" class="form-control" onchange="toggleEditRecurringOptions()">
                            <option value="one_time" <?php echo $job['job_type'] === 'one_time' ? 'selected' : ''; ?>>One-Time</option>
                            <option value="recurring" <?php echo $job['job_type'] === 'recurring' ? 'selected' : ''; ?>>Recurring</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="date" name="scheduled_date" class="form-control"
                               value="<?php echo htmlspecialchars($job['scheduled_date'] ?? date('Y-m-d')); ?>">
                    </div>

                    <div style="display: flex; gap: 12px;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Start Time</label>
                            <input type="time" name="scheduled_time_start" class="form-control"
                                   value="<?php echo htmlspecialchars($job['scheduled_time_start'] ?? '09:00'); ?>">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">End Time</label>
                            <input type="time" name="scheduled_time_end" class="form-control"
                                   value="<?php echo htmlspecialchars($job['scheduled_time_end'] ?? '10:00'); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Duration (minutes)</label>
                        <input type="number" name="estimated_duration_minutes" class="form-control"
                               value="<?php echo htmlspecialchars($job['estimated_duration_minutes'] ?? 60); ?>">
                    </div>
                </div>

                <!-- Recurring Options Section (Conditional) -->
                <div id="editRecurringOptions" style="<?php echo $job['job_type'] === 'recurring' ? '' : 'display: none;'; ?> border-bottom: 1px solid #e0e0e0; padding-bottom: 16px; margin-bottom: 16px;">
                    <h5 style="margin: 0 0 12px 0; font-weight: 600; color: #333;">Recurring Options</h5>

                    <div class="form-group">
                        <label class="form-label">Frequency</label>
                        <select name="recurrence_pattern" id="editRecurrencePattern" class="form-control" onchange="toggleEditCustomRecurrence()">
                            <option value="weekly" <?php echo $job['recurrence_pattern'] === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="biweekly" <?php echo $job['recurrence_pattern'] === 'biweekly' ? 'selected' : ''; ?>>Every 2 Weeks</option>
                            <option value="monthly" <?php echo $job['recurrence_pattern'] === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="custom" <?php echo $job['recurrence_pattern'] === 'custom' ? 'selected' : ''; ?>>Custom Interval</option>
                        </select>
                    </div>

                    <!-- Custom Interval Options (Conditional) -->
                    <div id="editCustomIntervalOptions" style="<?php echo $job['recurrence_pattern'] === 'custom' ? '' : 'display: none;'; ?> display: flex; gap: 12px; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <label class="form-label">Every</label>
                            <input type="number" name="recurrence_interval" class="form-control" min="1"
                                   value="<?php echo htmlspecialchars($job['recurrence_interval'] ?? 1); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label class="form-label">Unit</label>
                            <select name="recurrence_interval_unit" class="form-control">
                                <option value="days" <?php echo ($job['recurrence_interval_unit'] ?? 'weeks') === 'days' ? 'selected' : ''; ?>>Days</option>
                                <option value="weeks" <?php echo ($job['recurrence_interval_unit'] ?? 'weeks') === 'weeks' ? 'selected' : ''; ?>>Weeks</option>
                                <option value="months" <?php echo ($job['recurrence_interval_unit'] ?? 'weeks') === 'months' ? 'selected' : ''; ?>>Months</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="recurrence_end_date" class="form-control"
                               value="<?php echo htmlspecialchars($job['recurrence_end_date'] ?? ''); ?>">
                    </div>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editJobModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reschedule Modal -->
    <div class="mw-modal-overlay" id="rescheduleModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Reschedule Job</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="reschedule">

                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="scheduled_date" class="form-control"
                           value="<?php echo $job['scheduled_date'] ?? date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Start Time</label>
                    <input type="time" name="scheduled_time_start" class="form-control"
                           value="<?php echo $job['scheduled_time_start'] ?? '09:00'; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">End Time</label>
                    <input type="time" name="scheduled_time_end" class="form-control"
                           value="<?php echo $job['scheduled_time_end'] ?? '10:00'; ?>">
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('rescheduleModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Complete Modal -->
    <div class="mw-modal-overlay" id="completeModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Complete Job</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="complete">

                <div class="form-group">
                    <label class="form-label">Actual Amount</label>
                    <input type="number" name="actual_amount" class="form-control" step="0.01"
                           value="<?php echo $job['estimated_amount']; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Completion Notes</label>
                    <textarea name="completion_notes" class="form-control"
                              placeholder="Any notes about the completed work..."></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-success">Complete Job</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('completeModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Note Modal -->
    <div class="mw-modal-overlay" id="noteModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Add Note</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="add_note">

                <div class="form-group">
                    <label class="form-label">Note Type</label>
                    <select name="note_type" class="form-control">
                        <option value="general">General</option>
                        <option value="customer_request">Customer Request</option>
                        <option value="issue">Issue</option>
                        <option value="internal">Internal</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea name="note_content" class="form-control" required
                              placeholder="Enter note..."></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Add Note</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('noteModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Photo Modal -->
    <div class="mw-modal-overlay" id="photoModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Upload Photo</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="upload_photo">

                <div class="form-group">
                    <label class="form-label">Photo Type</label>
                    <select name="photo_type" class="form-control">
                        <option value="before">Before</option>
                        <option value="during">During</option>
                        <option value="after" selected>After</option>
                        <option value="issue">Issue</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Photo</label>
                    <input type="file" name="photo" class="form-control" accept="image/*" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Caption (optional)</label>
                    <input type="text" name="photo_caption" class="form-control" placeholder="Describe the photo...">
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Upload</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('photoModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showModal(id) {
            document.getElementById(id).classList.add('show');
        }

        function hideModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        // Close modal on overlay click
        document.querySelectorAll('.mw-modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });

        // Toggle recurring options based on job type in Edit modal
        function toggleEditRecurringOptions() {
            var jobType = document.getElementById('editJobType').value;
            var recurringOptions = document.getElementById('editRecurringOptions');
            if (jobType === 'recurring') {
                recurringOptions.style.display = 'block';
            } else {
                recurringOptions.style.display = 'none';
            }
        }

        // Toggle custom interval fields based on recurrence pattern
        function toggleEditCustomRecurrence() {
            var pattern = document.getElementById('editRecurrencePattern').value;
            var customOptions = document.getElementById('editCustomIntervalOptions');
            if (pattern === 'custom') {
                customOptions.style.display = 'flex';
            } else {
                customOptions.style.display = 'none';
            }
        }
    </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
