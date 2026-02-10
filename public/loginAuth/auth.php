<?php
declare(strict_types=1);

/**
 * /loginAuth/auth.php
 * Central auth bootstrap for Mowology.
 *
 * Contract:
 * - Pages in /loginAuth MUST include only this file (never config.php directly).
 * - Sessions are started only by /app_config/session_config.php.
 * - Redirects use web-root absolute paths so calls from /crm/* work.
 */

$__baseDir = dirname(__DIR__);

// --- Locate app_config files reliably (one level up from /loginAuth) ---
$__sessionCandidates = [
    $__baseDir . '/app_config/session_config.php',
    $__baseDir . '/session_config.php',
];

$__configCandidates = [
    $__baseDir . '/app_config/config.php',
    $__baseDir . '/config.php',
];

$__sessionPath = null;
foreach ($__sessionCandidates as $__p) {
    if (is_file($__p)) { $__sessionPath = $__p; break; }
}

$__configPath = null;
foreach ($__configCandidates as $__p) {
    if (is_file($__p)) { $__configPath = $__p; break; }
}

if (!$__sessionPath || !$__configPath) {
    http_response_code(500);
    die('Server configuration error: missing app_config/session_config.php or app_config/config.php');
}

require_once $__sessionPath;
require_once $__configPath;

// Backwards-compatible DB accessor (legacy pages call getDB())
if (!function_exists('getDB')) {
    if (class_exists('Database') && method_exists('Database', 'pdo')) {
        function getDB(): PDO { return Database::pdo(); }
    } else {
        http_response_code(500);
        die('Config loaded, but Database::pdo() missing (cannot provide getDB()).');
    }
}

/**
 * Web-root absolute URLs so redirects work from /crm/* or anywhere.
 * Update DASHBOARD_URL to your real dashboard location.
 */
if (!defined('LOGIN_URL'))     define('LOGIN_URL',     '/loginAuth/login.php');
if (!defined('LOGOUT_URL'))    define('LOGOUT_URL',    '/loginAuth/logout.php');
if (!defined('DASHBOARD_URL')) define('DASHBOARD_URL', '/crm/dashboard_appstack.php');

// Escape for HTML output
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

// Sanitize input for basic form usage (NOT for passwords)
if (!function_exists('sanitizeInput')) {
    function sanitizeInput(string $value): string {
        return trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }
}

// -------------------- Auth helpers --------------------

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * REQUIRED by protected pages.
 * Redirects to LOGIN_URL if not authenticated.
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . LOGIN_URL);
        exit();
    }
}

/**
 * Optional: idle timeout (default 30 minutes).
 */
function checkSessionTimeout(int $timeout = 1800): void {
    if (isLoggedIn()) {
        $last = (int)($_SESSION['last_activity'] ?? 0);
        if ($last > 0 && (time() - $last) > $timeout) {
            logoutUser();
            header('Location: ' . LOGIN_URL . '?timeout=1');
            exit();
        }
        $_SESSION['last_activity'] = time();
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;

    return [
        'id'    => (int)$_SESSION['user_id'],
        'email' => (string)($_SESSION['user_email'] ?? ''),
        'name'  => (string)($_SESSION['user_name'] ?? ''),
        'role'  => (string)($_SESSION['user_role'] ?? 'staff'),
    ];
}

function isAdmin(): bool {
    $u = getCurrentUser();
    return $u && ($u['role'] === 'admin');
}

// -------------------- Login / Logout --------------------

function loginUser(string $email, string $password): bool {
    $db = getDB();

    try {
        $stmt = $db->prepare("
            SELECT id, email, password_hash, full_name, role, is_active
            FROM users
            WHERE email = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && isset($user['password_hash']) && password_verify($password, (string)$user['password_hash'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_email'] = (string)$user['email'];
            $_SESSION['user_name'] = (string)($user['full_name'] ?? '');
            $_SESSION['user_role'] = (string)($user['role'] ?? 'staff');
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();

            // Update last login
            $upd = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ? LIMIT 1");
            $upd->execute([(int)$user['id']]);

            // Log
            logActivity((int)$user['id'], null, 'User logged in', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

            return true;
        }

        // Failed login attempt (if user exists)
        if ($user && isset($user['id'])) {
            logActivity((int)$user['id'], null, 'Failed login attempt', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        }

        return false;
    } catch (Throwable $e) {
        error_log("loginUser() error: " . $e->getMessage());
        return false;
    }
}

function logoutUser(): void {
    if (isset($_SESSION['user_id'])) {
        logActivity((int)$_SESSION['user_id'], null, 'User logged out', null);
    }

    $_SESSION = [];

    if (isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

// -------------------- Security helpers --------------------

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

function generateCSRFToken(): string {
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verifyCSRFToken(string $token): bool {
    return isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// -------------------- Activity log --------------------

function logActivity(int $user_id, $client_id, string $action, ?string $details = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, client_id, action, details, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $client_id,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        error_log("logActivity() error: " . $e->getMessage());
    }
}

