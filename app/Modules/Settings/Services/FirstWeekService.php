<?php
declare(strict_types=1);

/**
 * FirstWeekService — the per-business "first-week view" switch (net-version plan, step 2).
 *
 * When ON, the CRM shows a newcomer the short version: the simple dashboard and the short menu
 * (steps 3 and 4 of docs/plans/net-version-simpler-faster.md read isOn()). When OFF, nothing changes.
 *
 * Stored in ops_settings (one row per business database, so it is per tenant). A missing row means OFF:
 * existing businesses (Mowology) keep today's CRM until someone flips it. New businesses are seeded ON by
 * TenantProvisioner. Plain SELECT then UPDATE/INSERT (no MySQL-only upsert) so the test runs on SQLite.
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
final class FirstWeekService
{
    public const KEY = 'first_week_mode';
    public const DESCRIPTION = 'Show the short first-week CRM (simple dashboard, short menu)';

    /** @var array<int, bool> per-request cache, keyed by spl_object_id of the connection */
    private static array $cache = [];

    public static function isOn(PDO $db): bool
    {
        $id = spl_object_id($db);
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $on = false;
        try {
            $st = $db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $st->execute([self::KEY]);
            $v = $st->fetchColumn();
            $on = $v !== false && trim((string)$v) === '1';
        } catch (Throwable $e) {
            // ops_settings missing on an old database: the switch is off.
        }
        return self::$cache[$id] = $on;
    }

    public static function set(PDO $db, bool $on, ?int $userId = null): void
    {
        $val = $on ? '1' : '0';
        $st = $db->prepare('SELECT COUNT(*) FROM ops_settings WHERE setting_key = ?');
        $st->execute([self::KEY]);
        if ((int)$st->fetchColumn() > 0) {
            $db->prepare('UPDATE ops_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?')->execute([$val, $userId, self::KEY]);
        } else {
            $db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description, updated_by) VALUES (?, ?, ?, ?)')
               ->execute([self::KEY, $val, self::DESCRIPTION, $userId]);
        }
        self::$cache[spl_object_id($db)] = $on;
    }

    /** Forget the per-request cache (tests). */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
