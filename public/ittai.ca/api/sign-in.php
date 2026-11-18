<?php
declare(strict_types=1);

/**
 * https://ittai.ca/api/sign-in.php — one phone sign-in for every business (2026-11-11, "one app per platform").
 *
 * The phone sends the email and password here, on the marketing address; BusinessDirectory tries them at every
 * business and answers with the business's own address and a token issued FOR that business. From then on the
 * app talks to that address only. A business's own /api/auth/token.php is unchanged.
 *
 * POST, JSON body: { "email", "password", "audience"?: "mobile"|"web", "business"?: "<key from a choose list>" }
 * 200 { business: {key, name, app_url, test}, token, user, expires_in }
 * 200 { choose: true, businesses: [{key, name, app_url, test}, …] }   — several matched; ask again with business
 * 400 email/password missing · 401 Invalid credentials · 405 not POST · 429 too many attempts · 503 no business could check
 * A failure never says which businesses exist. No CORS: phones only.
 */

// Before anything boots: this request is the directory, never a business, and has no database of its own.
define('ITTAI_DIRECTORY_MODE', true);

// ── Bootstrap ──────────────────────────────────────────────────────────────
// Upward search for paths.php, as token.php does: production has app/ beside public_html's files
// (public_html/ittai.ca/api → public_html), local dev one level higher (public/ittai.ca/api → repo root).
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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ── Method guard ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Parse body (the same rules as token.php) ───────────────────────────────
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) $body = [];

$credential = isset($body['email'])    ? strtolower(trim((string)$body['email']))    : '';
$password   = isset($body['password']) ? (string)$body['password']                  : '';
$audience   = isset($body['audience']) ? strtolower(trim((string)$body['audience'])) : 'web';
// Valid audiences: 'web' (8-hour token), 'mobile' (30 days) — the phones send 'mobile', as they do to token.php.
$audience   = in_array($audience, ['web', 'mobile'], true) ? $audience : 'web';
$business   = isset($body['business']) && is_string($body['business']) && $body['business'] !== '' ? $body['business'] : null;

if ($credential === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Email (or username) and password are required']);
    exit;
}

// ── Sign in ────────────────────────────────────────────────────────────────
// auth.php holds the rate-limit helpers PhoneSignIn calls (each on that business's own database).
require_once APP_ROOT . '/Core/Auth/auth.php';
require_once APP_ROOT . '/Modules/Auth/Services/BusinessDirectory.php';

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$result = (new BusinessDirectory())->signIn($credential, $password, $audience, $ip, $business);

switch ($result['status']) {
    case 'ok':
        http_response_code(200);
        echo json_encode([
            'business'   => $result['business'],
            'token'      => $result['token'],
            'user'       => $result['user'],
            'expires_in' => $result['expires_in'],
        ]);
        exit;
    case 'choose':
        http_response_code(200);
        echo json_encode(['choose' => true, 'businesses' => $result['businesses']]);
        exit;
    case 'rate_limited':
        http_response_code(429);
        echo json_encode(['error' => 'Too many login attempts. Try again in 10 minutes.']);
        exit;
    case 'unavailable':   // no business could check: a server fault, never "wrong password"
        http_response_code(503);
        echo json_encode(['error' => 'Sign-in is not available right now. Please try again.']);
        exit;
    default:   // 'invalid'
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit;
}
