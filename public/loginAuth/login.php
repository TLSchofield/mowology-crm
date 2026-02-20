<?php
require_once __DIR__ . '/auth.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: ' . DASHBOARD_URL);
    exit();
}


$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['csrf_token']) && verifyCSRFToken($_POST['csrf_token'])) {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Normalize email: lowercase, strip invisible chars
        $email = strtolower($email);
        $email = preg_replace('/[\x00-\x1F\x7F\xC2\xA0]/u', '', $email);

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } elseif (isLoginRateLimited($email)) {
            $error = 'Too many login attempts. Please try again in a few minutes.';
        } else {
            if (loginUser($email, $password)) {
                header('Location: ' . DASHBOARD_URL);
                exit();
            } else {
                $error = 'Invalid email or password.';
            }
        }
    } else {
        $error = 'Invalid request. Please try again.';
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Mowology CRM</title>
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
        
        /* Animated background elements */
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
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
        
        .login-form {
            padding: 48px 40px;
        }
        
        .form-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--forest-dark);
            margin-bottom: 32px;
            text-align: center;
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
        
        .login-button:active {
            transform: translateY(0);
        }
        
        .login-footer {
            padding: 24px 40px;
            background: var(--mist-gray);
            text-align: center;
            font-size: 13px;
            color: var(--forest-main);
        }
        
        .default-creds {
            background: #FFF4D9;
            border: 2px solid #FFB800;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            font-size: 13px;
            line-height: 1.6;
        }
        
        .default-creds strong {
            display: block;
            color: #CC8800;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .default-creds code {
            background: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Space Mono', monospace;
            font-size: 12px;
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

        .forgot-password-link:hover {
            color: var(--grass-green);
        }

        .app-banner {
            background: linear-gradient(135deg, #1a3a2a 0%, var(--forest-dark) 100%);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            display: none;
            align-items: center;
            gap: 14px;
            color: var(--snow-white);
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 2px solid var(--lime-accent);
        }
        .app-banner:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.35);
            text-decoration: none;
            color: var(--snow-white);
        }
        .app-banner-icon {
            width: 48px;
            height: 48px;
            background: var(--lime-accent);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 26px;
        }
        .app-banner-text {
            flex: 1;
        }
        .app-banner-label {
            display: inline-block;
            background: var(--lime-accent);
            color: var(--forest-dark);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 2px 7px;
            border-radius: 4px;
            margin-bottom: 4px;
        }
        .app-banner-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 2px;
        }
        .app-banner-desc {
            font-size: 12px;
            opacity: 0.8;
        }
        .app-banner-arrow {
            font-size: 22px;
            opacity: 0.7;
        }
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

            <a href="/crm/downloads/mowology-crew.apk" id="appBanner" class="app-banner" download>
                <div class="app-banner-icon">🤖</div>
                <div class="app-banner-text">
                    <span class="app-banner-label">Android App</span>
                    <div class="app-banner-title">Download Mowology Crew App</div>
                    <div class="app-banner-desc">Install the native Android app for GPS tracking &amp; time clock</div>
                </div>
                <div class="app-banner-arrow">⬇</div>
            </a>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
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

            <a href="forgot_password.php" class="forgot-password-link">Forgot your password?</a>
        </form>

        <div class="login-footer">
            <p>Phase 1: Foundation Complete ✓<br>Secure authentication system active</p>
        </div>
    </div>
<script>
// Show Android APK download banner — only on Android browsers (not inside the native Capacitor app)
(function() {
    var ua = navigator.userAgent || '';
    var isAndroid = /Android/i.test(ua);
    var isCapacitor = window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform();
    if (isAndroid && !isCapacitor) {
        var banner = document.getElementById('appBanner');
        if (banner) banner.style.display = 'flex';
    }
})();
</script>
</body>
</html>
