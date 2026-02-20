<?php
/**
 * Public Unsubscribe Handler
 *
 * Token-based URL: /unsubscribe.php?email=X&token=HASH&sid=Y
 * Token = HMAC of email + secret — no DB lookup needed to validate.
 *
 * GET  → shows unsubscribe confirmation form
 * POST → processes unsubscribe and updates contacts table
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

// Only need DB access — no auth required (public endpoint)
if (defined('APP_ROOT')) {
    require_once APP_ROOT . '/Core/config.php';
}

$secret = defined('UNSUBSCRIBE_SECRET') ? UNSUBSCRIBE_SECRET : 'mowology-unsub-2026';

$email = strtolower(trim($_GET['email'] ?? $_POST['email'] ?? ''));
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$sendId = (int)($_GET['sid'] ?? $_POST['sid'] ?? 0);

// Validate token
$expectedToken = hash_hmac('sha256', $email, $secret);
$validToken = hash_equals($expectedToken, $token);

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken && !empty($email)) {
    $reason = trim($_POST['reason'] ?? '');

    try {
        $db = getDB();

        // Insert into marketing_unsubscribes (ignore duplicate)
        $db->prepare("
            INSERT IGNORE INTO marketing_unsubscribes (email, reason, unsubscribed_at)
            VALUES (?, ?, NOW())
        ")->execute([$email, $reason ?: null]);

        // Update contact.receive_marketing
        $db->prepare("
            UPDATE contacts SET receive_marketing = 0 WHERE email = ?
        ")->execute([$email]);

        // If we have a send ID, mark it
        if ($sendId) {
            $db->prepare("
                UPDATE campaign_sends SET status = 'unsubscribed' WHERE id = ? AND email = ?
            ")->execute([$sendId, $email]);
        }

        $success = true;
        $message = 'You have been successfully unsubscribed.';

    } catch (\Throwable $e) {
        error_log('Unsubscribe error: ' . $e->getMessage());
        $message = 'Something went wrong. Please contact us at info@mowology.ca to be removed.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribe — Mowology</title>
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
        .unsub-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .unsub-header {
            background: #1A5F4A;
            padding: 24px 32px;
            text-align: center;
        }
        .unsub-header h1 {
            color: #fff;
            font-size: 22px;
            font-weight: 700;
        }
        .unsub-header p {
            color: #7FD858;
            font-size: 13px;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .unsub-body {
            padding: 32px;
        }
        .unsub-body h2 {
            font-size: 18px;
            margin-bottom: 12px;
            color: #1a202c;
        }
        .unsub-body p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 16px;
        }
        .unsub-email {
            background: #f1f5f9;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 14px;
            color: #334155;
            margin-bottom: 16px;
        }
        .unsub-form label {
            display: block;
            font-size: 13px;
            color: #64748b;
            margin-bottom: 4px;
        }
        .unsub-form textarea {
            width: 100%;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 14px;
            resize: vertical;
            min-height: 60px;
            margin-bottom: 16px;
        }
        .unsub-form button {
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 10px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .unsub-form button:hover { background: #b91c1c; }
        .unsub-success {
            text-align: center;
            padding: 2rem 0;
        }
        .unsub-success svg {
            width: 48px;
            height: 48px;
            color: #22c55e;
            margin-bottom: 12px;
        }
        .unsub-invalid {
            text-align: center;
            color: #dc2626;
            padding: 2rem 0;
        }
    </style>
</head>
<body>
    <div class="unsub-card">
        <div class="unsub-header">
            <h1>Mowology</h1>
            <p>LANDSCAPING</p>
        </div>
        <div class="unsub-body">
            <?php if ($success): ?>
                <div class="unsub-success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <h2>Unsubscribed</h2>
                    <p><?php echo htmlspecialchars($message); ?></p>
                    <p>You will no longer receive marketing emails from Mowology.</p>
                </div>
            <?php elseif (!$validToken || empty($email)): ?>
                <div class="unsub-invalid">
                    <h2>Invalid Link</h2>
                    <p>This unsubscribe link is invalid or has expired. Please contact us at <a href="mailto:info@mowology.ca">info@mowology.ca</a> to be removed from our mailing list.</p>
                </div>
            <?php else: ?>
                <h2>Unsubscribe from Mowology Emails</h2>
                <p>We're sorry to see you go. You're about to unsubscribe:</p>
                <div class="unsub-email"><?php echo htmlspecialchars($email); ?></div>
                <form method="post" class="unsub-form">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="sid" value="<?php echo $sendId; ?>">
                    <label>Reason (optional):</label>
                    <textarea name="reason" placeholder="Tell us why you're unsubscribing..."></textarea>
                    <button type="submit">Unsubscribe</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
