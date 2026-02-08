<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Debug marker (remove later if you want)
echo "<pre>REACHED: " . __FILE__ . "\n</pre>";

// Load shared auth (this file itself loads /app_config/session_config.php + /app_config/config.php)
require_once dirname(__DIR__) . '/auth.php';

// If already logged in, go to CRM dashboard
if (is_logged_in()) {
    header('Location: /crm/dashboard.php');
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF (uses csrf_token()/csrf_verify() from app_config/config.php)
    $csrf = $_POST['csrf'] ?? '';
    if (!function_exists('csrf_verify') || !csrf_verify($csrf)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Please enter both email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Attempt login against a recommended `users` table
            // Fields expected: id, email, password_hash, role, status, name (optional)
            try {
                $db = Database::pdo();

                $stmt = $db->prepare("
                    SELECT id, email, password_hash, role, status, name
                    FROM users
                    WHERE email = ?
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                $ok = false;

                if ($row && isset($row['password_hash'])) {
                    $status = (string)($row['status'] ?? 'active');
                    if ($status === 'active' && password_verify($password, (string)$row['password_hash'])) {
                        $ok = true;

                        // Session fixation protection
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            session_regenerate_id(true);
                        }

                        $_SESSION['auth'] = [
                            'user_id'  => (int)$row['id'],
                            'role'     => (string)($row['role'] ?? 'staff'),
                            'email'    => (string)$row['email'],
                            'status'   => $status,
                            'login_at' => time(),
                            'name'     => (string)($row['name'] ?? ''),
                        ];

                        // Redirect based on role policy
                        header('Location: ' . auth_redirect_after_login());
                        exit;
                    }
                }

                // Uniform error to avoid email enumeration
                if (!$ok) {
                    $error = 'Invalid email or password.';
                }

            } catch (Throwable $e) {
                error_log("Login error: " . $e->getMessage());
                $error = 'Server error. Please try again.';
            }
        }
    }
}

// CSRF token for the form
$csrf_token = function_exists('csrf_token') ? csrf_token() : '';
// Escape helper
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Mowology CRM</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
            width: 500px; height: 500px;
            background: radial-gradient(circle, var(--grass-green) 0%, transparent 70%);
            top: -200px; right: -200px;
            opacity: 0.3;
            animation: float 8s ease-in-out infinite;
        }
        body::after {
            content: '';
            position: absolute;
            width: 400px; height: 400px;
            background: radial-gradient(circle, var(--lime-accent) 0%, transparent 70%);
            bottom: -150px; left: -150px;
            opacity: 0.2;
            animation: float 10s ease-in-out infinite reverse;
        }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(30px, -30px) scale(1.1); }
        }
        .login-container {
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
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .login-header {
            background: linear-gradient(135deg, var(--grass-green) 0%, var(--forest-main) 100%);
            padding: 48px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .login-header::before {
            content: '🌱';
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
        .login-form { padding: 48px 40px; }
        .form-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--forest-dark);
            margin-bottom: 32px;
            text-align: center;
        }
        .form-group { margin-bottom: 24px; }
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
        .error-message {
            background: #FEE;
            color: #C33;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            border-left: 4px solid #C33;
            animation: shake 0.4s ease;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        .login-button {
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
        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px var(--shadow);
        }
        .login-button:active { transform: translateY(0); }
        .login-footer {
            padding: 24px 40px;
            background: var(--mist-gray);
            text-align: center;
            font-size: 13px;
            color: var(--forest-main);
        }
        .forgot-password-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--forest-main);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        .forgot-password-link:hover { color: var(--grass-green); }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">Mowology</div>
            <div class="tagline">Client Management System</div>
        </div>

        <form class="login-form" method="POST" action="">
            <h2 class="form-title">Welcome Back</h2>

            <?php if ($error): ?>
                <div class="error-message"><?= h($error) ?></div>
            <?php endif; ?>

            <input type="hidden" name="csrf" value="<?= h($csrf_token) ?>">

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
                    value="<?= isset($_POST['email']) ? h((string)$_POST['email']) : '' ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-input"
                    required
                    autocomplete="current-password"
                    placeholder="Enter your password"
                >
            </div>

            <button type="submit" class="login-button">Sign In</button>

            <a href="/loginAuth/forms/forgot_password.php" class="forgot-password-link">Forgot your password?</a>
        </form>

        <div class="login-footer">
            <p>Phase 1: Foundation Complete ✓<br>Secure authentication system active</p>
        </div>
    </div>
</body>
</html>
