<?php
declare(strict_types=1);

/**
 * TenantContext — which tenant ("site") this request is working for (Ittai audit 2026-10-27).
 *
 * Operational tables are isolated by giving each tenant its own database; the tables that CAN hold
 * more than one tenant's rows (head_lessons, the knowledge commons) carry a site_id. Until now there
 * was no resolver: HeadLessons, PennyLessons::forVendor and others defaulted to a literal 1, so a
 * second tenant would have silently read and written site 1.
 *
 * siteId() reads ops_settings 'tenant_site_id' (a positive integer) and falls back to 1, so an
 * install with no setting behaves exactly as before. A database error also returns 1 and is logged
 * once — the same fail-open as the rest of the head layer; the leak test (TenantIsolationTest) is what
 * proves the scoping, not this fallback.
 *
 * Pure apart from one SELECT, cached per connection for the request. Global namespace; require_once it.
 */
final class TenantContext
{
    public const SETTING = 'tenant_site_id';
    public const DEFAULT_SITE = 1;

    /** @var array<int,int> object id of the PDO => site id */
    private static array $cache = [];

    public static function siteId(?PDO $db = null): int
    {
        if ($db === null) {
            return self::DEFAULT_SITE;
        }
        $key = spl_object_id($db);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $site = self::DEFAULT_SITE;
        $resolved = true;
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::SETTING]);
            $v = $s->fetchColumn();
            if ($v !== false && ctype_digit((string)$v) && (int)$v > 0) {
                $site = (int)$v;
            }
        } catch (Throwable $e) {
            // Not cached: a lookup that failed must not stick as "site 1" for the rest of the request.
            error_log('TenantContext: ' . $e->getMessage() . ' — using site ' . self::DEFAULT_SITE);
            $resolved = false;
        }
        if ($resolved) {
            self::$cache[$key] = $site;
        }
        return $site;
    }

    /** Forget cached values (tests, or after the setting is changed in the same request). */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
