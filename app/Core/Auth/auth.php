<?php
declare(strict_types=1);

/**
 * /app/Core/Auth/auth.php
 * Central auth bootstrap for Mowology.
 *
 * Contract:
 * - Pages in /loginAuth MUST include only this file (never config.php directly).
 * - Sessions are started only by session_config.php.
 * - Redirects use web-root absolute paths so calls from /crm/* work.
 * - /public/loginAuth/auth.php is a compatibility shim that forwards here.
 *
 * Migrated from /public/loginAuth/auth.php
 */

// Ensure path constants are available
if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__) . '/paths.php';
}

// --- Load session and config from /app/Core/ (canonical locations) ---
require_once APP_ROOT . '/Core/session_config.php';
require_once APP_ROOT . '/Core/config.php';

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
if (!defined('DASHBOARD_URL')) define('DASHBOARD_URL', '/crm/jobs/schedule.php');

// Rate-limit constants
if (!defined('LOGIN_MAX_ATTEMPTS'))  define('LOGIN_MAX_ATTEMPTS', 5);
if (!defined('LOGIN_WINDOW_SECONDS')) define('LOGIN_WINDOW_SECONDS', 600); // 10 minutes

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
        'id'         => (int)$_SESSION['user_id'],
        'email'      => (string)($_SESSION['user_email'] ?? ''),
        'name'       => (string)($_SESSION['user_name'] ?? ''),
        'role'       => (string)($_SESSION['user_role'] ?? 'user'),
        'first_name' => (string)($_SESSION['user_first_name'] ?? ''),
        'last_name'  => (string)($_SESSION['user_last_name'] ?? ''),
        'full_name'  => (string)($_SESSION['user_full_name'] ?? $_SESSION['user_name'] ?? ''),
        'is_driver'  => (bool)($_SESSION['user_is_driver'] ?? false),
    ];
}

function isAdmin(): bool {
    $u = getCurrentUser();
    return $u && ($u['role'] === 'admin');
}

// -------------------- Rate limiting --------------------

/**
 * Check whether the email+IP combination has exceeded the login attempt limit.
 * Uses the login_attempts table (migration 401).
 *
 * @return bool true = blocked (too many attempts), false = allowed
 */
function isLoginRateLimited(string $email, ?string $ip = null): bool {
    $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE email = ? AND ip_address = ?
              AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$email, $ip, LOGIN_WINDOW_SECONDS]);
        return (int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
    } catch (Throwable $e) {
        // If the table doesn't exist yet, fail open (don't block logins)
        error_log("isLoginRateLimited() error: " . $e->getMessage());
        return false;
    }
}

/**
 * Record a failed login attempt.
 */
function recordFailedLogin(string $email, ?string $ip = null): void {
    $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)");
        $stmt->execute([$email, $ip]);
    } catch (Throwable $e) {
        error_log("recordFailedLogin() error: " . $e->getMessage());
    }
}

/**
 * Clear login attempts for an email+IP after successful login.
 */
function clearLoginAttempts(string $email, ?string $ip = null): void {
    $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    try {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE email = ? AND ip_address = ?");
        $stmt->execute([$email, $ip]);
    } catch (Throwable $e) {
        error_log("clearLoginAttempts() error: " . $e->getMessage());
    }
}

/**
 * Purge expired login attempts (older than the rate-limit window).
 * Call periodically (e.g., from cron) to keep the table small.
 */
function purgeExpiredLoginAttempts(): int {
    try {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([LOGIN_WINDOW_SECONDS]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log("purgeExpiredLoginAttempts() error: " . $e->getMessage());
        return 0;
    }
}

// -------------------- Login / Logout --------------------

function loginUser(string $email, string $password): bool {
    $db = getDB();

    // Normalize email: trim, lowercase, strip invisible chars
    $email = strtolower(trim($email));
    $email = preg_replace('/[\x00-\x1F\x7F\xC2\xA0]/u', '', $email);

    try {
        // Fetch user without is_active filter — check it separately so NULL is treated as active
        $stmt = $db->prepare("
            SELECT id, email, password_hash, full_name, first_name, last_name, role, is_active, is_driver
            FROM users
            WHERE LOWER(email) = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Explicitly deactivated users (is_active = 0) cannot log in; NULL/1 are both OK
        if ($user && isset($user['is_active']) && (int)$user['is_active'] === 0) {
            $user = false;
        }

        if ($user && isset($user['password_hash']) && password_verify($password, (string)$user['password_hash'])) {
            session_regenerate_id(true);

            $_SESSION['user_id']         = (int)$user['id'];
            $_SESSION['user_email']      = (string)$user['email'];
            $_SESSION['user_name']       = (string)($user['full_name'] ?? '');
            $_SESSION['user_role']       = (string)($user['role'] ?: 'user');
            $_SESSION['user_first_name'] = (string)($user['first_name'] ?? '');
            $_SESSION['user_last_name']  = (string)($user['last_name'] ?? '');
            $_SESSION['user_full_name']  = (string)($user['full_name'] ?? '');
            $_SESSION['user_is_driver']  = !empty($user['is_driver']);
            $_SESSION['login_time']      = time();
            $_SESSION['last_activity']   = time();

            // Update last login
            $upd = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ? LIMIT 1");
            $upd->execute([(int)$user['id']]);

            // Clear rate-limit records on success
            clearLoginAttempts($email);

            // Log
            logActivity((int)$user['id'], null, 'User logged in', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

            return true;
        }

        // Record the failed attempt for rate limiting
        recordFailedLogin($email);

        // Also log to activity_log if the user account exists
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

// -------------------- RBAC authorization (permissions) --------------------

require_once __DIR__ . '/authz.php';

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

// -------------------- Snake_case compatibility wrappers --------------------
// loginAuth_ARCHITECTURE.md documents helpers in snake_case.
// Canonical functions above are camelCase. These wrappers bridge the gap
// so either naming convention works.

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool { return isLoggedIn(); }
}
if (!function_exists('require_login')) {
    function require_login(): void { requireLogin(); }
}
if (!function_exists('current_user')) {
    function current_user(): ?array { return getCurrentUser(); }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool { return isAdmin(); }
}
if (!function_exists('login_user')) {
    function login_user(string $email, string $password): bool { return loginUser($email, $password); }
}
if (!function_exists('logout_user')) {
    function logout_user(): void { logoutUser(); }
}
if (!function_exists('hash_password')) {
    function hash_password(string $password): string { return hashPassword($password); }
}
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token(): string { return generateCSRFToken(); }
}
if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(string $token): bool { return verifyCSRFToken($token); }
}
if (!function_exists('log_activity')) {
    function log_activity(int $user_id, $client_id, string $action, ?string $details = null): void {
        logActivity($user_id, $client_id, $action, $details);
    }
}
if (!function_exists('check_session_timeout')) {
    function check_session_timeout(int $timeout = 1800): void { checkSessionTimeout($timeout); }
}
if (!function_exists('is_login_rate_limited')) {
    function is_login_rate_limited(string $email, ?string $ip = null): bool { return isLoginRateLimited($email, $ip); }
}
if (!function_exists('record_failed_login')) {
    function record_failed_login(string $email, ?string $ip = null): void { recordFailedLogin($email, $ip); }
}
if (!function_exists('clear_login_attempts')) {
    function clear_login_attempts(string $email, ?string $ip = null): void { clearLoginAttempts($email, $ip); }
}

// ── Global exception handler ──────────────────────────────────────────────────
// Catches any unhandled Throwable (DB errors, require failures, etc.) across
// the entire CRM.  Logs a structured entry via error_log() and — for API
// endpoints — returns a JSON error instead of a raw PHP fatal page.
// Registered once here so every page that loads auth.php gets it automatically.
set_exception_handler(function (Throwable $e): void {
    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    $msg  = $e->getMessage();
    $loc  = $e->getFile() . ':' . $e->getLine();
    $user = (isset($_SESSION['user_email']) ? $_SESSION['user_email'] : 'guest');

    error_log("[CRM FATAL] $msg | $loc | user=$user | uri=$uri");

    if (headers_sent()) {
        exit(1);
    }

    // API endpoints → JSON so JS callers get a parseable response
    if (strpos($uri, '/api/') !== false || strpos($uri, '/crm/api/') !== false) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
        exit(1);
    }

    // HTML pages → plain message (display_errors is Off in production)
    http_response_code(500);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">'
       . '<h2>Something went wrong</h2>'
       . '<p>An unexpected error occurred. Please try again or contact support.</p>'
       . '</body></html>';
    exit(1);
});
