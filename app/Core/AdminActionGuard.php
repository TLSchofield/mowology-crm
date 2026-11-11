<?php
/**
 * adminActionGuard() — the gate in front of admin "do something now" web endpoints
 * (cron "Run Now" shims, backfills, e-Transfer poll/rematch, log clearing).
 *
 * Fable review 2026-10-12 (LOW, admin GET): these ran on a plain GET, so a link in an email or an
 * <img> on any page a signed-in admin opened could charge autopay cards, poll mailboxes, rewrite
 * history or wipe the system log. Now:
 *   - CLI (the real cPanel cron) is never gated;
 *   - web: an admin (or one of $permissions) only — anyone else gets 403 JSON;
 *   - a GET runs nothing: it shows a one-button confirmation that POSTs back with the CSRF token
 *     (query string kept, so ?dry_run=1 still means dry run);
 *   - the endpoint's own code continues only on a POST carrying a valid token
 *     (form field csrf_token, JSON body csrf_token, or the X-CSRF-Token header).
 *
 * Needs auth.php loaded (isLoggedIn/getCurrentUser/verifyCSRFToken). Pure helpers below are
 * unit-tested; the function itself exits on refusal.
 */
declare(strict_types=1);

if (!class_exists('AdminActionGuard', false)) {
    final class AdminActionGuard
    {
        /** The CSRF token a request carries, from form, JSON body or header. */
        public static function tokenFromRequest(array $post, array $server, ?string $rawBody): string
        {
            $t = $post['csrf_token'] ?? null;
            if (!is_string($t) || $t === '') {
                $t = $server['HTTP_X_CSRF_TOKEN'] ?? null;
            }
            if ((!is_string($t) || $t === '') && $rawBody !== null && $rawBody !== '' && $rawBody[0] === '{') {
                $j = json_decode($rawBody, true);
                $t = is_array($j) ? ($j['csrf_token'] ?? null) : null;
            }
            return is_string($t) ? $t : '';
        }

        /** 'run' | 'confirm' | 'refuse' for a web request from an allowed user. */
        public static function decide(string $method, string $token, callable $verify): string
        {
            $method = strtoupper($method);
            if ($method !== 'POST') return 'confirm';
            if ($token === '' || !$verify($token)) return 'refuse';
            return 'run';
        }

        public static function confirmPage(string $title, string $csrf, string $note = ''): string
        {
            $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<meta name="robots" content="noindex"><title>Run ' . $t . '</title></head>'
                . '<body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:40px auto;padding:0 16px">'
                . '<h1 style="font-size:20px">Run ' . $t . '?</h1>' . $note
                . '<p>Nothing has run yet.</p>'
                . '<form method="post"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit" style="padding:10px 18px;font-size:15px">Run it</button></form></body></html>';
        }
    }
}

if (!function_exists('adminActionGuard')) {
    /**
     * @param string   $title       Shown on the confirmation page.
     * @param string[] $permissions Any one of these also lets a non-admin through.
     */
    function adminActionGuard(string $title = '', array $permissions = []): void
    {
        if (PHP_SAPI === 'cli') return;

        $u = function_exists('getCurrentUser') && function_exists('isLoggedIn') && isLoggedIn() ? getCurrentUser() : null;
        $allowed = $u && (function_exists('isAdmin') ? isAdmin() : (($u['role'] ?? '') === 'admin'));
        if (!$allowed && $u && function_exists('userHasPermission')) {
            foreach ($permissions as $p) {
                if (userHasPermission($p)) { $allowed = true; break; }
            }
        }
        if (!$allowed) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            exit;
        }

        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $raw    = $method === 'POST' ? (string)file_get_contents('php://input') : null;
        $token  = AdminActionGuard::tokenFromRequest($_POST, $_SERVER, $raw);
        $verdict = AdminActionGuard::decide($method, $token, static function (string $t): bool {
            return function_exists('verifyCSRFToken') && verifyCSRFToken($t);
        });
        if ($verdict === 'run') return;

        if ($verdict === 'refuse') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Security token missing or expired — reload the page and try again.']);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $name = $title !== '' ? $title : basename((string)($_SERVER['SCRIPT_NAME'] ?? 'this job'), '.php');
        echo AdminActionGuard::confirmPage($name, function_exists('generateCSRFToken') ? generateCSRFToken() : '');
        exit;
    }
}
