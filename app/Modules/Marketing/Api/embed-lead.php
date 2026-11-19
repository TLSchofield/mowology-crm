<?php
/**
 * Embeddable lead form endpoint (public, cross-origin).
 *
 * POST /crm/api/embed-lead.php   (multipart/form-data or URL-encoded — a "simple" CORS request, no preflight)
 *   key (tenant public key), name, email, phone, message, source_url, form_id
 *   website (honeypot — must be empty)
 *
 * The key only names the tenant. The request's Origin header must exactly match the tenant's allowed origins
 * (cms_sites.embed_origins). Any failure to identify the tenant returns the same generic 403 so keys cannot
 * be probed. Limits: 3 per IP per tenant per 24 h, 100 per tenant per 24 h.
 *
 * Returns { success: true } or { success: false, error: "..." }.
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

require_once PUBLIC_ROOT . '/includes/bootstrap.php';
require_once APP_ROOT . '/Modules/CMS/Services/CmsFormSubmitRules.php';
require_once APP_ROOT . '/Modules/Marketing/Services/EmbedLeadRules.php';
require_once APP_ROOT . '/Modules/Marketing/Services/EmbedLeadService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function embedLeadFail(int $code, string $error): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    embedLeadFail(405, 'Method not allowed');
}

try {
    $db  = getDB();
    $svc = new EmbedLeadService($db);

    $site = $svc->siteForKey((string)($_POST['key'] ?? ''));
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($site === null || !EmbedLeadRules::originAllowed($origin, $site['embed_origins'])) {
        embedLeadFail(403, 'This form is not available.');
    }
    $siteId = (int)$site['id'];

    // Echo back only the exact origin that passed the check.
    header('Access-Control-Allow-Origin: ' . EmbedLeadRules::normaliseOrigin($origin));
    header('Vary: Origin');

    // Honeypot: pretend success, store nothing.
    if (!empty($_POST['website'])) {
        echo json_encode(['success' => true]);
        exit;
    }

    $f = CmsFormSubmitRules::fields($_POST);
    if (trim((string)($_POST['form_id'] ?? '')) === '') $f['form_id'] = 'embed-contact';
    if ($f['name'] === '') {
        embedLeadFail(422, 'Please enter your name.');
    }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        embedLeadFail(422, 'Please enter a valid email address.');
    }

    $ip    = CmsFormSubmitRules::clientIp($_SERVER);
    $since = date('Y-m-d H:i:s', time() - 86400);
    if (EmbedLeadRules::overSiteLimit($svc->countForSite($siteId, $since))) {
        embedLeadFail(429, 'Too many messages today. Please try again tomorrow.');
    }
    if ($ip !== '' && CmsFormSubmitRules::overLimit($svc->countFromIp($siteId, $ip, $since))) {
        embedLeadFail(429, 'Thanks, we already have your message.');
    }

    $id = $svc->store($siteId, $f, $ip, date('Y-m-d H:i:s'));

    // Alert the tenant (not Mowology). Failure to email must not lose the stored enquiry.
    $to = EmbedLeadRules::notifyAddress($site);
    if ($to !== '') {
        require_once CRM_INCLUDES . '/messaging.php';
        if (function_exists('sendEmail')) {
            // Reply-To = the visitor, so "Reply" in the tenant's inbox goes straight to them.
            @sendEmail(
                $to,
                'New website enquiry — ' . $site['name'],
                EmbedLeadRules::notificationHtml($f, $id, (string)$site['name']),
                null,
                (string)$site['name'],
                [],
                [],
                $f['email']
            );
        }
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (\Throwable $e) {
    error_log('embed-lead.php error: ' . $e->getMessage());
    embedLeadFail(500, 'Server error. Please try again later.');
}
