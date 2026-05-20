<?php
declare(strict_types=1);

/**
 * /public/api/auth/token.php
 *
 * BlueMoon Web — JWT Token Endpoint
 * Issues a signed JWT for authenticated Mowology users.
 *
 * POST /api/auth/token
 * Body (JSON): { "email": "...", "password": "..." }
 *
 * Response 200: { "token": "<jwt>", "user": { id, email, name, role } }
 * Response 401: { "error": "Invalid credentials" }
 * Response 429: { "error": "Too many attempts" }
 * Response 405: { "error": "Method not allowed" }
 *
 * CORS: Allows BlueMoon web origin only (set BLUEMOON_ORIGIN in secrets.php)
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────
// Use upward search to find paths.php — works in both local dev and production.
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';

// ── CORS ───────────────────────────────────────────────────────────────────
// Allowed origins: local dev + production BlueMoon URL
$allowedOrigins = [
    'http://localhost:5173',        // Vite dev server
    'http://localhost:3000',        // fallback dev
    'http://127.0.0.1:5173',
];

// Add production origin from secrets if defined
if (defined('BLUEMOON_ORIGIN') && BLUEMOON_ORIGIN) {
    $allowedOrigins[] = BLUEMOON_ORIGIN;
}

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Native mobile clients (iOS/Android) send no Origin header.
// For those requests, suppress the CORS header entirely — native apps
// don't use CORS. For web clients, restrict to allowed origins.
if ($requestOrigin === '') {
    // Native app — no Origin header needed
    $corsOrigin = null;
} elseif (in_array($requestOrigin, $allowedOrigins, true)) {
    $corsOrigin = $requestOrigin;
} else {
    $corsOrigin = $allowedOrigins[0]; // fallback to first allowed origin for dev
}

if ($corsOrigin !== null) {
    header('Access-Control-Allow-Origin: ' . $corsOrigin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: false');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Method guard ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Parse body ─────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);

$credential = isset($body['email'])    ? strtolower(trim((string)$body['email']))    : '';
$password   = isset($body['password']) ? (string)$body['password']                  : '';
$audience   = isset($body['audience']) ? strtolower(trim((string)$body['audience'])) : 'web';
// Valid audiences: 'web' (BlueMoon), 'mobile' (iOS/Android)
$audience = in_array($audience, ['web', 'mobile'], true) ? $audience : 'web';

if ($credential === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Email (or username) and password are required']);
    exit;
}
// Keep $email alias for rate-limiting calls below
$email = $credential;

// ── Rate limiting ──────────────────────────────────────────────────────────
require_once APP_ROOT . '/Core/Auth/auth.php';

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (isLoginRateLimited($email, $ip)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many login attempts. Try again in 10 minutes.']);
    exit;
}

// ── Credential validation ──────────────────────────────────────────────────
try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT id, email, password_hash, full_name, role, is_active
        FROM users
        WHERE LOWER(email) = ? OR LOWER(username) = ?
        LIMIT 1
    ");
    $stmt->execute([$credential, $credential]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check active — treat NULL/missing is_active as active (matches web login behaviour)
    if ($user && isset($user['is_active']) && (int)$user['is_active'] === 0) {
        $user = false; // explicitly deactivated
    }

} catch (Throwable $e) {
    error_log('[BlueMoon/token] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error. Please try again.']);
    exit;
}

if (!$user || !isset($user['password_hash']) || !password_verify($password, (string)$user['password_hash'])) {
    recordFailedLogin($email, $ip);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

// ── Success — clear rate limit, log activity ───────────────────────────────
clearLoginAttempts($email, $ip);

try {
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ? LIMIT 1")
       ->execute([(int)$user['id']]);

    $loginAction = $audience === 'mobile' ? 'iOS app login' : 'BlueMoon web login';
    $db->prepare("
        INSERT INTO activity_log (user_id, client_id, action, details, ip_address)
        VALUES (?, NULL, ?, ?, ?)
    ")->execute([(int)$user['id'], $loginAction, 'JWT issued', $ip]);

} catch (Throwable $e) {
    // Non-fatal — log but continue
    error_log('[BlueMoon/token] Post-auth logging error: ' . $e->getMessage());
}

// ── Build JWT (hand-rolled, no dependency) ─────────────────────────────────
$secret = defined('BLUEMOON_JWT_SECRET') ? BLUEMOON_JWT_SECRET : JWT_SECRET_FALLBACK();

$header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$now     = time();
// Mobile tokens live 30 days — crew must survive server outages and long
// weekends without being forced to re-authenticate. Web tokens stay short.
$aud = $audience === 'mobile' ? 'ios' : 'bluemoon';
$ttl = $audience === 'mobile' ? (30 * 24 * 3600) : (8 * 3600);

$payload = base64url_encode(json_encode([
    'iss'   => 'mowology',
    'aud'   => $aud,
    'sub'   => (string)$user['id'],
    'email' => (string)$user['email'],
    'name'  => (string)($user['full_name'] ?? ''),
    'role'  => (string)($user['role'] ?? 'user'),
    'iat'   => $now,
    'exp'   => $now + $ttl,
]));

$signature = base64url_encode(
    hash_hmac('sha256', $header . '.' . $payload, $secret, true)
);

$token = $header . '.' . $payload . '.' . $signature;

// ── Respond ────────────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'token' => $token,
    'user'  => [
        'id'    => (int)$user['id'],
        'email' => (string)$user['email'],
        'name'  => (string)($user['full_name'] ?? ''),
        'role'  => (string)($user['role'] ?? 'user'),
    ],
    'expires_in' => $ttl,
]);

// ── Helpers ────────────────────────────────────────────────────────────────

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Fallback secret derivation if BLUEMOON_JWT_SECRET not set in secrets.php.
 * Uses a deterministic site-specific value — NOT cryptographically ideal.
 * Add BLUEMOON_JWT_SECRET to secrets.php for production.
 */
function JWT_SECRET_FALLBACK(): string {
    // Combines DB_PASS with a fixed salt — unique per install but stable
    $base = defined('DB_PASS') ? DB_PASS : 'bluemoon_fallback';
    return hash('sha256', $base . '_bluemoon_jwt_v1');
}
