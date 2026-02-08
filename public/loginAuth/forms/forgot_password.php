<?php
<?php
require_once __DIR__ . '/auth.php';


// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
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
        } else {
            $db = getDB();

            try {
                // Check if user exists
                $stmt = $db->prepare("SELECT id, email, full_name FROM users WHERE email = ? AND is_active = 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    // Generate secure reset token
                    $token = bin2hex(random_bytes(32));

                    // Delete any existing tokens for this user
                    $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$user['id']]);

                    // Store new token - use MySQL's NOW() + INTERVAL for consistent timezone
                    $stmt = $db->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
                    $stmt->execute([$user['id'], hash('sha256', $token)]);

                    // Build reset URL
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'];
                    $path = dirname($_SERVER['REQUEST_URI']);
                    $resetUrl = "{$protocol}://{$host}{$path}/reset_password_token.php?token={$token}";

                    // Send email
                    $to = $user['email'];
                    $subject = "Password Reset Request - Mowology CRM";
                    $headers = "From: Mowology CRM <noreply@mowology.ca>\r\n";
                    $headers .= "Reply-To: noreply@mowology.ca\r\n";
                    $headers .= "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

                    $emailBody = "
                    <html>
                    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                            <h2 style='color: #1A5F4A;'>Password Reset Request</h2>
                            <p>Hi {$user['full_name']},</p>
                            <p>We received a request to reset your password for the Mowology CRM system.</p>
                            <p>Click the button below to reset your password:</p>
                            <p style='margin: 30px 0;'>
                                <a href='{$resetUrl}' style='background: #2D8659; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; display: inline-block;'>Reset Password</a>
                            </p>
                            <p>Or copy and paste this link into your browser:</p>
                            <p style='word-break: break-all; color: #666;'>{$resetUrl}</p>
                            <p><strong>This link will expire in 1 hour.</strong></p>
                            <p>If you didn't request this password reset, you can safely ignore this email.</p>
                            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
                            <p style='color: #999; font-size: 12px;'>Mowology CRM System</p>
                        </div>
                    </body>
                    </html>
                    ";

                    mail($to, $subject, $emailBody, $headers);

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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Mowology CRM</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --forest-dark: #0D3B2E;
            --forest-main: #1A5F4A;
            --grass-green: #2D8659;
            --lime-accent: #7FD858;
            --earth-brown: #8B5A3C;
            --snow-white: #F8FFFE;
            --mist-gray: #E8F3F0;
            --shadow: rgba(13, 59, 46, 0.12);
        }

        body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, var(--forest-dark) 0%, var(--forest-main) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, var(--grass-green) 0%, transparent 70%);
            top: -200px;
            right: -200px;
            opacity: 0.3;
            animation: float 8s ease-in-out infinite;
        }

        body::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, var(--lime-accent) 0%, transparent 70%);
            bottom: -150px;
            left: -150px;
            opacity: 0.2;
            animation: float 10s ease-in-out infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(30px, -30px) scale(1.1); }
        }

        .container {
            background: var(--snow-white);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            background: linear-gradient(135deg, var(--grass-green) 0%, var(--forest-main) 100%);
            padding: 48px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '🔑';
            font-size: 80px;
            position: absolute;
            top: 10px;
            right: 20px;
            opacity: 0.15;
            transform: rotate(-15deg);
        }

        .logo {
            font-family: 'Space Mono', monospace;
            font-size: 32px;
            font-weight: 700;
            color: var(--snow-white);
            letter-spacing: -1px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .tagline {
            color: var(--lime-accent);
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .form-wrapper {
            padding: 48px 40px;
        }

        .form-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--forest-dark);
            margin-bottom: 12px;
            text-align: center;
        }

        .form-subtitle {
            font-size: 14px;
            color: #666;
            text-align: center;
            margin-bottom: 32px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--forest-main);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--mist-gray);
            border-radius: 12px;
            font-size: 15px;
            font-family: 'DM Sans', sans-serif;
            transition: all 0.3s ease;
            background: var(--snow-white);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--grass-green);
            box-shadow: 0 0 0 4px rgba(45, 134, 89, 0.1);
        }

        .message {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }

        .message-error {
            background: #FEE;
            color: #C33;
            border-left: 4px solid #C33;
        }

        .message-success {
            background: #E8F5E9;
            color: #2E7D32;
            border-left: 4px solid #2E7D32;
        }

        .submit-button {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--grass-green) 0%, var(--forest-main) 100%);
            color: var(--snow-white);
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 12px var(--shadow);
        }

        .submit-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px var(--shadow);
        }

        .submit-button:active {
            transform: translateY(0);
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 24px;
            color: var(--forest-main);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .back-link:hover {
            color: var(--grass-green);
        }

        .footer {
            padding: 24px 40px;
            background: var(--mist-gray);
            text-align: center;
            font-size: 13px;
            color: var(--forest-main);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">Mowology</div>
            <div class="tagline">Client Management System</div>
        </div>

        <div class="form-wrapper">
            <h2 class="form-title">Forgot Password?</h2>
            <p class="form-subtitle">No worries! Enter your email address and we'll send you instructions to reset your password.</p>

            <?php if ($message): ?>
                <div class="message message-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input"
                        required
                        autocomplete="email"
                        placeholder="your@email.com"
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                    >
                </div>

                <button type="submit" class="submit-button">Send Reset Link</button>
            </form>

            <a href="login.php" class="back-link">&larr; Back to Login</a>
        </div>

        <div class="footer">
            <p>Remember your password? <a href="login.php" style="color: var(--grass-green);">Sign in</a></p>
        </div>
    </div>
</body>
</html>
