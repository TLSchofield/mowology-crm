<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/trip-report.php
 *
 * Mobile commercial-vehicle trip inspections — per-shift driver declaration,
 * pre-trip and post-trip. Authorization: Bearer <jwt>
 *
 * GET  ?mode=status
 * POST {action:'declare',   driving: bool, vehicle_id?}
 * POST {action:'pre_trip',  vehicle_id, chk_*: bool…, odometer_start?, defects_critical?,
 *                           defect_unhitch?, defects_non_urgent?, safe_to_drive: bool}
 * POST {action:'post_trip', odometer_end?, end_of_day_remarks?, hos_*?, confirm_odometer?}
 *
 * Every response carries the full refreshed status. Thin controller — the rules live
 * in TripReportService.
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
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Driver/Services/TripReportService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $db      = getDB();
    $service = new TripReportService($db);
    $today   = date('Y-m-d');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['success' => true] + $service->status($userId, $today));
        exit;
    }

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = trim((string)($input['action'] ?? ''));
    $extra  = [];

    // A vehicle id is only ever one the fleet list offers — never free text from a client.
    $pickVehicle = static function () use ($service, $input): string {
        $offered = array_column($service->vehicles(), 'id');
        $wanted  = (string)($input['vehicle_id'] ?? '');
        if ($wanted !== '' && in_array($wanted, $offered, true)) {
            return $wanted;
        }
        if (count($offered) === 1) {
            return $offered[0];
        }
        throw new InvalidArgumentException('Choose which vehicle you are driving.');
    };

    switch ($action) {
        case 'declare':
            $driving = !empty($input['driving']);
            $service->declare($userId, $driving, $driving ? $pickVehicle() : null, 'ios');
            break;

        case 'pre_trip':
            $vehicleId = $pickVehicle();
            $result    = $service->savePreTrip($userId, $vehicleId, $input, $today);
            $service->declare($userId, true, $vehicleId, 'ios');
            $extra     = ['report_id' => $result['report_id'], 'may_drive' => $result['may_drive'], 'unchecked' => $result['unchecked']];
            if (!$result['may_drive']) {
                $service->alertOfficeUnsafe($result['report_id'], (string)($jwtUser['name'] ?? "User #{$userId}"));
            }
            break;

        case 'post_trip':
            $extra = ['report_id' => $service->savePostTrip($userId, $input, $today)];
            // Closing the trip means they are no longer the driver — until they say otherwise.
            $service->declare($userId, false, null, 'ios');
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
            exit;
    }

    echo json_encode(['success' => true] + $extra + $service->status($userId, $today));

} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[schedule/trip-report] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the inspection. Please try again.']);
}
