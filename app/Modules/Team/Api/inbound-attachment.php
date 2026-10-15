<?php
/**
 * A kept attachment of billing mail routed to Penny, for the iOS app (signature-gated).
 *
 * GET /api/team/inbound-attachment?a=<attachment id>&e=<expiry ts>&s=<hmac>
 *
 * The app opens the form in Safari / Quick Look, which can't send a Bearer header, so the
 * JWT-authenticated desk (bookkeeper-mobile ?mode=queue → messages[].attachments[].url) mints a
 * short-lived HMAC-signed link (InboundAttachmentService::appUrl, keyed with jwtSecret()) and
 * this endpoint checks it — the same pattern as receipt-image.php. No session, no token.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once APP_ROOT . '/Modules/Comms/Services/InboundAttachmentService.php';

$id  = (int)($_GET['a'] ?? 0);
$exp = (int)($_GET['e'] ?? 0);
$sig = (string)($_GET['s'] ?? '');

if (!InboundAttachmentService::validSignature($id, $exp, $sig, jwtSecret(), time())) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Link expired or invalid — refresh Penny\'s card.');
}

try {
    $a = (new InboundAttachmentService(getDB()))->find($id);
    if (!$a) {
        http_response_code(404);
        header('Content-Type: text/plain');
        exit('Not found');
    }
    InboundAttachmentService::stream($a);
} catch (Throwable $e) {
    error_log('[inbound-attachment] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Error';
}
