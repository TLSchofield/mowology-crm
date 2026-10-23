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

final class Uploads
{
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
        return $dir;
    }

    /** Pure: the .htaccess for a business's folder — its own hosts only, no scripts, no listings. */
    public static function htaccess(array $hosts): string
    {
        $conds = [];
        foreach ($hosts as $h) {
            $h = strtolower(trim((string)$h));
            if (!preg_match('/^[a-z0-9.-]+$/', $h)) continue;
            $conds[] = 'RewriteCond %{HTTP_HOST} !^' . str_replace('.', '\\.', $h) . '(:\\d+)?$ [NC]';
        }
        if (!$conds) $conds[] = 'RewriteCond %{HTTP_HOST} .';   // no valid host: refuse everyone
        return "# One business's files: served only on its own address (Ittai). Written by Uploads::ensureTenantFolder().\n"
            . "Options -Indexes\n"
            . "<IfModule mod_rewrite.c>\nRewriteEngine On\n" . implode("\n", $conds) . "\nRewriteRule ^ - [F]\n</IfModule>\n"
            . "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\nRequire all denied\n</FilesMatch>\n";
    }
}
