<?php
/**
 * Shared Helper Functions for Mowology CRM
 *
 * Migrated from /public/crm/includes/functions.php
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

    // $jobId parameter kept for backward compat but no longer inserted (jobs table dropped)
    try {
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, company_id, quote_id, invoice_id, plan_id, visit_id, action, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $companyId,
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
 * Get active products for dropdowns, grouped by category
 */
function getActiveProducts($categoryId = null) {
    $db = getDB();
    $sql = "
        SELECT p.id, p.name, p.description, p.base_price,
               c.name as category_name
        FROM products p
        LEFT JOIN product_categories c ON p.category_id = c.id
        WHERE p.active = 1 AND p.is_archived = 0
    ";
    $params = [];
    if ($categoryId !== null) {
        $sql .= " AND p.category_id = ?";
        $params[] = $categoryId;
    }
    $sql .= " ORDER BY c.name, p.display_order, p.name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        $stmt = $db->query("
            SELECT c.*,
                   TRIM(CONCAT(COALESCE(pc.first_name, ''), ' ', COALESCE(pc.last_name, ''))) AS primary_contact_name
            FROM companies c
            LEFT JOIN contacts pc ON c.primary_contact_id = pc.id
            ORDER BY c.company_name ASC
        ");
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
                   COALESCE(ls.stage_order, 999) as stage_order,
                   TRIM(CONCAT(COALESCE(pc.first_name, ''), ' ', COALESCE(pc.last_name, ''))) AS primary_contact_name
            FROM companies c
            LEFT JOIN lifecycle_stages ls ON c.lifecycle_stage = ls.stage_key AND ls.is_active = 1
            LEFT JOIN contacts pc ON c.primary_contact_id = pc.id
            ORDER BY stage_order ASC, c.company_name ASC
        ");
    } catch (Exception $e) {
        // lifecycle_stages table may not exist yet — fall back to companies only
        error_log("getCompaniesByLifecycleStage: " . $e->getMessage());
        $stmt = $db->query("
            SELECT c.*,
                   CONCAT(UPPER(LEFT(c.lifecycle_stage, 1)), SUBSTRING(c.lifecycle_stage, 2)) as stage_label,
                   '#6B7280' as stage_color,
                   999 as stage_order,
                   TRIM(CONCAT(COALESCE(pc.first_name, ''), ' ', COALESCE(pc.last_name, ''))) AS primary_contact_name
            FROM companies c
            LEFT JOIN contacts pc ON c.primary_contact_id = pc.id
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
 * Get recent visits on a property
 * @param int $propertyId
 * @param int $limit
 * @return array recent visits with plan info
 */
function getRecentJobsOnProperty($propertyId, $limit = 5) {
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT jv.id as visit_id, jp.id as plan_id, jp.plan_number, jp.title, jp.service_type,
                   jv.scheduled_date, jv.status, jv.completed_at
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE jp.property_id = ?
            ORDER BY jv.scheduled_date DESC
            LIMIT ?
        ");
        $stmt->execute([$propertyId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
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

// ── Contact Duplicate Detection ──────────────────────────────────────────────

/**
 * Find potential duplicate contacts.
 * Returns array keyed by contact ID, each value is an array of potential duplicate contact IDs.
 *
 * Matching tiers:
 *  1. Exact email match (non-empty) OR exact phone/mobile match (digits-only)
 *  2. Same property address (via properties.site_contact_id)
 *  3. Exact first+last name (case-insensitive) — only if both have a last name
 *
 * @return array [contact_id => [duplicate_id, ...], ...]
 */
function findDuplicateContacts() {
    $db = getDB();
    $duplicates = [];

    try {
        // Check if merged_into_id column exists
        $hasMergedCol = false;
        try {
            $cols = $db->query("SHOW COLUMNS FROM contacts LIKE 'merged_into_id'")->fetchAll();
            $hasMergedCol = count($cols) > 0;
        } catch (Exception $e) {}

        $mergedFilter = $hasMergedCol ? "AND (merged_into_id IS NULL OR merged_into_id = 0)" : "";

        $contacts = $db->query("
            SELECT id, first_name, last_name, email, phone, mobile
            FROM contacts
            WHERE is_active = 1
            {$mergedFilter}
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Build property address map: contact_id => [addresses]
        $addressMap = [];
        try {
            $props = $db->query("
                SELECT site_contact_id, LOWER(TRIM(address)) as addr
                FROM properties
                WHERE site_contact_id IS NOT NULL
                AND address IS NOT NULL AND address != ''
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($props as $p) {
                $addressMap[(int)$p['site_contact_id']][] = $p['addr'];
            }
        } catch (Exception $e) {}

        $count = count($contacts);
        $matched = [];

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $contacts[$i];
                $b = $contacts[$j];
                $pairKey = $a['id'] . '-' . $b['id'];

                if (isset($matched[$pairKey])) continue;

                $isDuplicate = false;

                // Tier 1: exact email match (non-empty)
                if (!empty($a['email']) && !empty($b['email'])
                    && strtolower(trim($a['email'])) === strtolower(trim($b['email']))) {
                    $isDuplicate = true;
                }

                // Tier 1: exact phone/mobile match (digits-only)
                if (!$isDuplicate) {
                    $phonesA = array_filter([
                        preg_replace('/\D/', '', $a['phone'] ?? ''),
                        preg_replace('/\D/', '', $a['mobile'] ?? '')
                    ], function($v) { return $v !== ''; });
                    $phonesB = array_filter([
                        preg_replace('/\D/', '', $b['phone'] ?? ''),
                        preg_replace('/\D/', '', $b['mobile'] ?? '')
                    ], function($v) { return $v !== ''; });

                    foreach ($phonesA as $pa) {
                        foreach ($phonesB as $pb) {
                            if ($pa === $pb) { $isDuplicate = true; break 2; }
                        }
                    }
                }

                // Tier 2: same property address
                if (!$isDuplicate) {
                    $addrsA = $addressMap[(int)$a['id']] ?? [];
                    $addrsB = $addressMap[(int)$b['id']] ?? [];
                    foreach ($addrsA as $addrA) {
                        if (in_array($addrA, $addrsB)) { $isDuplicate = true; break; }
                    }
                }

                // Tier 3: exact name match (first + last, case-insensitive, last name required)
                if (!$isDuplicate
                    && trim($a['last_name'] ?? '') !== ''
                    && trim($b['last_name'] ?? '') !== ''
                    && strtolower(trim($a['first_name'])) === strtolower(trim($b['first_name']))
                    && strtolower(trim($a['last_name'])) === strtolower(trim($b['last_name']))) {
                    $isDuplicate = true;
                }

                if ($isDuplicate) {
                    $matched[$pairKey] = true;
                    if (!isset($duplicates[$a['id']])) $duplicates[$a['id']] = [];
                    if (!isset($duplicates[$b['id']])) $duplicates[$b['id']] = [];
                    if (!in_array($b['id'], $duplicates[$a['id']])) $duplicates[$a['id']][] = $b['id'];
                    if (!in_array($a['id'], $duplicates[$b['id']])) $duplicates[$b['id']][] = $a['id'];
                }
            }
        }
    } catch (Exception $e) {
        error_log("findDuplicateContacts error: " . $e->getMessage());
    }

    return $duplicates;
}

/**
 * Merge two contacts: keep one, absorb the other.
 * The "merge" contact is soft-deleted and all its FK references are reassigned.
 *
 * @param int   $keepId       Contact to survive
 * @param int   $mergeId      Contact to absorb (will be soft-deleted)
 * @param array $fieldChoices ['first_name' => 'keep'|'merge', 'email' => 'keep'|'merge', ...]
 * @param int   $userId       User performing the merge
 * @return array ['success' => bool, 'error' => string|null]
 */
function mergeContacts($keepId, $mergeId, $fieldChoices, $userId) {
    $db = getDB();

    try {
        // 1. Fetch both contacts
        $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ? AND is_active = 1");
        $stmt->execute([$keepId]);
        $keep = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt->execute([$mergeId]);
        $merge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$keep || !$merge) {
            return ['success' => false, 'error' => 'One or both contacts not found or inactive.'];
        }
        if ($keepId === $mergeId) {
            return ['success' => false, 'error' => 'Cannot merge a contact with itself.'];
        }

        $db->beginTransaction();

        // 2. Apply field choices to surviving contact
        $mergeableFields = ['first_name', 'last_name', 'email', 'phone', 'mobile', 'preferred_contact_method'];
        $updates = [];
        $params = [];
        foreach ($mergeableFields as $field) {
            $choice = $fieldChoices[$field] ?? 'keep';
            if ($choice === 'merge' && isset($merge[$field]) && $merge[$field] !== '') {
                $updates[] = "{$field} = ?";
                $params[] = $merge[$field];
            }
        }

        // Notes: append option
        if (($fieldChoices['notes'] ?? 'keep') === 'append') {
            $combined = trim(
                ($keep['notes'] ?? '') .
                "\n---\nMerged from contact #{$mergeId}:\n" .
                ($merge['notes'] ?? '')
            );
            $updates[] = "notes = ?";
            $params[] = $combined;
        } elseif (($fieldChoices['notes'] ?? 'keep') === 'merge') {
            $updates[] = "notes = ?";
            $params[] = $merge['notes'] ?? '';
        }

        if (!empty($updates)) {
            $params[] = $keepId;
            $db->prepare("UPDATE contacts SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")
               ->execute($params);
        }

        // 3. Reassign foreign keys
        // Simple updates (no unique constraint conflicts)
        $simpleUpdates = [
            "UPDATE companies SET primary_contact_id = ? WHERE primary_contact_id = ?",
            "UPDATE companies SET billing_contact_id = ? WHERE billing_contact_id = ?",
            "UPDATE properties SET site_contact_id = ? WHERE site_contact_id = ?",
            "UPDATE quote_requests SET contact_id = ? WHERE contact_id = ?",
            "UPDATE activity_log SET contact_id = ? WHERE contact_id = ?",
        ];
        foreach ($simpleUpdates as $sql) {
            try { $db->prepare($sql)->execute([$keepId, $mergeId]); } catch (Exception $e) {}
        }

        // consent_log — no unique constraint
        try {
            $db->prepare("UPDATE consent_log SET contact_id = ? WHERE contact_id = ?")
               ->execute([$keepId, $mergeId]);
        } catch (Exception $e) {}

        // client_notes — no unique constraint
        try {
            $db->prepare("UPDATE client_notes SET contact_id = ? WHERE contact_id = ?")
               ->execute([$keepId, $mergeId]);
        } catch (Exception $e) {}

        // invoice_contacts — may have unique constraint on (invoice_id, contact_id, contact_role)
        try {
            $db->prepare("
                DELETE ic_merge FROM invoice_contacts ic_merge
                INNER JOIN invoice_contacts ic_keep
                    ON ic_keep.invoice_id = ic_merge.invoice_id
                    AND ic_keep.contact_role = ic_merge.contact_role
                    AND ic_keep.contact_id = ?
                WHERE ic_merge.contact_id = ?
            ")->execute([$keepId, $mergeId]);
            $db->prepare("UPDATE invoice_contacts SET contact_id = ? WHERE contact_id = ?")
               ->execute([$keepId, $mergeId]);
        } catch (Exception $e) {}

        // property_contacts — may have unique constraint on (property_id, contact_id, contact_role)
        try {
            $db->prepare("
                DELETE pc_merge FROM property_contacts pc_merge
                INNER JOIN property_contacts pc_keep
                    ON pc_keep.property_id = pc_merge.property_id
                    AND pc_keep.contact_role = pc_merge.contact_role
                    AND pc_keep.contact_id = ?
                WHERE pc_merge.contact_id = ?
            ")->execute([$keepId, $mergeId]);
            $db->prepare("UPDATE property_contacts SET contact_id = ? WHERE contact_id = ?")
               ->execute([$keepId, $mergeId]);
        } catch (Exception $e) {}

        // 4. Soft-delete the merged contact
        $hasMergedCol = false;
        try {
            $cols = $db->query("SHOW COLUMNS FROM contacts LIKE 'merged_into_id'")->fetchAll();
            $hasMergedCol = count($cols) > 0;
        } catch (Exception $e) {}

        if ($hasMergedCol) {
            $db->prepare("
                UPDATE contacts SET is_active = 0, merged_into_id = ?, merged_at = NOW(), merged_by = ?
                WHERE id = ?
            ")->execute([$keepId, $userId, $mergeId]);
        } else {
            $db->prepare("UPDATE contacts SET is_active = 0 WHERE id = ?")->execute([$mergeId]);
        }

        // 5. Log the merge
        try {
            $db->prepare("
                INSERT INTO activity_log (user_id, contact_id, action, details, ip_address)
                VALUES (?, ?, 'Contacts merged', ?, ?)
            ")->execute([
                $userId,
                $keepId,
                "Merged contact #{$mergeId} (" . trim($merge['first_name'] . ' ' . $merge['last_name']) . ") into #{$keepId} (" . trim($keep['first_name'] . ' ' . $keep['last_name']) . ")",
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (Exception $e) {}

        $db->commit();
        return ['success' => true, 'error' => null];

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("mergeContacts error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Merge failed: ' . $e->getMessage()];
    }
}

// ── Company Helper Functions ──────────────────────────────────────────────

/**
 * Get a single company by ID with primary/billing contact names
 */
function getCompanyById($id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT c.*,
               pc.first_name as primary_first_name, pc.last_name as primary_last_name,
               pc.email as primary_email, pc.phone as primary_phone,
               bc.first_name as billing_first_name, bc.last_name as billing_last_name,
               bc.email as billing_email_contact, bc.phone as billing_phone_contact
        FROM companies c
        LEFT JOIN contacts pc ON c.primary_contact_id = pc.id
        LEFT JOIN contacts bc ON c.billing_contact_id = bc.id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all contacts linked to a company (primary, billing, or via company_contacts if exists)
 */
function getCompanyContacts($companyId) {
    $db = getDB();
    $company = getCompanyById($companyId);
    if (!$company) return [];

    $contactIds = array_filter([
        $company['primary_contact_id'],
        $company['billing_contact_id']
    ]);

    if (empty($contactIds)) return [];

    $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
    $stmt = $db->prepare("SELECT * FROM contacts WHERE id IN ({$placeholders}) ORDER BY first_name ASC");
    $stmt->execute(array_values($contactIds));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get properties linked to a company via the company_properties junction table
 */
function getCompanyProperties($companyId) {
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT p.*, cp.relationship_type, cp.is_primary
            FROM properties p
            JOIN company_properties cp ON p.id = cp.property_id
            WHERE cp.company_id = ?
            ORDER BY cp.is_primary DESC, p.address ASC
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ── Work Queue ───────────────────────────────────────────────────────────────

/**
 * Get all work queue items across the system.
 * Each item is a live query result — items disappear when the underlying issue is resolved.
 *
 * Categories: critical, data_quality, operations, marketing
 * Each item: [category, icon, title, description, count, link, priority]
 *
 * @return array of work queue items sorted by priority
 */
function getWorkQueueItems() {
    $db = getDB();
    $items = [];

    // ── CRITICAL SYSTEMS ─────────────────────────────────────────────────

    // Overdue invoices
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt, SUM(balance_due) as total
            FROM invoices
            WHERE status = 'overdue'
            AND balance_due > 0
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'critical',
                'icon' => 'alert-triangle',
                'title' => $row['cnt'] . ' Overdue Invoice' . ($row['cnt'] > 1 ? 's' : ''),
                'description' => formatCurrency($row['total']) . ' outstanding past due date',
                'count' => (int)$row['cnt'],
                'link' => 'invoices/list.php?status=overdue',
                'priority' => 1,
            ];
        }
    } catch (Exception $e) {}

    // Stuck visits (scheduled in the past, not completed/cancelled/skipped)
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM job_visits
            WHERE scheduled_date < CURDATE()
            AND status IN ('scheduled', 'in_progress')
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'critical',
                'icon' => 'alert-circle',
                'title' => $row['cnt'] . ' Stuck Visit' . ($row['cnt'] > 1 ? 's' : ''),
                'description' => 'Past-date visits still marked scheduled or in progress',
                'count' => (int)$row['cnt'],
                'link' => 'schedule_appstack.php',
                'priority' => 2,
            ];
        }
    } catch (Exception $e) {}

    // Stale plans (active plans with no visits scheduled in next 30 days)
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM job_plans jp
            WHERE jp.status = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM job_visits jv
                WHERE jv.plan_id = jp.id
                AND jv.scheduled_date >= CURDATE()
                AND jv.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                AND jv.status NOT IN ('cancelled', 'skipped')
            )
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'critical',
                'icon' => 'clock',
                'title' => $row['cnt'] . ' Stale Plan' . ($row['cnt'] > 1 ? 's' : ''),
                'description' => 'Active plans with no upcoming visits in next 30 days',
                'count' => (int)$row['cnt'],
                'link' => 'jobs/plans.php',
                'priority' => 3,
            ];
        }
    } catch (Exception $e) {}

    // ── DATA QUALITY ─────────────────────────────────────────────────────

    // Properties missing geocoding
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM properties
            WHERE is_active = 1
            AND (latitude IS NULL OR latitude = 0 OR longitude IS NULL OR longitude = 0)
            AND address IS NOT NULL AND address != ''
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'data_quality',
                'icon' => 'map-pin',
                'title' => $row['cnt'] . ' Propert' . ($row['cnt'] > 1 ? 'ies' : 'y') . ' Need Geocoding',
                'description' => 'Properties with addresses but no lat/lng coordinates',
                'count' => (int)$row['cnt'],
                'link' => 'map_appstack.php',
                'priority' => 10,
            ];
        }
    } catch (Exception $e) {}

    // Contacts missing email
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM contacts
            WHERE is_active = 1
            AND (email IS NULL OR email = '')
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'data_quality',
                'icon' => 'mail',
                'title' => $row['cnt'] . ' Contact' . ($row['cnt'] > 1 ? 's' : '') . ' Missing Email',
                'description' => 'Active contacts with no email address on file',
                'count' => (int)$row['cnt'],
                'link' => 'clients_appstack.php',
                'priority' => 11,
            ];
        }
    } catch (Exception $e) {}

    // Contacts missing phone
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM contacts
            WHERE is_active = 1
            AND (phone IS NULL OR phone = '')
            AND (mobile IS NULL OR mobile = '')
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'data_quality',
                'icon' => 'phone',
                'title' => $row['cnt'] . ' Contact' . ($row['cnt'] > 1 ? 's' : '') . ' Missing Phone',
                'description' => 'Active contacts with no phone or mobile number',
                'count' => (int)$row['cnt'],
                'link' => 'clients_appstack.php',
                'priority' => 12,
            ];
        }
    } catch (Exception $e) {}

    // Duplicate contacts
    try {
        $dupes = findDuplicateContacts();
        $dupeCount = count($dupes);
        if ($dupeCount > 0) {
            $items[] = [
                'category' => 'data_quality',
                'icon' => 'users',
                'title' => $dupeCount . ' Potential Duplicate' . ($dupeCount > 1 ? 's' : ''),
                'description' => 'Contacts that may be duplicates based on name, email, or phone',
                'count' => $dupeCount,
                'link' => 'clients_appstack.php',
                'priority' => 13,
            ];
        }
    } catch (Exception $e) {}

    // ── OPERATIONS ───────────────────────────────────────────────────────

    // New quote requests waiting
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM quote_requests
            WHERE status IN ('new', 'reviewing')
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'operations',
                'icon' => 'inbox',
                'title' => $row['cnt'] . ' Quote Request' . ($row['cnt'] > 1 ? 's' : '') . ' Pending',
                'description' => 'New or in-review quote requests awaiting action',
                'count' => (int)$row['cnt'],
                'link' => 'products/quote-requests.php',
                'priority' => 20,
            ];
        }
    } catch (Exception $e) {}

    // Draft quotes (created but not sent)
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM quotes
            WHERE status = 'draft'
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'operations',
                'icon' => 'edit',
                'title' => $row['cnt'] . ' Draft Quote' . ($row['cnt'] > 1 ? 's' : ''),
                'description' => 'Quotes created but not yet sent to clients',
                'count' => (int)$row['cnt'],
                'link' => 'quotes/list.php?status=draft',
                'priority' => 21,
            ];
        }
    } catch (Exception $e) {}

    // Stale sent quotes (sent > 14 days ago, no response)
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM quotes
            WHERE status = 'sent'
            AND sent_at < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'operations',
                'icon' => 'send',
                'title' => $row['cnt'] . ' Stale Quote' . ($row['cnt'] > 1 ? 's' : ''),
                'description' => 'Sent over 14 days ago with no client response',
                'count' => (int)$row['cnt'],
                'link' => 'quotes/list.php?status=sent',
                'priority' => 22,
            ];
        }
    } catch (Exception $e) {}

    // Unsent invoices (draft invoices)
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM invoices
            WHERE status = 'draft'
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'operations',
                'icon' => 'file-text',
                'title' => $row['cnt'] . ' Draft Invoice' . ($row['cnt'] > 1 ? 's' : ''),
                'description' => 'Invoices ready to be sent to clients',
                'count' => (int)$row['cnt'],
                'link' => 'invoices/list.php?status=draft',
                'priority' => 23,
            ];
        }
    } catch (Exception $e) {}

    // ── MARKETING ────────────────────────────────────────────────────────

    // Contacts without marketing consent
    try {
        $row = $db->query("
            SELECT COUNT(*) as cnt
            FROM contacts
            WHERE is_active = 1
            AND receive_marketing = 0
            AND email IS NOT NULL AND email != ''
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            $items[] = [
                'category' => 'marketing',
                'icon' => 'bell-off',
                'title' => $row['cnt'] . ' Contact' . ($row['cnt'] > 1 ? 's' : '') . ' — No Marketing Consent',
                'description' => 'Active contacts with email but no marketing opt-in',
                'count' => (int)$row['cnt'],
                'link' => 'clients_appstack.php',
                'priority' => 30,
            ];
        }
    } catch (Exception $e) {}

    // Sort by priority
    usort($items, function($a, $b) {
        return $a['priority'] - $b['priority'];
    });

    return $items;
}

/**
 * Get daily profitability breakdown for a date range.
 *
 * For each day: revenue from completed visits, labor cost from time entries,
 * expenses, overhead, and scheduled/estimated figures for future days.
 *
 * @param string $startDate YYYY-MM-DD
 * @param string $endDate   YYYY-MM-DD
 * @return array Keyed by YYYY-MM-DD date strings
 */
function getDailyProfitability(string $startDate, string $endDate): array
{
    $db = getDB();
    $today = date('Y-m-d');
    $result = [];

    // Initialize each day in range
    $d = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day');
    while ($d < $end) {
        $ds = $d->format('Y-m-d');
        $result[$ds] = [
            'revenue'          => 0,
            'labor_cost'       => 0,
            'expense_cost'     => 0,
            'overhead_cost'    => 0,
            'total_cost'       => 0,
            'profit'           => 0,
            'margin_pct'       => 0,
            'visits_completed' => 0,
            'visits_scheduled' => 0,
            'est_revenue'      => 0,
        ];
        $d->modify('+1 day');
    }

    try {
        // 1. Revenue from completed visits
        $stmt = $db->prepare("
            SELECT jv.scheduled_date,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(COALESCE(jv.actual_amount, jp.price_per_visit, 0)), 0) AS revenue
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE jv.status = 'completed'
              AND jv.scheduled_date BETWEEN ? AND ?
            GROUP BY jv.scheduled_date
        ");
        $stmt->execute([$startDate, $endDate]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ds = $row['scheduled_date'];
            if (isset($result[$ds])) {
                $result[$ds]['revenue'] = (float)$row['revenue'];
                $result[$ds]['visits_completed'] = (int)$row['cnt'];
            }
        }

        // 2. Scheduled visits (not yet completed) + estimated revenue
        $stmt = $db->prepare("
            SELECT jv.scheduled_date,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(COALESCE(jp.price_per_visit, 0)), 0) AS est_revenue
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE jv.status IN ('scheduled', 'in_progress')
              AND jv.scheduled_date BETWEEN ? AND ?
            GROUP BY jv.scheduled_date
        ");
        $stmt->execute([$startDate, $endDate]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ds = $row['scheduled_date'];
            if (isset($result[$ds])) {
                $result[$ds]['visits_scheduled'] = (int)$row['cnt'];
                $result[$ds]['est_revenue'] = (float)$row['est_revenue'];
            }
        }

        // 3. Labor cost from time entries
        $stmt = $db->prepare("
            SELECT jv.scheduled_date,
                   COALESCE(SUM(jte.duration_minutes * COALESCE(u.hourly_rate, 25) / 60), 0) AS labor_cost
            FROM job_time_entries jte
            JOIN job_visits jv ON jte.visit_id = jv.id
            JOIN users u ON jte.user_id = u.id
            WHERE jte.status IN ('completed', 'edited')
              AND jv.scheduled_date BETWEEN ? AND ?
            GROUP BY jv.scheduled_date
        ");
        $stmt->execute([$startDate, $endDate]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ds = $row['scheduled_date'];
            if (isset($result[$ds])) {
                $result[$ds]['labor_cost'] = (float)$row['labor_cost'];
            }
        }

        // 4. Expenses by date
        $stmt = $db->prepare("
            SELECT expense_date,
                   COALESCE(SUM(total), 0) AS expense_total
            FROM expenses
            WHERE status IN ('draft', 'approved', 'forwarded')
              AND expense_date BETWEEN ? AND ?
            GROUP BY expense_date
        ");
        $stmt->execute([$startDate, $endDate]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ds = $row['expense_date'];
            if (isset($result[$ds])) {
                $result[$ds]['expense_cost'] = (float)$row['expense_total'];
            }
        }

        // 5. Overhead settings (self-contained query to avoid PlanFunctions dependency)
        $overheadPct = 20.0;
        try {
            $ohStmt = $db->prepare("SELECT setting_value FROM overhead_settings WHERE setting_key = 'overhead_percent'");
            $ohStmt->execute();
            $val = $ohStmt->fetchColumn();
            if ($val !== false) $overheadPct = (float)$val;
        } catch (Exception $e) {}

        // 6. Calculate totals for each day
        foreach ($result as $ds => &$day) {
            $directCosts = $day['labor_cost'] + $day['expense_cost'];
            $day['overhead_cost'] = round($directCosts * ($overheadPct / 100), 2);
            $day['total_cost'] = round($directCosts + $day['overhead_cost'], 2);
            $day['profit'] = round($day['revenue'] - $day['total_cost'], 2);
            $day['margin_pct'] = $day['revenue'] > 0
                ? round(($day['profit'] / $day['revenue']) * 100, 1)
                : 0;
        }
        unset($day);

    } catch (PDOException $e) {
        error_log("getDailyProfitability error: " . $e->getMessage());
    }

    return $result;
}

// ============================================================================
// CRON RUN LOGGING
// ============================================================================

/**
 * Record a cron job execution to the cron_runs table.
 *
 * Call this at the end of every cron script (both CLI and web paths).
 *
 * @param string      $cronKey      Machine key (e.g. 'generate_visits')
 * @param string      $status       'success' | 'warning' | 'error'
 * @param string|null $summary      One-line outcome (≤512 chars)
 * @param int|null    $durationMs   Wall-clock milliseconds
 * @param string|null $errorMessage Error detail when status = 'error'
 * @param bool        $fromWeb      true when triggered via the Run Now button
 */
function recordCronRun(
    string  $cronKey,
    string  $status      = 'success',
    ?string $summary     = null,
    ?int    $durationMs  = null,
    ?string $errorMessage = null,
    bool    $fromWeb     = false
): void {
    $allowedStatuses = ['success', 'warning', 'error'];
    if (!in_array($status, $allowedStatuses, true)) $status = 'success';
    if ($summary !== null) $summary = substr($summary, 0, 512);

    try {
        $db = getDB();

        // Idempotent DDL — creates table only if it doesn't exist yet
        $db->exec("CREATE TABLE IF NOT EXISTS cron_runs (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            cron_key      VARCHAR(64)  NOT NULL,
            triggered_by  ENUM('cron','web') NOT NULL DEFAULT 'cron',
            status        ENUM('success','warning','error') NOT NULL DEFAULT 'success',
            duration_ms   INT UNSIGNED NULL,
            summary       VARCHAR(512) NULL,
            error_message TEXT         NULL,
            ran_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_cron_key_ran_at (cron_key, ran_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $stmt = $db->prepare("
            INSERT INTO cron_runs (cron_key, triggered_by, status, duration_ms, summary, error_message, ran_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $cronKey,
            $fromWeb ? 'web' : 'cron',
            $status,
            $durationMs,
            $summary,
            $errorMessage,
        ]);
    } catch (Throwable $e) {
        // Never crash the cron over a logging failure
        error_log("recordCronRun failed for [{$cronKey}]: " . $e->getMessage());
    }
}

