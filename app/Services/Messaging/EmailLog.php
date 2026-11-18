<?php
/**
 * EmailLog — one line per email send attempt, per business, outside the web folder.
 *
 * Security audit 2026-11-08: the old log sat in public/crm/email-logs/ (one folder for every business), kept the
 * first 500 characters of each email (sign-up codes, reset links, client details) for ever, and two viewer pages
 * showed it to any signed-in user of any business. Now: no email text and no headers are written, each business
 * has its own folder under STORAGE_ROOT/logs/email/ (the default business at the top, any other in t/<key>/, the
 * same rule as PdfStore), and files older than KEEP_DAYS are deleted when a new day's file starts.
 *
 * Pure helpers plus the file write. No namespace / no autoloader.
 */
require_once dirname(__DIR__, 2) . '/Core/Tenant.php';

final class EmailLog
{
    public const KEEP_DAYS = 30;
    private const FILE_PATTERN = '/^email-(\d{4}-\d{2}-\d{2})\.log$/';

    /** This business's log folder under $root (STORAGE_ROOT), or null when the business key is not a safe folder name. */
    public static function dir(string $root): ?string
    {
        $dir = rtrim($root, '/') . '/logs/email';
        if (Tenant::isDefault()) return $dir;
        $key = Tenant::key();
        if (!preg_match('/^[a-z0-9-]+$/', $key)) return null;
        return $dir . '/t/' . $key;
    }

    /** How the email left: the PHPMailer label the caller passes, or "mail()" when it passed raw headers. */
    public static function transport(string $headers): string
    {
        if (strncmp($headers, 'PHPMailer', 9) !== 0) return 'mail()';
        return self::clean($headers, 80);
    }

    /** One tab-separated line: time, sent/failed, recipient, subject, transport, size of the email. Never its text. */
    public static function line(string $to, string $subject, string $headers, bool $ok, int $bodyLength, int $now): string
    {
        return implode("\t", [
            date('Y-m-d H:i:s', $now),
            $ok ? 'sent' : 'failed',
            self::clean($to, 200),
            self::clean($subject, 150),
            self::transport($headers),
            $bodyLength . ' bytes',
        ]) . "\n";
    }

    /** Append one attempt to today's file for this business; start-of-day also prunes old files. Returns the file, or null if nothing was written. */
    public static function record(string $to, string $subject, string $headers, bool $ok, int $bodyLength, ?string $root = null, ?int $now = null): ?string
    {
        $root = $root ?? (defined('STORAGE_ROOT') ? STORAGE_ROOT : '');
        if ($root === '') return null;
        $dir = self::dir($root);
        if ($dir === null) return null;
        $now = $now ?? time();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return null;

        $file = $dir . '/email-' . date('Y-m-d', $now) . '.log';
        $newDay = !is_file($file);
        if (@file_put_contents($file, self::line($to, $subject, $headers, $ok, $bodyLength, $now), FILE_APPEND | LOCK_EX) === false) {
            return null;
        }
        if ($newDay) {
            @chmod($file, 0640);
            self::prune($dir, $now);
        }
        return $file;
    }

    /** Delete this folder's daily files dated more than KEEP_DAYS before $now. Returns how many were deleted. */
    public static function prune(string $dir, int $now): int
    {
        $cutoff = date('Y-m-d', $now - self::KEEP_DAYS * 86400);
        $deleted = 0;
        foreach (@scandir($dir) ?: [] as $name) {
            if (!preg_match(self::FILE_PATTERN, $name, $m) || $m[1] >= $cutoff) continue;
            if (is_file($dir . '/' . $name) && @unlink($dir . '/' . $name)) $deleted++;
        }
        return $deleted;
    }

    /** One field on one line: control characters (line breaks, tabs) become spaces, then cut to $max. */
    private static function clean(string $value, int $max): string
    {
        $value = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
