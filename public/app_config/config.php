<?php
declare(strict_types=1);

/**
 * /app_config/config.php
 * App-wide config: loads secrets, defines helpers, and provides Database access.
 * No session_start here (that lives in session_config.php).
 */

// Set timezone early (stable)
date_default_timezone_set('America/Vancouver');

// Environment flags (set APP_ENV in server config if you want)
if (!defined('APP_ENV')) {
    // dev | prod
    define('APP_ENV', 'dev');
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

// Load secrets (single source of truth)
require_once __DIR__ . '/secrets.php';
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
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            $charset
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return self::$pdo;
    }
}
