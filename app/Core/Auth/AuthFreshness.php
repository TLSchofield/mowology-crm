<?php
declare(strict_types=1);

/**
 * app/Core/Auth/AuthFreshness.php — pure rules for "is this login still true?" (Fable review 2026-10-12).
 *
 * Before: a web session kept the role and active flag it had at sign-in, and the permission cache
 * never expired, so a deactivated or demoted user kept their access until they signed out. A JWT
 * survived a password change for up to 30 days.
 *
 * Now:
 *   - auth.php isLoggedIn() re-reads users.role / is_active at most every RECHECK_SECONDS;
 *   - authz.php getUserPermissions() rebuilds the cache after PERM_CACHE_TTL seconds;
 *   - every JWT carries pwd_v (first 8 hex of sha256(password_hash)); JwtAuth refuses a token whose
 *     pwd_v no longer matches. Tokens issued before this change have no pwd_v and are accepted
 *     (signing every phone out at once was judged worse) — they age out within 30 days.
 *
 * No database here — callers pass rows in. Tested in tests/Unit/Core/AuthFreshnessTest.php.
 */
final class AuthFreshness
{
    public const RECHECK_SECONDS = 60;
    public const PERM_CACHE_TTL  = 300;

    /** True when the session's user row should be read again. */
    public static function recheckDue(int $lastCheckedAt, int $now): bool
    {
        return $lastCheckedAt <= 0 || ($now - $lastCheckedAt) >= self::RECHECK_SECONDS || $now < $lastCheckedAt;
    }

    /**
     * What to do with a session given the user's row now.
     *   'logout'  — the user is gone or deactivated (is_active = 0; NULL counts as active, as at login)
     *   'refresh' — still active but role or driver flag changed: update the session, drop the perm cache
     *   'ok'      — nothing changed
     */
    public static function sessionVerdict(?array $row, string $sessionRole, bool $sessionIsDriver = false): string
    {
        if (!$row) return 'logout';
        if (array_key_exists('is_active', $row) && $row['is_active'] !== null && (int)$row['is_active'] === 0) {
            return 'logout';
        }
        $role = (string)(($row['role'] ?? '') ?: 'user');
        if ($role !== $sessionRole) return 'refresh';
        if (array_key_exists('is_driver', $row) && (bool)$row['is_driver'] !== $sessionIsDriver) return 'refresh';
        return 'ok';
    }

    /** True when a session permission cache can still be used. */
    public static function permCacheFresh($cache, int $userId, int $version, int $now): bool
    {
        if (!is_array($cache)) return false;
        if ((int)($cache['user_id'] ?? 0) !== $userId) return false;
        if ((int)($cache['version'] ?? 0) !== $version) return false;
        if (!isset($cache['permissions']) || !is_array($cache['permissions'])) return false;
        $at = (int)($cache['at'] ?? 0);
        return $at > 0 && $now >= $at && ($now - $at) < self::PERM_CACHE_TTL;
    }

    /** The pwd_v claim for a password hash: first 8 hex chars of sha256(hash). '' for no hash. */
    public static function passwordVersion(?string $passwordHash): string
    {
        $h = (string)$passwordHash;
        return $h === '' ? '' : substr(hash('sha256', $h), 0, 8);
    }

    /**
     * Does a JWT payload still match the user's password? A token without pwd_v (issued before
     * 2026-10-12) is accepted; one with pwd_v must equal the current hash's version.
     */
    public static function jwtPasswordOk(array $payload, ?string $currentHash): bool
    {
        if (!array_key_exists('pwd_v', $payload)) return true;
        $claim = (string)$payload['pwd_v'];
        $now   = self::passwordVersion($currentHash);
        return $claim !== '' && $now !== '' && hash_equals($now, $claim);
    }
}
