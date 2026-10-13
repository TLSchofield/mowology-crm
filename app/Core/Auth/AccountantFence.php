<?php
declare(strict_types=1);

/**
 * app/Core/Auth/AccountantFence.php — an outside accountant's login sees the Ledger and nothing else
 * (Accountant's Ledger step 1, 2026-10-13).
 *
 * Why a fence and not only permission keys: many CRM pages check only requireLogin() (dashboard,
 * tasks, messages, the crew app launcher that pings GPS), so a login with no keys would still reach
 * them. The accountant role (users.role = 'accountant', RBAC role 'accountant') holds only
 * ledger.view + ledger.propose, AND every session request from that login is checked here against a
 * short allowlist: deny by default. A page added later is closed to the accountant until someone
 * adds it here on purpose.
 *
 * Enforced from session_config.php (deny/redirect, no database needed) and auth.php (also logs every
 * allowed page view / export to activity_log as 'ledger.view' / 'ledger.export'). JWT: token.php
 * refuses to issue a token to an accountant and jwtCurrentUser() treats one as signed out.
 *
 * Pure rules + two small effectful helpers. Tested in tests/Unit/Ledger/AccountantFenceTest.php.
 */
final class AccountantFence
{
    public const ROLE = 'accountant';

    /** Paths an accountant may open (prefix match). Everything else under a fenced root is refused. */
    public const ALLOWED_PREFIXES = [
        '/crm/ledger/',
        '/loginAuth/',
    ];

    /** Single files an accountant may open. */
    public const ALLOWED_EXACT = [
        '/crm/accounting/trial-balance.php',
        '/crm/accounting/balance-sheet.php',
        '/crm/accounting/income-statement.php',
        '/crm/api/get-csrf.php',
        '/crm/logout_secure.php',
        '/api/expenses/receipt-image',     // HMAC-signed receipt photo links the Ledger hands out
    ];

    /** Landing pages that send the accountant to the Ledger instead of a 403. */
    public const LANDINGS = [
        '/crm', '/crm/', '/crm/index.php', '/crm/dashboard_appstack.php', '/crm/app-launch.php',
    ];

    /** The fence applies under these roots; the public website and customer portal are not CRM pages. */
    public const FENCED_ROOTS = ['/crm', '/api/', '/cms/', '/jobFlow/', '/site-admin/', '/bluemoon/', '/client/',
                                 '/language/', '/database/', '/layouts/', '/includes/', '/storage/', '/downloads/'];

    public const HOME = '/crm/ledger/';

    public static function isAccountantRole(?string $role): bool
    {
        return strtolower(trim((string)$role)) === self::ROLE;
    }

    /** Normalise a request URI to its path ("/crm/ledger/journal.php"), collapsing "//" and dot segments. */
    public static function pathOf(string $uri): string
    {
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($parts); continue; }
            $parts[] = $seg;
        }
        $norm = '/' . implode('/', $parts);
        if ($norm !== '/' && substr($path, -1) === '/') $norm .= '/';
        return $norm;
    }

    public static function isFenced(string $path): bool
    {
        foreach (self::FENCED_ROOTS as $root) {
            if ($path === rtrim($root, '/') || strpos($path, $root) === 0) return true;
        }
        return false;
    }

    /** May an accountant open this path? */
    public static function allows(string $path): bool
    {
        if (in_array($path, self::ALLOWED_EXACT, true)) return true;
        foreach (self::ALLOWED_PREFIXES as $p) {
            if (strpos($path, $p) === 0) return true;
        }
        if ($path === rtrim(self::HOME, '/')) return true;
        return !self::isFenced($path);
    }

    /**
     * What to do with an accountant's request:
     *   'allow' | 'redirect' (to the Ledger home) | 'deny' (403).
     */
    public static function verdict(?string $role, string $uri): string
    {
        if (!self::isAccountantRole($role)) return 'allow';
        $path = self::pathOf($uri);
        if (self::allows($path)) return 'allow';
        if (in_array($path, self::LANDINGS, true)) return 'redirect';
        return 'deny';
    }

    /** May a JWT (iPhone / Android app) be issued to, or honoured for, this role? Never for an accountant. */
    public static function jwtAllowed(?string $role): bool
    {
        return !self::isAccountantRole($role);
    }

    /** Which activity_log action an allowed accountant request is, or null when it is not worth a row. */
    public static function auditAction(string $uri, array $query): ?string
    {
        $path = self::pathOf($uri);
        if (strpos($path, '/loginAuth/') === 0 || $path === '/crm/api/get-csrf.php' || $path === '/crm/logout_secure.php') return null;
        if (!self::isFenced($path)) return null;
        return (isset($query['export']) && $query['export'] !== '') ? 'ledger.export' : 'ledger.view';
    }

    // ── Effectful helpers (web requests only) ────────────────────────────────

    /** Is the signed-in session an accountant's? */
    public static function activeForSession(): bool
    {
        return PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE
            && !empty($_SESSION['user_id']) && self::isAccountantRole((string)($_SESSION['user_role'] ?? ''));
    }

    /** Called from session_config.php / auth.php: refuse or redirect anything outside the fence. */
    public static function enforce(): void
    {
        if (!self::activeForSession()) return;
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $v = self::verdict((string)$_SESSION['user_role'], $uri);
        if ($v === 'allow') return;
        if (headers_sent()) exit;
        if ($v === 'redirect') {
            header('Location: ' . self::HOME);
            exit;
        }
        http_response_code(403);
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $path = self::pathOf($uri);
        if (strpos($accept, 'application/json') !== false || strpos($path, '/api/') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Not available to the accountant login']);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Not available</title></head><body>'
           . '<h1>Not available</h1><p>This login opens the Ledger only.</p>'
           . '<p><a href="' . self::HOME . '">Go to the Ledger</a></p></body></html>';
        exit;
    }

    /** Called from auth.php once the database is available: one activity_log row per accountant page view / export. */
    public static function logRequest(): void
    {
        static $done = false;
        if ($done || !self::activeForSession() || !function_exists('logActivity')) return;
        $done = true;
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $action = self::auditAction($uri, $_GET);
        if ($action === null) return;
        $details = json_encode(['page' => self::pathOf($uri), 'params' => self::loggableParams($_GET),
                                'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')],
                               JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        logActivity((int)$_SESSION['user_id'], null, $action, $details);
    }

    /** Query params worth keeping in the audit row (signed-link secrets left out). */
    public static function loggableParams(array $q): array
    {
        $out = [];
        foreach ($q as $k => $v) {
            if (in_array((string)$k, ['s', 'token', 'csrf_token'], true)) continue;
            $out[(string)$k] = is_scalar($v) ? mb_substr((string)$v, 0, 120) : '[list]';
        }
        return $out;
    }
}
