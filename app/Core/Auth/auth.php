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

// Auth error logger — writes to the PHP error log. The extra file copy in /storage/ is written ONLY
// when LOGIN_DEBUG is defined (e.g. in secrets.php while chasing a login bug). Never log raw login
// input or anything derived from the password (security audit 2026-10-12 #24: the old per-attempt
// trace recorded every identifier, IP and password length, unbounded).
if (!function_exists('_loginDebugLog')) {
    function _loginDebugLog(string $msg): void {
        error_log($msg);
        if (!defined('LOGIN_DEBUG') || !LOGIN_DEBUG) {
            return;
        }
        try {
            $path = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 3) . '/public') . '/storage/login-debug.log';
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
            @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // never let logging break auth
        }
    }
}

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

require_once __DIR__ . '/AuthFreshness.php';
require_once __DIR__ . '/RoleRules.php';

function isLoggedIn(): bool {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) return false;
    _recheckSessionUser();
    return !empty($_SESSION['user_id']);
}

/**
 * Re-read the signed-in user's role and active flag at most every AuthFreshness::RECHECK_SECONDS
 * (Fable review 2026-10-12): deactivating or demoting someone now reaches their open sessions
 * within a minute instead of at their next sign-in. A database error leaves the session alone
 * (and tries again on the next request) — a hiccup must not sign the office out.
 */
function _recheckSessionUser(): void {
    $now = time();
    if (!AuthFreshness::recheckDue((int)($_SESSION['user_checked_at'] ?? 0), $now)) return;
    try {
        $st = getDB()->prepare("SELECT role, is_active, is_driver, password_hash FROM users WHERE id = ? LIMIT 1");
        $st->execute([(int)$_SESSION['user_id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('_recheckSessionUser: user check failed: ' . $e->getMessage());
        return;
    }
    $verdict = AuthFreshness::sessionVerdict($row, (string)($_SESSION['user_role'] ?? 'user'), !empty($_SESSION['user_is_driver']));
    if ($verdict === 'logout') {
        error_log('_recheckSessionUser: user ' . (int)$_SESSION['user_id'] . ' is inactive or gone — session ended');
        $_SESSION = [];
        return;
    }
    // A password change (reset, admin set) ends every other web session (#12, Fable audit 2026-10-12).
    $pwVerdict = AuthFreshness::sessionPasswordVerdict(
        isset($_SESSION['pwd_v']) ? (string)$_SESSION['pwd_v'] : null,
        isset($row['password_hash']) ? (string)$row['password_hash'] : null
    );
    if ($pwVerdict === 'logout') {
        error_log('_recheckSessionUser: user ' . (int)$_SESSION['user_id'] . ' changed password — session ended');
        $_SESSION = [];
        return;
    }
    if ($pwVerdict === 'adopt') {
        $_SESSION['pwd_v'] = AuthFreshness::passwordVersion((string)$row['password_hash']);
    }
    if ($verdict === 'refresh') {
        $_SESSION['user_role']      = (string)(($row['role'] ?? '') ?: 'user');
        $_SESSION['user_is_driver'] = !empty($row['is_driver']);
        unset($_SESSION['perm_cache']);
    }
    $_SESSION['user_checked_at'] = $now;
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
        // Three ceilings (Fable review 2026-10-12): the (email, IP) pair as before, plus one IP
        // trying many emails (spraying) and one email tried from many IPs (a distributed guess).
        $stmt = $db->prepare("
            SELECT SUM(email = ? AND ip_address = ?) AS pair,
                   SUM(ip_address = ? AND email NOT LIKE 'reset:%' AND email NOT LIKE 'pin:%') AS ip,
                   SUM(email = ?) AS em
            FROM login_attempts
            WHERE (email = ? OR ip_address = ?)
              AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$email, $ip, $ip, $email, $email, $ip, LOGIN_WINDOW_SECONDS]);
        $n = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return loginThrottled((int)($n['pair'] ?? 0), (int)($n['ip'] ?? 0), (int)($n['em'] ?? 0));
    } catch (Throwable $e) {
        // Fail CLOSED (Fable review 2026-10-12): if the throttle can't be read, an attacker could
        // guess without limit. Callers show "try again shortly"-style text for a true return.
        error_log("isLoginRateLimited() error — refusing login: " . $e->getMessage());
        return true;
    }
}

/**
 * Pure: blocked when any ceiling is reached in the window — the (email, IP) pair at
 * LOGIN_MAX_ATTEMPTS, one IP across all emails at LOGIN_MAX_PER_IP, one email across all IPs at
 * LOGIN_MAX_PER_EMAIL.
 */
function loginThrottled(int $pair, int $perIp, int $perEmail): bool {
    $pairMax  = defined('LOGIN_MAX_ATTEMPTS') ? (int)LOGIN_MAX_ATTEMPTS : 5;
    $ipMax    = defined('LOGIN_MAX_PER_IP') ? (int)LOGIN_MAX_PER_IP : 20;
    $emailMax = defined('LOGIN_MAX_PER_EMAIL') ? (int)LOGIN_MAX_PER_EMAIL : 15;
    return $pair >= $pairMax || $perIp >= $ipMax || $perEmail >= $emailMax;
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
        _loginDebugLog("recordFailedLogin() error: " . $e->getMessage());
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
        _loginDebugLog("clearLoginAttempts() error: " . $e->getMessage());
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
        _loginDebugLog("purgeExpiredLoginAttempts() error: " . $e->getMessage());
        return 0;
    }
}

// -------------------- Login / Logout --------------------

function loginUser(string $email, string $password, bool &$isDriver = false): bool {
    $db = getDB();

    // Normalize input: trim, lowercase, strip invisible chars
    $email = strtolower(trim($email));
    $email = preg_replace('/[\x00-\x1F\x7F\xC2\xA0]/u', '', $email);

    try {
        // Accept username OR email — drivers use short usernames (e.g. "nigel", "dodgeram")
        $stmt = $db->prepare("
            SELECT id, email, username, password_hash, full_name, first_name, last_name, role, is_active, is_driver
            FROM users
            WHERE LOWER(email) = ? OR (username IS NOT NULL AND LOWER(username) = ?)
            LIMIT 1
        ");
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Explicitly deactivated users (is_active = 0) cannot log in; NULL/1 are both OK
        if ($user && isset($user['is_active']) && (int)$user['is_active'] === 0) {
            $user = false;
        }

        if ($user && isset($user['password_hash']) && password_verify($password, (string)$user['password_hash'])) {
            session_regenerate_id(true);

            // Force a fresh CSRF token for the authenticated session.
            // session_regenerate_id() carries over all session data including the
            // pre-auth CSRF token that was embedded in the login page HTML.
            // Without this, the login-page token remains valid post-authentication.
            unset($_SESSION['csrf_token']);

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
            $_SESSION['user_checked_at'] = time();
            $_SESSION['pwd_v']           = AuthFreshness::passwordVersion((string)$user['password_hash']);

            // Capture driver flag NOW before session_write_close() clears $_SESSION.
            $isDriver = !empty($user['is_driver']);

            // Release the session file lock now — all session writes are done.
            // Without this the lock is held through the DB calls below, and the
            // redirect target (app-launch.php) blocks at session_start() until
            // this script exits, causing a race that shows the user as not-logged-in.
            session_write_close();

            // ── Post-auth cleanup: non-critical. Session is already committed above.
            // Any DB error here (e.g. MySQL 1615 "Prepared statement needs to be
            // re-prepared" on cPanel shared hosting) must NOT cause a false login
            // failure — the user is already authenticated.
            try {
                $upd = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ? LIMIT 1");
                $upd->execute([(int)$user['id']]);
            } catch (Throwable $e) {
                _loginDebugLog("loginUser() last_login update failed (non-fatal): " . $e->getMessage());
            }

            try {
                clearLoginAttempts($email);
            } catch (Throwable $e) {
                _loginDebugLog("loginUser() clearLoginAttempts failed (non-fatal): " . $e->getMessage());
            }

            try {
                logActivity((int)$user['id'], null, 'User logged in', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            } catch (Throwable $e) {
                _loginDebugLog("loginUser() logActivity failed (non-fatal): " . $e->getMessage());
            }

            return true;
        }

        // Record the failed attempt for rate limiting
        recordFailedLogin($email);

        // Also log to activity_log if the user account exists
        if ($user && isset($user['id'])) {
            try {
                logActivity((int)$user['id'], null, 'Failed login attempt', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            } catch (Throwable $e) { /* non-critical */ }
        }

        return false;
    } catch (Throwable $e) {
        _loginDebugLog("loginUser() error: " . $e->getMessage());
        // If session was already written (session_write_close was called), the user
        // IS authenticated — return true so they're not falsely rejected.
        return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
    }
}

function logoutUser(): void {
    if (isset($_SESSION['user_id'])) {
        logActivity((int)$_SESSION['user_id'], null, 'User logged out', null);
    }

    // The phone this session registered for push stops getting this user's pushes (Fable review
    // 2026-10-12). Never blocks sign-out.
    if (!empty($_SESSION['push_device_token'])) {
        try {
            require_once dirname(__DIR__, 2) . '/Services/Push/DeviceTokenService.php';
            DeviceTokenService::forget(getDB(), (string)$_SESSION['push_device_token']);
        } catch (Throwable $e) {
            error_log('logoutUser: device token not retired: ' . $e->getMessage());
        }
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

/**
 * Stateless login-form CSRF — no session cookie required.
 *
 * Needed because the Capacitor Android WebView sometimes does not send the
 * session cookie on the very first POST (cookie-store flush race), causing the
 * standard session-based CSRF check to fail and force a re-attempt.
 *
 * The secret mixes the server's JWT secret (secrets.php) into stable server values — the
 * save path and session name alone are guessable, so a forged token was possible before
 * (Fable review 2026-10-12).
 * Token is valid for the current and previous 5-minute window (up to 10 min).
 */
function _loginCSRFSecret(): string {
    // session_save_path() and session_name() are set in session_config.php before
    // this function is ever called, so they are stable across requests.
    static $secret = null;
    if ($secret === null) {
        $key = (defined('BLUEMOON_JWT_SECRET') && strlen((string)BLUEMOON_JWT_SECRET) >= 32)
            ? (string)BLUEMOON_JWT_SECRET
            : '';
        if ($key === '') {
            // Fail closed: a random per-request key means no token verifies (login shows "Invalid request").
            error_log('_loginCSRFSecret: BLUEMOON_JWT_SECRET missing or short — login form refused');
            $key = random_bytes(32);
        }
        $secret = hash_hmac('sha256', session_save_path() . ':' . session_name() . ':mow-login-csrf-v2', $key, true);
    }
    return $secret;
}

function generateLoginCSRFToken(): string {
    $window = (int)floor(time() / 300);
    return base64_encode(hash_hmac('sha256', 'login:' . $window, _loginCSRFSecret(), true));
}

function verifyLoginCSRFToken(string $token): bool {
    if (empty($token)) return false;
    $secret = _loginCSRFSecret();
    $now    = (int)floor(time() / 300);
    foreach ([$now, $now - 1] as $window) {
        $expected = base64_encode(hash_hmac('sha256', 'login:' . $window, $secret, true));
        if (hash_equals($expected, $token)) {
            return true;
        }
    }
    return false;
}

// -------------------- RBAC authorization (permissions) --------------------

require_once __DIR__ . '/authz.php';

// -------------------- Activity log --------------------

function logActivity(int $user_id, $client_id, string $action, ?string $details = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, contact_id, action, details, ip_address)
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
        _loginDebugLog("logActivity() error: " . $e->getMessage());
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

// ── Dual-mode authentication ─────────────────────────────────────────────────
// Used by API endpoints that accept BOTH session-based (CRM web) AND JWT Bearer
// (iOS / Capacitor native) requests.
//
// If an "Authorization: Bearer <token>" header is present, the JWT is validated
// and the decoded payload is returned as the user array.
// Otherwise, falls back to requireLogin() + getCurrentUser() (session auth).
//
// Returns an array with at least ['id' => int, 'email' => string, 'role' => string].
if (!function_exists('requireLoginOrJwt')) {
    function requireLoginOrJwt(): array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                   ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                   ?? '';

        if (str_starts_with($authHeader, 'Bearer ')) {
            // JWT path — load JwtAuth.php if not already loaded
            if (!function_exists('requireJwt')) {
                require_once __DIR__ . '/JwtAuth.php';
            }
            return requireJwt(); // exits with 401 JSON if token invalid/missing
        }

        // Session path
        requireLogin();
        $u = getCurrentUser();
        if ($u === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            exit;
        }
        return $u;
    }
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

    _loginDebugLog("[CRM FATAL] $msg | $loc | user=$user | uri=$uri");

    // CLI (cron) → plain text to stdout so cron emails show the real error
    if (php_sapi_name() === 'cli') {
        echo "[FATAL] " . get_class($e) . ": $msg\n  in $loc\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }

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
