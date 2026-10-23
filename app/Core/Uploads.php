<?php
declare(strict_types=1);

/**
 * Uploads — where a business's files live, in ONE place (Ittai founding businesses, 2026-10-23).
 *
 * The original business keeps exactly today's paths: /uploads/<area>/… (files under PUBLIC_ROOT/uploads).
 * Every other business gets its own folder: /uploads/t/<tenant>/<area>/…, with an .htaccess that refuses
 * those files on any other business's address (written by ensureTenantFolder() when the business is set up).
 * Stored links keep the full path (/uploads/t/greenleaf/photos/x.jpg), so reading a file back never needs
 * to know the tenant.
 *
 * Use: uploadsDir('photos') → absolute folder (created if missing); uploadsUrl('photos') → web path.
 * No namespace / no autoloader in production: require_once.
 */
require_once __DIR__ . '/Tenant.php';

if (!function_exists('uploadsBase')) {
    /** The business's uploads root, relative to the web root: "uploads" or "uploads/t/<tenant>". */
    function uploadsBase(): string
    {
        return Tenant::isDefault() ? 'uploads' : 'uploads/t/' . Tenant::key();
    }
}

if (!function_exists('uploadsDir')) {
    /**
     * Absolute folder for an upload area ("photos", "receipts/thumbs"…), created if missing. No trailing slash.
     * $area may not climb out ("..") or start with "/".
     */
    function uploadsDir(string $area = ''): string
    {
        $area = trim(str_replace('\\', '/', $area), '/');
        if ($area !== '' && (strpos($area, '..') !== false || !preg_match('#^[A-Za-z0-9_./-]+$#', $area))) {
            throw new InvalidArgumentException('Bad upload area.');
        }
        $root = defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 2) . '/public';
        $dir = rtrim($root, '/') . '/' . uploadsBase() . ($area !== '' ? '/' . $area : '');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // A business's receipts are never served directly (as /uploads/receipts/.htaccess does for the original
        // business): only serve-receipt.php reads them (Codex 2026-10-23).
        if (!Tenant::isDefault() && ($area === 'receipts' || strpos($area, 'receipts/') === 0)) {
            $deny = rtrim($root, '/') . '/' . uploadsBase() . '/receipts/.htaccess';
            if (!is_file($deny)) @file_put_contents($deny, Uploads::DENY_ALL);
        }
        return $dir;
    }
}

if (!function_exists('uploadsUrl')) {
    /** Web path for an upload area: "/uploads/photos" (original business) or "/uploads/t/<tenant>/photos". */
    function uploadsUrl(string $area = ''): string
    {
        $area = trim(str_replace('\\', '/', $area), '/');
        return '/' . uploadsBase() . ($area !== '' ? '/' . $area : '');
    }
}

if (!function_exists('uploadsInside')) {
    /**
     * True when $absPath (a file on disk) really lies inside the current business's upload $area — resolved
     * with realpath and compared on a folder boundary ("receipts/" never matches "receipts-private/").
     * Use before serving, zipping or deleting a file named by a stored path (Codex 2026-10-23).
     */
    function uploadsInside(string $absPath, string $area): bool
    {
        $real = realpath($absPath);
        $base = realpath(uploadsDir($area));
        return $real !== false && $base !== false && strpos($real, rtrim($base, '/') . '/') === 0;
    }
}

final class Uploads
{
    public const DENY_ALL = "# Receipts are served only through serve-receipt.php (Ittai).\nOptions -Indexes\nRequire all denied\n";

    /**
     * Create a business's uploads folder with an .htaccess that serves its files only on its own addresses.
     * Called when a business is set up (provisioning). Idempotent.
     */
    public static function ensureTenantFolder(string $key, array $hosts): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $key) || $key === Tenant::DEFAULT) throw new InvalidArgumentException('Bad tenant key.');
        $root = defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 2) . '/public';
        $dir = rtrim($root, '/') . '/uploads/t/' . $key;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', self::htaccess($hosts));
        if (!is_dir($dir . '/receipts')) @mkdir($dir . '/receipts', 0755, true);
        file_put_contents($dir . '/receipts/.htaccess', self::DENY_ALL);
        return $dir;
    }

    /**
     * Pure: the .htaccess for a business's folder — its own hosts only, no listings, no scripts. Uses Apache 2.4's
     * core <If> (no mod_rewrite needed), so the host rule can't silently vanish (Codex 2026-10-23).
     */
    public static function htaccess(array $hosts): string
    {
        $alts = [];
        foreach ($hosts as $h) {
            $h = strtolower(trim((string)$h));
            if (preg_match('/^[a-z0-9.-]+$/', $h)) $alts[] = str_replace('.', '\\.', $h);
        }
        $rule = $alts
            ? "<If \"%{HTTP_HOST} !~ /^(" . implode('|', $alts) . ")(:[0-9]+)?$/i\">\n    Require all denied\n</If>\n"
            : "Require all denied\n";   // no valid host: refuse everyone
        return "# One business's files: served only on its own address (Ittai). Written by Uploads::ensureTenantFolder().\n"
            . "Options -Indexes\n" . $rule
            . "<FilesMatch \"(?i)\\.(ph(p\\d*|t|tml|ar|ps)|inc|cgi|pl|py|sh)$\">\n    Require all denied\n</FilesMatch>\n";
    }
}
