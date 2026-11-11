<?php
declare(strict_types=1);

/**
 * app/Core/Auth/AttemptThrottle.php — throttles that are NOT the login throttle (Fable audit 2026-10-12).
 *
 * They share the login_attempts table (email = a prefixed key, ip_address = caller IP) so no migration
 * is needed, but each has its own key prefix, so they can never lock a login:
 *   - 'reset:<email>'  password-reset requests — 3 per email and 10 per IP per hour (#9). Before, reset
 *                      requests were recorded as failed LOGINS, so anyone could lock a user out by
 *                      requesting resets for their address.
 *   - 'pin:<user_id>'  wrong manager PINs (pre-trip bypass) — locked after 5 in 10 minutes (#10).
 * isLoginRateLimited() (auth.php) leaves 'reset:'/'pin:' rows out of its per-IP ceiling.
 *
 * Reads fail CLOSED (a broken throttle refuses), writes never throw.
 * Pure parts tested in tests/Unit/Core/AttemptThrottleTest.php.
 */
final class AttemptThrottle
{
    public const RESET_PREFIX        = 'reset:';
    public const RESET_WINDOW        = 3600;
    public const RESET_MAX_PER_EMAIL = 3;
    public const RESET_MAX_PER_IP    = 10;

    public const PIN_PREFIX  = 'pin:';
    public const PIN_WINDOW  = 600;
    public const PIN_MAX     = 5;

    // ── Pure rules ────────────────────────────────────────────────────────

    public static function resetKey(string $email): string
    {
        return substr(self::RESET_PREFIX . strtolower(trim($email)), 0, 255);
    }

    public static function pinKey(int $userId): string
    {
        return self::PIN_PREFIX . $userId;
    }

    /** A reset request may go ahead while both counts (already in the last hour) are under their ceiling. */
    public static function resetAllowed(int $perEmail, int $perIp): bool
    {
        return $perEmail < self::RESET_MAX_PER_EMAIL && $perIp < self::RESET_MAX_PER_IP;
    }

    /** The PIN is locked once PIN_MAX wrong tries sit in the window. */
    public static function pinLocked(int $failures): bool
    {
        return $failures >= self::PIN_MAX;
    }

    // ── Database ──────────────────────────────────────────────────────────

    /** Rows for an exact key in the last $window seconds. Throws on DB error (callers fail closed). */
    public static function countKey(PDO $db, string $key, int $window): int
    {
        $st = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $st->execute([$key, $window]);
        return (int)$st->fetchColumn();
    }

    /** Rows from one IP under a key prefix in the last $window seconds. Throws on DB error. */
    public static function countIp(PDO $db, string $prefix, string $ip, int $window): int
    {
        $st = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND email LIKE ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $st->execute([$ip, $prefix . '%', $window]);
        return (int)$st->fetchColumn();
    }

    public static function record(PDO $db, string $key, string $ip): void
    {
        try {
            $db->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)")->execute([$key, substr($ip, 0, 45)]);
        } catch (Throwable $e) {
            error_log('AttemptThrottle::record failed: ' . $e->getMessage());
        }
    }

    public static function clear(PDO $db, string $key): void
    {
        try {
            $db->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$key]);
        } catch (Throwable $e) {
            error_log('AttemptThrottle::clear failed: ' . $e->getMessage());
        }
    }

    /**
     * May this reset request go ahead? Records it when it may. False (refuse) on a DB error.
     * Callers show the same generic page either way.
     */
    public static function takeResetSlot(PDO $db, string $email, string $ip): bool
    {
        try {
            $ok = self::resetAllowed(
                self::countKey($db, self::resetKey($email), self::RESET_WINDOW),
                self::countIp($db, self::RESET_PREFIX, $ip, self::RESET_WINDOW)
            );
        } catch (Throwable $e) {
            error_log('AttemptThrottle::takeResetSlot read failed — refusing: ' . $e->getMessage());
            return false;
        }
        if ($ok) self::record($db, self::resetKey($email), $ip);
        return $ok;
    }

    /** True when this user's PIN entry is locked (or the throttle can't be read). */
    public static function pinIsLocked(PDO $db, int $userId): bool
    {
        try {
            return self::pinLocked(self::countKey($db, self::pinKey($userId), self::PIN_WINDOW));
        } catch (Throwable $e) {
            error_log('AttemptThrottle::pinIsLocked read failed — locking: ' . $e->getMessage());
            return true;
        }
    }
}
