<?php
declare(strict_types=1);

/**
 * The crew-app version feed, per business (2026-11-03).
 *
 * One shared `app-version.json` names the release every business gets. A business may carry an
 * override file beside it — `app-version.<tenant key>.json` — holding only the keys that differ for
 * that business (usually the `native_*` switches while a build is on trial there). The override is
 * merged key by key under each platform; the original business never reads one, so its feed is the
 * shared file exactly as written.
 */
final class AppVersionFeed
{
    /** The override file for a business, or null for the original business or an unsafe key. */
    public static function overrideFile(string $dir, string $tenantKey, bool $isDefault): ?string
    {
        if ($isDefault || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $tenantKey)) return null;
        return rtrim($dir, '/') . '/app-version.' . $tenantKey . '.json';
    }

    /** Each platform's keys from the override replace the base's; unknown platforms in the override are ignored. */
    public static function merge(array $base, array $override): array
    {
        foreach ($base as $platform => $keys) {
            if (!is_array($keys) || !isset($override[$platform]) || !is_array($override[$platform])) continue;
            $base[$platform] = array_replace($keys, $override[$platform]);
        }
        return $base;
    }

    /** The feed a business sees: the base, with its override merged in when the file exists and parses. */
    public static function forTenant(array $base, string $dir, string $tenantKey, bool $isDefault): array
    {
        $file = self::overrideFile($dir, $tenantKey, $isDefault);
        if ($file === null || !is_file($file)) return $base;
        $override = json_decode((string)file_get_contents($file), true);
        return is_array($override) ? self::merge($base, $override) : $base;
    }
}
