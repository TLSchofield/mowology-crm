<?php
/**
 * Field Job API  (crew add a job / visit on the spot)
 * ───────────────────────────────────────────────────
 * POST JSON. Session auth (Android WebView). Three actions:
 *
 *   add_visit         — add one ad-hoc visit to an existing plan (the common
 *                       "I'm here, no visit scheduled" case)
 *                       body: { plan_id, date? }
 *
 *   create_job        — create a job (plan + visits) for an existing property
 *                       body: { property_id, service_type, title?, notes?,
 *                               recurring(0|1), frequency?(weekly|biweekly|monthly),
 *                               date? }
 *
 *   create_client_job — create a new contact + property (lat/lng from the crew's
 *                       GPS fix) then a job for it
 *                       body: { first_name, last_name?, phone?, property_address,
 *                               property_city?, property_postal_code?, lat?, lng?,
 *                               service_type, title?, notes?, recurring, frequency?, date? }
 *
 * Idempotency: every request carries a body `client_request_id` (a UUID the
 * client generates once). The offline queue replays the body verbatim, so the
 * same id arrives on the first attempt and every replay — we dedupe on it via
 * the shared idempotency_keys store. (The queue's Idempotency-Key HTTP header is
 * NOT used here: it differs between the first online attempt and the replay.)
 *
 * Returns: { "success": bool, "error"?: string, plan_id?, plan_number?, visit_id?, visit_number? }
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once APP_ROOT . '/Core/IdempotencyHelper.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/plan-functions.php';
    require_once APP_ROOT . '/Modules/Contacts/Services/ContactService.php';

    requireLogin();
    requireAnyPermission(['jobs.create_field', 'jobs.edit']);
    $user = getCurrentUser();
    $db   = getDB();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    // Accept JSON body or form POST.
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_POST;
    }

    // CSRF must be verified before any session_write_close().
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode([
            'success'   => false,
            'error'     => 'Your session expired. Please reload the page and try again.',
            'code'      => 'CSRF_INVALID',
            'retryable' => false,
        ]);
        exit;
    }

    $action = $input['action'] ?? '';
    $userId = (int)$user['id'];

    // Idempotency: prefer the body id (stable across offline replays).
    $idempKey = trim((string)($input['client_request_id'] ?? ''));
    if ($idempKey !== '') {
        $cached = idempotencyCheck($db, $idempKey, $userId);
        if ($cached !== null) { echo $cached; exit; }
    }

    // All rules live in FieldJobService — shared with the iOS endpoint (/api/schedule/field-job).
    require_once APP_ROOT . '/Modules/Jobs/Services/FieldJobService.php';
    $result = (new FieldJobService($db))->handle(
        (string)$action,
        $input,
        $userId,
        (string)($user['full_name'] ?? $user['email'] ?? 'crew')
    );

    if (empty($result['success'])) {
        http_response_code((int)($result['status'] ?? 422));
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Could not save.']);
        exit;
    }

    $json = json_encode($result);
    if ($idempKey !== '') {
        idempotencyStore($db, $idempKey, $userId, 'field-job', (string)$action, $json);
    }
    echo $json;
    exit;

} catch (Throwable $e) {
    error_log('field-job.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
