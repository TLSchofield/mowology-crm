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

    /**
     * Build the createJobPlan payload shared by create_job / create_client_job.
     * The visit is assigned to the acting crew member so it lands on their
     * schedule. createJobPlan() generates the visit(s) itself (one-time plans
     * get a single visit on plan_start_date; recurring plans expand the
     * recurrence), so we do not insert visits separately here.
     */
    $buildPlanData = function (int $propertyId) use ($input, $userId): array {
        $serviceType = trim((string)($input['service_type'] ?? ''));
        $title       = trim((string)($input['title'] ?? ''));
        if ($title === '') $title = $serviceType !== '' ? $serviceType : 'Field job';

        $date = trim((string)($input['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        $recurring = !empty($input['recurring']);
        $freq      = (string)($input['frequency'] ?? 'weekly');
        if (!in_array($freq, ['weekly', 'biweekly', 'monthly'], true)) $freq = 'weekly';

        // Per-visit price (NET / pre-GST, same convention as the desktop create form).
        // Optional — left blank in the field means an un-priced plan the office prices later.
        $price = (isset($input['price']) && $input['price'] !== '' && is_numeric($input['price']))
            ? round((float)$input['price'], 2)
            : null;
        if ($price !== null && $price < 0) $price = null;

        $planData = [
            'property_id'    => $propertyId,
            'title'          => $title,
            'service_type'   => $serviceType,
            'description'    => trim((string)($input['notes'] ?? '')),
            'plan_start_date'=> $date,
            'default_crew_id'=> $userId,
            'crew_ids'       => [$userId],
            'pricing_model'  => 'per_visit',
            'price_per_visit'=> $price,
            'estimated_amount'=> $price,
            'is_recurring'   => $recurring ? 1 : 0,
        ];

        if ($recurring) {
            $planData['recurrence_pattern']      = $freq; // weekly|biweekly|monthly
            $planData['recurrence_interval']     = 1;
            $planData['recurrence_interval_unit']= $freq === 'monthly' ? 'months' : 'weeks';
            $planData['recurrence_day_of_week']  = (int)date('w', strtotime($date)); // 0=Sun..6=Sat
        }

        return $planData;
    };

    $respond = function (array $response) use ($db, $idempKey, $userId, $action): void {
        $json = json_encode($response);
        if ($idempKey !== '' && !empty($response['success'])) {
            idempotencyStore($db, $idempKey, $userId, 'field-job', (string)$action, $json);
        }
        echo $json;
        exit;
    };

    switch ($action) {

        case 'add_visit': {
            $planId = (int)($input['plan_id'] ?? 0);
            $date   = trim((string)($input['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

            if ($planId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'A plan is required.']);
                exit;
            }

            $result = addAdHocVisit($planId, $date, $userId, $userId);
            if (!$result['success']) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => implode(' ', $result['errors'])]);
                exit;
            }
            $respond([
                'success'      => true,
                'visit_id'     => $result['visit_id'],
                'visit_number' => $result['visit_number'],
            ]);
            break;
        }

        case 'create_job': {
            $propertyId = (int)($input['property_id'] ?? 0);
            if ($propertyId <= 0 || trim((string)($input['service_type'] ?? '')) === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Property and service are required.']);
                exit;
            }

            $result = createJobPlan($buildPlanData($propertyId), $userId);
            if (!$result['success']) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => implode(' ', $result['errors'])]);
                exit;
            }
            $respond([
                'success'     => true,
                'plan_id'     => $result['plan_id'],
                'plan_number' => $result['plan_number'],
            ]);
            break;
        }

        case 'create_client_job': {
            $firstName = trim((string)($input['first_name'] ?? ''));
            $address   = trim((string)($input['property_address'] ?? ''));
            if ($firstName === '' || $address === '' || trim((string)($input['service_type'] ?? '')) === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Name, address and service are required.']);
                exit;
            }

            $lat = isset($input['lat']) && $input['lat'] !== '' ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) && $input['lng'] !== '' ? (float)$input['lng'] : null;

            $contactService = new ContactService($db);
            $contactId = $contactService->createContact([
                'first_name'           => $firstName,
                'last_name'            => trim((string)($input['last_name'] ?? '')),
                'phone'                => trim((string)($input['phone'] ?? '')),
                'preferred_contact_method' => 'phone',
                'notes'                => 'Created in the field by ' . ($user['full_name'] ?? $user['email'] ?? 'crew') . ' — please review.',
                'property_address'     => $address,
                'property_city'        => trim((string)($input['property_city'] ?? 'Vancouver')) ?: 'Vancouver',
                'property_postal_code' => trim((string)($input['property_postal_code'] ?? '')),
                'property_latitude'    => $lat,
                'property_longitude'   => $lng,
            ]);

            // The brand-new contact has exactly one property — fetch its id.
            $pStmt = $db->prepare("SELECT id FROM properties WHERE site_contact_id = ? ORDER BY id DESC LIMIT 1");
            $pStmt->execute([$contactId]);
            $propertyId = (int)$pStmt->fetchColumn();

            if ($propertyId <= 0) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Client saved but property could not be created.']);
                exit;
            }

            $result = createJobPlan($buildPlanData($propertyId), $userId);
            if (!$result['success']) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => implode(' ', $result['errors'])]);
                exit;
            }
            $respond([
                'success'     => true,
                'contact_id'  => $contactId,
                'property_id' => $propertyId,
                'plan_id'     => $result['plan_id'],
                'plan_number' => $result['plan_number'],
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
            exit;
    }

} catch (Throwable $e) {
    error_log('field-job.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
