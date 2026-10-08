<?php
/**
 * QuickBooks OAuth 2.0 — step 2: Intuit sends the admin back here (2026-10-07).
 *
 * Query: code (one-time), state (must equal the session's), realmId (the company id).
 * Exchanges the code ONCE for access + refresh tokens (a second exchange invalidates them),
 * stores them encrypted in qbo_connections, then redirects to the settings page, which
 * runs the discovery read. Never renders the code on a page (Intuit's advice: handle, then
 * redirect without the parameters).
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
require_once APP_ROOT . '/Modules/Accounting/Services/QuickBooks/QboConnectionService.php';

requireLogin();
$returnUrl = '/crm/accounting/quickbooks.php';
if (!isAdmin()) {
    header('Location: ' . $returnUrl . '?error=' . urlencode('Only an admin can connect QuickBooks.'));
    exit;
}

$code    = (string)($_GET['code'] ?? '');
$state   = (string)($_GET['state'] ?? '');
$realmId = (string)($_GET['realmId'] ?? '');
$error   = (string)($_GET['error'] ?? '');

if ($error !== '') {
    header('Location: ' . $returnUrl . '?error=' . urlencode('Intuit said: ' . $error));
    exit;
}
$expected = (string)($_SESSION['qbo_oauth_state'] ?? '');
unset($_SESSION['qbo_oauth_state']);
if ($expected === '' || !hash_equals($expected, $state)) {
    header('Location: ' . $returnUrl . '?error=' . urlencode('The OAuth state did not match (possible CSRF or a stale tab). Start the connection again.'));
    exit;
}
if ($code === '' || !preg_match('/^\d{5,32}$/', $realmId)) {
    header('Location: ' . $returnUrl . '?error=' . urlencode('Intuit did not return an authorization code and company id.'));
    exit;
}

try {
    $config = QboConfig::fromConstants();
    $oauth  = new QboOAuthService($config, new QboCurlTransport());
    $tokens = $oauth->exchangeCode($code);
    $user   = getCurrentUser();
    $svc    = new QboConnectionService(getDB(), $config, $oauth);
    $svc->connect($realmId, $tokens, (int)($user['id'] ?? 0));
    header('Location: ' . $returnUrl . '?connected=1');
    exit;
} catch (Throwable $e) {
    error_log('[quickbooks oauth] ' . $e->getMessage());
    header('Location: ' . $returnUrl . '?error=' . urlencode('Connecting failed: ' . $e->getMessage()));
    exit;
}
