<?php
/**
 * OAuth Callback — handles Google + Meta redirects after user authorization.
 *
 * Flow:
 *   1. Verify state parameter (CSRF protection)
 *   2. Exchange code for tokens
 *   3. Store tokens in session (pending)
 *   4. Redirect to accounts page with step=select_location (GBP)
 *      or step=select_page (Meta Phase 2)
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────
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
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Social/Services/SocialEncryption.php';
require_once APP_ROOT . '/Modules/Social/Services/GoogleBusinessService.php';
require_once APP_ROOT . '/Modules/Social/Services/MetaService.php';

requireLogin();
requirePermission('marketing.approve');

// ── Validate OAuth state ───────────────────────────────────────────────
$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

$returnUrl = '/crm/marketing/social-accounts.php';

if ($error) {
    $msg = urlencode('OAuth denied: ' . htmlspecialchars($error));
    header('Location: ' . $returnUrl . '?error=' . $msg);
    exit;
}

if (!$code) {
    header('Location: ' . $returnUrl . '?error=' . urlencode('No authorization code received'));
    exit;
}

// Verify state against session
$expectedState = $_SESSION['social_oauth_state'] ?? '';
$platform      = $_SESSION['social_oauth_platform'] ?? '';

if (!$expectedState || !hash_equals($expectedState, $state)) {
    header('Location: ' . $returnUrl . '?error=' . urlencode('Invalid OAuth state — possible CSRF attempt. Please try again.'));
    exit;
}

// ── Exchange code for tokens ───────────────────────────────────────────
try {
    if ($platform === 'gbp') {
        $tokenData = GoogleBusinessService::exchangeCode($code);
    } elseif (in_array($platform, ['facebook', 'instagram'], true)) {
        $tokenData = MetaService::exchangeCode($code);
    } else {
        throw new RuntimeException("Unknown platform: $platform");
    }

    // Store pending token in session (will be used by connect action)
    $_SESSION['social_pending_token']    = $tokenData;
    $_SESSION['social_oauth_platform']   = $platform; // Keep for location listing step

    // Clear state (one-time use)
    unset($_SESSION['social_oauth_state']);

    // ── Redirect to next step ──────────────────────────────────────
    if ($platform === 'gbp') {
        // Need to select which GBP location to connect
        header('Location: ' . $returnUrl . '?step=select_location&platform=gbp');
    } else {
        // Phase 2: Meta location/page selection
        header('Location: ' . $returnUrl . '?step=select_page&platform=' . $platform);
    }
    exit;

} catch (\Throwable $e) {
    error_log('OAuth callback error: ' . $e->getMessage());
    $msg = urlencode('Authentication failed: ' . $e->getMessage());
    header('Location: ' . $returnUrl . '?error=' . $msg);
    exit;
}
