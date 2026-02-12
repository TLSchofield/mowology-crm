<?php
/**
 * Shared Helper Functions for Mowology CRM
 */

/**
 * Generate a unique quote number
 * Format: QUO-YYYY-NNNN
 */
function generateQuoteNumber() {
    $db = getDB();
    $year = date('Y');
    $stmt = $db->query("
        SELECT MAX(CAST(SUBSTRING(quote_number, 10) AS UNSIGNED)) as max_num
        FROM quotes
        WHERE quote_number LIKE 'QUO-{$year}-%'
    ");
    $result = $stmt->fetch();
    $nextNum = ($result['max_num'] ?? 0) + 1;
    return sprintf("QUO-%s-%04d", $year, $nextNum);
}

/**
 * Generate a unique job number
 * Format: JOB-YYYY-NNNN
 */
function generateJobNumber() {
    $db = getDB();
    $year = date('Y');
    $stmt = $db->query("
        SELECT MAX(CAST(SUBSTRING(job_number, 10) AS UNSIGNED)) as max_num
        FROM jobs
        WHERE job_number LIKE 'JOB-{$year}-%'
    ");
    $result = $stmt->fetch();
    $nextNum = ($result['max_num'] ?? 0) + 1;
    return sprintf("JOB-%s-%04d", $year, $nextNum);
}

/**
 * Generate a unique invoice number
 * Format: INV-YYYY-NNNN
 */
function generateInvoiceNumber() {
    $db = getDB();
    $year = date('Y');
    $stmt = $db->query("
        SELECT MAX(CAST(SUBSTRING(invoice_number, 11) AS UNSIGNED)) as max_num
        FROM invoices
        WHERE invoice_number LIKE 'INV-{$year}-%'
    ");
    $result = $stmt->fetch();
    $nextNum = ($result['max_num'] ?? 0) + 1;
    return sprintf("INV-%s-%04d", $year, $nextNum);
}

/**
 * Generate a secure access token for customer-facing pages
 */
function generateAccessToken() {
    return bin2hex(random_bytes(32));
}

/**
 * Calculate quote totals from line items
 */
function calculateQuoteTotals($lineItems, $taxRate = 0.05) {
    $subtotal = 0;
    foreach ($lineItems as $item) {
        if (!($item['is_optional'] ?? false)) {
            $subtotal += floatval($item['line_total']);
        }
    }
    $taxAmount = round($subtotal * $taxRate, 2);
    $total = $subtotal + $taxAmount;

    return [
        'subtotal' => $subtotal,
        'tax_rate' => $taxRate,
        'tax_amount' => $taxAmount,
        'total' => $total
    ];
}

/**
 * Format currency for display
 */
function formatCurrency($amount) {
    return '$' . number_format(floatval($amount), 2);
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'M j, Y') {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 */
function formatDateTime($datetime, $format = 'M j, Y g:i A') {
    if (empty($datetime)) return '-';
    return date($format, strtotime($datetime));
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status, $type = 'quote') {
    $colors = [
        // Quote statuses
        'draft' => ['bg' => '#6B7280', 'text' => '#FFFFFF'],
        'sent' => ['bg' => '#3B82F6', 'text' => '#FFFFFF'],
        'accepted' => ['bg' => '#2D8659', 'text' => '#FFFFFF'],
        'declined' => ['bg' => '#DC2626', 'text' => '#FFFFFF'],
        'expired' => ['bg' => '#F59E0B', 'text' => '#000000'],

        // Job / Visit statuses
        'scheduled' => ['bg' => '#3B82F6', 'text' => '#FFFFFF'],
        'in_progress' => ['bg' => '#F59E0B', 'text' => '#000000'],
        'completed' => ['bg' => '#2D8659', 'text' => '#FFFFFF'],
        'cancelled' => ['bg' => '#6B7280', 'text' => '#FFFFFF'],
        'on_hold' => ['bg' => '#8B5CF6', 'text' => '#FFFFFF'],
        'skipped' => ['bg' => '#9CA3AF', 'text' => '#FFFFFF'],
        'weather' => ['bg' => '#60A5FA', 'text' => '#FFFFFF'],

        // Plan statuses
        'active' => ['bg' => '#2D8659', 'text' => '#FFFFFF'],
        'paused' => ['bg' => '#F59E0B', 'text' => '#000000'],

        // Invoice statuses
        'paid' => ['bg' => '#2D8659', 'text' => '#FFFFFF'],
        'partial' => ['bg' => '#F59E0B', 'text' => '#000000'],
        'overdue' => ['bg' => '#DC2626', 'text' => '#FFFFFF'],
        'viewed' => ['bg' => '#8B5CF6', 'text' => '#FFFFFF'],
    ];

    $color = $colors[$status] ?? ['bg' => '#6B7280', 'text' => '#FFFFFF'];
    $label = ucfirst(str_replace('_', ' ', $status));

    return sprintf(
        '<span class="mw-badge-status" style="background: %s; color: %s;">%s</span>',
        $color['bg'],
        $color['text'],
        htmlspecialchars($label)
    );
}

/**
 * Log activity with extended fields
 * Supports both legacy job_id and new plan_id/visit_id
 */
function logActivityExtended($userId, $action, $details = null, $companyId = null, $jobId = null, $quoteId = null, $invoiceId = null, $planId = null, $visitId = null) {
    $db = getDB();

    try {
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, company_id, job_id, quote_id, invoice_id, plan_id, visit_id, action, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $companyId,
            $jobId,
            $quoteId,
            $invoiceId,
            $planId,
            $visitId,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch(PDOException $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

/**
 * Get company/property/contact info for a quote or job
 */
function getPropertyDetails($propertyId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT
            p.*,
            c.company_name,
            c.billing_email,
            c.billing_phone,
            ct.first_name,
            ct.last_name,
            ct.email as contact_email,
            ct.phone as contact_phone
        FROM properties p
        LEFT JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
        LEFT JOIN companies c ON cp.company_id = c.id
        LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
        WHERE p.id = ?
    ");
    $stmt->execute([$propertyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all staff members for assignment dropdown
 */
function getStaffMembers() {
    $db = getDB();
    $stmt = $db->query("
        SELECT id, full_name, email, role
        FROM users
        WHERE is_active = 1
        ORDER BY full_name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get service templates for quick quote creation
 */
function getServiceTemplates() {
    $db = getDB();
    $stmt = $db->query("
        SELECT * FROM service_templates
        WHERE is_active = 1
        ORDER BY sort_order, name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @deprecated Use createPlanFromQuote() in plan-functions.php instead.
 * This function inserts into the legacy `jobs` table which will be dropped.
 * Kept only for backward compatibility during migration.
 */
function createJobFromQuote($quoteId, $userId) {
    $db = getDB();

    // Get quote details with lead_event_id for ROI attribution
    $stmt = $db->prepare("
        SELECT q.*, q.company_id, q.lead_event_id, p.address
        FROM quotes q
        JOIN properties p ON q.property_id = p.id
        WHERE q.id = ?
    ");
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        return ['success' => false, 'error' => 'Quote not found'];
    }

    $db->beginTransaction();

    try {
        $jobNumber = generateJobNumber();

        // Create job
        $stmt = $db->prepare("
            INSERT INTO jobs (
                job_number, quote_id, property_id, company_id, title, description,
                service_type, estimated_amount, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)
        ");
        $stmt->execute([
            $jobNumber,
            $quoteId,
            $quote['property_id'],
            $quote['company_id'],
            $quote['title'] ?: 'Job from ' . $quote['quote_number'],
            $quote['description'],
            $quote['service_type'],
            $quote['amount'],
            $userId
        ]);

        $jobId = $db->lastInsertId();

        // Create ROI attribution (link job to lead event)
        if (!empty($quote['lead_event_id'])) {
            require_once __DIR__ . '/roi-functions.php';
            createROIAttribution(
                $jobId,
                (int)$quote['lead_event_id'],
                null,
                (float)($quote['amount'] ?? 0)
            );
            logConversionEvent((int)$quote['lead_event_id'], 'job_created', $jobId);
        }

        // Update contact to client status
        $contactStmt = $db->prepare("
            SELECT contact_id FROM quote_requests WHERE quote_id = ? LIMIT 1
        ");
        $contactStmt->execute([$quoteId]);
        $contactRow = $contactStmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($contactRow['contact_id'])) {
            require_once __DIR__ . '/roi-functions.php';
            updateContactToClient((int)$contactRow['contact_id']);
        }

        // Log activity
        logActivityExtended(
            $userId,
            'Job created from quote',
            "Job {$jobNumber} created from quote {$quote['quote_number']}",
            $quote['company_id'],
            $jobId,
            $quoteId
        );

        $db->commit();

        return [
            'success' => true,
            'job_id' => $jobId,
            'job_number' => $jobNumber
        ];

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error creating job from quote: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update job status with proper logging
 */
function updateJobStatus($jobId, $newStatus, $userId, $notes = null) {
    $db = getDB();

    $timestampField = '';
    if ($newStatus === 'in_progress') {
        $timestampField = ', started_at = NOW()';
    } elseif ($newStatus === 'completed') {
        $timestampField = ', completed_at = NOW()';
    }

    $completionNotes = '';
    if ($notes && $newStatus === 'completed') {
        $completionNotes = ', completion_notes = ?';
    }

    try {
        $sql = "UPDATE jobs SET status = ?, status_changed_at = NOW(){$timestampField}{$completionNotes} WHERE id = ?";
        $params = [$newStatus];
        if ($notes && $newStatus === 'completed') {
            $params[] = $notes;
        }
        $params[] = $jobId;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Log activity
        logActivityExtended($userId, "Job status changed to {$newStatus}", $notes, null, $jobId);

        return true;
    } catch (Exception $e) {
        error_log("Error updating job status: " . $e->getMessage());
        return false;
    }
}

/**
 * Get dashboard statistics
 * Updated to use job_plans + job_visits tables
 */
function getDashboardStats() {
    $db = getDB();
    $stats = [];

    // Quote stats
    $stmt = $db->query("SELECT status, COUNT(*) as count FROM quotes GROUP BY status");
    $stats['quotes'] = [];
    while ($row = $stmt->fetch()) {
        $stats['quotes'][$row['status']] = $row['count'];
    }

    // Plan stats (replaces job stats)
    $stats['plans'] = [];
    try {
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM job_plans GROUP BY status");
        while ($row = $stmt->fetch()) {
            $stats['plans'][$row['status']] = $row['count'];
        }
    } catch (Exception $e) {
        // Table may not exist yet during migration
    }

    // Visit stats
    $stats['visits'] = [];
    try {
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM job_visits WHERE scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) GROUP BY status");
        while ($row = $stmt->fetch()) {
            $stats['visits'][$row['status']] = $row['count'];
        }
    } catch (Exception $e) {
        // Table may not exist yet during migration
    }

    // Visits today
    $stats['visits_today'] = 0;
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM job_visits WHERE scheduled_date = CURDATE() AND status IN ('scheduled', 'in_progress')");
        $stats['visits_today'] = $stmt->fetch()['count'];
    } catch (Exception $e) {
        // Table may not exist yet
    }

    // Backward compat: map plan stats to 'jobs' key for dashboard
    $stats['jobs'] = [];
    $stats['jobs']['scheduled'] = $stats['visits']['scheduled'] ?? 0;
    $stats['jobs']['in_progress'] = $stats['visits']['in_progress'] ?? 0;
    $stats['jobs']['completed'] = $stats['visits']['completed'] ?? 0;
    $stats['jobs_today'] = $stats['visits_today'];

    // Invoice stats
    $stmt = $db->query("SELECT status, COUNT(*) as count, SUM(balance_due) as total_due FROM invoices GROUP BY status");
    $stats['invoices'] = [];
    $stats['total_outstanding'] = 0;
    while ($row = $stmt->fetch()) {
        $stats['invoices'][$row['status']] = $row['count'];
        if (in_array($row['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
            $stats['total_outstanding'] += floatval($row['total_due']);
        }
    }

    return $stats;
}

/**
 * Human-readable relative time (e.g., "2 hours ago")
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'just now';
}

/**
 * Format service_types comma-separated string into array of display labels
 */
function formatServiceTypes($services) {
    if (empty($services)) return [];
    $labels = [
        'maintenance' => 'Maintenance',
        'cleanup' => 'Cleanup',
        'hedge_trimming' => 'Hedge Trimming',
        'lawn_care' => 'Lawn Care',
        'snow_removal' => 'Snow Removal',
        'landscaping' => 'Landscaping',
        'garden_maintenance' => 'Garden Maintenance',
        'seasonal_cleanup' => 'Seasonal Cleanup',
    ];
    $list = explode(',', $services);
    $formatted = [];
    foreach ($list as $s) {
        $s = trim($s);
        if ($s !== '') {
            $formatted[] = $labels[$s] ?? ucwords(str_replace('_', ' ', $s));
        }
    }
    return $formatted;
}

/**
 * Get all notes for a quote
 * @param int $quoteId
 * @return array Array of note records
 */
function getQuoteNotes($quoteId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT qn.*, u.full_name as created_by_name
        FROM quote_notes qn
        LEFT JOIN users u ON qn.created_by = u.id
        WHERE qn.quote_id = ?
        ORDER BY qn.created_at DESC
    ");
    $stmt->execute([$quoteId]);
    return $stmt->fetchAll();
}

/**
 * Add a new note to a quote
 * @param int $quoteId
 * @param string $content
 * @param string $noteType
 * @param int $userId
 * @return array|null New note record or null on error
 */
function addQuoteNote($quoteId, $content, $noteType, $userId) {
    if (empty($content) || empty($quoteId)) {
        return null;
    }

    $db = getDB();
    try {
        $stmt = $db->prepare("
            INSERT INTO quote_notes (quote_id, note_type, content, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$quoteId, $noteType, $content, $userId]);

        // Log activity
        logActivityExtended($userId, 'Note added', "Added note to quote", null, null, $quoteId);

        // Fetch and return the new note
        $noteId = $db->lastInsertId();
        $stmt = $db->prepare("
            SELECT qn.*, u.full_name as created_by_name
            FROM quote_notes qn
            LEFT JOIN users u ON qn.created_by = u.id
            WHERE qn.id = ?
        ");
        $stmt->execute([$noteId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error adding quote note: " . $e->getMessage());
        return null;
    }
}

/**
 * Get all notes for a client/contact
 * @param int $contactId
 * @return array Array of note records
 */
function getClientNotes($contactId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT cn.*, u.full_name as created_by_name
        FROM client_notes cn
        LEFT JOIN users u ON cn.created_by = u.id
        WHERE cn.contact_id = ?
        ORDER BY cn.created_at DESC
    ");
    $stmt->execute([$contactId]);
    return $stmt->fetchAll();
}

/**
 * Add a new note to a client/contact
 * @param int $contactId
 * @param string $content
 * @param string $noteType
 * @param int $userId
 * @return array|null New note record or null on error
 */
function addClientNote($contactId, $content, $noteType, $userId) {
    if (empty($content) || empty($contactId)) {
        return null;
    }

    $db = getDB();
    try {
        $stmt = $db->prepare("
            INSERT INTO client_notes (contact_id, note_type, content, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$contactId, $noteType, $content, $userId]);

        // Log activity
        logActivityExtended($userId, 'Client note added', "Added note to client", $contactId, null, null);

        // Fetch and return the new note
        $noteId = $db->lastInsertId();
        $stmt = $db->prepare("
            SELECT cn.*, u.full_name as created_by_name
            FROM client_notes cn
            LEFT JOIN users u ON cn.created_by = u.id
            WHERE cn.id = ?
        ");
        $stmt->execute([$noteId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error adding client note: " . $e->getMessage());
        return null;
    }
}

/**
 * Format a note type enum as a badge label
 * @param string $noteType
 * @return string HTML-escaped label
 */
function getNoteTypeLabel($noteType) {
    $labels = [
        'general' => 'General',
        'customer_request' => 'Customer Request',
        'issue' => 'Issue',
        'follow_up' => 'Follow-up',
        'internal' => 'Internal',
    ];
    return htmlspecialchars($labels[$noteType] ?? ucfirst($noteType));
}

/**
 * Format a note type enum as a CSS class for styling
 * @param string $noteType
 * @return string CSS class name
 */
function getNoteTypeClass($noteType) {
    return 'mw-note-type-' . str_replace('_', '-', htmlspecialchars($noteType));
}

/**
 * Generate a unique project number
 * Format: PORT-YYYY-NNNN
 */
function generateProjectNumber() {
    $db = getDB();
    $year = date('Y');
    $stmt = $db->query("
        SELECT MAX(CAST(SUBSTRING(project_number, 12) AS UNSIGNED)) as max_num
        FROM portfolio_projects
        WHERE project_number LIKE 'PORT-{$year}-%'
    ");
    $result = $stmt->fetch();
    $nextNum = ($result['max_num'] ?? 0) + 1;
    return sprintf("PORT-%s-%04d", $year, $nextNum);
}

/**
 * Get all portfolio projects with optional filters
 * @param string $status 'draft', 'published', or empty for all
 * @param bool $featuredOnly Return only featured projects
 * @param int $limit Maximum number of projects to return
 * @return array Array of project records
 */
function getPortfolioProjects($status = '', $featuredOnly = false, $limit = 100) {
    $db = getDB();
    $params = [];
    $whereConditions = ['1=1'];

    if ($status) {
        $whereConditions[] = 'status = ?';
        $params[] = $status;
    }

    if ($featuredOnly) {
        $whereConditions[] = 'featured = true';
    }

    $whereClause = implode(' AND ', $whereConditions);

    $stmt = $db->prepare("
        SELECT *
        FROM portfolio_projects
        WHERE {$whereClause}
        ORDER BY featured DESC, display_order ASC, created_at DESC
        LIMIT ?
    ");
    $params[] = intval($limit);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a single portfolio project by ID
 * @param int $projectId
 * @return array|null Project record or null if not found
 */
function getPortfolioProject($projectId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM portfolio_projects WHERE id = ?");
    $stmt->execute([intval($projectId)]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Create a new portfolio project
 * @param array $data Project data: project_name, description, location, categories (json), status, featured, display_order, created_by
 * @return int|null New project ID or null on failure
 */
function createPortfolioProject($data) {
    $db = getDB();
    $projectNumber = generateProjectNumber();

    $stmt = $db->prepare("
        INSERT INTO portfolio_projects (
            project_number, project_name, description, location,
            status, featured, display_order, categories, tags, before_image_path, after_image_path,
            gallery_images, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $result = $stmt->execute([
        $projectNumber,
        $data['project_name'] ?? '',
        $data['description'] ?? '',
        $data['location'] ?? '',
        $data['status'] ?? 'draft',
        $data['featured'] ?? false,
        $data['display_order'] ?? 999,
        $data['categories'] ?? '[]',
        $data['tags'] ?? '[]',
        $data['before_image_path'] ?? null,
        $data['after_image_path'] ?? null,
        $data['gallery_images'] ?? '[]',
        $data['created_by'] ?? null
    ]);

    return $result ? $db->lastInsertId() : null;
}

/**
 * Update an existing portfolio project
 * @param int $projectId
 * @param array $data Data to update
 * @return bool Success status
 */
function updatePortfolioProject($projectId, $data) {
    $db = getDB();

    $allowedFields = ['project_name', 'description', 'location', 'status', 'featured', 'display_order', 'categories', 'tags', 'before_image_path', 'after_image_path', 'gallery_images'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "{$field} = ?";
            $params[] = $data[$field];
        }
    }

    if (empty($updates)) return false;

    $updates[] = "updated_at = NOW()";
    $params[] = intval($projectId);

    $stmt = $db->prepare("
        UPDATE portfolio_projects
        SET " . implode(', ', $updates) . "
        WHERE id = ?
    ");

    return $stmt->execute($params);
}

/**
 * Delete a portfolio project
 * @param int $projectId
 * @return bool Success status
 */
function deletePortfolioProject($projectId) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM portfolio_projects WHERE id = ?");
    return $stmt->execute([intval($projectId)]);
}

/**
 * Get portfolio projects grouped by category
 * @return array Associative array with categories as keys and project arrays as values
 */
function getPortfolioProjectsByCategory() {
    $db = getDB();
    $stmt = $db->query("
        SELECT *
        FROM portfolio_projects
        WHERE status = 'published'
        ORDER BY featured DESC, display_order ASC
    ");

    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];

    foreach ($projects as $project) {
        $categories = json_decode($project['categories'] ?? '[]', true);
        if (empty($categories)) {
            $categories = ['Uncategorized'];
        }

        foreach ($categories as $category) {
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $project;
        }
    }

    return $grouped;
}

/**
 * Parse gallery images JSON array
 * @param string|null $galleryJson JSON array of image paths
 * @return array Array of image paths
 */
function parseGalleryImages($galleryJson) {
    if (empty($galleryJson)) return [];
    $decoded = json_decode($galleryJson, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Get all lifecycle stages, optionally filtered by entity type
 * @param string $entityType 'contact', 'company', or 'both' (default) - optional for backward compat
 * @return array Array of lifecycle stage records
 */
function getLifecycleStages($entityType = null) {
    $db = getDB();

    try {
        // For backward compatibility, if entityType is not specified or is 'both', get all active stages
        if (!$entityType || $entityType === 'both') {
            $stmt = $db->query("
                SELECT id, stage_key, stage_label, stage_order, stage_color, description, is_active, entity_type
                FROM lifecycle_stages
                WHERE is_active = 1
                ORDER BY stage_order ASC
            ");
        } else {
            // Filter by entity_type
            $stmt = $db->prepare("
                SELECT id, stage_key, stage_label, stage_order, stage_color, description, is_active, entity_type
                FROM lifecycle_stages
                WHERE (entity_type = ? OR entity_type = 'both')
                AND is_active = 1
                ORDER BY stage_order ASC
            ");
            $stmt->execute([$entityType]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching lifecycle stages: " . $e->getMessage());
        return [];
    }
}

/**
 * Get companies grouped by lifecycle stage
 * @return array Associative array with stage_key as key and company arrays as values
 */
function getCompaniesByLifecycleStage() {
    $db = getDB();

    // Check if lifecycle_stage column exists on companies
    $hasLifecycleCol = false;
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM companies LIKE 'lifecycle_stage'");
        $hasLifecycleCol = ($colCheck->rowCount() > 0);
    } catch (Exception $e) {
        // Ignore
    }

    if (!$hasLifecycleCol) {
        // Column doesn't exist — return all companies under 'prospect'
        $stmt = $db->query("SELECT c.* FROM companies c ORDER BY c.company_name ASC");
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($companies as $company) {
            if (!isset($grouped['prospect'])) {
                $grouped['prospect'] = ['label' => 'Prospect', 'color' => '#3B82F6', 'companies' => []];
            }
            $grouped['prospect']['companies'][] = $company;
        }
        return $grouped;
    }

    try {
        $stmt = $db->query("
            SELECT c.*,
                   COALESCE(ls.stage_label, CONCAT(UPPER(LEFT(c.lifecycle_stage, 1)), SUBSTRING(c.lifecycle_stage, 2))) as stage_label,
                   COALESCE(ls.stage_color, '#6B7280') as stage_color,
                   COALESCE(ls.stage_order, 999) as stage_order
            FROM companies c
            LEFT JOIN lifecycle_stages ls ON c.lifecycle_stage = ls.stage_key AND ls.is_active = 1
            ORDER BY stage_order ASC, c.company_name ASC
        ");
    } catch (Exception $e) {
        // lifecycle_stages table may not exist yet — fall back to companies only
        error_log("getCompaniesByLifecycleStage: " . $e->getMessage());
        $stmt = $db->query("
            SELECT c.*,
                   CONCAT(UPPER(LEFT(c.lifecycle_stage, 1)), SUBSTRING(c.lifecycle_stage, 2)) as stage_label,
                   '#6B7280' as stage_color,
                   999 as stage_order
            FROM companies c
            ORDER BY c.company_name ASC
        ");
    }

    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];

    foreach ($companies as $company) {
        $stage = $company['lifecycle_stage'] ?? 'prospect';
        if (!isset($grouped[$stage])) {
            $grouped[$stage] = [
                'label' => $company['stage_label'] ?? ucfirst($stage),
                'color' => $company['stage_color'] ?? '#6B7280',
                'companies' => []
            ];
        }
        $grouped[$stage]['companies'][] = $company;
    }

    return $grouped;
}

/**
 * Update company lifecycle stage
 * @param int $companyId
 * @param string $newStage
 * @param int $userId
 * @return bool Success status
 */
function updateCompanyLifecycleStage($companyId, $newStage, $userId) {
    $db = getDB();

    try {
        $stmt = $db->prepare("UPDATE companies SET lifecycle_stage = ? WHERE id = ?");
        $result = $stmt->execute([$newStage, $companyId]);

        if ($result) {
            logActivityExtended($userId, 'Company lifecycle stage changed', "Changed to {$newStage}", $companyId);
        }

        return $result;
    } catch (Exception $e) {
        error_log("Error updating lifecycle stage: " . $e->getMessage());
        return false;
    }
}

/**
 * Add a new lifecycle stage
 * @param array $data stage_key, stage_label, stage_order, stage_color, description
 * @return int|null New stage ID or null on failure
 */
function addLifecycleStage($data) {
    $db = getDB();

    try {
        $stmt = $db->prepare("
            INSERT INTO lifecycle_stages (stage_key, stage_label, stage_order, stage_color, description, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");

        $result = $stmt->execute([
            $data['stage_key'] ?? '',
            $data['stage_label'] ?? '',
            $data['stage_order'] ?? 0,
            $data['stage_color'] ?? '#6B7280',
            $data['description'] ?? null
        ]);

        return $result ? $db->lastInsertId() : null;
    } catch (Exception $e) {
        error_log("Error adding lifecycle stage: " . $e->getMessage());
        return null;
    }
}

/**
 * Update an existing lifecycle stage
 * @param int $stageId
 * @param array $data Fields to update
 * @return bool Success status
 */
function updateLifecycleStage($stageId, $data) {
    $db = getDB();

    $allowedFields = ['stage_label', 'stage_order', 'stage_color', 'description', 'is_active'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "{$field} = ?";
            $params[] = $data[$field];
        }
    }

    if (empty($updates)) return false;

    $updates[] = "updated_at = NOW()";
    $params[] = intval($stageId);

    try {
        $stmt = $db->prepare("
            UPDATE lifecycle_stages
            SET " . implode(', ', $updates) . "
            WHERE id = ?
        ");

        return $stmt->execute($params);
    } catch (Exception $e) {
        error_log("Error updating lifecycle stage: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete a lifecycle stage
 * @param int $stageId
 * @return bool Success status
 */
function deleteLifecycleStage($stageId) {
    $db = getDB();

    try {
        // Check if stage is in use
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM companies WHERE lifecycle_stage = (SELECT stage_key FROM lifecycle_stages WHERE id = ?)");
        $stmt->execute([$stageId]);
        $result = $stmt->fetch();

        if ($result['count'] > 0) {
            return false; // Stage is in use, cannot delete
        }

        $stmt = $db->prepare("DELETE FROM lifecycle_stages WHERE id = ?");
        return $stmt->execute([$stageId]);
    } catch (Exception $e) {
        error_log("Error deleting lifecycle stage: " . $e->getMessage());
        return false;
    }
}

/**
 * Get a contact's current lifecycle stage
 * @param int $contactId
 * @return string|null Stage key or null if not found
 */
function getContactLifecycleStage($contactId) {
    $db = getDB();

    try {
        $stmt = $db->prepare("SELECT lifecycle_stage FROM contacts WHERE id = ? LIMIT 1");
        $stmt->execute([$contactId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['lifecycle_stage'] ?? null;
    } catch (Exception $e) {
        error_log("Error getting contact lifecycle stage: " . $e->getMessage());
        return null;
    }
}

/**
 * Update a contact's lifecycle stage
 * @param int $contactId
 * @param string $newStage Stage key from lifecycle_stages.stage_key
 * @param int $userId User ID performing the update
 * @return bool Success status
 */
function updateContactLifecycleStage($contactId, $newStage, $userId) {
    $db = getDB();

    try {
        // Map stage keys to prospect_status values
        $prospectStatusMap = [
            'prospect' => 'prospect', 'lead' => 'prospect',
            'qualified' => 'prospect', 'opportunity' => 'prospect',
            'client' => 'client', 'won' => 'client',
            'inactive' => 'inactive', 'lost' => 'inactive'
        ];
        $prospectStatus = $prospectStatusMap[$newStage] ?? 'prospect';

        // Update prospect_status (always available)
        $stmt = $db->prepare("UPDATE contacts SET prospect_status = ? WHERE id = ?");
        $result = $stmt->execute([$prospectStatus, $contactId]);

        // Also update lifecycle_stage if column exists
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM contacts LIKE 'lifecycle_stage'");
            if ($colCheck->rowCount() > 0) {
                // Verify the stage exists in lifecycle_stages if table exists
                $stageValid = true;
                try {
                    $stmtCheck = $db->prepare("SELECT id FROM lifecycle_stages WHERE stage_key = ? AND is_active = 1 LIMIT 1");
                    $stmtCheck->execute([$newStage]);
                    $stageValid = (bool)$stmtCheck->fetch();
                } catch (Exception $e) {
                    // lifecycle_stages table may not exist
                }

                if ($stageValid) {
                    $db->prepare("UPDATE contacts SET lifecycle_stage = ? WHERE id = ?")->execute([$newStage, $contactId]);
                }
            }
        } catch (Exception $e) {
            // Ignore — lifecycle_stage column may not exist
        }

        if ($result) {
            logActivityExtended($userId, 'Contact lifecycle stage changed', "Changed to {$newStage}", null, null, null, null);
        }

        return $result;
    } catch (Exception $e) {
        error_log("Error updating contact lifecycle stage: " . $e->getMessage());
        return false;
    }
}

/**
 * ═══════════════════════════════════════════════════════════════
 * NEXT-GENERATION JOB CREATION FUNCTIONS (Phase 2)
 * ═══════════════════════════════════════════════════════════════
 */

/**
 * Get all service packages, optionally filtered
 * @param string $category (optional) mowing, trimming, cleanup, seasonal
 * @param bool $activeOnly (default: true)
 * @return array service packages with billing template info
 */
function getServicePackages($category = null, $activeOnly = true) {
    $db = getDB();

    try {
        $query = "
            SELECT
                sp.*,
                bt.template_name as billing_template_name,
                bt.invoicing_mode
            FROM service_packages sp
            LEFT JOIN billing_templates bt ON sp.billing_template_id = bt.id
            WHERE sp.is_active = ?
        ";

        $params = [$activeOnly ? 1 : 0];

        if (!empty($category)) {
            $query .= " AND sp.category = ?";
            $params[] = $category;
        }

        $query .= " ORDER BY sp.sort_order ASC, sp.package_name ASC";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting service packages: " . $e->getMessage());
        return [];
    }
}

/**
 * Get single service package with full details
 * @param int $packageId
 * @return array|null package details or null if not found
 */
function getServicePackageDetails($packageId) {
    $db = getDB();

    try {
        $stmt = $db->prepare("
            SELECT
                sp.*,
                bt.template_name as billing_template_name,
                bt.invoicing_mode,
                bt.invoice_when,
                bt.days_until_due
            FROM service_packages sp
            LEFT JOIN billing_templates bt ON sp.billing_template_id = bt.id
            WHERE sp.id = ? AND sp.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$packageId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        error_log("Error getting service package details: " . $e->getMessage());
        return null;
    }
}

/**
 * Get recent plans on a property for "last used" suggestions
 * Updated to use job_plans table
 * @param int $propertyId
 * @param int $limit
 * @return array recent plans with service info
 */
function getRecentJobsOnProperty($propertyId, $limit = 3) {
    $db = getDB();

    try {
        $stmt = $db->prepare("
            SELECT
                jp.id,
                jp.plan_number AS job_number,
                jp.service_type,
                jp.service_package_id,
                sp.package_name,
                sp.base_price,
                jp.created_at,
                jp.estimated_duration_minutes
            FROM job_plans jp
            LEFT JOIN service_packages sp ON jp.service_package_id = sp.id
            WHERE jp.property_id = ?
            AND jp.status IN ('active', 'completed', 'paused')
            ORDER BY jp.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$propertyId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting recent plans: " . $e->getMessage());
        return [];
    }
}

/**
 * Auto-suggest best crew for job based on location, capacity, skills
 * @param int $propertyId
 * @param string $scheduledDate YYYY-MM-DD format
 * @param int $durationMinutes
 * @param int $crewSizeRequired
 * @return array crew suggestion with ETA, capacity, conflicts
 */
function suggestCrewForJob($propertyId, $scheduledDate, $durationMinutes, $crewSizeRequired = 1) {
    $db = getDB();

    try {
        // Get property location
        $propStmt = $db->prepare("SELECT latitude, longitude FROM properties WHERE id = ? LIMIT 1");
        $propStmt->execute([$propertyId]);
        $property = $propStmt->fetch(PDO::FETCH_ASSOC);

        if (!$property || !$property['latitude']) {
            // Fallback: get first available crew
            $stmt = $db->prepare("
                SELECT id, full_name FROM users
                WHERE is_active = 1 AND role IN ('crew', 'admin')
                ORDER BY full_name ASC
                LIMIT 1
            ");
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            return $user ? [
                'crew_id' => $user['id'],
                'crew_name' => $user['full_name'],
                'reason' => 'First available crew (property location unknown)',
                'conflicts' => [],
                'eta_minutes' => null
            ] : null;
        }

        // Get crew with recent location history (within last 2 hours)
        $twoHoursAgo = date('Y-m-d H:i:s', strtotime('-2 hours'));

        $stmt = $db->prepare("
            SELECT
                u.id,
                u.full_name,
                clh.latitude,
                clh.longitude,
                clh.logged_at,
                (
                    6371 * acos(
                        cos(radians(?)) * cos(radians(clh.latitude)) *
                        cos(radians(clh.longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(clh.latitude))
                    )
                ) as distance_km
            FROM users u
            LEFT JOIN crew_location_history clh ON u.id = clh.crew_id
                AND clh.logged_at = (
                    SELECT MAX(logged_at) FROM crew_location_history
                    WHERE crew_id = u.id AND logged_at > ?
                )
            WHERE u.is_active = 1 AND u.role IN ('crew', 'admin')
            ORDER BY distance_km ASC, u.full_name ASC
            LIMIT 1
        ");

        $stmt->execute([$property['latitude'], $property['longitude'], $property['latitude'], $twoHoursAgo]);
        $crew = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$crew) {
            // Fallback to first crew
            $stmt = $db->prepare("
                SELECT id, full_name FROM users
                WHERE is_active = 1 AND role IN ('crew', 'admin')
                ORDER BY full_name ASC LIMIT 1
            ");
            $stmt->execute();
            $crew = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$crew) {
            return null;
        }

        // Check for conflicts on that date
        $conflicts = detectSchedulingConflicts($crew['id'], $scheduledDate . ' 09:00:00', $durationMinutes);

        return [
            'crew_id' => $crew['id'],
            'crew_name' => $crew['full_name'],
            'distance_km' => round($crew['distance_km'] ?? 0, 1),
            'reason' => $crew['distance_km'] ? 'Nearest crew (' . round($crew['distance_km'], 1) . ' km away)' : 'First available crew',
            'conflicts' => $conflicts,
            'eta_minutes' => $crew['distance_km'] ? ceil($crew['distance_km'] * 3) : null // ~3 min per km
        ];
    } catch (Exception $e) {
        error_log("Error suggesting crew: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if date/time slot is available for crew
 * @param int $crewId
 * @param string $startTime DateTime format or YYYY-MM-DD HH:MM:SS
 * @param int $durationMinutes
 * @return bool is available
 */
function checkCrewAvailability($crewId, $startTime, $durationMinutes) {
    $db = getDB();

    try {
        // Parse start time
        $start = new DateTime($startTime);
        $end = (clone $start)->add(new DateInterval('PT' . $durationMinutes . 'M'));

        // Check for overlapping visits
        $stmt = $db->prepare("
            SELECT COUNT(*) as conflict_count
            FROM job_visits
            WHERE assigned_crew_id = ?
            AND scheduled_date = ?
            AND (
                (scheduled_time_start < ? AND scheduled_time_end > ?)
                OR (scheduled_time_start >= ? AND scheduled_time_start < ?)
            )
            AND status NOT IN ('cancelled', 'skipped', 'weather')
        ");

        $stmt->execute([
            $crewId,
            $start->format('Y-m-d'),
            $end->format('H:i:s'),
            $start->format('H:i:s'),
            $start->format('H:i:s'),
            $end->format('H:i:s')
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['conflict_count'] == 0;
    } catch (Exception $e) {
        error_log("Error checking crew availability: " . $e->getMessage());
        return false;
    }
}

/**
 * Detect scheduling conflicts for crew + date + time
 * @param int $crewId
 * @param string $startTime YYYY-MM-DD HH:MM:SS
 * @param int $durationMinutes
 * @return array conflicts array
 */
function detectSchedulingConflicts($crewId, $startTime, $durationMinutes) {
    $db = getDB();
    $conflicts = [];

    try {
        $start = new DateTime($startTime);
        $end = (clone $start)->add(new DateInterval('PT' . $durationMinutes . 'M'));

        $stmt = $db->prepare("
            SELECT
                jv.id,
                jv.visit_number,
                jp.title,
                jv.scheduled_time_start,
                jv.scheduled_time_end,
                jp.estimated_duration_minutes
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE jv.assigned_crew_id = ?
            AND jv.scheduled_date = ?
            AND jv.status NOT IN ('cancelled', 'completed', 'skipped', 'weather')
            AND (
                (jv.scheduled_time_start < ? AND jv.scheduled_time_end > ?)
                OR (jv.scheduled_time_start >= ? AND jv.scheduled_time_start < ?)
            )
        ");

        $stmt->execute([
            $crewId,
            $start->format('Y-m-d'),
            $end->format('H:i:s'),
            $start->format('H:i:s'),
            $start->format('H:i:s'),
            $end->format('H:i:s')
        ]);

        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error detecting scheduling conflicts: " . $e->getMessage());
    }

    return $conflicts;
}

/**
 * Calculate smart time window based on crew location & route
 * @param int $propertyId
 * @param int $crewId
 * @param int $durationMinutes service duration
 * @return array [earliest: time, latest: time, optimal: time, reason: string]
 */
function calculateOptimalTimeWindow($propertyId, $crewId, $durationMinutes) {
    $db = getDB();

    try {
        // Default time window: 8 AM to 5 PM
        $earliestTime = '08:00';
        $latestTime = '17:00';
        $optimalTime = '09:00'; // Most jobs start at 9 AM
        $reason = 'Standard work hours';

        // Check crew's first visit today
        $today = date('Y-m-d');
        $stmt = $db->prepare("
            SELECT jv.scheduled_time_start, jp.estimated_duration_minutes
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE jv.assigned_crew_id = ? AND jv.scheduled_date = ? AND jv.status NOT IN ('cancelled', 'skipped', 'weather')
            ORDER BY jv.scheduled_time_start ASC
            LIMIT 1
        ");
        $stmt->execute([$crewId, $today]);
        $firstJob = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($firstJob) {
            // Schedule after first job
            $first = new DateTime('2000-01-01 ' . $firstJob['scheduled_time_start']);
            $first->add(new DateInterval('PT' . $firstJob['estimated_duration_minutes'] . 'M'));
            $first->add(new DateInterval('PT30M')); // 30 min buffer

            $optimalTime = $first->format('H:i');
            $reason = 'After scheduled jobs (crew routing)';
        }

        return [
            'earliest' => $earliestTime,
            'latest' => $latestTime,
            'optimal' => $optimalTime,
            'reason' => $reason
        ];
    } catch (Exception $e) {
        error_log("Error calculating optimal time window: " . $e->getMessage());
        return [
            'earliest' => '08:00',
            'latest' => '17:00',
            'optimal' => '09:00',
            'reason' => 'Error calculating (using defaults)'
        ];
    }
}

/**
 * Validate job creation against guardrails
 * @param array $jobData [client_id, property_id, service_package_id, scheduled_date, ...]
 * @param int $userId
 * @return array [is_valid: bool, errors: [], warnings: [], suggestions: {}]
 */
function validateJobCreationGuardrails($jobData, $userId) {
    $db = getDB();
    $errors = [];
    $warnings = [];
    $suggestions = [];

    try {
        // Validate service package exists
        if (empty($jobData['service_package_id'])) {
            $errors[] = 'Service package is required';
        } else {
            $pkgStmt = $db->prepare("SELECT id, is_active FROM service_packages WHERE id = ? LIMIT 1");
            $pkgStmt->execute([$jobData['service_package_id']]);
            if (!$pkgStmt->fetch()) {
                $errors[] = 'Invalid service package selected';
            }
        }

        // Validate property exists
        if (empty($jobData['property_id'])) {
            $errors[] = 'Property is required';
        } else {
            $propStmt = $db->prepare("SELECT id FROM properties WHERE id = ? LIMIT 1");
            $propStmt->execute([$jobData['property_id']]);
            if (!$propStmt->fetch()) {
                $errors[] = 'Property not found';
            }
        }

        // Validate client exists
        if (empty($jobData['client_id'])) {
            $errors[] = 'Client is required';
        } else {
            $clientStmt = $db->prepare("SELECT id FROM companies WHERE id = ? LIMIT 1");
            $clientStmt->execute([$jobData['client_id']]);
            if (!$clientStmt->fetch()) {
                $errors[] = 'Client not found';
            }
        }

        // Check crew availability if crew assigned
        if (!empty($jobData['assigned_to']) && !empty($jobData['scheduled_date']) && !empty($jobData['estimated_duration_minutes'])) {
            $startTime = $jobData['scheduled_date'] . ' ' . ($jobData['scheduled_time_start'] ?? '09:00:00');
            $available = checkCrewAvailability($jobData['assigned_to'], $startTime, $jobData['estimated_duration_minutes']);

            if (!$available) {
                $errors[] = 'Selected crew has scheduling conflict';
                $suggestions['crew_conflict'] = 'Try a different date, time, or crew member';
            }
        }

        // Validate billing template compatibility
        if (!empty($jobData['job_type']) && $jobData['job_type'] === 'recurring' && !empty($jobData['billing_template_id'])) {
            $btStmt = $db->prepare("SELECT invoicing_mode FROM billing_templates WHERE id = ? LIMIT 1");
            $btStmt->execute([$jobData['billing_template_id']]);
            $bt = $btStmt->fetch(PDO::FETCH_ASSOC);

            if ($bt && $bt['invoicing_mode'] === 'per_visit') {
                $warnings[] = 'Per-visit billing with recurring jobs may create many invoices';
                $suggestions['billing_switch'] = 'Consider switching to monthly_grouped for recurring jobs';
            }
        }

        // Check if strata client - suggest monthly billing
        if (!empty($jobData['client_id'])) {
            $coStmt = $db->prepare("SELECT is_strata FROM companies WHERE id = ? LIMIT 1");
            $coStmt->execute([$jobData['client_id']]);
            $co = $coStmt->fetch(PDO::FETCH_ASSOC);

            if ($co && $co['is_strata'] && (!empty($jobData['billing_template_id']))) {
                $btStmt = $db->prepare("SELECT invoicing_mode FROM billing_templates WHERE id = ? LIMIT 1");
                $btStmt->execute([$jobData['billing_template_id']]);
                $bt = $btStmt->fetch(PDO::FETCH_ASSOC);

                if ($bt && $bt['invoicing_mode'] !== 'monthly_flat' && $bt['invoicing_mode'] !== 'monthly_grouped') {
                    $suggestions['strata_billing'] = 'Strata clients typically use monthly billing templates';
                }
            }
        }

    } catch (Exception $e) {
        error_log("Error validating job creation: " . $e->getMessage());
        $errors[] = 'Validation error occurred';
    }

    return [
        'is_valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'suggestions' => $suggestions
    ];
}

/**
 * Create job with smart defaults applied
 * @param array $jobData
 * @param int $userId
 * @return array [job_id: int, success: bool, errors: []]
 */
function createJobWithDefaults($jobData, $userId) {
    $db = getDB();
    $errors = [];

    try {
        // Validate first
        $validation = validateJobCreationGuardrails($jobData, $userId);
        if (!$validation['is_valid']) {
            return [
                'job_id' => null,
                'success' => false,
                'errors' => $validation['errors']
            ];
        }

        // Get service package details for defaults
        $package = getServicePackageDetails($jobData['service_package_id']);
        if (!$package) {
            return [
                'job_id' => null,
                'success' => false,
                'errors' => ['Service package not found']
            ];
        }

        // Apply defaults
        $jobData['estimated_duration_minutes'] = $jobData['estimated_duration_minutes'] ?? $package['default_duration_minutes'];
        $jobData['crew_size_required'] = $jobData['crew_size_required'] ?? $package['default_crew_size'];
        $jobData['estimated_amount'] = $jobData['estimated_amount'] ?? $package['base_price'];
        $jobData['billing_template_id'] = $jobData['billing_template_id'] ?? $package['billing_template_id'];
        $jobData['service_type'] = $jobData['service_type'] ?? $package['service_type'];

        // Generate job number
        $jobNumber = generateJobNumber();

        // Insert job
        $stmt = $db->prepare("
            INSERT INTO jobs (
                job_number, quote_id, property_id, company_id,
                title, description, service_type, service_package_id,
                job_type, scheduled_date, scheduled_time_start, scheduled_time_end,
                estimated_duration_minutes, recurrence_pattern,
                recurrence_day_of_week, recurrence_end_date,
                status, assigned_to, estimated_amount,
                crew_size_required, billing_template_id,
                created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $result = $stmt->execute([
            $jobNumber,
            $jobData['quote_id'] ?? null,
            $jobData['property_id'],
            $jobData['client_id'],
            $jobData['title'] ?? 'Job from ' . $package['package_name'],
            $jobData['description'] ?? $package['description'],
            $jobData['service_type'],
            $jobData['service_package_id'],
            $jobData['job_type'] ?? 'one_time',
            $jobData['scheduled_date'] ?? date('Y-m-d'),
            $jobData['scheduled_time_start'] ?? '09:00:00',
            $jobData['scheduled_time_end'] ?? null,
            $jobData['estimated_duration_minutes'],
            $jobData['recurrence_pattern'] ?? null,
            $jobData['recurrence_day_of_week'] ?? null,
            $jobData['recurrence_end_date'] ?? null,
            'scheduled',
            $jobData['assigned_to'] ?? null,
            $jobData['estimated_amount'],
            $jobData['crew_size_required'],
            $jobData['billing_template_id'],
            $userId
        ]);

        if (!$result) {
            return [
                'job_id' => null,
                'success' => false,
                'errors' => ['Failed to create job']
            ];
        }

        $jobId = $db->lastInsertId();

        // Create proof of work requirements
        createJobProofOfWork($jobId, $jobData['service_package_id']);

        // Log activity
        logActivityExtended($userId, 'Job created', $jobNumber . ' - ' . $package['package_name'], null, $jobId, null, null);

        return [
            'job_id' => $jobId,
            'job_number' => $jobNumber,
            'success' => true,
            'errors' => []
        ];
    } catch (Exception $e) {
        error_log("Error creating job with defaults: " . $e->getMessage());
        return [
            'job_id' => null,
            'success' => false,
            'errors' => ['Database error: ' . $e->getMessage()]
        ];
    }
}

/**
 * Create proof of work requirements for job
 * @param int $jobId
 * @param int $servicePackageId
 * @return bool success
 */
function createJobProofOfWork($jobId, $servicePackageId) {
    $db = getDB();

    try {
        $package = getServicePackageDetails($servicePackageId);
        if (!$package) {
            return false;
        }

        $stmt = $db->prepare("
            INSERT INTO job_proof_of_work (
                job_id,
                required_checklist_items,
                required_photo_types,
                gps_enforcement,
                checklist_blocks_completion,
                photos_block_completion,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                required_checklist_items = VALUES(required_checklist_items),
                required_photo_types = VALUES(required_photo_types),
                gps_enforcement = VALUES(gps_enforcement),
                checklist_blocks_completion = VALUES(checklist_blocks_completion),
                photos_block_completion = VALUES(photos_block_completion)
        ");

        return $stmt->execute([
            $jobId,
            $package['checklist_items'] ?? '[]',
            $package['photo_types_required'] ?? '[]',
            $package['gps_enforcement'] ?? 'optional',
            $package['checklist_blocks_completion'] ? 1 : 0,
            $package['photos_block_completion'] ? 1 : 0
        ]);
    } catch (Exception $e) {
        error_log("Error creating job proof of work: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if job is eligible for invoicing (proof complete)
 * @param int $jobId
 * @return array [can_invoice: bool, missing_requirements: [], photos_count: int]
 */
function canInvoiceJob($jobId) {
    $db = getDB();

    try {
        // Get proof requirements
        $stmt = $db->prepare("SELECT * FROM job_proof_of_work WHERE job_id = ? LIMIT 1");
        $stmt->execute([$jobId]);
        $proof = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proof) {
            return [
                'can_invoice' => true,
                'missing_requirements' => [],
                'photos_count' => 0
            ];
        }

        $missing = [];

        // Check checklist if it blocks completion
        if ($proof['checklist_blocks_completion']) {
            $checklist = json_decode($proof['checklist_items_completed'], true) ?? [];
            foreach (json_decode($proof['required_checklist_items'], true) ?? [] as $item) {
                if (!($checklist[$item] ?? false)) {
                    $missing[] = 'Checklist: ' . $item;
                }
            }
        }

        // Check photos if they block completion
        $photosCount = 0;
        if ($proof['photos_block_completion']) {
            $photos = json_decode($proof['photos_uploaded'], true) ?? [];
            foreach (json_decode($proof['required_photo_types'], true) ?? [] as $type) {
                if (empty($photos[$type])) {
                    $missing[] = 'Missing photo: ' . $type;
                }
            }
            foreach ($photos as $photoList) {
                $photosCount += is_array($photoList) ? count($photoList) : 0;
            }
        }

        return [
            'can_invoice' => empty($missing),
            'missing_requirements' => $missing,
            'photos_count' => $photosCount
        ];
    } catch (Exception $e) {
        error_log("Error checking job invoice eligibility: " . $e->getMessage());
        return [
            'can_invoice' => false,
            'missing_requirements' => ['Error checking requirements'],
            'photos_count' => 0
        ];
    }
}

/**
 * Suggest modifiers based on property characteristics
 * @param int $propertyId
 * @param int $servicePackageId
 * @return array modifiers with reason
 */
function suggestModifiers($propertyId, $servicePackageId) {
    $db = getDB();
    $suggestions = [];

    try {
        $package = getServicePackageDetails($servicePackageId);
        if (!$package) {
            return [];
        }

        $modifiers = json_decode($package['modifiers'], true) ?? [];
        if (empty($modifiers)) {
            return [];
        }

        // Check property size
        $propStmt = $db->prepare("SELECT sqft FROM properties WHERE id = ? LIMIT 1");
        $propStmt->execute([$propertyId]);
        $prop = $propStmt->fetch(PDO::FETCH_ASSOC);

        // Add modifiers based on property characteristics
        foreach ($modifiers as $mod) {
            if ($prop['sqft'] > 5000 && strpos($mod['name'], 'Large') !== false) {
                $suggestions[] = [
                    'id' => $mod['id'],
                    'name' => $mod['name'],
                    'cost' => $mod['cost'] ?? 0,
                    'reason' => 'Large property (' . $prop['sqft'] . ' sqft)'
                ];
            } else {
                $suggestions[] = [
                    'id' => $mod['id'],
                    'name' => $mod['name'],
                    'cost' => $mod['cost'] ?? 0,
                    'reason' => 'Optional add-on'
                ];
            }
        }

        return $suggestions;
    } catch (Exception $e) {
        error_log("Error suggesting modifiers: " . $e->getMessage());
        return [];
    }
}

/**
 * Create recurring job series with instances
 * @param array $jobData + recurrence_pattern, recurrence_day_of_week, recurrence_end_date
 * @param int $userId
 * @return array [parent_job_id: int, instances_created: int, success: bool, errors: []]
 */
function createRecurringJobSeries($jobData, $userId) {
    $db = getDB();

    try {
        // Set job type to recurring
        $jobData['job_type'] = 'recurring';

        // Create parent job
        $parentResult = createJobWithDefaults($jobData, $userId);
        if (!$parentResult['success']) {
            return [
                'parent_job_id' => null,
                'instances_created' => 0,
                'success' => false,
                'errors' => $parentResult['errors']
            ];
        }

        $parentJobId = $parentResult['job_id'];

        // Generate recurring instances
        $startDate = new DateTime($jobData['scheduled_date'] ?? date('Y-m-d'));
        $endDate = new DateTime($jobData['recurrence_end_date'] ?? date('Y-m-d', strtotime('+1 year')));

        $instancesCreated = generateRecurringJobInstances($parentJobId, $startDate, $endDate);

        return [
            'parent_job_id' => $parentJobId,
            'instances_created' => $instancesCreated,
            'success' => true,
            'errors' => []
        ];
    } catch (Exception $e) {
        error_log("Error creating recurring job series: " . $e->getMessage());
        return [
            'parent_job_id' => null,
            'instances_created' => 0,
            'success' => false,
            'errors' => ['Error creating recurring series']
        ];
    }
}

/**
 * Generate individual job instances for recurring parent
 * @param int $parentJobId
 * @param DateTime $startDate
 * @param DateTime $endDate
 * @return int count of jobs created
 */
function generateRecurringJobInstances($parentJobId, $startDate, $endDate) {
    $db = getDB();
    $instanceCount = 0;

    try {
        // Get parent job details
        $stmt = $db->prepare("
            SELECT * FROM jobs WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$parentJobId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parent) {
            return 0;
        }

        $pattern = $parent['recurrence_pattern'] ?? 'weekly';
        $dayOfWeek = $parent['recurrence_day_of_week'] ?? 'Wednesday';
        $current = clone $startDate;

        $dayMap = [
            'Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed',
            'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun'
        ];

        $targetDay = $dayMap[$dayOfWeek] ?? 'Wed';

        while ($current <= $endDate && $instanceCount < 52) { // Limit to 52 instances (1 year weekly)
            // Find next occurrence of target day
            if ($current->format('D') !== $targetDay) {
                $daysUntilTarget = (7 - (array_search($current->format('D'), array_values($dayMap)) - array_search($targetDay, array_values($dayMap)) + 7)) % 7;
                if ($daysUntilTarget === 0) $daysUntilTarget = 7;
                $current->add(new DateInterval('P' . $daysUntilTarget . 'D'));
            }

            if ($current > $endDate) break;

            // Create instance job (don't duplicate, just update parent)
            // In production, this might create separate child job records
            // For MVP, we use parent job with recurrence rules

            $instanceCount++;
            $current->add(new DateInterval('P' . (($pattern === 'biweekly') ? '14D' : ($pattern === 'monthly' ? '1M' : '7D'))));
        }

        return $instanceCount;
    } catch (Exception $e) {
        error_log("Error generating recurring job instances: " . $e->getMessage());
        return 0;
    }
}

/**
 * Generate individual child job instances for a recurring parent job
 * Creates separate job records for each occurrence on the calendar
 *
 * @param int $parentJobId Parent job ID
 * @param int $companyId Company ID
 * @param int $propertyId Property ID
 * @param string $startDate Start date (Y-m-d)
 * @param string $endDate End date (Y-m-d)
 * @param string $pattern weekly|biweekly|monthly|custom
 * @param int $interval Interval number (e.g., 2 for "every 2 weeks")
 * @param string $intervalUnit days|weeks|months (for custom patterns)
 * @param int $dayOfWeek 0-6 (0=Sunday, 6=Saturday) - only used for weekly patterns
 * @param string $timeStart Start time (HH:MM)
 * @param string $timeEnd End time (HH:MM)
 * @param int $durationMinutes Duration in minutes
 * @param int $userId User creating the instances
 * @return int Number of instances created
 */
function generateRecurringJobInstancesForParent($parentJobId, $companyId, $propertyId, $startDate, $endDate, $pattern, $interval, $intervalUnit, $dayOfWeek, $timeStart, $timeEnd, $durationMinutes, $userId) {
    $db = getDB();
    $instanceCount = 0;

    try {
        // Get parent job data
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ? LIMIT 1");
        $stmt->execute([$parentJobId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parent) {
            return 0;
        }

        // Delete existing child instances first
        $stmt = $db->prepare("DELETE FROM jobs WHERE parent_job_id = ?");
        $stmt->execute([$parentJobId]);

        $current = new DateTime($startDate);
        $end = new DateTime($endDate);
        $maxInstances = 156; // Limit to 3 years of instances

        // Day name mapping
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        while ($current <= $end && $instanceCount < $maxInstances) {
            $shouldCreate = false;
            $currentDayOfWeek = intval($current->format('w'));

            // Determine if this date should have an instance
            if ($pattern === 'weekly') {
                // Create instance if it's the target day of week
                $shouldCreate = ($currentDayOfWeek === $dayOfWeek);
            } elseif ($pattern === 'biweekly') {
                // Create on target day, but only every 2 weeks
                if ($currentDayOfWeek === $dayOfWeek) {
                    $diffWeeks = intval($current->diff(new DateTime($startDate))->days / 7);
                    $shouldCreate = ($diffWeeks % 2 === 0);
                }
            } elseif ($pattern === 'monthly') {
                // Create on same day of month
                $startDay = intval(date('d', strtotime($startDate)));
                $currentDay = intval($current->format('d'));
                $shouldCreate = ($currentDay === $startDay);
            } elseif ($pattern === 'custom') {
                // Custom interval
                $diff = $current->diff(new DateTime($startDate));
                $unitValue = 0;

                if ($intervalUnit === 'days') {
                    $unitValue = $diff->days;
                } elseif ($intervalUnit === 'weeks') {
                    $unitValue = intval($diff->days / 7);
                } elseif ($intervalUnit === 'months') {
                    $unitValue = $diff->m + ($diff->y * 12);
                }

                $shouldCreate = ($unitValue > 0 && $unitValue % $interval === 0);
            }

            if ($shouldCreate) {
                // Generate unique job number for instance
                $instanceNumber = $instanceCount + 1;
                $instanceJobNumber = $parent['job_number'] . '-' . $instanceNumber;

                // Create child job instance
                $stmt = $db->prepare("
                    INSERT INTO jobs (
                        job_number, parent_job_id, quote_id, property_id, company_id,
                        title, description, service_type, job_type,
                        scheduled_date, scheduled_time_start, scheduled_time_end,
                        estimated_duration_minutes,
                        status, assigned_to, estimated_amount, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $instanceJobNumber,
                    $parentJobId,
                    $parent['quote_id'],
                    $propertyId,
                    $companyId,
                    $parent['title'],
                    $parent['description'],
                    $parent['service_type'],
                    'one_time', // Child instances are not recurring themselves
                    $current->format('Y-m-d'),
                    $timeStart ?: $parent['scheduled_time_start'],
                    $timeEnd ?: $parent['scheduled_time_end'],
                    $durationMinutes ?: $parent['estimated_duration_minutes'],
                    'scheduled',
                    $parent['assigned_to'],
                    $parent['estimated_amount'],
                    $userId
                ]);

                $instanceCount++;
            }

            // Move to next day for iteration
            $current->modify('+1 day');
        }

        return $instanceCount;
    } catch (Exception $e) {
        error_log("Error generating recurring job instances for parent: " . $e->getMessage());
        return 0;
    }
}
