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

    $user = cms_authenticateSiteUser(CMS_SITE_ID, $email, $password);
    if (!$user) {
        return null;
    }

    $_SESSION['cms_site_user_id'] = $user['id'];
    $_SESSION['cms_site_id']      = $user['site_id'];
    $_SESSION['cms_site_user']    = $user;
    session_regenerate_id(true);

    return $user;
}

/**
 * Log out the current site user.
 */
function siteLogout(): void
{
    unset($_SESSION['cms_site_user_id'], $_SESSION['cms_site_id'], $_SESSION['cms_site_user']);
}
