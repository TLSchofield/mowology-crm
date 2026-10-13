<?php
declare(strict_types=1);

/**
 * LedgerSite — the CRM's own address for links it hands out (the accountant's set-password link),
 * and a small cache for the Ledger overview's expensive figures (Accountant's Ledger review, 2026-10-13).
 *
 * Address, in order:
 *   1. the CRM_SITE_URL constant, when a deployment defines one;
 *   2. business_settings.crm_url (migration 1356);
 *   3. the current request's host — ONLY when it is in the allow-list: the host of crm_url or of
 *      company_website (with or without www), or a local development host. A forged Host header
 *      can never put another domain into a link the owner sends their accountant.
 * Nothing usable → null, and the page says so instead of guessing.
 */
final class LedgerSite
{
    /** Local development hosts (docs/LOCAL_SITE.md). */
    public const DEV_HOSTS = ['localhost', '127.0.0.1'];

    /** "https://example.com" from a URL or bare domain; null when it isn't one. Pure. */
    public static function normalise(?string $url): ?string
    {
        $url = trim((string)$url);
        if ($url === '') return null;
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !preg_match('/^[a-z0-9.-]+$/i', $p['host'])) return null;
        return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    }

    /**
     * Pick the address. Pure.
     * @param ?string $configured CRM_SITE_URL or business_settings.crm_url
     * @param ?string $website    business_settings.company_website (allow-list only)
     * @param string  $host       the request's Host header
     * @param bool    $https      was the request over https
     */
    public static function pick(?string $configured, ?string $website, string $host, bool $https): ?string
    {
        $conf = self::normalise($configured);
        if ($conf !== null) return $conf;
        $host = strtolower(trim($host));
        if (!preg_match('/^([a-z0-9.-]+)(:\d{1,5})?$/', $host, $m)) return null;
        $name = $m[1];
        $allowed = self::DEV_HOSTS;
        foreach ([$website] as $u) {
            $n = self::normalise($u);
            if ($n === null) continue;
            $h = (string)parse_url($n, PHP_URL_HOST);
            $allowed[] = $h;
            $allowed[] = strpos($h, 'www.') === 0 ? substr($h, 4) : 'www.' . $h;
        }
        if (!in_array($name, $allowed, true)) return null;
        $dev = in_array($name, self::DEV_HOSTS, true);
        return ($https || !$dev ? 'https' : 'http') . '://' . $host;
    }

    /** The CRM's address for this request, or null. */
    public static function url(PDO $db): ?string
    {
        $configured = defined('CRM_SITE_URL') ? (string)CRM_SITE_URL : null;
        $website = null;
        if ($configured === null) {
            try {
                $r = $db->query("SELECT crm_url, company_website FROM business_settings ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $configured = $r['crm_url'] ?? null;
                $website = $r['company_website'] ?? null;
            } catch (Throwable $e) {   // before migration 1356
                try { $website = $db->query("SELECT company_website FROM business_settings ORDER BY id LIMIT 1")->fetchColumn() ?: null; }
                catch (Throwable $e2) { $website = null; }
            }
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return self::pick($configured, $website, (string)($_SERVER['HTTP_HOST'] ?? ''), $https);
    }

    // ── A five-minute cache for the overview (GIFI readiness, exception count) ──────────────────

    public const CACHE_TTL = 300;

    private static function cacheFile(string $key): string
    {
        $base = defined('STORAGE_ROOT') && is_dir(STORAGE_ROOT) && is_writable(STORAGE_ROOT) ? STORAGE_ROOT . '/cache' : sys_get_temp_dir() . '/mw-cache';
        if (!is_dir($base)) @mkdir($base, 0750, true);
        $db = defined('DB_NAME') ? (string)DB_NAME : 'db';
        return $base . '/ledger-' . sha1($db . '|' . $key) . '.json';
    }

    /**
     * The cached value of $fn() for $ttl seconds. A cache that can't be read or written just runs $fn —
     * the figure is never wrong for longer than the TTL, and never missing.
     */
    public static function remember(string $key, callable $fn, int $ttl = self::CACHE_TTL)
    {
        $file = self::cacheFile($key);
        if (is_file($file) && (time() - (int)@filemtime($file)) < $ttl) {
            $raw = @file_get_contents($file);
            $v = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($v) && array_key_exists('v', $v)) return $v['v'];
        }
        $val = $fn();
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['v' => $val])) !== false) @rename($tmp, $file);
        return $val;
    }

    /** Drop a cached figure (after a change that moves it, e.g. a GIFI save). */
    public static function forget(string $key): void
    {
        @unlink(self::cacheFile($key));
    }
}
