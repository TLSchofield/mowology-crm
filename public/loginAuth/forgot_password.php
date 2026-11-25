<?php
require_once __DIR__ . '/auth.php';
require_once APP_ROOT . '/Core/Auth/AttemptThrottle.php';
require_once APP_ROOT . '/Core/Auth/ResetCaptcha.php';
require_once __DIR__ . '/signin-chrome.php';   // Ittai v1.2 sign-in look (2026-10-23)
$__bizName = itSigninBizName();

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: /crm/app-launch.php');   // loginAuth/dashboard.php does not exist
    exit();
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['csrf_token']) && verifyCSRFToken($_POST['csrf_token'])) {
        $email = sanitizeInput($_POST['email'] ?? '');

        if (empty($email)) {
            $message = 'Please enter your email address.';
            $messageType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } elseif (!ResetCaptcha::passes((string)($_POST['recaptcha_v3_token'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null)) {
            $__ph = (string)(BusinessIdentity::get()['phone'] ?? '');
            $message = 'Security check failed. Please try again' . ($__ph !== '' ? ', or call ' . BusinessIdentity::phone($__ph) : '') . '.';
            $messageType = 'error';
        } elseif (!AttemptThrottle::takeResetSlot(getDB(), $email, (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
            // Own throttle (#9, Fable audit 2026-10-12): 3 per email / 10 per IP per hour, keyed 'reset:'
            // so it never locks a login. Same message as a real request.
            $message = 'If an account exists with that email, you will receive password reset instructions shortly.';
            $messageType = 'success';
        } else {
            $db = getDB();

            try {
                // Check if user exists
                $stmt = $db->prepare("SELECT id, email, full_name FROM users WHERE email = ? AND is_active = 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // Build reset URL from the site's own address — never the request's Host header,
                // which a caller controls (a forged Host would mail the token to their server).
                // Another business: only its REGISTERED address (Tenant::appUrl, bound to its hosts) — never a
                // settings value such as the website, which would mail the token elsewhere (Codex 2026-10-23).
                // The original business keeps SITE_URL. No address → no token and no email (same answer shown).
                $siteUrl = Tenant::isDefault()
                    ? (defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : 'https://mowology.ca')
                    : Tenant::appUrl();
                if ($user && $siteUrl === '') {
                    error_log('Forgot password: no registered address for business ' . Tenant::key() . ' - no link sent');
                    $user = false;
                }

                if ($user) {
                    // Generate secure reset token
                    $token = bin2hex(random_bytes(32));

                    // Store the new token; older links are removed only once this one has been sent (below), so a failed
                    // send never leaves the person with no working link (Codex review 2026-10-16).
                    $stmt = $db->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
                    $stmt->execute([$user['id'], hash('sha256', $token)]);

                    $resetUrl = $siteUrl . '/loginAuth/reset_password.php?token=' . $token;
                    $resetHref = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    // Send email
                    $to = $user['email'];
                    $subject = "Reset your password - " . $__bizName;

                    $emailBody = "
                    <html>
                    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #131D17;'>
                        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                            <h2 style='color: #131D17;'>Password Reset Request</h2>
                            <p>Hi " . htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') . ",</p>
                            <p>We received a request to reset your password for " . htmlspecialchars($__bizName, ENT_QUOTES, 'UTF-8') . ".</p>
                            <p>Click the button below to reset your password:</p>
                            <p style='margin: 30px 0;'>
                                <a href='{$resetHref}' style='background: #233A5E; color: #FFFFFF; padding: 14px 28px; text-decoration: none; border-radius: 8px; display: inline-block;'>Reset Password</a>
                            </p>
                            <p>Or copy and paste this link into your browser:</p>
                            <p style='word-break: break-all; color: #48534C;'>{$resetHref}</p>
                            <p><strong>This link will expire in 1 hour.</strong></p>
                            <p>If you didn't request this password reset, you can safely ignore this email.</p>
                            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
                            <p style='color: #48534C; font-size: 12px;'>" . htmlspecialchars($__bizName, ENT_QUOTES, 'UTF-8') . " · Ittai</p>
                        </div>
                    </body>
                    </html>
                    ";

                    // Through the messaging service (CLAUDE.md rule 9), not bare mail(); a failure is logged.
                    require_once CRM_INCLUDES . '/messaging.php';
                    if (sendCrmEmail($to, $subject, $emailBody)) {
                        $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND token <> ?")->execute([$user['id'], hash('sha256', $token)]);
                    } else {
                        error_log('Forgot password: reset email NOT sent to user #' . (int)$user['id']);
                    }

                    // Log activity
                    logActivity($user['id'], null, 'Password reset requested', "IP: " . $_SERVER['REMOTE_ADDR']);
                }

                // Always show success message (don't reveal if email exists)
                $message = 'If an account exists with that email, you will receive password reset instructions shortly.';
                $messageType = 'success';

            } catch(PDOException $e) {
                error_log("Forgot password error: " . $e->getMessage());
                $message = 'An error occurred. Please try again later.';
                $messageType = 'error';
            }
        }
    } else {
        $message = 'Invalid request. Please try again.';
        $messageType = 'error';
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php itSigninHead('Forgot password - ' . $__bizName); ?>
<?= ResetCaptcha::headTags() ?>
</head>
<body class="<?= itSigninBodyClass() ?>">
<main class="it-signin-wrap">
    <?php itSigninLockup(); ?>

    <div class="it-signin-card">
        <h1 class="it-signin-title">Forgot your password?</h1>
        <p class="it-signin-sub">Enter your email and we'll send a link to set a new one for <strong><?= htmlspecialchars($__bizName) ?></strong>.</p>

        <?php if ($message): ?>
            <div class="it-signin-msg it-signin-msg--<?php echo $messageType === 'success' ? 'success' : 'error'; ?>" role="<?php echo $messageType === 'success' ? 'status' : 'alert'; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="resetForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <div class="it-signin-field">
                <label class="it-signin-label" for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="it-signin-input"
                    required
                    autocomplete="email"
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                >
            </div>

            <button type="submit" class="it-signin-button">Send reset link</button>
            <?= ResetCaptcha::formTags('resetForm') ?>
        </form>

        <div class="it-signin-links">
            <a href="login.php" class="it-signin-link">Back to sign in</a>
        </div>
    </div>
</main>
</body>
</html>
