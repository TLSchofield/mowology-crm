<?php
/**
 * PortalTokenService — client portal links expire and can be replaced (security review #14, 2026-10-12).
 *
 * contacts.portal_token is the whole key to a client's portal (invoices, quotes, photos, their own
 * details). It used to live forever and could never be changed. Now:
 *   - each token carries contacts.portal_token_issued_at (migration 1341) and works for 365 days;
 *     the migration stamps every existing token with NOW(), so nobody is cut off on the day it runs;
 *   - a token with no issue date (after 1341) is treated as expired — every path that issues one
 *     stamps it (issue() / regenerate());
 *   - the office can "Regenerate portal link" on the contact (clients.edit) — the old link stops
 *     working at once.
 * Before 1341 runs nothing changes (links behave as before).
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
declare(strict_types=1);

final class PortalTokenService
{
    public const LIFETIME_DAYS = 365;

    /** per-connection "has the column" cache */
    private static ?WeakMap $ready = null;

    /** contacts.portal_token_issued_at exists (migration 1341). */
    public static function ready(PDO $db): bool
    {
        self::$ready ??= new WeakMap();
        if (!isset(self::$ready[$db])) {
            try {
                $db->query("SELECT portal_token_issued_at FROM contacts LIMIT 0");
                self::$ready[$db] = true;
            } catch (Throwable $e) {
                self::$ready[$db] = false;
            }
        }
        return self::$ready[$db];
    }

    /** Is a token issued at $issuedAt still live at $now? No date = not live. PURE. */
    public static function isLive(?string $issuedAt, ?int $now = null): bool
    {
        if ($issuedAt === null || trim($issuedAt) === '') return false;
        $t = strtotime($issuedAt);
        if ($t === false) return false;
        return ($now ?? time()) - $t < self::LIFETIME_DAYS * 86400;
    }

    /**
     * SQL to AND onto "WHERE <alias>portal_token = ?" so an expired token matches nothing.
     * '' before migration 1341.
     */
    public static function liveSql(PDO $db, string $alias = ''): string
    {
        if (!self::ready($db)) return '';
        $a = $alias !== '' ? $alias . '.' : '';
        return " AND {$a}portal_token_issued_at IS NOT NULL AND {$a}portal_token_issued_at > DATE_SUB(NOW(), INTERVAL " . self::LIFETIME_DAYS . " DAY)";
    }

    /** The contact a live token belongs to (only the listed columns), or null. */
    public static function find(PDO $db, string $token, string $columns = 'id'): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) return null;
        $s = $db->prepare("SELECT {$columns} FROM contacts WHERE portal_token = ?" . self::liveSql($db) . " LIMIT 1");
        $s->execute([$token]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(24)); // 48 hex, as before
    }

    /** Give a contact a fresh token (stamped). Returns it. */
    public static function issue(PDO $db, int $contactId): string
    {
        $token = self::newToken();
        if (self::ready($db)) {
            $db->prepare("UPDATE contacts SET portal_token = ?, portal_token_issued_at = NOW() WHERE id = ?")->execute([$token, $contactId]);
        } else {
            $db->prepare("UPDATE contacts SET portal_token = ? WHERE id = ?")->execute([$token, $contactId]);
        }
        return $token;
    }

    /** Replace the contact's token (the old link dies at once) and log who did it. */
    public static function regenerate(PDO $db, int $contactId, ?int $userId, string $ip = ''): string
    {
        $token = self::issue($db, $contactId);
        try {
            $db->prepare("INSERT INTO activity_log (user_id, contact_id, action, details, ip_address) VALUES (?, ?, 'Portal link regenerated', ?, ?)")
               ->execute([$userId, $contactId, 'The old client portal link no longer works; a new one was issued (valid ' . self::LIFETIME_DAYS . ' days).', substr($ip, 0, 45)]);
        } catch (Throwable $e) {
            error_log('PortalTokenService::regenerate log: ' . $e->getMessage());
        }
        return $token;
    }
}
