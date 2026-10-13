<?php
declare(strict_types=1);

/**
 * app/Core/Auth/JwtService.php
 *
 * JWT generation for Mowology CRM sessions.
 * Validation counterpart: JwtAuth.php
 *
 * Usage:
 *   require_once APP_ROOT . '/Core/Auth/JwtService.php';
 *   $token = generateMowologyJwt(1, 'tim@example.com', 'Tim', 'admin');
 */

/**
 * Generate a signed HS256 JWT for a CRM user.
 *
 * @param int    $userId  users.id
 * @param string $email
 * @param string $name    full_name
 * @param string $role
 * @param int    $ttl     Seconds until expiry. Default 30 days.
 * @return string  Signed JWT string
 */
function generateMowologyJwt(int $userId, string $email, string $name, string $role, int $ttl = 2592000): string
{
    // Load the shared secret from JwtAuth.php if not already available
    if (!function_exists('jwtSecret')) {
        require_once __DIR__ . '/JwtAuth.php';
    }

    $secret = jwtSecret();
    $now    = time();
    // pwd_v: a password change or reset makes this token invalid (Fable review 2026-10-12).
    $pwdV   = jwtPasswordVersionFor($userId);

    $header  = _jwtB64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = _jwtB64(json_encode([
        'iss'   => 'mowology',
        'aud'   => 'crm',
        'sub'   => (string)$userId,
        'email' => $email,
        'name'  => $name,
        'role'  => $role,
        'iat'   => $now,
        'exp'   => $now + $ttl,
    ] + ($pwdV !== '' ? ['pwd_v' => $pwdV] : [])));
    $sig = _jwtB64(hash_hmac('sha256', $header . '.' . $payload, $secret, true));

    return $header . '.' . $payload . '.' . $sig;
}

function _jwtB64(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
