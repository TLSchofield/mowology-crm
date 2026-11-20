<?php
/**
 * Marketing Opt-In Confirmation — shim
 *
 * The opt-in re-consent email (app/Modules/Marketing/Api/optin.php) historically
 * generated confirmation links pointing at /crm/api/optin-confirm.php while the
 * actual handler lives at the web root (/optin-confirm.php). This shim keeps
 * already-delivered links working (CASL compliance) by delegating to the canonical
 * handler. Future emails use /optin-confirm.php directly.
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
require_once PUBLIC_ROOT . '/optin-confirm.php';
