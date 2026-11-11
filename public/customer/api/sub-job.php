<?php
/**
 * Subcontract job page API. The private link is the permission: every call carries it.
 * No cookies are read, so another site cannot act for her.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

require_once dirname(__DIR__, 2) . '/app_config/config.php';
require_once APP_ROOT . '/Modules/Subcontract/Services/SubcontractService.php';

$reply = static function (array $body, int $status = 200): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $reply(['ok' => false, 'error' => 'Not available.'], 405);

try {
    $service = new SubcontractService(getDB());
    $token = is_string($_POST['t'] ?? null) ? $_POST['t'] : '';
    $job = $service->resolve($token);
    // A 256-bit link can't be guessed, and the login limiter counts by address: a mistyped link on office wifi would lock staff out.
    if (!$job) {
        $reply(['ok' => false, 'error' => 'This link is not valid.'], 404);
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    switch ($action) {
        case 'opened':
            if ($job['link_state'] === 'ok') $service->recordOpened($job);
            $result = ['ok' => true];
            break;
        case 'upload':
            $kind = ($_POST['kind'] ?? '') === 'invoice' ? 'invoice' : 'photo';
            $result = isset($_FILES['file']) && is_array($_FILES['file']) ? $service->addFile($job, $kind, $_FILES['file']) : ['ok' => false, 'error' => 'That file did not arrive. Please try again.'];
            break;
        case 'bill':
            $result = $service->submitBill($job, $_POST);
            break;
        case 'interested':
            $result = $service->askAboutIttai($job);
            break;
        default:
            $result = $service->act($job, $action, $_POST);
    }
    $reply($result, !empty($result['ok']) ? 200 : 422);
} catch (Throwable $e) {
    error_log('customer/api/sub-job.php: ' . $e->getMessage());
    $reply(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
