<?php
/**
 * Site Admin Auth
 *
 * Authentication for the tenant site-admin panel.
 * Uses cms_site_users (separate from CRM users table).
 * Site is determined by the request domain via CMS_SITE_ID.
 */

declare(strict_types=1);

// Ensure bootstrap has run (provides getDB, CMS_SITE_ID, etc.)
if (!defined('CMS_SITE_ID')) {
    $__bootstrapPath = dirname(__DIR__, 2) . '/includes/bootstrap.php';
    if (is_file($__bootstrapPath)) {
        require_once $__bootstrapPath;
    }
}

// Ensure CmsSiteFunctions is loaded
if (!function_exists('cms_authenticateSiteUser')) {
    $__siteFuncsPath = dirname(__DIR__, 2) . '/app/Modules/CMS/Services/CmsSiteFunctions.php';
    if (is_file($__siteFuncsPath)) {
        require_once $__siteFuncsPath;
    }
}

/**
 * Require the user to be logged in to the site admin.
 * Redirects to login if not authenticated.
 */
function requireSiteLogin(): void
{
    if (!isSiteLoggedIn()) {
        $loginUrl = '/site-admin/login.php';
        if (!empty($_SERVER['REQUEST_URI'])) {
            $loginUrl .= '?redirect=' . urlencode($_SERVER['REQUEST_URI']);
        }
        header('Location: ' . $loginUrl);
        exit;
    }
}

/**
 * Check if the current visitor is logged into the site admin.
 *
 * @return bool
 */
function isSiteLoggedIn(): bool
{
    if (empty($_SESSION['cms_site_user_id']) || empty($_SESSION['cms_site_id'])) {
        return false;
    }

    // Verify session site_id matches current domain's site
    if ((int)$_SESSION['cms_site_id'] !== CMS_SITE_ID) {
        // Cross-site session — clear it
        unset($_SESSION['cms_site_user_id'], $_SESSION['cms_site_id'], $_SESSION['cms_site_user']);
        return false;
    }

    return siteRecheckUser();
}

/** Seconds between re-reads of the signed-in site user (same as the CRM's AuthFreshness). */
const SITE_RECHECK_SECONDS = 60;

/**
 * Re-read the site user at most every SITE_RECHECK_SECONDS (Fable audit 2026-10-12): a deactivated,
 * deleted or re-roled site user now loses or changes access within a minute, not at next sign-in.
 * A database error keeps the session (tries again next request).
 */
function siteRecheckUser(): bool
{
    $now  = time();
    $last = (int)($_SESSION['cms_site_checked_at'] ?? 0);
    if ($last > 0 && $now >= $last && ($now - $last) < SITE_RECHECK_SECONDS) return true;
    try {
        $st = getDB()->prepare("SELECT * FROM cms_site_users WHERE id = ? AND site_id = ? LIMIT 1");
        $st->execute([(int)$_SESSION['cms_site_user_id'], (int)$_SESSION['cms_site_id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('siteRecheckUser: check failed: ' . $e->getMessage());
        return true;
    }
    if (!$row || (isset($row['is_active']) && (int)$row['is_active'] !== 1)) {
        siteLogout();
        return false;
    }
    unset($row['password_hash']);
    $_SESSION['cms_site_user']       = $row;
    $_SESSION['cms_site_checked_at'] = $now;
    return true;
}

/**
 * Get the currently logged-in site user from session.
 * Returns cached session data (no DB query per page load).
 *
 * @return array|null
 */
function getSiteUser(): ?array
{
    if (!isSiteLoggedIn()) {
        return null;
    }
    return $_SESSION['cms_site_user'] ?? null;
}

/**
 * Log in a site user by email + password for the current site.
 * Populates $_SESSION on success.
 *
 * @param string $email
 * @param string $password
 * @return array|null User record on success, null on failure
 */
function siteLogin(string $email, string $password): ?array
{
    if (!function_exists('cms_authenticateSiteUser')) {
        return null;
    }

    // Throttle (Fable review 2026-10-12): same login_attempts table and ceilings as the CRM,
    // keyed "site-admin:<email>" so the two logins never share a counter. Fails closed.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (cms_siteLoginThrottled($email, $ip)) {
        return null;
    }

    $user = cms_authenticateSiteUser(CMS_SITE_ID, $email, $password);
    if (!$user) {
        cms_recordSiteLoginFailure($email, $ip);
        return null;
    }

    $_SESSION['cms_site_user_id'] = $user['id'];
    $_SESSION['cms_site_id']      = $user['site_id'];
    $_SESSION['cms_site_user']    = $user;
    $_SESSION['cms_site_checked_at'] = time();
    session_regenerate_id(true);
    unset($_SESSION['cms_site_csrf']); // a fresh token for the signed-in session

    return $user;
}

// ── CSRF + roles (Fable review 2026-10-12) ───────────────────────────────────

/** The site-admin CSRF token for this session (created on first use). */
function siteCsrfToken(): string
{
    if (empty($_SESSION['cms_site_csrf']) || !is_string($_SESSION['cms_site_csrf'])) {
        $_SESSION['cms_site_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['cms_site_csrf'];
}

/** Hidden form field carrying the token. */
function siteCsrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(siteCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** True when the request carries this session's token (form field or X-CSRF-Token header). */
function verifySiteCsrf(): bool
{
    $sent = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $have = $_SESSION['cms_site_csrf'] ?? '';
    return is_string($have) && $have !== '' && $sent !== '' && hash_equals($have, $sent);
}

/** Roles that may change the site. Viewers read only. */
const SITE_WRITE_ROLES = ['owner', 'admin', 'editor'];

/** True when the signed-in site user has one of $roles. */
function siteUserHasRole(array $roles): bool
{
    $u = getSiteUser();
    return $u !== null && in_array((string)($u['role'] ?? ''), $roles, true);
}

/**
 * Refuse unless the signed-in site user has one of $roles. JSON for API calls, a plain page
 * otherwise.
 */
function requireSiteRole(array $roles, bool $json = false): void
{
    if (siteUserHasRole($roles)) return;
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Your account can view this site but not change it.']);
    } else {
        echo 'Your account can view this site but not change it.';
    }
    exit;
}

/**
 * Every write: POST, a valid CSRF token, and a role in SITE_WRITE_ROLES.
 */
function requireSiteWrite(bool $json = false): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifySiteCsrf()) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Session expired — reload the page and try again.']);
        } else {
            echo 'Session expired — go back, reload the page and try again.';
        }
        exit;
    }
    requireSiteRole(SITE_WRITE_ROLES, $json);
}

/**
 * Log out the current site user.
 */
function siteLogout(): void
{
    unset($_SESSION['cms_site_user_id'], $_SESSION['cms_site_id'], $_SESSION['cms_site_user'], $_SESSION['cms_site_checked_at']);
}
