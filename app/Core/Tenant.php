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
 * The original business's own app address (for the phone sign-in directory, 2026-11-11) is
 *   $ITTAI_DEFAULT_APP_URL = 'https://mowology.ca';
 * in the same file.
 *
 * Directory mode (2026-11-11): a script on the marketing address that must reach every business (the phone
 * sign-in, public_html/ittai.ca/api/sign-in.php) defines ITTAI_DIRECTORY_MODE before boot(). Its request is then
 * the "directory", which has no database of its own; it opens each business's with Database::forBusiness().
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
    /** The marketing address's own scripts in directory mode: no business, no database. */
    public const DIRECTORY = 'directory';

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

    /**
     * Pure: directory mode's answer for a web host — the directory on the marketing addresses (and on localhost
     * in dev), else null so the host resolves exactly as it always has.
     */
    public static function directoryFor(?string $host, bool $dev): ?array
    {
        $h = rtrim((string)preg_replace('/:\d+$/', '', strtolower(trim((string)$host))), '.');
        if (in_array($h, self::NOT_TENANT, true) || ($dev && $h === 'localhost')) {
            return ['key' => self::DIRECTORY, 'cfg' => []];
        }
        return null;
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
        // A cron/CLI run with no business named is the original business (never the web's empty-Host rule).
        if ($cli && ($key === null || $key === '')) {
            self::$current = ['key' => self::DEFAULT, 'cfg' => []];
            return;
        }
        $r = self::resolve($cli ? null : (string)($_SERVER['HTTP_HOST'] ?? ''), $key, $registry);
        if (!$cli && defined('ITTAI_DIRECTORY_MODE') && ITTAI_DIRECTORY_MODE === true) {
            $dev = (defined('APP_ENV') && APP_ENV === 'dev') || getenv('APP_ENV') === 'dev';
            $r = self::directoryFor((string)($_SERVER['HTTP_HOST'] ?? ''), $dev) ?? $r;
        }
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
        return self::registryFile()[0];
    }

    /** tenants.php read once per call: [$ITTAI_TENANTS merged with secrets.php's, $ITTAI_DEFAULT_APP_URL]. */
    private static function registryFile(): array
    {
        $reg = [];
        $defaultUrl = '';
        $file = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 2) . '/public') . '/app_config/tenants.php';
        if (is_file($file)) {
            $ITTAI_TENANTS = [];
            $ITTAI_DEFAULT_APP_URL = '';
            include $file;
            if (is_array($ITTAI_TENANTS)) $reg = $ITTAI_TENANTS;
            if (is_string($ITTAI_DEFAULT_APP_URL)) $defaultUrl = $ITTAI_DEFAULT_APP_URL;
        }
        if (isset($GLOBALS['ITTAI_TENANTS']) && is_array($GLOBALS['ITTAI_TENANTS'])) {
            foreach ($GLOBALS['ITTAI_TENANTS'] as $k => $cfg) $reg[$k] = array_merge($reg[$k] ?? [], (array)$cfg);
        }
        if (isset($GLOBALS['ITTAI_DEFAULT_APP_URL']) && is_string($GLOBALS['ITTAI_DEFAULT_APP_URL'])) {
            $defaultUrl = $GLOBALS['ITTAI_DEFAULT_APP_URL'];
        }
        return [$reg, $defaultUrl];
    }

    /**
     * Every business on this install, for the phone sign-in directory (2026-11-11): the original business first,
     * then each valid registered one by key. Each is ['key', 'app_url', 'db' => [name, user, pass], 'test' => bool].
     * app_url is '' when it is missing or not one of the business's own hosts (the same rule as appUrl()); the
     * original business's comes from $ITTAI_DEFAULT_APP_URL and only needs to be a plain https address.
     * Never touches the current request's tenant, so it works in directory mode.
     */
    public static function businesses(?array $registry = null, ?string $defaultAppUrl = null): array
    {
        if ($registry === null || $defaultAppUrl === null) {
            [$fileReg, $fileUrl] = self::registryFile();
            $registry = $registry ?? $fileReg;
            $defaultAppUrl = $defaultAppUrl ?? $fileUrl;
        }
        $u = rtrim(trim($defaultAppUrl), '/');
        $list = [[
            'key'     => self::DEFAULT,
            'app_url' => preg_match('#^https://[a-z0-9.-]+$#i', $u) ? $u : '',
            'db'      => [
                defined('DB_NAME') ? (string)DB_NAME : '',
                defined('DB_USER') ? (string)DB_USER : '',
                defined('DB_PASS') ? (string)DB_PASS : '',
            ],
            'test'    => false,
        ]];
        foreach (self::registeredKeys($registry) as $key) {
            // A registry key may never stand in for the original business or the directory.
            if ($key === self::DEFAULT || $key === self::DIRECTORY) continue;
            $cfg = $registry[$key];
            $list[] = [
                'key'     => $key,
                'app_url' => self::appUrlFor($cfg),
                'db'      => [
                    (string)$cfg['db_name'],
                    (string)($cfg['db_user'] ?? (defined('DB_USER') ? DB_USER : '')),
                    (string)($cfg['db_pass'] ?? (defined('DB_PASS') ? DB_PASS : '')),
                ],
                'test'    => self::isTestCfg($cfg),
            ];
        }
        return $list;
    }

    /** Registered business keys, for CLI fan-out. */
    public static function registeredKeys(?array $registry = null): array
    {
        $keys = [];
        foreach ($registry ?? self::registry() as $key => $cfg) {
            if (self::valid($cfg)) $keys[] = (string)$key;
        }
        sort($keys, SORT_STRING);
        return $keys;
    }

    public static function key(): string
    {
        return self::$current['key'] ?? self::DEFAULT;
    }

    public static function isDefault(): bool
    {
        return self::key() === self::DEFAULT;
    }

    /**
     * Only the original business takes cards, from its own customers. Ittai launches no charges for other
     * businesses: their clients pay on QuickBooks' own page (Bank of Canada RPAA scenarios; Tim 2026-11-09).
     */
    public static function takesCards(): bool
    {
        return self::isDefault();
    }

    /** Pure registry-config resolver: only an explicit boolean true marks a business as a test business. */
    public static function isTestCfg(array $cfg): bool
    {
        return isset($cfg['test']) && $cfg['test'] === true;
    }

    /** The original business is never a test business, regardless of registry/global configuration. */
    public static function isTest(): bool
    {
        return !self::isDefault() && self::isTestCfg((array)(self::$current['cfg'] ?? []));
    }

    /** The database this tenant uses: [name, user, pass] — the install's own for the default tenant. */
    public static function db(): array
    {
        $c = self::$current['cfg'] ?? [];
        $k = self::key();
        // Nothing on the marketing address may fall through to the original business's database (2026-11-11).
        if ($k === self::DIRECTORY) {
            throw new RuntimeException('The directory has no database of its own');
        }
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
        return self::appUrlFor((array)(self::$current['cfg'] ?? []));
    }

    /** Pure: a registry entry's app_url, or '' when it is not one of that entry's own hosts. */
    private static function appUrlFor(array $cfg): string
    {
        $u = rtrim((string)($cfg['app_url'] ?? ''), '/');
        if (!preg_match('#^https://([a-z0-9.-]+)$#i', $u, $m)) return '';
        // Only one of this business's own registered hosts: a typo or a bad entry can never send its links
        // (password resets included) to another business or an outside address (Codex 2026-10-23).
        $hosts = array_map('strtolower', (array)($cfg['hosts'] ?? []));
        return in_array(strtolower($m[1]), $hosts, true) ? $u : '';
    }

    /** This business's own registered address, for links when no app_url is set; '' for the default tenant. */
    public static function ownAddress(): string
    {
        if (self::isDefault()) return '';
        $host = (string)(((array)(self::$current['cfg']['hosts'] ?? []))[0] ?? '');
        return preg_match('/^[a-z0-9.-]+$/i', $host) ? 'https://' . strtolower($host) : '';
    }

    /** Tests only. */
    public static function forTests(?array $current): void
    {
        self::$current = $current;
    }
}
