<?php

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

require_once CRM_ROOT . '/products/config.php';
require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
?>



<?php
/**
 * Quote Request Processing
 * Mowology - Professional Landscaping Services
 * Processes form submissions, sends notifications, logs to database
 */

require_once CRM_ROOT . '/products/config.php';

// Start session
session_start();

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Initialize response
$response = ['success' => false, 'message' => '', 'redirect' => ''];

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: get-quote.html');
    exit;
}

try {
    // Get database connection
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('Database connection failed. Please try again later.');
    }

    // Verify reCAPTCHA
    $recaptchaToken = $_POST['g-recaptcha-response'] ?? '';
    if (!verifyRecaptcha($recaptchaToken)) {
        throw new Exception('Security verification failed. Please try again.');
    }

    // Sanitize and validate input
    $name = trim($_POST['name'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $phone = trim($_POST['phone'] ?? '');
    $propertyType = trim($_POST['propertyType'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? 'Vancouver');
    $postalCode = trim($_POST['postalCode'] ?? '');
    $propertySize = trim($_POST['propertySize'] ?? '');
    $services = isset($_POST['services']) ? $_POST['services'] : [];
    $message = trim($_POST['message'] ?? '');
    $timeline = trim($_POST['timeline'] ?? '');
    $budget = trim($_POST['budget'] ?? '');
    $preferredContact = trim($_POST['preferred_contact'] ?? 'email');
    $heardAboutUs = trim($_POST['heard_about_us'] ?? '');
    $newsletter = isset($_POST['newsletter']) ? 1 : 0;

    // Validate required fields
    if (empty($name) || !$email || empty($phone) || empty($propertyType) ||
        empty($address) || empty($city) || empty($postalCode) || empty($message) || empty($services)) {
        throw new Exception('Please fill in all required fields, including postal code.');
    }

    // Validate Canadian postal code format
    if (!preg_match('/^[A-Za-z][0-9][A-Za-z][ ]?[0-9][A-Za-z][0-9]$/', $postalCode)) {
        throw new Exception('Please enter a valid Canadian postal code (e.g., V6B 1A1).');
    }

    // Convert services array to comma-separated string
    $servicesString = is_array($services) ? implode(', ', $services) : $services;

    // Get client IP and user agent
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Begin transaction
    $pdo->beginTransaction();

    // Check if contact exists
    $stmt = $pdo->prepare("SELECT id FROM contacts WHERE email = ?");
    $stmt->execute([$email]);
    $existingContact = $stmt->fetch();

    $contactId = null;

    if ($existingContact) {
        // Update existing contact
        $contactId = $existingContact['id'];
        $stmt = $pdo->prepare("
            UPDATE contacts
            SET first_name = ?, last_name = ?, phone = ?, mobile = ?,
                consent_ip_address = ?, consent_marketing_email = ?, updated_at = NOW()
            WHERE id = ?
        ");

        // Split name into first and last
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = isset($nameParts[1]) ? $nameParts[1] : '';

        $stmt->execute([$firstName, $lastName, $phone, $phone, $address, $newsletter, $contactId]);

    } else {
        // Create new contact
        $stmt = $pdo->prepare("
            INSERT INTO contacts (first_name, last_name, email, phone, mobile,
                                 title, consent_ip_address, consent_marketing_email,
                                 consent_source, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'website_quote_form', NOW())
        ");

        // Split name into first and last
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = isset($nameParts[1]) ? $nameParts[1] : '';

        $stmt->execute([$firstName, $lastName, $email, $phone, $phone,
                       $propertyType, $address, $newsletter]);
        $contactId = $pdo->lastInsertId();
    }

    // Create property record and geocode address
    $propertyId = null;
    $geocodeData = null;

    try {
        // Create property record with address information
        $stmt = $pdo->prepare("
            INSERT INTO properties (property_name, property_type, address, city,
                                   postal_code, site_contact_id, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
        ");

        $propertyName = $propertyType . ' - ' . $address;
        $stmt->execute([$propertyName, $propertyType, $address, $city ?? 'Vancouver',
                       $postal_code, $contactId]);
        $propertyId = $pdo->lastInsertId();

        // Attempt to geocode the address
        $geocodeData = geocodeAddress($address, $city ?? 'Vancouver', $province ?? 'BC');

        if ($geocodeData) {
            // Update property with geocoded coordinates
            $stmt = $pdo->prepare("
                UPDATE properties
                SET latitude = ?, longitude = ?, geocode = ?, geocoded_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $geocodeData['lat'],
                $geocodeData['lng'],
                $geocodeData['formatted_address'],
                $propertyId
            ]);
        }
    } catch (Exception $e) {
        error_log("Property creation failed: " . $e->getMessage());
        // Don't fail the quote submission if property creation fails
    }

    // Insert quote request
    $stmt = $pdo->prepare("
        INSERT INTO quote_requests (
            first_name, last_name, email, phone,
            property_address, property_type, property_size,
            services_requested, project_description, timeline, budget_range,
            preferred_contact_method, heard_about_us, consent_marketing,
            contact_id, property_id, source, ip_address, user_agent, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'website', ?, ?, 'new', NOW())
    ");

    $nameParts = explode(' ', $name, 2);
    $firstName = $nameParts[0];
    $lastName = isset($nameParts[1]) ? $nameParts[1] : '';

    $stmt->execute([
        $firstName, $lastName, $email, $phone,
        $address, $propertyType, $propertySize,
        $servicesString, $message, $timeline, $budget,
        $preferredContact, $heardAboutUs, $newsletter,
        $contactId, $propertyId, $ipAddress, $userAgent
    ]);

    $quoteRequestId = $pdo->lastInsertId();

    // Log activity
    logActivity($pdo, 'quote_requested', "New quote request from {$name}", $quoteRequestId, 'quote_request');
    logActivity($pdo, 'admin_notified', "Admin notified via email and SMS", $quoteRequestId, 'quote_request');

    // Commit transaction
    $pdo->commit();

    // Send email notification to admin
    // These emails render as HTML — escape every anonymous-visitor-supplied field before
    // interpolating so an HTML/script payload in name/notes/etc. can't render in the
    // recipient's mail client. The unescaped originals above are still used for the DB
    // record (prepared statements handle SQL-safety separately from HTML-safety).
    $nameH              = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $emailH             = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $phoneH             = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
    $propertyTypeH      = htmlspecialchars($propertyType, ENT_QUOTES, 'UTF-8');
    $propertySizeH      = htmlspecialchars($propertySize, ENT_QUOTES, 'UTF-8');
    $addressH           = htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
    $servicesStringH    = htmlspecialchars($servicesString, ENT_QUOTES, 'UTF-8');
    $messageH           = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $timelineH          = htmlspecialchars($timeline, ENT_QUOTES, 'UTF-8');
    $budgetH            = htmlspecialchars($budget, ENT_QUOTES, 'UTF-8');
    $preferredContactH  = htmlspecialchars($preferredContact, ENT_QUOTES, 'UTF-8');
    $firstNameH         = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

    $emailSubject = "🌱 New Quote Request - {$propertyTypeH} in " . explode(',', $addressH)[0];
    $emailMessage = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background: #4a7c2c; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .info-box { background: #f8f9fa; border-left: 4px solid #4a7c2c; padding: 15px; margin: 10px 0; }
            .label { font-weight: bold; color: #4a7c2c; }
            .button { display: inline-block; background: #e85d04; color: white; padding: 12px 24px;
                     text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h2>New Quote Request</h2>
            <p>ID: #{$quoteRequestId}</p>
        </div>

        <div class='content'>
            <div class='info-box'>
                <p><span class='label'>Customer:</span> {$nameH}</p>
                <p><span class='label'>Email:</span> <a href='mailto:{$emailH}'>{$emailH}</a></p>
                <p><span class='label'>Phone:</span> <a href='tel:{$phoneH}'>" . htmlspecialchars(formatPhone($phone), ENT_QUOTES, 'UTF-8') . "</a></p>
            </div>

            <div class='info-box'>
                <p><span class='label'>Property Type:</span> {$propertyTypeH}</p>
                <p><span class='label'>Property Size:</span> {$propertySizeH}</p>
                <p><span class='label'>Address:</span> {$addressH}</p>
            </div>

            <div class='info-box'>
                <p><span class='label'>Services Requested:</span></p>
                <p>{$servicesStringH}</p>
            </div>

            <div class='info-box'>
                <p><span class='label'>Project Description:</span></p>
                <p>{$messageH}</p>
            </div>

            <div class='info-box'>
                <p><span class='label'>Timeline:</span> {$timelineH}</p>
                <p><span class='label'>Budget:</span> {$budgetH}</p>
                <p><span class='label'>Preferred Contact:</span> {$preferredContactH}</p>
            </div>

            <center>
                <a href='" . SITE_URL . "/crm/quote-review.php?id={$quoteRequestId}' class='button'>
                    Review & Create Quote
                </a>
            </center>
        </div>

        <div class='footer'>
            <p>Mowology Professional Landscaping | " . ADMIN_PHONE . " | office@mowology.ca</p>
            <p>Received: " . date('F j, Y g:i A') . "</p>
        </div>
    </body>
    </html>
    ";

    $emailSent = sendCrmEmail(ADMIN_EMAIL, $emailSubject, $emailMessage);

    // Send SMS notification
    $smsMessage = "New Quote: {$name} - {$propertyType} @ " . explode(',', $address)[0] . ". Services: {$servicesString}. Review: " . SITE_URL . "/crm";
    $smsSent = sendSMS($smsMessage);

    // Send confirmation email to customer
    $customerSubject = "Quote Request Received - Mowology";
    $customerMessage = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background: #4a7c2c; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .highlight { background: #ecfdf5; border-left: 4px solid #10b981; padding: 15px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h2>Thank You for Your Quote Request!</h2>
        </div>

        <div class='content'>
            <p>Hi {$firstNameH},</p>

            <p>We've received your quote request and will get back to you within 24 hours with a detailed proposal.</p>

            <div class='highlight'>
                <p><strong>What's Next?</strong></p>
                <ol>
                    <li>We'll review your project details</li>
                    <li>One of our team members will contact you to discuss specifics</li>
                    <li>You'll receive a detailed quote via email</li>
                </ol>
            </div>

            <p><strong>Your Request Summary:</strong></p>
            <ul>
                <li>Property: {$addressH}</li>
                <li>Services: {$servicesStringH}</li>
                <li>Timeline: {$timelineH}</li>
            </ul>

            <p>If you have any immediate questions, feel free to call us at <strong>" . ADMIN_PHONE . "</strong>.</p>

            <p>Best regards,<br>
            <strong>The Mowology Team</strong><br>
            A Higher Degree of Service</p>
        </div>
    </body>
    </html>
    ";

    sendCrmEmail($email, $customerSubject, $customerMessage);

    // Set success response
    $response['success'] = true;
    $response['message'] = 'Quote request submitted successfully!';
    $response['redirect'] = SITE_URL . '/quote-success.html?ref=' . $quoteRequestId;

    // Redirect to success page
    header('Location: ' . $response['redirect']);
    exit;

} catch (Exception $e) {
    // Rollback transaction if active
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Log error
    error_log("Quote request error: " . $e->getMessage());

    // Set error response
    $response['message'] = $e->getMessage();

    // Redirect back to form with error
    header('Location: get-quote.html?error=' . urlencode($response['message']));
    exit;
}
?>
