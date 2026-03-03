<?php
declare(strict_types=1);

/**
 * app/Core/Auth/JwtAuth.php
 *
 * JWT Bearer token validation middleware for mobile API endpoints.
 *
 * Counterpart to /public/api/auth/token.php which *issues* tokens.
 * This file *validates* them — reading the Authorization header,
 * verifying the HS256 signature, and checking expiry.
 *
 * Usage in API endpoints:
 *   require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
 *   $jwtUser = requireJwt();   // dies 401 if invalid
 *   // $jwtUser = ['id'=>1, 'email'=>'...', 'name'=>'...', 'role'=>'admin']
 *
 * No external dependencies. Hand-rolled HS256 to match token.php.
 */

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * URL-safe base64 decode (counterpart to base64url_encode in token.php).
 */
function base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/**
 * Returns the JWT secret — must match the secret used in token.php.
 */
function jwtSecret(): string
{
    if (defined('BLUEMOON_JWT_SECRET') && BLUEMOON_JWT_SECRET !== '') {
        return BLUEMOON_JWT_SECRET;
    }
    // Fallback: same derivation as JWT_SECRET_FALLBACK() in token.php
    $base = defined('DB_PASS') ? DB_PASS : 'bluemoon_fallback';
    return hash('sha256', $base . '_bluemoon_jwt_v1');
}

// ── Core validation ──────────────────────────────────────────────────────────

/**
 * Validate a raw JWT string.
 *
 * @param  string $token  Raw JWT (three dot-separated base64url segments)
 * @return array|null     Decoded payload array on success, null on any failure
 */
function validateJwtToken(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerB64, $payloadB64, $sigB64] = $parts;

    // Verify signature
    $secret   = jwtSecret();
    $expected = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);

    if (!hash_equals($expected, base64url_decode($sigB64))) {
        return null;
    }

    // Decode payload
    $payload = json_decode(base64url_decode($payloadB64), true);
    if (!is_array($payload)) {
        return null;
    }

    // Check expiry
    if (isset($payload['exp']) && time() > (int)$payload['exp']) {
        return null;  // Token expired
    }

    // Validate issuer
    if (($payload['iss'] ?? '') !== 'mowology') {
        return null;
    }

    return $payload;
}

// ── Convenience wrappers ─────────────────────────────────────────────────────

/**
 * Extract the Bearer token from the Authorization header.
 *
 * @return string  Raw token string, or '' if header missing/malformed
 */
function extractBearerToken(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Some servers pass it via a different key
    if ($header === '') {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }

    if (str_starts_with($header, 'Bearer ')) {
        return substr($header, 7);
    }

    return '';
}

/**
 * Non-fatal JWT check. Returns the user payload array or null.
 *
 * Use when you want to optionally authenticate (e.g. public endpoints
 * that return richer data for logged-in users).
 *
 * @return array|null  ['id'=>int, 'email'=>string, 'name'=>string, 'role'=>string] or null
 */
function getJwtUser(): ?array
{
    $token = extractBearerToken();
    if ($token === '') {
        return null;
    }

    $payload = validateJwtToken($token);
    if ($payload === null) {
        return null;
    }

    return [
        'id'    => (int)($payload['sub'] ?? 0),
        'email' => (string)($payload['email'] ?? ''),
        'name'  => (string)($payload['name'] ?? ''),
        'role'  => (string)($payload['role'] ?? 'user'),
    ];
}

/**
 * Require a valid JWT Bearer token. Terminates with 401 JSON if missing or invalid.
 *
 * @return array  ['id'=>int, 'email'=>string, 'name'=>string, 'role'=>string]
 */
function requireJwt(): array
{
    $token = extractBearerToken();

    if ($token === '') {
        http_response_code(401);
        echo json_encode(['error' => 'Authorization header required', 'code' => 'missing_token']);
        exit;
    }

    $payload = validateJwtToken($token);

    if ($payload === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token', 'code' => 'invalid_token']);
        exit;
    }

    return [
        'id'    => (int)($payload['sub'] ?? 0),
        'email' => (string)($payload['email'] ?? ''),
        'name'  => (string)($payload['name'] ?? ''),
        'role'  => (string)($payload['role'] ?? 'user'),
    ];
}

/**
 * Returns true if the given role string has admin-level access.
 */
function jwtIsAdmin(string $role): bool
{
    return in_array($role, ['admin', 'manager'], true);
}
