<?php
/**
 * CMS Form Capture Submission API — P3-D
 *
 * POST /crm/api/cms-form-submit.php
 * Body (multipart/form-data or URL-encoded):
 *   name, email, phone, message, form_id, source_url
 *   website (honeypot — must be empty)
 *
 * Returns: { success: true } or { success: false, error: "..." }
 * Stores submission in cms_form_submissions table.
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

require_once PUBLIC_ROOT . '/includes/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Honeypot ───────────────────────────────────────────────────────────────
if (!empty($_POST['website'])) {
    // Silently accept (fool bots) but don't store
    echo json_encode(['success' => true]);
    exit;
}

// ── Input collection & sanitisation ───────────────────────────────────────
$name      = trim(strip_tags($_POST['name']      ?? ''));
$email     = trim($_POST['email']     ?? '');
$phone     = trim(strip_tags($_POST['phone']     ?? ''));
$message   = trim(strip_tags($_POST['message']   ?? ''));
$formId    = trim(strip_tags($_POST['form_id']   ?? 'cms-contact'));
$sourceUrl = trim($_POST['source_url'] ?? '');
$ip        = $_SERVER['HTTP_CF_CONNECTING_IP']
          ?? $_SERVER['HTTP_X_FORWARDED_FOR']
          ?? $_SERVER['REMOTE_ADDR']
          ?? '';

// ── Validation ─────────────────────────────────────────────────────────────
$errors = [];
if ($name === '') {
    $errors[] = 'Name is required.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if (!empty($errors)) {
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ── Store in DB ────────────────────────────────────────────────────────────
try {
    $db = getDB();

    $db->prepare("
        INSERT INTO cms_form_submissions
            (form_id, name, email, phone, message, source_url, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $formId,
        $name,
        $email,
        $phone ?: null,
        $message ?: null,
        $sourceUrl ?: null,
        $ip ?: null,
    ]);

    $submissionId = (int)$db->lastInsertId();

    // ── Optional: send notification email ─────────────────────────────────
    // Only if sendCrmEmail is available (messaging.php loaded)
    if (function_exists('sendCrmEmail')) {
        $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@mowology.ca';
        $subject    = 'New Website Enquiry — ' . $formId;
        $body = '<h3>New Form Submission</h3>'
              . '<p><strong>Form:</strong> ' . htmlspecialchars($formId) . '</p>'
              . '<p><strong>Name:</strong> '  . htmlspecialchars($name)  . '</p>'
              . '<p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>'
              . ($phone   ? '<p><strong>Phone:</strong> '   . htmlspecialchars($phone)   . '</p>' : '')
              . ($message ? '<p><strong>Message:</strong><br>' . nl2br(htmlspecialchars($message)) . '</p>' : '')
              . '<p><strong>Source:</strong> ' . htmlspecialchars($sourceUrl) . '</p>'
              . '<p><em>Submission #' . $submissionId . '</em></p>';

        @sendCrmEmail($adminEmail, $subject, $body);
    }

    echo json_encode(['success' => true, 'id' => $submissionId]);

} catch (\Throwable $e) {
    error_log('cms-form-submit.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again later.']);
}
