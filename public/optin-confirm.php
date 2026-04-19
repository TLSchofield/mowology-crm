<?php
/**
 * Public Opt-In Confirmation Page
 *
 * Customer clicks "Yes, keep me subscribed" in the re-consent email
 * → lands here → token is validated → branded thank-you page shown.
 *
 * URL: /optin-confirm.php?token=XXXX
 *
 * @package Mowology
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

if (defined('APP_ROOT')) {
    require_once APP_ROOT . '/Core/config.php';
}

$token   = trim($_GET['token'] ?? '');
$success = false;
$message = '';
$heading = '';

if (empty($token)) {
    $heading = 'Invalid Link';
    $message = 'This confirmation link is missing or malformed. Please check the link in your email and try again.';
} else {
    try {
        $db = getDB();

        // Try exact token match (v1 format used by reconsent sender)
        $stmt = $db->prepare("SELECT * FROM marketing_optin_tokens WHERE token = ? AND status = 'pending'");
        $stmt->execute([$token]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        // Also try SHA-256 hash match (v2 format)
        if (!$record) {
            $stmt = $db->prepare("SELECT * FROM marketing_optin_tokens WHERE token = ? AND status = 'pending'");
            $stmt->execute([hash('sha256', $token)]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$record) {
            // Check if already confirmed
            $checkStmt = $db->prepare("SELECT status FROM marketing_optin_tokens WHERE token = ? OR token = ?");
            $checkStmt->execute([$token, hash('sha256', $token)]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && $existing['status'] === 'confirmed') {
                $success = true;
                $heading = 'Already Confirmed';
                $message = 'Your subscription is already confirmed. You\'re all set to receive our updates!';
            } else {
                $heading = 'Link Expired';
                $message = 'This confirmation link has expired or is no longer valid. If you\'d like to resubscribe, please contact us at info@mowology.ca.';
            }
        } else {
            // Check expiry
            if ($record['expires_at'] && strtotime($record['expires_at']) < time()) {
                $db->prepare("UPDATE marketing_optin_tokens SET status='expired' WHERE id=?")->execute([$record['id']]);
                $heading = 'Link Expired';
                $message = 'This confirmation link has expired. Please contact us at info@mowology.ca if you\'d like to resubscribe.';
            } else {
                // Confirm the opt-in
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $db->prepare("UPDATE marketing_optin_tokens SET status='confirmed', confirmed_at=NOW(), ip_address=? WHERE id=?")->execute([$ip, $record['id']]);
                $db->prepare("UPDATE contacts SET receive_marketing=1 WHERE id=?")->execute([$record['contact_id']]);

                // Upsert preferences
                $db->prepare("
                    INSERT INTO client_marketing_preferences (contact_id, email_opt_in, confirmed_at, confirmation_method)
                    VALUES (?, 1, NOW(), 'optin_email')
                    ON DUPLICATE KEY UPDATE email_opt_in=1, confirmed_at=NOW(), confirmation_method='optin_email'
                ")->execute([$record['contact_id']]);

                // Add tag
                $db->prepare("
                    INSERT IGNORE INTO contact_tags (contact_id, tag, added_at)
                    VALUES (?, 'Marketing Confirmed', NOW())
                ")->execute([$record['contact_id']]);

                $success = true;
                $heading = 'You\'re Subscribed!';
                $message = 'Thank you for confirming your email preferences. You\'ll receive occasional updates about seasonal services, special offers, and landscaping tips.';
            }
        }

    } catch (\Throwable $e) {
        error_log('optin-confirm error: ' . $e->getMessage());
        $heading = 'Something Went Wrong';
        $message = 'We couldn\'t process your request. Please try again or contact us at info@mowology.ca.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($heading); ?> — Mowology</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 1rem;
        }
        .confirm-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .confirm-header {
            background: #1A5F4A;
            padding: 24px 32px;
            text-align: center;
        }
        .confirm-header h1 {
            color: #fff;
            font-size: 22px;
            font-weight: 700;
        }
        .confirm-header p {
            color: #7FD858;
            font-size: 13px;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .confirm-body {
            padding: 32px;
            text-align: center;
        }
        .confirm-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        .confirm-body h2 {
            font-size: 20px;
            margin-bottom: 12px;
            color: #1a202c;
        }
        .confirm-body p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 16px;
        }
        .confirm-success h2 { color: #2D8659; }
        .confirm-error h2 { color: #dc3545; }
        .confirm-footer {
            border-top: 1px solid #e2e8f0;
            padding: 16px 32px;
            text-align: center;
        }
        .confirm-footer a {
            color: #2D8659;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .confirm-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="confirm-card">
        <div class="confirm-header">
            <h1>Mowology Landscaping</h1>
            <p>PROFESSIONAL LANDSCAPE SERVICES</p>
        </div>
        <div class="confirm-body <?php echo $success ? 'confirm-success' : 'confirm-error'; ?>">
            <div class="confirm-icon"><?php echo $success ? '&#10004;' : '&#9888;'; ?></div>
            <h2><?php echo htmlspecialchars($heading); ?></h2>
            <p><?php echo htmlspecialchars($message); ?></p>
        </div>
        <div class="confirm-footer">
            <a href="https://mowology.ca">Visit Mowology.ca</a>
            &nbsp;&middot;&nbsp;
            <a href="tel:7788469273">(778) 846-9273</a>
        </div>
    </div>
</body>
</html>
