<?php
declare(strict_types=1);

/**
 * /public/api/auth/token.php
 *
 * BlueMoon Web — JWT Token Endpoint
 * Issues a signed JWT for authenticated Mowology users.
 * Two-step sign-in (2026-11-06) covers WEB sign-in only for now: this phone sign-in is unchanged, because the
 * installed apps cannot show a code field. A later phase adds it here with an app update.
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
    // Unknown origin: no CORS header at all (Fable audit 2026-10-12). It used to echo
    // http://localhost:5173 — harmless to browsers elsewhere, but a needless grant.
    $corsOrigin = null;
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

// ── Sign in ────────────────────────────────────────────────────────────────
// The credential check and the token live in PhoneSignIn (2026-11-11), shared with the ittai.ca sign-in
// directory. Same checks, same order, same answers as before; the token now also names its business (biz).
require_once APP_ROOT . '/Core/Auth/auth.php';
require_once APP_ROOT . '/Core/Auth/PhoneSignIn.php';

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

try {
    $db = getDB();
} catch (Throwable $e) {
    // As before: with no database the throttle could not be read, and it fails closed.
    error_log('isLoginRateLimited() error — refusing login: ' . $e->getMessage());
    http_response_code(429);
    echo json_encode(['error' => 'Too many login attempts. Try again in 10 minutes.']);
    exit;
}

$result = PhoneSignIn::attempt($db, Tenant::key(), $credential, $password, $audience, $ip);

switch ($result['status']) {
    case 'ok':
        http_response_code(200);
        echo json_encode([
            'token'      => $result['token'],
            'user'       => $result['user'],
            'expires_in' => $result['expires_in'],
        ]);
        exit;
    case 'rate_limited':
        http_response_code(429);
        echo json_encode(['error' => 'Too many login attempts. Try again in 10 minutes.']);
        exit;
    case 'invalid':
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit;
    case 'web_only':
        http_response_code(403);
        echo json_encode(['error' => 'This login works on the website only.']);
        exit;
    case 'not_configured':
        http_response_code(500);
        echo json_encode(['error' => 'Sign-in is not configured on the server', 'code' => 'jwt_secret_missing']);
        exit;
    default:   // 'error'
        http_response_code(500);
        echo json_encode(['error' => 'Server error. Please try again.']);
        exit;
}
