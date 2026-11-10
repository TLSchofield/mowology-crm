<?php
/**
 * Subcontract API for staff: send a visit to a company, follow it, accept the work, mark it paid, read what they sent.
 * POST JSON { action, ... } with CSRF. GET ?mode=file&id= returns one of their photos or invoices.
 */
declare(strict_types=1);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once APP_ROOT . '/Services/PortalBrand.php';
require_once CRM_INCLUDES . '/messaging.php';
require_once APP_ROOT . '/Core/ApiResponse.php';
require_once APP_ROOT . '/Modules/Subcontract/Services/SubcontractService.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.edit');
$userId = (int)$user['id'];
$service = new SubcontractService(getDB());

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['mode'] ?? '') === 'file') {
    $file = $service->fileForStaff((int)($_GET['id'] ?? 0));
    if (!$file || !is_file($file['path'])) { http_response_code(404); exit; }
    header('Content-Type: ' . $file['mime_type']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="' . ($file['kind'] === 'invoice' ? 'invoice' : 'photo') . '-' . (int)$file['id'] . '"');
    readfile($file['path']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$reply = static function (array $body, int $status = 200): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $reply(['ok' => false, 'error' => 'POST required'], 405);

$input = json_decode((string)file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
if (!verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) $reply(['ok' => false, 'error' => 'Your session expired. Reload the page.'], 403);
session_write_close();

$linkFor = static fn (string $token): string => rtrim(PortalBrand::appUrl(), '/') . '/customer/sub-job.php?t=' . $token;
$jobId = (int)($input['job_id'] ?? 0);

try {
    switch ((string)($input['action'] ?? '')) {
        case 'create':
            $companyId = (int)($input['subcontractor_id'] ?? 0);
            if ($companyId <= 0) $companyId = $service->saveCompany($input, $userId);
            $sent = $service->create((int)($input['visit_id'] ?? 0), $companyId, $userId, [
                'agreed_amount' => $input['agreed_amount'] ?? null,
                'note' => $input['note'] ?? '',
                'share_client_name' => !empty($input['share_client_name']),
                'share_client_phone' => !empty($input['share_client_phone']),
                'already_done' => !empty($input['already_done']),
            ]);
            $reply(['ok' => true, 'job_id' => $sent['job_id'], 'link' => $linkFor($sent['token'])]);
        case 'new_link':
            $token = $service->newLink($jobId, $userId);
            $reply($token === null ? ['ok' => false, 'error' => 'This job is cancelled.'] : ['ok' => true, 'link' => $linkFor($token)]);
        case 'email_link':
            $to = (string)($input['email'] ?? '');
            $link = (string)($input['link'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $reply(['ok' => false, 'error' => 'That email address does not look right.'], 422);
            if (strpos($link, rtrim(PortalBrand::appUrl(), '/') . '/customer/sub-job.php?t=') !== 0) $reply(['ok' => false, 'error' => 'Create the link first.'], 422);
            $business = PortalBrand::displayName();
            $safe = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
            $html = '<p>' . htmlspecialchars($business, ENT_QUOTES, 'UTF-8') . ' has a job for you. The address, the work and what you need to do next are here:</p><p><a href="' . $safe . '">Open the job</a></p>';
            $reply(sendCrmEmail($to, 'A job from ' . $business, $html) ? ['ok' => true] : ['ok' => false, 'error' => 'The email did not send. Copy the link instead.']);
        case 'accept':
            $reply($service->acceptWork($jobId, $userId));
        case 'mark_paid':
            $reply($service->markPaid($jobId, $userId));
        case 'cancel':
            $reply(['ok' => $service->cancel($jobId, $userId)]);
        default:
            $reply(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    $reply(['ok' => false, 'error' => ApiResponse::message($e, 'subcontract')], $e instanceof InvalidArgumentException ? 422 : 500);
}
