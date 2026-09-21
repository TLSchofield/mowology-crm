<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/field-job.php
 *
 * Mobile Schedule API — add a job / visit on the spot (iOS). Same rules as the Android/web
 * overlay: both go through FieldJobService.
 *
 * GET  /api/schedule/field-job?mode=nearby&lat=..&lng=..[&radius=250]
 *        → { success, results:[{id,address,city,distance_m,contact_name,plans:[{id,title,
 *            service_type,has_visit_today}], …}], service_types:[…], frequencies:[…] }
 * POST /api/schedule/field-job   { action, client_request_id, … }
 *        add_visit         { plan_id, date? }
 *        create_job        { property_id, service_type, title?, notes?, price?, recurring, frequency?, date? }
 *        create_client_job { first_name, last_name?, phone?, property_address, property_city?,
 *                            property_postal_code?, lat?, lng?, service_type, … }
 *
 * (?mode=, not ?action= — the /api router's rewrite owns `action` on GET.)
 * Authorization: Bearer <jwt>. Needs jobs.create_field or jobs.edit, like the web endpoint.
 * `client_request_id` (a UUID made once per attempt) makes a retried POST safe.
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once APP_ROOT . '/Core/IdempotencyHelper.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/plan-functions.php';
    require_once APP_ROOT . '/Modules/Contacts/Services/ContactService.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/FieldJobService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if (!jwtUserHasPermission($jwtUser, 'jobs.create_field') && !jwtUserHasPermission($jwtUser, 'jobs.edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Your account can't add jobs from the field. Ask the office."]);
        exit;
    }

    $db      = getDB();
    $service = new FieldJobService($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
        $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;

        echo json_encode([
            'success'       => true,
            'results'       => ($lat === null || $lng === null)
                ? []
                : $service->nearby($lat, $lng, FieldJobService::resolveRadius($_GET['radius'] ?? 0)),
            'service_types' => FieldJobService::SERVICE_TYPES,
            'frequencies'   => FieldJobService::FREQUENCIES,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = (string)($input['action'] ?? '');

    $idempKey = trim((string)($input['client_request_id'] ?? ''));
    if ($idempKey !== '') {
        $cached = idempotencyCheck($db, $idempKey, $userId);
        if ($cached !== null) { echo $cached; exit; }
    }

    $result = $service->handle($action, $input, $userId, (string)($jwtUser['name'] ?? ''));

    if (empty($result['success'])) {
        http_response_code((int)($result['status'] ?? 422));
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Could not save.']);
        exit;
    }

    $json = json_encode($result);
    if ($idempKey !== '') {
        idempotencyStore($db, $idempKey, $userId, 'field-job', $action, $json);
    }
    echo $json;

} catch (Throwable $e) {
    error_log('schedule/field-job API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
