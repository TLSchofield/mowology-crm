<?php
declare(strict_types=1);

/**
 * Tenant — which business a request (or a cron run) is for, decided BEFORE any database is opened
 * (Ittai founding businesses, 2026-10-23).
 *
 * One installation serves several businesses, each with its own database, session folder and address.
 * Only addresses under ittai.ca are routed: a registered one gets its business; an unregistered one gets a
 * plain "not found" and never a database. Every other address (mowology.ca, local dev…) is the original
 * business, exactly as before — key "default".
 *
 * Registry — no secrets needed: `$ITTAI_TENANTS` in public/app_config/tenants.php (and/or secrets.php):
 *   $ITTAI_TENANTS = [
 *     'greenleaf' => ['hosts' => ['greenleaf.ittai.ca'], 'db_name' => 'mowology_greenleaf',
 *                     'app_url' => 'https://greenleaf.ittai.ca'],
 *   ];
 * A tenant uses the install's database user (on cPanel, grant that user the tenant's database). A tenant with
 * its own user puts 'db_user'/'db_pass' in secrets.php only (credentials live nowhere else).
 * Cron / CLI: MW_TENANT=<key> env var or a --tenant=<key> argument; none = default.
 *
 * No namespace / no autoloader in production: require_once.
 */
final class Tenant
{
    public const DEFAULT = 'default';
    /** The routed domain: every address under it must be a registered business. */
    public const ROUTED_SUFFIX = '.ittai.ca';
    /** Addresses under the routed domain that are not businesses (the marketing site): never the app. */
    public const NOT_TENANT = ['ittai.ca', 'www.ittai.ca'];

    private static ?array $current = null;

    /**
     * Pure: the tenant for a host / CLI key. Returns ['key' => string, 'cfg' => array], or ['key' => null]
     * when the address is a routed one that isn't registered (or the CLI key is unknown).
     */
    public static function resolve(?string $host, ?string $cliKey, array $registry): array
    {
        if ($cliKey !== null && $cliKey !== '') {
            if ($cliKey === self::DEFAULT) return ['key' => self::DEFAULT, 'cfg' => []];
            return isset($registry[$cliKey]) && self::valid($registry[$cliKey]) ? ['key' => $cliKey, 'cfg' => $registry[$cliKey]] : ['key' => null];
        }
        // Fail closed (Codex 2026-10-23): an empty or malformed address, and Ittai's own marketing addresses,
        // never fall back to the original business — they are not found. Only a well-formed address outside
        // ittai.ca is the original business.
        $h = strtolower(trim((string)$host));
        if (!preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]*[a-z0-9])?\.?(:\d{1,5})?$/', $h)) return ['key' => null];
        $h = rtrim((string)preg_replace('/:\d+$/', '', $h), '.');   // drop a port and a trailing dot
        if ($h === ltrim(self::ROUTED_SUFFIX, '.') || in_array($h, self::NOT_TENANT, true)) return ['key' => null];
        if (substr($h, -strlen(self::ROUTED_SUFFIX)) !== self::ROUTED_SUFFIX) {
            return ['key' => self::DEFAULT, 'cfg' => []];
        }
        foreach ($registry as $key => $cfg) {
            if (!self::valid($cfg)) continue;
            foreach ((array)$cfg['hosts'] as $th) {
                if (strtolower((string)$th) === $h) return ['key' => (string)$key, 'cfg' => $cfg];
            }
        }
        return ['key' => null];
    }

    /** A registry entry must name its hosts and its own database — never the default one. */
    private static function valid($cfg): bool
    {
        return is_array($cfg) && !empty($cfg['hosts']) && is_array($cfg['hosts'])
            && !empty($cfg['db_name']) && is_string($cfg['db_name'])
            && (!defined('DB_NAME') || $cfg['db_name'] !== DB_NAME)
            && preg_match('/^[A-Za-z0-9_]+$/', $cfg['db_name']);
    }

    /** Decide the tenant for this request/run; an unknown routed address ends here with 404. Call once, early. */
    public static function boot(): void
    {
        if (self::$current !== null) return;
        $registry = self::registry();
        $cli = PHP_SAPI === 'cli';
        $key = null;
        if ($cli) {
            $key = getenv('MW_TENANT') !== false ? (string)getenv('MW_TENANT') : null;
            foreach ((array)($_SERVER['argv'] ?? []) as $a) {
                if (strpos((string)$a, '--tenant=') === 0) $key = substr((string)$a, 9);
            }
        }
        $r = self::resolve($cli ? null : (string)($_SERVER['HTTP_HOST'] ?? ''), $key, $registry);
        if ($r['key'] === null) {
            if ($cli) {
                fwrite(STDERR, "Unknown tenant: {$key}\n");
                exit(1);
            }
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo "Not found.\n";
            exit;
        }
        self::$current = $r;
    }

    /** The registry from tenants.php and secrets.php (secrets.php may add db_user/db_pass to an entry). */
    private static function registry(): array
    {
        $reg = [];
        $file = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 2) . '/public') . '/app_config/tenants.php';
        if (is_file($file)) {
            $ITTAI_TENANTS = [];
            include $file;
            if (is_array($ITTAI_TENANTS)) $reg = $ITTAI_TENANTS;
        }
        if (isset($GLOBALS['ITTAI_TENANTS']) && is_array($GLOBALS['ITTAI_TENANTS'])) {
            foreach ($GLOBALS['ITTAI_TENANTS'] as $k => $cfg) $reg[$k] = array_merge($reg[$k] ?? [], (array)$cfg);
        }
        return $reg;
    }

    public static function key(): string
    {
        return self::$current['key'] ?? self::DEFAULT;
    }

    public static function isDefault(): bool
    {
        return self::key() === self::DEFAULT;
    }

    /** The database this tenant uses: [name, user, pass] — the install's own for the default tenant. */
    public static function db(): array
    {
        $c = self::$current['cfg'] ?? [];
        $k = self::key();
        // Sessions boot the tenant before secrets.php loads: read any secrets.php additions (db_user/db_pass) now.
        if ($k !== self::DEFAULT && isset($GLOBALS['ITTAI_TENANTS'][$k]) && is_array($GLOBALS['ITTAI_TENANTS'][$k])) {
            $c = array_merge($c, $GLOBALS['ITTAI_TENANTS'][$k]);
        }
        if ($k !== self::DEFAULT && defined('DB_NAME') && ($c['db_name'] ?? '') === DB_NAME) {
            throw new RuntimeException('A business may never use the original business\'s database.');
        }
        return [
            (string)($c['db_name'] ?? (defined('DB_NAME') ? DB_NAME : '')),
            (string)($c['db_user'] ?? (defined('DB_USER') ? DB_USER : '')),
            (string)($c['db_pass'] ?? (defined('DB_PASS') ? DB_PASS : '')),
        ];
    }

    /** The tenant's own address for links ('' for the default tenant: BusinessIdentity uses its website). */
    public static function appUrl(): string
    {
        $u = (string)(self::$current['cfg']['app_url'] ?? '');
        return preg_match('#^https://[a-z0-9.-]+$#i', rtrim($u, '/')) ? rtrim($u, '/') : '';
    }

    /** Tests only. */
    public static function forTests(?array $current): void
    {
        self::$current = $current;
    }
}
