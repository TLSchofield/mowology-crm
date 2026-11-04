<?php
declare(strict_types=1);

/**
 * /app/Core/config.php
 * App-wide config: loads secrets, defines helpers, and provides Database access.
 * No session_start here (that lives in session_config.php).
 *
 * Migrated from /public/app_config/config.php
 */

// Ensure path constants are available
if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/paths.php';
}

// Set timezone early (stable)
date_default_timezone_set('America/Vancouver');

// Load secrets first (single source of truth) — secrets.php stays in
// /public/app_config/ (not in git). It may OPTIONALLY define('APP_ENV','dev')
// on a local/dev machine; production's secrets.php leaves it undefined so we
// fall through to the safe 'prod' default below.
require_once PUBLIC_ROOT . '/app_config/secrets.php';

// Which business this request is for, before any database opens: an unregistered *.ittai.ca address stops
// here with 404; every other address is the original business, unchanged (Ittai, 2026-10-23).
require_once __DIR__ . '/Tenant.php';
Tenant::boot();

// Environment flags. SAFE DEFAULT IS 'prod' — a box that forgot to set this must
// NOT leak warnings/paths to users or API consumers. Override precedence:
//   secrets.php define('APP_ENV',...)  >  APP_ENV env var (cPanel SetEnv / .user.ini)  >  'prod'
if (!defined('APP_ENV')) {
    $appEnv = getenv('APP_ENV');
    define('APP_ENV', ($appEnv === 'dev') ? 'dev' : 'prod');
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', APP_ENV !== 'prod');
}

// Error reporting policy (safe defaults)
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

/**
 * Backwards-compatible DB accessor used across older pages.
 * Prefer Database::pdo() in new code.
 */
if (!function_exists('getDB')) {
    function getDB(): PDO {
        return Database::pdo();
    }
}

/**
 * HTML escape helper
 */
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Cryptographically secure token generator
 */
if (!function_exists('secure_random_token')) {
    function secure_random_token(int $bytes = 32): string {
        return bin2hex(random_bytes($bytes));
    }
}

/**
 * CSRF helpers
 * Requires session to be started by session_config.php on pages that use CSRF.
 */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Fail closed: caller must include session_config.php first
            throw new RuntimeException('Session not started before csrf_token(). Include session_config.php.');
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = secure_random_token(32);
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $sess = $_SESSION['csrf_token'] ?? '';
        if (!is_string($sess) || $sess === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($sess, $token);
    }
}

/**
 * PDO Database singleton
 *
 * Two MySQL users (Fable security audit #27, 2026-10-12):
 *   DB_USER / DB_PASS              — the app's everyday user. On production this should be a
 *                                    DATA-ONLY user (SELECT, INSERT, UPDATE, DELETE, EXECUTE,
 *                                    LOCK TABLES, CREATE TEMPORARY TABLES) so an SQL injection or
 *                                    a stolen session cannot DROP or ALTER anything.
 *   DB_DDL_HOST / DB_DDL_USER / DB_DDL_PASS (optional, secrets.php)
 *                                  — the schema-owning user, used ONLY by the migration runners
 *                                    (migrationRunnerGuard → Database::useDdlForRequest(), and
 *                                    migrations-manager.php) and the few admin repair tools that
 *                                    must change the schema (Database::ddl()).
 * With no DB_DDL_* defined, ddl() is simply the normal connection — nothing changes.
 */
if (!class_exists('Database')) {
    class Database
    {
        private static ?PDO $pdo = null;
        private static ?PDO $ddl = null;

        public static function pdo(): PDO
        {
            if (self::$pdo instanceof PDO) {
                return self::$pdo;
            }
            [, $tenantUser, $tenantPass] = Tenant::db();   // the install's user unless the tenant has its own
            return self::$pdo = self::connect(DB_HOST, $tenantUser, $tenantPass);
        }

        /** True when secrets.php names a separate schema-owning user. */
        public static function hasDdlUser(): bool
        {
            return defined('DB_DDL_USER') && (string)DB_DDL_USER !== '' && defined('DB_DDL_PASS');
        }

        /** The connection allowed to CREATE / ALTER / DROP — the normal one when no DDL user is set. */
        public static function ddl(): PDO
        {
            if (!self::hasDdlUser()) {
                return self::pdo();
            }
            if (self::$ddl instanceof PDO) {
                return self::$ddl;
            }
            $host = defined('DB_DDL_HOST') && (string)DB_DDL_HOST !== '' ? DB_DDL_HOST : DB_HOST;
            return self::$ddl = self::connect($host, DB_DDL_USER, DB_DDL_PASS);
        }

        /**
         * For the rest of THIS request, getDB() / Database::pdo() return the DDL connection.
         * Called by migrationRunnerGuard() once an admin has confirmed a migration run, so the
         * 150-odd run-migration-*.php scripts need no edit.
         */
        public static function useDdlForRequest(): void
        {
            if (self::hasDdlUser()) {
                self::$pdo = self::ddl();
            }
        }

        private static function connect(
            string $host,
            string $user,
            // Kept out of stack traces (PHP 8.2+; a comment to older PHP — keep it on its own line).
            #[\SensitiveParameter]
            string $pass
        ): PDO
        {
            $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $host,
                Tenant::db()[0],   // the tenant's own database (DB_NAME for the original business)
                $charset
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $pdo = new PDO($dsn, $user, $pass, $options);

            // Align MySQL session timezone with PHP (America/Vancouver / Pacific).
            // Use UTC offset instead of named timezone — shared hosting MySQL often
            // lacks the timezone name tables loaded, causing "Unknown time zone" errors.
            $utcOffset = date('P'); // e.g. "-08:00" or "-07:00" (DST-aware from PHP)
            $pdo->exec("SET time_zone = '" . $utcOffset . "'");

            return $pdo;
        }
    }
}
