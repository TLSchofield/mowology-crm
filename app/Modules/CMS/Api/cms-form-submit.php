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
require_once dirname(__DIR__, 3) . '/Services/Messaging/IdentityMail.php';

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
// Fable review 2026-10-12: fields clipped, IP = REMOTE_ADDR only (forwarded headers are spoofable).
require_once APP_ROOT . '/Modules/CMS/Services/CmsFormSubmitRules.php';
$f         = CmsFormSubmitRules::fields($_POST);
$name      = $f['name'];
$email     = $f['email'];
$phone     = $f['phone'];
$message   = $f['message'];
$formId    = $f['form_id'];
$sourceUrl = $f['source_url'];
$ip        = CmsFormSubmitRules::clientIp($_SERVER);

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

    // Rate limit: 3 per IP per 24 h (same rule as privacy-submit).
    if ($ip !== '') {
        $rc = $db->prepare("SELECT COUNT(*) FROM cms_form_submissions WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $rc->execute([$ip]);
        if (CmsFormSubmitRules::overLimit((int)$rc->fetchColumn())) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Thanks — we already have your message. Please call (778) 846-9273 if it is urgent.']);
            exit;
        }
    }

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

    // ── Notification email ────────────────────────────────────────────────
    // messaging.php was never loaded here, so the admin notice silently never fired (Fable 2026-10-12).
    require_once CRM_INCLUDES . '/messaging.php';
    if (function_exists('sendCrmEmail')) {
        $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : _idReplyTo(); // same inbox as rebook notices
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
