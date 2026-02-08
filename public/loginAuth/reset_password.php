<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$message = '';
$messageType = '';
$validToken = false;

// Always read token from GET first, then POST (so form submit still knows the token)
$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$token = is_string($token) ? trim($token) : '';

$tokenData = null;
$db = null;

if ($token === '') {
    $message = 'Invalid or missing reset token.';
    $messageType = 'error';
} else {
    try {
        $db = getDB();

        // Check if token is valid and not expired
        $stmt = $db->prepare("
            SELECT prt.user_id, prt.expires_at, u.email, u.full_name
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ? AND prt.expires_at > NOW() AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([hash('sha256', $token)]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tokenData) {
            $validToken = true;
        } else {
            $message = 'This reset link is invalid or has expired. Please request a new one.';
            $messageType = 'error';
        }
    } catch (Throwable $e) {
        error_log("Reset token check error: " . $e->getMessage());
        $message = 'An error occurred. Please try again.';
        $messageType = 'error';
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken && $tokenData && $db) {
    if (isset($_POST['csrf_token']) && verifyCSRFToken((string)$_POST['csrf_token'])) {

        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($password === '' || $confirmPassword === '') {
            $message = 'Please fill in both password fields.';
            $messageType = 'error';
        } elseif (strlen($password) < 12) {
            $message = 'Password must be at least 12 characters long.';
            $messageType = 'error';
        } elseif ($password !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $messageType = 'error';
        } else {
            try {
                $db->beginTransaction();

                // Update password
                $hashedPassword = hashPassword($password);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1");
                $stmt->execute([$hashedPassword, (int)$tokenData['user_id']]);

                // Delete the used token (or all tokens for that user)
                $stmt = $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? LIMIT 10");
                $stmt->execute([(int)$tokenData['user_id']]);

                $db->commit();

                // Log activity
                logActivity((int)$tokenData['user_id'], null, 'Password reset completed', "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                $message = 'Your password has been reset successfully! You can now log in with your new password.';
                $messageType = 'success';
                $validToken = false; // Hide the form after success

            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log("Password reset error: " . $e->getMessage());
                $message = 'An error occurred while resetting your password. Please try again.';
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
    <title>Reset Password - Mowology CRM</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* (KEEP YOUR EXISTING CSS AS-IS) */
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --forest-dark:#0D3B2E; --forest-main:#1A5F4A; --grass-green:#2D8659;
            --lime-accent:#7FD858; --earth-brown:#8B5A3C; --snow-white:#F8FFFE;
            --mist-gray:#E8F3F0; --shadow:rgba(13, 59, 46, 0.12);
        }
        body {
            font-family:'DM Sans',-apple-system,BlinkMacSystemFont,sans-serif;
            background:linear-gradient(135deg,var(--forest-dark) 0%,var(--forest-main) 100%);
            min-height:100vh; display:flex; align-items:center; justify-content:center;
            position:relative; overflow:hidden;
        }
        body::before {
            content:''; position:absolute; width:500px; height:500px;
            background:radial-gradient(circle,var(--grass-green) 0%,transparent 70%);
            top:-200px; right:-200px; opacity:.3; animation:float 8s ease-in-out infinite;
        }
        body::after {
            content:''; position:absolute; width:400px; height:400px;
            background:radial-gradient(circle,var(--lime-accent) 0%,transparent 70%);
            bottom:-150px; left:-150px; opacity:.2; animation:float 10s ease-in-out infinite reverse;
        }
        @keyframes float { 0%,100%{transform:translate(0,0) scale(1)} 50%{transform:translate(30px,-30px) scale(1.1)} }
        .container{background:var(--snow-white); border-radius:24px; box-shadow:0 20px 60px rgba(0,0,0,.3);
            overflow:hidden; width:100%; max-width:440px; position:relative; z-index:1; animation:slideUp .6s ease-out;}
        @keyframes slideUp{from{opacity:0; transform:translateY(30px)} to{opacity:1; transform:translateY(0)}}
        .header{background:linear-gradient(135deg,var(--grass-green) 0%,var(--forest-main) 100%);
            padding:48px 40px; text-align:center; position:relative; overflow:hidden;}
        .header::before{content:'🔐'; font-size:80px; position:absolute; top:10px; right:20px; opacity:.15; transform:rotate(-15deg);}
        .logo{font-family:'Space Mono',monospace; font-size:32px; font-weight:700; color:var(--snow-white);
            letter-spacing:-1px; margin-bottom:8px; text-transform:uppercase;}
        .tagline{color:var(--lime-accent); font-size:14px; font-weight:500; letter-spacing:2px; text-transform:uppercase;}
        .form-wrapper{padding:48px 40px;}
        .form-title{font-size:24px; font-weight:700; color:var(--forest-dark); margin-bottom:12px; text-align:center;}
        .form-subtitle{font-size:14px; color:#666; text-align:center; margin-bottom:32px; line-height:1.5;}
        .form-group{margin-bottom:24px;}
        .form-label{display:block; font-size:13px; font-weight:600; color:var(--forest-main);
            margin-bottom:8px; text-transform:uppercase; letter-spacing:.5px;}
        .form-input{width:100%; padding:14px 16px; border:2px solid var(--mist-gray); border-radius:12px;
            font-size:15px; font-family:'DM Sans',sans-serif; transition:all .3s ease; background:var(--snow-white);}
        .form-input:focus{outline:none; border-color:var(--grass-green); box-shadow:0 0 0 4px rgba(45,134,89,.1);}
        .message{padding:14px 16px; border-radius:12px; margin-bottom:24px; font-size:14px; font-weight:500;}
        .message-error{background:#FEE; color:#C33; border-left:4px solid #C33;}
        .message-success{background:#E8F5E9; color:#2E7D32; border-left:4px solid #2E7D32;}
        .submit-button{width:100%; padding:16px; background:linear-gradient(135deg,var(--grass-green) 0%,var(--forest-main) 100%);
            color:var(--snow-white); border:none; border-radius:12px; font-size:16px; font-weight:700; cursor:pointer;
            transition:all .3s ease; text-transform:uppercase; letter-spacing:1px; box-shadow:0 4px 12px var(--shadow);}
        .submit-button:hover{transform:translateY(-2px); box-shadow:0 6px 20px var(--shadow);}
        .submit-button:active{transform:translateY(0);}
        .password-requirements{background:var(--mist-gray); padding:12px 16px; border-radius:8px; margin-bottom:24px; font-size:13px; color:#666;}
        .password-requirements strong{color:var(--forest-dark); display:block; margin-bottom:8px;}
        .back-link{display:block; text-align:center; margin-top:24px; color:var(--forest-main); text-decoration:none; font-weight:500; transition:color .3s;}
        .back-link:hover{color:var(--grass-green);}
        .footer{padding:24px 40px; background:var(--mist-gray); text-align:center; font-size:13px; color:var(--forest-main);}
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">Mowology</div>
        <div class="tagline">Client Management System</div>
    </div>

    <div class="form-wrapper">
        <h2 class="form-title">Reset Password</h2>
        <p class="form-subtitle">Create a new secure password for your account.</p>

        <?php if ($message): ?>
            <div class="message message-<?php echo htmlspecialchars($messageType); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($validToken): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="password-requirements">
                    <strong>Password Requirements:</strong>
                    - At least 12 characters long<br>
                    - Use a mix of words, numbers, and symbols
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">New Password</label>
                    <input type="password" id="password" name="password" class="form-input"
                           required minlength="12" autocomplete="new-password" placeholder="Enter new password">
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                           required minlength="12" autocomplete="new-password" placeholder="Confirm new password">
                </div>

                <button type="submit" class="submit-button">Reset Password</button>
            </form>
        <?php else: ?>
            <a href="login.php" class="back-link">Go to Login &rarr;</a>
        <?php endif; ?>

        <?php if ($validToken): ?>
            <a href="forgot_password.php" class="back-link">Request a new reset link</a>
        <?php endif; ?>
    </div>

    <div class="footer">
        <p>Remember your password? <a href="login.php" style="color: var(--grass-green);">Sign in</a></p>
    </div>
</div>
</body>
</html>
