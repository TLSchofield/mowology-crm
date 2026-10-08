<?php
/**
 * QuickBooks OAuth 2.0 — step 1: send the admin to Intuit's consent page (2026-10-07).
 *
 * Sets a one-time `state` in the session (CSRF — Intuit requires it) and redirects to
 * https://appcenter.intuit.com/connect/oauth2 with scope com.intuit.quickbooks.accounting.
 * The callback is /crm/api/quickbooks/oauth-callback.php (must equal QBO_REDIRECT_URI exactly).
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once APP_ROOT . '/Modules/Accounting/Services/QuickBooks/QboOAuthService.php';

requireLogin();
$returnUrl = '/crm/accounting/quickbooks.php';
if (!isAdmin()) {
    header('Location: ' . $returnUrl . '?error=' . urlencode('Only an admin can connect QuickBooks.'));
    exit;
}
if (!verifyCSRFToken((string)($_GET['csrf_token'] ?? ''))) {
    header('Location: ' . $returnUrl . '?error=' . urlencode('Your session token is stale — reload the page and try again.'));
    exit;
}

try {
    $config = QboConfig::fromConstants();
    $oauth  = new QboOAuthService($config, new QboCurlTransport());
    $state  = QboOAuthService::newState();
    $_SESSION['qbo_oauth_state'] = $state;
    $_SESSION['qbo_oauth_env']   = $config->env();
    header('Location: ' . $oauth->authorizeUrl($state));
    exit;
} catch (Throwable $e) {
    header('Location: ' . $returnUrl . '?error=' . urlencode($e->getMessage()));
    exit;
}
