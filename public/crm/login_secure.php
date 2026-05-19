<?php
require_once __DIR__ . '/../loginAuth/auth.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: dashboard_appstack.php');
    exit();
}

$error   = '';
$jwtData = null; // set on successful login to inject into page

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
        } else {
            if (loginUser($email, $password)) {
                // Issue a 30-day JWT for offline/mobile use.
                // The JS below stores it in localStorage and redirects to dashboard.
                $user = getCurrentUser();
                if ($user) {
                    require_once dirname(__DIR__, 2) . '/app/Core/Auth/JwtService.php';
                    $ttl   = 30 * 24 * 3600; // 30 days
                    $token = generateMowologyJwt(
                        (int)$user['id'],
                        (string)$user['email'],
                        (string)$user['name'],
                        (string)$user['role'],
                        $ttl
                    );
                    $jwtData = [
                        'token' => $token,
                        'user'  => $user,
                        'exp'   => time() + $ttl,
                    ];
                } else {
                    header('Location: dashboard_appstack.php');
                    exit();
                }
            } else {
                $error = 'Invalid email or password.';
            }
        }
    } else {
        $error = 'Invalid security token. Please try again.';
    }
}

$csrf_token = generateCSRFToken();

// If login succeeded, render a tiny redirect page that stores the JWT first
if ($jwtData !== null):
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Logging in…</title></head>
<body>
<script>
(function() {
    try {
        var d = <?= json_encode($jwtData) ?>;
        localStorage.setItem('mw_jwt', JSON.stringify(d));
    } catch(e) {}
    window.location.replace('dashboard_appstack.php');
})();
</script>
<noscript><meta http-equiv="refresh" content="0;url=dashboard_appstack.php"></noscript>
</body>
</html>
<?php
    exit();
endif;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Mowology CRM</title>

    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon/favicon-16x16.png">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --mw-forest: #0D3B2E;
            --mw-dark: #1A5F4A;
            --mw-green: #2D8659;
            --mw-lime: #7FD858;
            --mw-light: #E8F3F0;
            --snow-white: #F8FFFE;
            --shadow: rgba(13, 59, 46, 0.12);
        }
        
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, var(--mw-forest) 0%, var(--mw-dark) 100%);
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
            background: radial-gradient(circle, var(--mw-green) 0%, transparent 70%);
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
            background: radial-gradient(circle, var(--mw-lime) 0%, transparent 70%);
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
            background: linear-gradient(135deg, var(--mw-green) 0%, var(--mw-dark) 100%);
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
            font-family: 'Montserrat', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: var(--snow-white);
            letter-spacing: -1px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        
        .tagline {
            color: var(--mw-lime);
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
            color: var(--mw-forest);
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
            color: var(--mw-dark);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--mw-light);
            border-radius: 12px;
            font-size: 15px;
            font-family: 'Montserrat', sans-serif;
            transition: all 0.3s ease;
            background: var(--snow-white);
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--mw-green);
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
            background: linear-gradient(135deg, var(--mw-green) 0%, var(--mw-dark) 100%);
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
            background: var(--mw-light);
            text-align: center;
            font-size: 13px;
            color: var(--mw-dark);
        }
        
        .security-badge {
            display: inline-block;
            background: #d4edda;
            color: #155724;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
    </style>
    <!-- JWT manager: fires offline-login bypass before the form renders -->
    <script src="/crm/js/mw-auth.js?v=20260519a"></script>
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
        </form>
        
        <div class="login-footer">
            <span class="security-badge">🔒 Secure Sessions Active</span>
            <p style="margin-top: 12px; font-size: 11px; opacity: 0.7;">Step 1: Custom PHP Sessions Complete ✓</p>
        </div>
    </div>
</body>
</html>
