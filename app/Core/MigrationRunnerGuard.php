<?php
/**
 * migrationRunnerGuard() — the gate in front of every public/crm/api/run-migration-*.php.
 *
 * Fable review 2026-10-12 (Ada): the runners ran schema changes on a plain GET, several for any
 * logged-in user (crew included), with no CSRF token — a link in an email or an <img> on any page
 * a signed-in admin opened could run one. Now:
 *   - only an admin (or database.manage) gets past;
 *   - a GET runs nothing: it shows a one-button confirmation that POSTs back with the CSRF token;
 *   - the runner's own code continues only on a POST carrying a valid token.
 * Call it straight after requireLogin(), before session_write_close().
 */
declare(strict_types=1);

if (!function_exists('migrationRunnerGuard')) {
    function migrationRunnerGuard(): void
    {
        $u = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $allowed = $u && ((($u['role'] ?? '') === 'admin') || (function_exists('userHasPermission') && userHasPermission('database.manage')));
        if (!$allowed) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Admin only.';
            exit;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $token = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($method === 'POST' && $token !== '' && function_exists('verifyCSRFToken') && verifyCSRFToken($token)) {
            // Schema changes run as the schema-owning MySQL user when secrets.php names one
            // (DB_DDL_*); the app's everyday user is data-only (Fable audit #27, 2026-10-12).
            if (class_exists('Database') && method_exists('Database', 'useDdlForRequest')) {
                Database::useDdlForRequest();
            }
            // Ada watches migration runs (Fable audit #25d).
            if (function_exists('logActivityExtended')) {
                logActivityExtended(isset($u['id']) ? (int)$u['id'] : null, 'Migration run',
                    'Runner ' . basename((string)($_SERVER['SCRIPT_NAME'] ?? 'migration')) . ' confirmed and started');
            } elseif (function_exists('logActivity') && isset($u['id'])) {
                logActivity((int)$u['id'], null, 'Migration run',
                    'Runner ' . basename((string)($_SERVER['SCRIPT_NAME'] ?? 'migration')) . ' confirmed and started');
            }
            return;
        }
        http_response_code($method === 'POST' ? 403 : 200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $csrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
        $name = htmlspecialchars(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'migration')), ENT_QUOTES, 'UTF-8');
        $msg = $method === 'POST' ? '<p style="color:#b00">The security token expired. Press the button again.</p>' : '';
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Run ' . $name . '</title></head><body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:40px auto;padding:0 16px">'
           . '<h1 style="font-size:20px">Run ' . $name . '?</h1>' . $msg
           . '<p>This changes the database. Nothing has run yet.</p>'
           . '<form method="post"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">'
           . '<button type="submit" style="padding:10px 18px;font-size:15px">Run it</button></form></body></html>';
        exit;
    }
}
