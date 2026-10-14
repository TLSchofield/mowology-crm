<?php
/**
 * iPhone: Otto → Add equipment (JWT). The web page does the same through /crm/api/dispatch.php; both
 * call EquipmentService (readPlate / addFromPlate / receiptCandidates / formOptions) — one place.
 *
 * GET  /api/team/equipment-mobile?mode=options
 *      → {ok, classes[{value,label}], power[{value,label}], people[{id,name}], trucks[{id,name}]}
 * POST /api/team/equipment-mobile  multipart: mode=plate, plate_photo (required), machine_photo (optional)
 *      → {ok, message, plate{make, model, serial, …}, suggested{name, make, model, serial_no, equipment_class,
 *         power_source, model_name}, existing{id,name}|null, known_model{…}|null, receipts[{id, date, total,
 *         vendor, why}], plate_media_id, photo_media_id, label_capture_id}
 * POST JSON {mode: 'receipts', make?, model?, date?, cost?}  → {ok, receipts[]}
 * POST JSON {mode: 'save', name, equipment_class, make, model, serial_no, power_source, purchase_date?, cost?,
 *            expense_id?, assigned_user_id?, truck_id?, plate_media_id?, photo_media_id?, label_capture_id?, model_name?}
 *      → {ok, message, id, catalog_product_id, shared_intervals, url}
 * Auth: Authorization: Bearer <jwt>; jobs.edit (Otto is for people who run the schedule). No CSRF (JWT).
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

header('Content-Type: application/json');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once APP_ROOT . '/Core/ApiResponse.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Operations/Services/EquipmentService.php';

    $jwtUser = requireJwt();
    if (!jwtUserHasPermission($jwtUser, 'jobs.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Adding equipment is for people who run the schedule (jobs.edit).']);
        exit;
    }
    $uid = (int)$jwtUser['id'];
    $eq = new EquipmentService(getDB());
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $gm = (string)($_GET['mode'] ?? '');
        if ($gm === 'list') { echo json_encode(['ok' => true, 'items' => $eq->phoneList()]); exit; }   // iPhone list/edit (2026-10-14)
        if ($gm !== 'options') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Unknown mode']); exit; }
        echo json_encode(['ok' => true] + $eq->formOptions());
        exit;
    }
    if ($method !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }

    $isMultipart = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
    $in = $isMultipart ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?? []);
    $mode = (string)($in['mode'] ?? '');

    switch ($mode) {
        case 'plate':
            if (function_exists('set_time_limit')) @set_time_limit(90);
            $r = $eq->readPlate($uid, $_FILES['plate_photo'] ?? [], $_FILES['machine_photo'] ?? []);
            if (!empty($r['http_code'])) { http_response_code((int)$r['http_code']); unset($r['http_code']); }
            echo json_encode($r);
            exit;
        case 'receipts':
            echo json_encode(['ok' => true, 'receipts' => $eq->receiptCandidates($in)]);
            exit;
        case 'update':
            $r = $eq->updateFromPhone((int)($in['id'] ?? 0), $in);
            if (empty($r['ok'])) http_response_code(400);
            echo json_encode($r);
            exit;
        case 'save':
            $r = $eq->addFromPlate($in, $uid);
            if (empty($r['ok'])) http_response_code(400);
            echo json_encode($r);
            exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
} catch (Throwable $e) {
    error_log('equipment-mobile: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — ' . ApiResponse::message($e, 'equipment-mobile')]);
}
