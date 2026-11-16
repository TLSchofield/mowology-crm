<?php
declare(strict_types=1);

/**
 * app/Modules/Auth/Services/AppTokenService.php — the record of every app (JWT) sign-in, so one can be revoked
 * (Fable audit H2, Tim 2026-11-16). Table: app_token_sessions (migration 1458).
 *
 * Every token carries a jti that names its row. JwtAuth::jwtCurrentUser() asks refusal(): a token with no row,
 * a row for someone else, or a revoked row is refused. A token issued before this rule has no jti and so no row:
 * it is refused, and each phone signs in once more (Tim's decision, D1).
 *
 * Timestamps are stored in UTC (gmdate). Callers pass $now so the rules are testable without a clock.
 */
final class AppTokenService
{
    /** A jti is 32 lowercase hex characters (16 random bytes). */
    public static function isJti(string $jti): bool
    {
        return (bool)preg_match('/^[a-f0-9]{32}$/', $jti);
    }

    public static function newJti(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Pure: why a token must be refused, or null when it may be used.
     * $row is the app_token_sessions row for the token's jti (null when there is none).
     */
    public static function refusal(?array $row, int $tokenUserId): ?string
    {
        if ($row === null) {
            return 'unknown token';
        }
        if ((int)($row['user_id'] ?? 0) !== $tokenUserId) {
            return 'token belongs to another user';
        }
        if (!empty($row['revoked_at'])) {
            return 'token revoked';
        }
        return null;
    }

    /** Record a sign-in. Throws on failure, so a token is never issued without its row (sign-in then fails). */
    public static function issue(PDO $db, int $userId, string $jti, string $platform, int $ttl, int $now): void
    {
        if (!self::isJti($jti)) {
            throw new InvalidArgumentException('jti must be 32 hex characters');
        }
        // Checked explicitly: a PDO connection in silent error mode returns false rather than throwing, and a
        // token must never be handed out without its record.
        $st = $db->prepare(
            'INSERT INTO app_token_sessions (jti, user_id, platform, created_at, expires_at) VALUES (?, ?, ?, ?, ?)'
        );
        if ($st === false) {
            throw new RuntimeException('app sign-in could not be recorded');
        }
        $ok = $st->execute([$jti, $userId, $platform, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + $ttl)]);
        if ($ok !== true || $st->rowCount() !== 1) {
            throw new RuntimeException('app sign-in could not be recorded');
        }
    }

    /**
     * Revoke one sign-in belonging to $userId. Returns true when a live row was revoked, false when there was
     * nothing to revoke (unknown, someone else's, or already revoked).
     */
    public static function revoke(PDO $db, string $jti, int $userId, int $now): bool
    {
        if (!self::isJti($jti)) {
            return false;
        }
        $st = $db->prepare(
            'UPDATE app_token_sessions SET revoked_at = ? WHERE jti = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $st->execute([gmdate('Y-m-d H:i:s', $now), $jti, $userId]);
        return $st->rowCount() === 1;
    }

    /**
     * The sign-ins that can still be used for this person, newest first: [jti, platform, created_at, expires_at].
     * Used by the admin page to show what to sign out.
     */
    public static function activeFor(PDO $db, int $userId, int $now): array
    {
        $st = $db->prepare(
            'SELECT jti, platform, created_at, expires_at FROM app_token_sessions
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ?
             ORDER BY created_at DESC'
        );
        $st->execute([$userId, gmdate('Y-m-d H:i:s', $now)]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** The row for a jti, or null. JwtAuth uses it on every request that carries a token. */
    public static function find(PDO $db, string $jti): ?array
    {
        if (!self::isJti($jti)) {
            return null;
        }
        $st = $db->prepare('SELECT user_id, revoked_at FROM app_token_sessions WHERE jti = ? LIMIT 1');
        $st->execute([$jti]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
