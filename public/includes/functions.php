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
        '<span class="status-badge" style="background: %s; color: %s;">%s</span>',
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
        LEFT JOIN companies c ON p.company_id = c.id
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

    // Get quote details
    $stmt = $db->prepare("
        SELECT q.*, p.company_id, p.address
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
 * Get business settings (logo, branding colors, company info, etc.)
 * Loads from database or returns defaults if no settings exist
 */
function getBusinessSettings() {
    static $settings = null;

    // Cache settings to avoid multiple database queries
    if ($settings !== null) {
        return $settings;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM business_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            $settings = getDefaultBusinessSettings();
        }
    } catch (Exception $e) {
        error_log("Error loading business settings: " . $e->getMessage());
        $settings = getDefaultBusinessSettings();
    }

    return $settings;
}

/**
 * Get default business settings
 */
function getDefaultBusinessSettings() {
    return [
        'id' => 1,
        'company_name' => 'Mowology',
        'company_phone' => '778-846-9273',
        'company_email' => 'office@mowology.ca',
        'company_website' => 'https://mowology.ca',
        'company_address' => '',
        'gst_registration' => '',
        'pst_registration' => '',
        'business_license' => '',
        'logo_path' => '/assets/img/logo/mowology-logo.jpg',
        'logo_alt_text' => 'Mowology Logo',
        'brand_color_primary' => '#2D8659',
        'brand_color_secondary' => '#7FD858',
        'invoice_footer_text' => '',
        'invoice_terms_text' => '',
        'invoice_payment_instructions' => '',
        'email_signature_text' => '',
        'email_footer_html' => '',
        'quote_message_header' => '',
        'quote_message_footer' => '',
        'invoice_message_header' => '',
        'invoice_message_footer' => '',
        'receipt_message_header' => '',
        'receipt_message_footer' => '',
    ];
}
