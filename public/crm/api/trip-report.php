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
// Returns the driver's CURRENT trip for today. With multi-trip support,
// "current" means the latest row — open (in progress) if one exists,
// otherwise the most recently closed row so the caller can see what was
// just filed.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_today') {
    header('Content-Type: application/json');
    $today    = date('Y-m-d');
    $driverId = (int)$user['id'];

    $stmt = $db->prepare("
        SELECT * FROM vehicle_trip_reports
        WHERE driver_id = ? AND report_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");
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

// Soft-check: does the trip_sequence column exist yet? (migration 1014)
$hasTripSequence = false;
try {
    $hasTripSequence = (bool)$db->query("SHOW COLUMNS FROM vehicle_trip_reports LIKE 'trip_sequence'")->fetch();
} catch (Throwable $e) { /* column absent */ }

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
        // Multi-trip state machine:
        //   1. Find the latest row for today.
        //   2. If it's "open" (pre set, post null) → UPDATE it in place.
        //      Lets the driver re-save the same pre-trip for fixes.
        //   3. If it's "closed" (post_trip_at set) OR doesn't exist →
        //      INSERT a new row with the next trip_sequence.
        $seqCol = $hasTripSequence ? ', trip_sequence' : '';
        $latestStmt = $db->prepare("
            SELECT id, pre_trip_at, post_trip_at{$seqCol}
            FROM vehicle_trip_reports
            WHERE driver_id = ? AND report_date = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $latestStmt->execute([$driverId, $today]);
        $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);

        $reuseRow = $latest && empty($latest['post_trip_at']);

        if ($reuseRow) {
            // UPDATE the existing open row.
            $reportId = (int)$latest['id'];
            $upd = $db->prepare("
                UPDATE vehicle_trip_reports
                SET pre_trip_at       = IF(pre_trip_at IS NULL, NOW(), pre_trip_at),
                    odometer_start    = ?,
                    chk_leaks         = ?,
                    chk_hitch         = ?,
                    chk_rear_gate     = ?,
                    chk_loads_secure  = ?,
                    chk_trailer_lights= ?,
                    chk_truck_brakes  = ?,
                    chk_truck_lights  = ?,
                    chk_mirrors       = ?,
                    chk_tire_pressure = ?,
                    chk_washer_wipers = ?,
                    defects_critical  = ?,
                    defect_unhitch    = ?,
                    defects_non_urgent= ?,
                    safe_to_drive     = ?,
                    status            = IF(status = 'pre_pending', 'pre_complete', status)
                WHERE id = ?
            ");
            $upd->execute([
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
                $reportId,
            ]);
        } else {
            // INSERT a new trip. trip_sequence = previous max + 1 for
            // today's driver, or 1 if this is the first trip.
            $nextSeq = 1;
            if ($hasTripSequence && $latest) {
                $seqStmt = $db->prepare("
                    SELECT COALESCE(MAX(trip_sequence), 0) + 1
                    FROM vehicle_trip_reports
                    WHERE driver_id = ? AND report_date = ?
                ");
                $seqStmt->execute([$driverId, $today]);
                $nextSeq = (int)$seqStmt->fetchColumn();
            }

            $seqInsCol = $hasTripSequence ? ', trip_sequence' : '';
            $seqInsVal = $hasTripSequence ? ', ?' : '';
            $ins = $db->prepare("
                INSERT INTO vehicle_trip_reports
                    (driver_id, vehicle_id, report_date{$seqInsCol},
                     pre_trip_at, odometer_start,
                     chk_leaks, chk_hitch, chk_rear_gate, chk_loads_secure,
                     chk_trailer_lights, chk_truck_brakes, chk_truck_lights,
                     chk_mirrors, chk_tire_pressure, chk_washer_wipers,
                     defects_critical, defect_unhitch, defects_non_urgent,
                     safe_to_drive, status)
                VALUES
                    (?, 'RAM3500-PF8865', ?{$seqInsVal},
                     NOW(), ?,
                     ?, ?, ?, ?,
                     ?, ?, ?,
                     ?, ?, ?,
                     ?, ?, ?,
                     ?, 'pre_complete')
            ");
            $insParams = [$driverId, $today];
            if ($hasTripSequence) $insParams[] = $nextSeq;
            $insParams = array_merge($insParams, [
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
            $ins->execute($insParams);
            $reportId = (int)$db->lastInsertId();
        }

        $pdfResult = ['success' => false, 'error' => 'PDF not generated'];
        if ($reportId) {
            require_once APP_ROOT . '/Modules/Driver/TripReportPdf.php';
            $pdfResult = TripReportPdf::generate($reportId, $db);
        }

        echo json_encode([
            'success'   => true,
            'message'   => 'Pre-trip inspection saved',
            'pdf'       => $pdfResult,
            'report_id' => $reportId,
        ]);

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
        // Find the current open trip — the latest row for today where
        // pre_trip_at is set but post_trip_at is null. That's the trip
        // we're closing. If none exists (driver skipped pre-trip), fall
        // back to creating a shell row so the post-trip data has
        // somewhere to land — matches the legacy edge case.
        $openStmt = $db->prepare("
            SELECT id FROM vehicle_trip_reports
            WHERE driver_id = ?
              AND report_date = ?
              AND pre_trip_at IS NOT NULL
              AND post_trip_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $openStmt->execute([$driverId, $today]);
        $reportId = (int)($openStmt->fetchColumn() ?: 0);

        if ($reportId === 0) {
            // Edge case: driver somehow reached post-trip with no open
            // trip. Create a shell row so the post-trip data still saves.
            $nextSeq = 1;
            if ($hasTripSequence) {
                $seqStmt = $db->prepare("
                    SELECT COALESCE(MAX(trip_sequence), 0) + 1
                    FROM vehicle_trip_reports
                    WHERE driver_id = ? AND report_date = ?
                ");
                $seqStmt->execute([$driverId, $today]);
                $nextSeq = (int)$seqStmt->fetchColumn() ?: 1;
            }

            $seqInsCol2 = $hasTripSequence ? ', trip_sequence' : '';
            $seqInsVal2 = $hasTripSequence ? ', ?' : '';
            $ins = $db->prepare("
                INSERT INTO vehicle_trip_reports
                    (driver_id, vehicle_id, report_date{$seqInsCol2}, status)
                VALUES (?, 'RAM3500-PF8865', ?{$seqInsVal2}, 'pre_complete')
            ");
            $insParams2 = [$driverId, $today];
            if ($hasTripSequence) $insParams2[] = $nextSeq;
            $ins->execute($insParams2);
            $reportId = (int)$db->lastInsertId();
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
            WHERE id = ?
        ");
        $upd->execute([$odomEnd, $remarks, $hosOnDutyDriv, $hosOnDutyOth, $hosOffDuty, $reportId]);

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
