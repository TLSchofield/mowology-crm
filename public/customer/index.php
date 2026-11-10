<?php
// /customer/ has no page of its own: the portal is token-based (portal.php, invoice.php,
// quote.php, … reached from links in customer emails). A bare hit goes to the homepage.
// Replaces a stray Feb-2026 copy of the public homepage that fataled on a missing
// includes/bootstrap.php on every request (2026-10-16). The homepage is the business's own.
header('X-Robots-Tag: noindex');
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
require_once PUBLIC_ROOT . '/app_config/config.php';
require_once APP_ROOT . '/Core/Csp.php';
MwCsp::send();
require_once APP_ROOT . '/Services/PortalBrand.php';
$home = PortalBrand::homeUrl();
if ($home === '') {
    http_response_code(404);
    exit;
}
header('Location: ' . rtrim($home, '/') . '/', true, 302);
exit;
