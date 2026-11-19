<?php
declare(strict_types=1);

define('ITTAI_DIRECTORY_MODE', true);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Modules/Contacts/Services/GoogleContactsOAuth.php';
require_once APP_ROOT . '/Modules/Contacts/Services/GoogleContactsStageService.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$redirect = static function (bool $ok, string $nonce = '', string $reason = 'failed', string $grant = ''): void {
    $location = 'ittai-google://done?ok=' . ($ok ? '1' : '0');
    $location .= $ok ? '&nonce=' . rawurlencode($nonce) . '&grant=' . rawurlencode($grant) : '&reason=' . rawurlencode($reason);
    header('Location: ' . $location, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit;
}
if ((string)($_GET['error'] ?? '') === 'access_denied') $redirect(false, '', 'denied');

$secret = GoogleContactsOAuth::stateSecret();
$state = $secret !== null ? GoogleContactsOAuth::verifyState((string)($_GET['state'] ?? ''), $secret, time()) : null;
if ($state === null) $redirect(false, '', 'expired');

$code = (string)($_GET['code'] ?? '');
$clientId = defined('ITTAI_GOOGLE_WEB_CLIENT_ID') ? trim((string)constant('ITTAI_GOOGLE_WEB_CLIENT_ID')) : '';
$clientSecret = defined('ITTAI_GOOGLE_WEB_CLIENT_SECRET') ? trim((string)constant('ITTAI_GOOGLE_WEB_CLIENT_SECRET')) : '';
if ($code === '' || $clientId === '' || $clientSecret === '') {
    error_log('Google contacts callback failed: HTTP 0');
    $redirect(false, '', 'failed');
}

$oauth = new GoogleContactsOAuth($clientId, $clientSecret);
try {
    $token = $oauth->exchangeCode($code, GoogleContactsOAuth::REDIRECT_URI);
    if ($token === null) throw new RuntimeException('Token exchange failed.');
    $contacts = $oauth->fetchContacts($token);
    $db = Database::forBusiness((string)$state['b']);
    // The grant goes only into this redirect, which the browser session hands to the phone that ran the consent. Someone who
    // tricked another person into consenting (and so knows the nonce) never sees it, so cannot collect that person's list.
    $grant = bin2hex(random_bytes(16));
    (new GoogleContactsStageService($db))->stage((string)$state['n'], (int)$state['u'], $contacts, $grant);
    $redirect(true, (string)$state['n'], '', $grant);
} catch (Throwable $e) {
    error_log('Google contacts callback failed: HTTP ' . $oauth->lastHttpStatus());
    $redirect(false, '', 'failed');
}
