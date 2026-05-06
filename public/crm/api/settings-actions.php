<?php
/**
 * API Endpoint: Settings Actions
 *
 * POST /crm/api/settings-actions.php
 * Handles various settings-related actions (test email, etc.)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messaging.php';

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser();
requirePermission('settings.edit');

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request body']);
    exit;
}

// Verify CSRF
if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}
session_write_close();

$action = $input['action'] ?? '';

switch ($action) {
    case 'send_test_email':
        $toEmail = filter_var($input['to_email'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$toEmail) {
            echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
            exit;
        }

        // Load current settings for signature/footer
        try {
            $db = getDB();
            $settings = [];
            $stmt = $db->query("SELECT setting_key, setting_value FROM business_settings");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            $settings = [];
        }

        $companyName = $settings['company_name'] ?? 'Mowology Landscaping';
        $signature = $settings['email_signature_text'] ?? '';
        $footer = $settings['email_footer_html'] ?? '';

        $body = "<p>This is a test email from <strong>{$companyName}</strong>.</p>";
        $body .= "<p>If you're reading this, your email settings are configured correctly.</p>";
        if ($signature) {
            $body .= "<hr style='border:none;border-top:1px solid #e5e5e5;margin:20px 0;'>";
            $body .= "<p style='white-space:pre-line;color:#666;'>" . htmlspecialchars($signature) . "</p>";
        }
        if ($footer) {
            $body .= "<div style='margin-top:20px;padding-top:16px;border-top:1px solid #eee;'>{$footer}</div>";
        }

        $result = sendCrmEmail($toEmail, 'Test Email — ' . $companyName, $body);

        if ($result) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to send email. Check server mail configuration.']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}
