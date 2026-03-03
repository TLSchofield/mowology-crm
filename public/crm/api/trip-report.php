<?php
/**
 * Trip Report API
 *
 * Actions (POST):
 *   save_pre_trip  — Save pre-trip checklist + odometer start
 *   save_post_trip — Save post-trip data, trigger PDF generation
 *
 * Actions (GET):
 *   get_today  — Returns today's trip report row for the driver
 *   download   — Stream the PDF (admin or own report only)
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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
session_write_close(); // release session lock ASAP

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db     = getDB();

// ── GET: download ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); die('Missing id'); }

    $stmt = $db->prepare("SELECT * FROM vehicle_trip_reports WHERE id = ?");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) { http_response_code(404); die('Report not found'); }

    // Only admin/manager or the driver themselves can download
    if ($user['role'] === 'user' && (int)$report['driver_id'] !== (int)$user['id']) {
        http_response_code(403); die('Access denied');
    }

    if (empty($report['pdf_path'])) { http_response_code(404); die('PDF not yet generated'); }

    $fullPath = PROJECT_ROOT . '/' . $report['pdf_path'];
    if (!file_exists($fullPath)) { http_response_code(404); die('PDF file not found on disk'); }

    $filename = 'trip_report_' . $report['report_date'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($fullPath);
    exit;
}

// ── GET: get_today ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_today') {
    header('Content-Type: application/json');
    $today    = date('Y-m-d');
    $driverId = (int)$user['id'];

    $stmt = $db->prepare("SELECT * FROM vehicle_trip_reports WHERE driver_id = ? AND report_date = ?");
    $stmt->execute([$driverId, $today]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'report' => $report ?: null]);
    exit;
}

// ── POST actions ──────────────────────────────────────────────────────────────
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF check
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$driverId = (int)$user['id'];
$today    = date('Y-m-d');

// ── save_pre_trip ─────────────────────────────────────────────────────────────
if ($action === 'save_pre_trip') {
    $odomStart = isset($_POST['odometer_start']) && $_POST['odometer_start'] !== ''
        ? (int)$_POST['odometer_start'] : null;

    $chkFields = [
        'chk_leaks', 'chk_hitch', 'chk_rear_gate', 'chk_loads_secure',
        'chk_trailer_lights', 'chk_truck_brakes', 'chk_truck_lights',
        'chk_mirrors', 'chk_tire_pressure', 'chk_washer_wipers',
    ];

    $chkValues = [];
    foreach ($chkFields as $f) {
        $chkValues[$f] = empty($_POST[$f]) ? 0 : 1;
    }

    $defectsCrit    = trim($_POST['defects_critical']   ?? '');
    $defectUnhitch  = empty($_POST['defect_unhitch']) ? 0 : 1;
    $defectsNonUrg  = trim($_POST['defects_non_urgent'] ?? '');
    $safeToDrive    = empty($_POST['safe_to_drive'])  ? 0 : 1;

    try {
        // INSERT ... ON DUPLICATE KEY UPDATE — idempotent for re-saves
        $sql = "INSERT INTO vehicle_trip_reports
            (driver_id, vehicle_id, report_date,
             pre_trip_at, odometer_start,
             chk_leaks, chk_hitch, chk_rear_gate, chk_loads_secure,
             chk_trailer_lights, chk_truck_brakes, chk_truck_lights,
             chk_mirrors, chk_tire_pressure, chk_washer_wipers,
             defects_critical, defect_unhitch, defects_non_urgent,
             safe_to_drive, status)
        VALUES
            (?, 'RAM3500-PF8865', ?,
             NOW(), ?,
             ?, ?, ?, ?,
             ?, ?, ?,
             ?, ?, ?,
             ?, ?, ?,
             ?, 'pre_complete')
        ON DUPLICATE KEY UPDATE
            pre_trip_at      = IF(pre_trip_at IS NULL, NOW(), pre_trip_at),
            odometer_start   = VALUES(odometer_start),
            chk_leaks        = VALUES(chk_leaks),
            chk_hitch        = VALUES(chk_hitch),
            chk_rear_gate    = VALUES(chk_rear_gate),
            chk_loads_secure = VALUES(chk_loads_secure),
            chk_trailer_lights = VALUES(chk_trailer_lights),
            chk_truck_brakes = VALUES(chk_truck_brakes),
            chk_truck_lights = VALUES(chk_truck_lights),
            chk_mirrors      = VALUES(chk_mirrors),
            chk_tire_pressure = VALUES(chk_tire_pressure),
            chk_washer_wipers = VALUES(chk_washer_wipers),
            defects_critical  = VALUES(defects_critical),
            defect_unhitch    = VALUES(defect_unhitch),
            defects_non_urgent = VALUES(defects_non_urgent),
            safe_to_drive    = VALUES(safe_to_drive),
            status           = IF(status = 'pre_pending', 'pre_complete', status)";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $driverId, $today,
            $odomStart,
            $chkValues['chk_leaks'],
            $chkValues['chk_hitch'],
            $chkValues['chk_rear_gate'],
            $chkValues['chk_loads_secure'],
            $chkValues['chk_trailer_lights'],
            $chkValues['chk_truck_brakes'],
            $chkValues['chk_truck_lights'],
            $chkValues['chk_mirrors'],
            $chkValues['chk_tire_pressure'],
            $chkValues['chk_washer_wipers'],
            $defectsCrit,
            $defectUnhitch,
            $defectsNonUrg,
            $safeToDrive,
        ]);

        echo json_encode(['success' => true, 'message' => 'Pre-trip inspection saved']);

    } catch (Throwable $e) {
        error_log('[trip-report] save_pre_trip error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save pre-trip report']);
    }
    exit;
}

// ── save_post_trip ─────────────────────────────────────────────────────────────
if ($action === 'save_post_trip') {
    $odomEnd  = isset($_POST['odometer_end']) && $_POST['odometer_end'] !== ''
        ? (int)$_POST['odometer_end'] : null;
    $remarks       = trim($_POST['end_of_day_remarks'] ?? '');
    $hosOnDutyDriv = trim($_POST['hos_on_duty_driving'] ?? '');
    $hosOnDutyOth  = trim($_POST['hos_on_duty_other']   ?? '');
    $hosOffDuty    = trim($_POST['hos_off_duty']         ?? '');

    try {
        // Ensure a pre_trip row exists first (edge case: driver missed pre-trip)
        $check = $db->prepare("SELECT id FROM vehicle_trip_reports WHERE driver_id = ? AND report_date = ?");
        $check->execute([$driverId, $today]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            // Create a blank record so we can update it
            $ins = $db->prepare("INSERT INTO vehicle_trip_reports (driver_id, vehicle_id, report_date, status) VALUES (?, 'RAM3500-PF8865', ?, 'pre_complete')");
            $ins->execute([$driverId, $today]);
        }

        $upd = $db->prepare("
            UPDATE vehicle_trip_reports
            SET post_trip_at       = NOW(),
                odometer_end       = ?,
                end_of_day_remarks = ?,
                hos_on_duty_driving = ?,
                hos_on_duty_other  = ?,
                hos_off_duty       = ?,
                status             = 'complete'
            WHERE driver_id = ? AND report_date = ?
        ");
        $upd->execute([$odomEnd, $remarks, $hosOnDutyDriv, $hosOnDutyOth, $hosOffDuty, $driverId, $today]);

        // Fetch the report ID for PDF generation
        $row = $db->prepare("SELECT id FROM vehicle_trip_reports WHERE driver_id = ? AND report_date = ?");
        $row->execute([$driverId, $today]);
        $reportId = (int)($row->fetchColumn() ?: 0);

        // Generate PDF
        $pdfResult = ['success' => false, 'error' => 'PDF not generated'];
        if ($reportId) {
            require_once APP_ROOT . '/Modules/Driver/TripReportPdf.php';
            $pdfResult = TripReportPdf::generate($reportId, $db);
        }

        echo json_encode([
            'success'  => true,
            'message'  => 'Post-trip inspection saved',
            'pdf'      => $pdfResult,
            'report_id' => $reportId,
        ]);

    } catch (Throwable $e) {
        error_log('[trip-report] save_post_trip error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save post-trip report']);
    }
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
