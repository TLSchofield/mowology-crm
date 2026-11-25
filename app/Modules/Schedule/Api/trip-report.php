<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/trip-report.php
 *
 * Mobile commercial-vehicle trip inspections — per-shift driver declaration,
 * pre-trip and post-trip. Authorization: Bearer <jwt>
 *
 * GET  ?mode=status
 * GET  ?mode=history            — the driver's own recent trips (the phone's driver log)
 * GET  ?mode=pdf&report_id=N    — the driver's OWN report as a PDF (application/pdf)
 * POST {action:'declare',   driving: bool, vehicle_id?}
 * POST {action:'pre_trip',  vehicle_id, chk_*: bool…, odometer_start?, defects_critical?,
 *                           defect_unhitch?, defects_non_urgent?, safe_to_drive: bool}
 * POST {action:'pre_trip_bypass', pin, vehicle_id?, reason?}   — a manager's PIN waives the pre-trip
 * POST {action:'post_trip', odometer_end?, end_of_day_remarks?, hos_*?, confirm_odometer?}
 *
 * Every POST may carry `performed_at` (device epoch ms): an inspection done with no signal is
 * stored on the phone and filed later under the time it was DONE. Replays are idempotent.
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
    require_once APP_ROOT . '/Core/ApiResponse.php'; // generic errors to the user, details to the log
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Driver/Services/TripReportService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $db      = getDB();
    $service = new TripReportService($db);
    $today   = date('Y-m-d');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $mode = trim((string)($_GET['mode'] ?? 'status'));
        if ($mode === 'history') {
            echo json_encode(['success' => true, 'trips' => $service->history($userId, (int)($_GET['limit'] ?? 30))]);
            exit;
        }
        if ($mode === 'pdf') {
            $path = $service->pdfPathFor($userId, (int)($_GET['report_id'] ?? 0));
            if ($path === null) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'That report has no PDF yet.']);
                exit;
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="trip_report_' . (int)$_GET['report_id'] . '.pdf"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, max-age=0, must-revalidate');
            readfile($path);
            exit;
        }
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
            $service->declare($userId, $driving, $driving ? $pickVehicle() : null, 'ios', $input['performed_at'] ?? null);
            break;

        case 'pre_trip':
            $vehicleId = $pickVehicle();
            $result    = $service->savePreTrip($userId, $vehicleId, $input, $today);
            $service->declare($userId, true, $vehicleId, 'ios', $input['performed_at'] ?? null);
            $extra     = ['report_id' => $result['report_id'], 'may_drive' => $result['may_drive'], 'unchecked' => $result['unchecked']];
            if (!$result['may_drive']) {
                $service->alertOfficeUnsafe($result['report_id'], (string)($jwtUser['name'] ?? "User #{$userId}"));
            }
            break;

        case 'pre_trip_bypass':
            // The vehicle is optional here (a manager may waive before one is chosen), but when
            // given it must be one the fleet offers.
            $offered = array_column($service->vehicles(), 'id');
            $wanted  = (string)($input['vehicle_id'] ?? '');
            $vehicle = ($wanted !== '' && in_array($wanted, $offered, true)) ? $wanted : (count($offered) === 1 ? $offered[0] : null);
            require_once APP_ROOT . '/Modules/Driver/Services/PreTripBypassService.php';
            $r = PreTripBypassService::attempt($db, $userId, (string)($input['pin'] ?? ''), (string)($input['reason'] ?? ''),
                $vehicle, (string)($_SERVER['REMOTE_ADDR'] ?? ''), $today);
            if (!$r['ok']) {
                // A wrong PIN is a 4xx the phone shows — it never queues it.
                http_response_code($r['status']);
                echo json_encode(['success' => false, 'message' => $r['error']]);
                exit;
            }
            if ($vehicle !== null) {
                $service->declare($userId, true, $vehicle, 'ios', null);
            }
            $extra = ['report_id' => $r['report_id']];
            break;

        case 'post_trip':
            $extra = ['report_id' => $service->savePostTrip($userId, $input, $today)];
            // Closing the trip means they are no longer the driver — until they say otherwise.
            $service->declare($userId, false, null, 'ios', $input['performed_at'] ?? null);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
            exit;
    }

    echo json_encode(['success' => true] + $extra + $service->status($userId, $today));

} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => ApiResponse::message($e, 'trip-report')]);
} catch (Throwable $e) {
    error_log('[schedule/trip-report] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the inspection. Please try again.']);
}
