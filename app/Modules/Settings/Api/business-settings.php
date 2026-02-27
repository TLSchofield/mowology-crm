<?php
/**
 * Business Settings API
 *
 * Handles reading and updating business branding, legal info, and messaging
 * Used by invoices, email templates, and settings page
 *
 * POST /crm/api/business-settings.php - Update settings
 * GET  /crm/api/business-settings.php - Get all settings
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json');

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('settings.edit');

$db = getDB();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // GET all settings
        getBusinessSettings($db);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // POST update settings
        updateBusinessSettings($db, $user);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (PDOException $e) {
    error_log("Database error in business settings API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error in business settings API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get all business settings
 */
function getBusinessSettings($db) {
    $stmt = $db->prepare("SELECT * FROM business_settings WHERE id = 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        // Return default settings if none exist
        $settings = getDefaultSettings();
    }

    // Filter out metadata we don't need in response
    $response = [
        'company_name' => $settings['company_name'] ?? '',
        'company_phone' => $settings['company_phone'] ?? '',
        'company_email' => $settings['company_email'] ?? '',
        'company_website' => $settings['company_website'] ?? '',
        'company_address' => $settings['company_address'] ?? '',
        'gst_registration' => $settings['gst_registration'] ?? '',
        'pst_registration' => $settings['pst_registration'] ?? '',
        'business_license' => $settings['business_license'] ?? '',
        'gst_rate' => number_format((float)($settings['gst_rate'] ?? 5.00), 2),
        'pst_rate' => number_format((float)($settings['pst_rate'] ?? 0.00), 2),
        'show_tax_on_invoices' => $settings['show_tax_on_invoices'] ?? '1',
        'country' => $settings['country'] ?? 'Canada',
        'timezone' => $settings['timezone'] ?? 'America/Vancouver',
        'date_format' => $settings['date_format'] ?? 'M j, Y',
        'time_format' => $settings['time_format'] ?? '12h',
        'first_day_of_week' => $settings['first_day_of_week'] ?? '1',
        'logo_path' => $settings['logo_path'] ?? '',
        'logo_alt_text' => $settings['logo_alt_text'] ?? '',
        'brand_color_primary' => $settings['brand_color_primary'] ?? '#2D8659',
        'brand_color_secondary' => $settings['brand_color_secondary'] ?? '#7FD858',
        'invoice_footer_text' => $settings['invoice_footer_text'] ?? '',
        'invoice_terms_text' => $settings['invoice_terms_text'] ?? '',
        'invoice_payment_instructions' => $settings['invoice_payment_instructions'] ?? '',
        'email_signature_text' => $settings['email_signature_text'] ?? '',
        'email_footer_html' => $settings['email_footer_html'] ?? '',
        'quote_message_header' => $settings['quote_message_header'] ?? '',
        'quote_message_footer' => $settings['quote_message_footer'] ?? '',
        'invoice_message_header' => $settings['invoice_message_header'] ?? '',
        'invoice_message_footer' => $settings['invoice_message_footer'] ?? '',
        'receipt_message_header' => $settings['receipt_message_header'] ?? '',
        'receipt_message_footer' => $settings['receipt_message_footer'] ?? '',
    ];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'settings' => $response
    ]);
}

/**
 * Update business settings
 */
function updateBusinessSettings($db, $user) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }

    // Allowed fields
    $allowedFields = [
        'company_name',
        'company_phone',
        'company_email',
        'company_website',
        'company_address',
        'gst_registration',
        'pst_registration',
        'business_license',
        'gst_rate',
        'pst_rate',
        'show_tax_on_invoices',
        'country',
        'timezone',
        'date_format',
        'time_format',
        'first_day_of_week',
        'logo_path',
        'logo_alt_text',
        'brand_color_primary',
        'brand_color_secondary',
        'invoice_footer_text',
        'invoice_terms_text',
        'invoice_payment_instructions',
        'email_signature_text',
        'email_footer_html',
        'quote_message_header',
        'quote_message_footer',
        'invoice_message_header',
        'invoice_message_footer',
        'receipt_message_header',
        'receipt_message_footer',
    ];

    // Validate input fields
    $updates = [];
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[$field] = $input[$field];
        }
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields provided']);
        exit;
    }

    // Build SQL update statement
    $fields = [];
    $values = [];
    foreach ($updates as $field => $value) {
        $fields[] = "$field = ?";
        $values[] = $value;
    }

    // Add metadata
    $fields[] = 'updated_by = ?';
    $fields[] = 'updated_at = NOW()';
    $values[] = $user['id'];

    $sql = "UPDATE business_settings SET " . implode(', ', $fields) . " WHERE id = 1";
    $stmt = $db->prepare($sql);

    if (!$stmt->execute($values)) {
        throw new Exception('Failed to update business settings');
    }

    // Get updated settings
    $stmt = $db->prepare("SELECT * FROM business_settings WHERE id = 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Business settings updated successfully',
        'settings' => $settings
    ]);
}

/**
 * Get default business settings
 */
function getDefaultSettings() {
    return [
        'id' => 1,
        'company_name' => 'Mowology Landscaping',
        'company_phone' => '',
        'company_email' => 'info@mowology.ca',
        'company_website' => 'https://mowology.ca',
        'company_address' => '',
        'gst_registration' => '',
        'pst_registration' => '',
        'business_license' => '',
        'gst_rate' => '5.00',
        'pst_rate' => '0.00',
        'show_tax_on_invoices' => '1',
        'country' => 'Canada',
        'timezone' => 'America/Vancouver',
        'date_format' => 'M j, Y',
        'time_format' => '12h',
        'first_day_of_week' => '1',
        'logo_path' => '',
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
