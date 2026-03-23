<?php
/**
 * Public DSAR Submission Endpoint — no login required.
 * Accepts POST from /privacy-request.php and /crm/privacy_appstack.php.
 * Rate-limited to 3 requests per 24 hours per IP.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';     // session, CSRF
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Services/Messaging/EmailHelper.php';
require_once APP_ROOT . '/Modules/Privacy/Services/PrivacyService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

session_write_close();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (!verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Rate limit: max 3 submissions per IP per 24h
$db = getDB();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($ip) {
    $rateStmt = $db->prepare("
        SELECT COUNT(*) FROM privacy_requests
        WHERE export_path IS NULL
          AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND notes LIKE ?
    ");
    $rateStmt->execute(['%ip:' . $ip . '%']);
    $recentCount = (int) $rateStmt->fetchColumn();
    if ($recentCount >= 3) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again tomorrow.']);
        exit;
    }
}

$type  = trim($input['request_type']    ?? '');
$name  = trim($input['requester_name']  ?? '');
$email = trim($input['requester_email'] ?? '');
$notes = trim($input['notes']           ?? '');

$validTypes = ['access', 'deletion', 'correction'];
if (!in_array($type, $validTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request type']);
    exit;
}
if (empty($name) || strlen($name) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Name is required (max 255 characters)']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']);
    exit;
}

try {
    // Append IP for rate-limit tracking
    $notesWithIp = $notes . ($notes ? "\n" : '') . 'ip:' . $ip;

    // Try to match email to a contact
    $contactStmt = $db->prepare("SELECT id FROM contacts WHERE email = ? LIMIT 1");
    $contactStmt->execute([$email]);
    $contactId = $contactStmt->fetchColumn() ?: null;

    $svc = new PrivacyService($db);
    $id  = $svc->createRequest($type, $name, $email, $notesWithIp, $contactId ?: null);

    // Confirmation email to requester
    $typeLabels = ['access' => 'Access', 'deletion' => 'Erasure', 'correction' => 'Correction'];
    $typeLabel  = $typeLabels[$type] ?? ucfirst($type);

    if (function_exists('sendCrmEmail')) {
        sendCrmEmail(
            $email,
            "Privacy Request Received — Mowology",
            "Hi {$name},\n\n"
            . "We have received your Privacy {$typeLabel} Request (reference #{$id}).\n\n"
            . "We will respond within 30 days as required by PIPEDA.\n\n"
            . "If you have questions, contact us at office@mowology.ca or (778) 846-9273.\n\n"
            . "Thank you,\nMowology Landscaping",
            $name
        );

        // Admin notification
        sendCrmEmail(
            defined('SITE_EMAIL') ? SITE_EMAIL : 'office@mowology.ca',
            "New Privacy Request #{$id} ({$typeLabel})",
            "A new privacy {$typeLabel} request was submitted.\n\n"
            . "Name:  {$name}\n"
            . "Email: {$email}\n"
            . "Type:  {$typeLabel}\n"
            . "Notes: {$notes}\n\n"
            . "Log in to the CRM to manage this request:\n"
            . "https://mowology.ca/crm/privacy_appstack.php",
            'Mowology Admin'
        );
    }

    echo json_encode([
        'success'    => true,
        'id'         => $id,
        'message'    => "Your privacy {$typeLabel} request has been submitted. You will receive a confirmation email shortly.",
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error — please try again']);
}
