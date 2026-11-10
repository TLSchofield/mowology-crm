<?php
/**
 * Subcontract job page — one private link, no account or app.
 * The subcontractor takes the job, arranges it with the client, marks each step and sends a bill.
 * Everything shown comes from SubcontractRules::pagePayload(), which lists what she may see.
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

require_once dirname(__DIR__) . '/app_config/config.php';
require_once APP_ROOT . '/Services/PortalBrand.php';
require_once APP_ROOT . '/Core/Csp.php';
require_once APP_ROOT . '/Modules/Subcontract/Services/SubcontractService.php';
MwCsp::send();

$token = is_string($_GET['t'] ?? null) ? trim($_GET['t']) : '';
$sub = ['token' => $token, 'business' => PortalBrand::displayName(), 'contact' => PortalBrand::contactSentence(), 'state' => 'missing'];

try {
    $service = new SubcontractService(getDB());
    $job = $service->resolve($token);
    if ($job) {
        $sub['state'] = $job['link_state'];
        if ($job['link_state'] === 'ok') {
            $sub['page'] = $service->page($job);
            $sub['record'] = SubcontractRules::recordFor($service->timeline((int)$job['id']));
            $sub['photos'] = count($service->files((int)$job['id'], 'photo'));
            $sub['invoices'] = count($service->files((int)$job['id'], 'invoice'));
            $sub['show_panel'] = empty($job['on_ittai']) && in_array($sub['page']['next_step'], ['bill_waiting', 'payment_waiting', 'done'], true);
            $sub['asked'] = $service->hasAskedAboutIttai((int)$job['id']);
            if ($sub['show_panel']) $service->markPanelSeen($job);
        }
    }
} catch (Throwable $e) {
    error_log('sub-job.php: ' . $e->getMessage());
    $sub['state'] = 'error';
}
if ($sub['state'] !== 'ok') http_response_code($sub['state'] === 'error' ? 500 : 404);

include APP_ROOT . '/Modules/Subcontract/Views/sub-job.php';
