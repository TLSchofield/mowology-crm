<?php
/**
 * Privacy & DSAR API (authenticated CRM endpoint)
 *
 * GET  action=list             — list privacy_requests (paginated)
 * GET  action=export           — stream DSAR data export as JSON download
 * GET  action=retention        — get current retention settings
 * POST action=update_status    — update request status
 * POST action=anonymize        — anonymise a contact (admin only)
 * POST action=save_retention   — update a retention setting
 * POST action=run_purge        — purge expired location data now
 * POST action=create_request   — log a DSAR on behalf of a customer (admin)
 *
 * All writes require CSRF. Requires CRM login + settings.edit permission.
 * anonymize + run_purge additionally require database.manage.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once PUBLIC_ROOT . '/crm/includes/functions.php';
require_once APP_ROOT . '/Modules/Privacy/Services/PrivacyService.php';

requireLogin();
requirePermission('settings.edit');
$user = getCurrentUser();
session_write_close();

$db     = getDB();
$svc    = new PrivacyService($db);
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ── GET actions ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'list') {
        $status = $_GET['status'] ?? '';
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $requests = $svc->listRequests($status, $limit, $offset);
        $total    = $svc->countRequests($status);

        echo json_encode([
            'success'    => true,
            'requests'   => $requests,
            'total'      => $total,
            'page'       => $page,
            'total_pages' => (int) ceil($total / $limit),
        ]);
        exit;
    }

    if ($action === 'export') {
        $contactId = (int)($_GET['contact_id'] ?? 0);
        if (!$contactId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'contact_id required']);
            exit;
        }

        try {
            $export = $svc->generateDataExport($contactId);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Export failed']);
            exit;
        }

        // Stream as a downloadable JSON file
        $filename = 'dsar-contact-' . $contactId . '-' . date('Ymd') . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'retention') {
        $settings = $svc->getRetentionSettings();
        echo json_encode(['success' => true, 'settings' => array_values($settings)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ── POST actions ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (!verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

try {
    if ($action === 'update_status') {
        $id     = (int)($input['id']     ?? 0);
        $status = trim($input['status']  ?? '');
        $notes  = trim($input['notes']   ?? '');
        if (!$id || !$status) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id and status required']);
            exit;
        }
        $svc->updateRequestStatus($id, $status, (int)$user['id'], $notes);
        echo json_encode(['success' => true, 'message' => 'Request updated.']);

    } elseif ($action === 'create_request') {
        $type      = trim($input['request_type']    ?? '');
        $name      = trim($input['requester_name']  ?? '');
        $email     = trim($input['requester_email'] ?? '');
        $notes     = trim($input['notes']           ?? '');
        $contactId = (int)($input['contact_id']     ?? 0) ?: null;

        if (!$type || !$name || !$email) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'request_type, requester_name, requester_email required']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid email address']);
            exit;
        }
        $id = $svc->createRequest($type, $name, $email, $notes, $contactId);
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'Request logged.']);

    } elseif ($action === 'anonymize') {
        // Extra permission gate — admin only
        if (!userHasPermission('database.manage')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'database.manage permission required']);
            exit;
        }
        $contactId = (int)($input['contact_id'] ?? 0);
        $reason    = trim($input['reason']      ?? '');
        if (!$contactId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'contact_id required']);
            exit;
        }
        $svc->anonymizeContact($contactId, (int)$user['id'], $reason);
        echo json_encode(['success' => true, 'message' => 'Contact anonymised per PIPEDA right to erasure.']);

    } elseif ($action === 'save_retention') {
        if (!userHasPermission('database.manage')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'database.manage permission required']);
            exit;
        }
        $dataType = trim($input['data_type']      ?? '');
        $days     = (int)($input['retention_days'] ?? 0);
        if (!$dataType || !$days) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'data_type and retention_days required']);
            exit;
        }
        $svc->updateRetentionSetting($dataType, $days, (int)$user['id']);
        echo json_encode(['success' => true, 'message' => "Retention updated: {$dataType} = {$days} days."]);

    } elseif ($action === 'run_purge') {
        if (!userHasPermission('database.manage')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'database.manage permission required']);
            exit;
        }
        $result = $svc->purgeExpiredLocationData();
        echo json_encode(['success' => true, 'result' => $result]);

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error — please try again']);
}
