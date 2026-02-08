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

        // Job statuses
        'scheduled' => ['bg' => '#3B82F6', 'text' => '#FFFFFF'],
        'in_progress' => ['bg' => '#F59E0B', 'text' => '#000000'],
        'completed' => ['bg' => '#2D8659', 'text' => '#FFFFFF'],
        'cancelled' => ['bg' => '#6B7280', 'text' => '#FFFFFF'],
        'on_hold' => ['bg' => '#8B5CF6', 'text' => '#FFFFFF'],

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
 */
function logActivityExtended($userId, $action, $details = null, $companyId = null, $jobId = null, $quoteId = null, $invoiceId = null) {
    $db = getDB();

    try {
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, company_id, job_id, quote_id, invoice_id, action, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $companyId,
            $jobId,
            $quoteId,
            $invoiceId,
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
 * Create a job from an accepted quote
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

    // Job stats
    $stmt = $db->query("SELECT status, COUNT(*) as count FROM jobs GROUP BY status");
    $stats['jobs'] = [];
    while ($row = $stmt->fetch()) {
        $stats['jobs'][$row['status']] = $row['count'];
    }

    // Jobs today
    $stmt = $db->query("SELECT COUNT(*) as count FROM jobs WHERE scheduled_date = CURDATE()");
    $stats['jobs_today'] = $stmt->fetch()['count'];

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
