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

/**
 * Render a styled HTML error page for download failures.
 */
function renderTripReportError(int $code, string $heading, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . htmlspecialchars($heading) . ' — Mowology</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
       background: #f4f4f4; display: flex; justify-content: center; align-items: center;
       min-height: 100vh; padding: 1rem; }
.err-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            max-width: 480px; width: 100%; overflow: hidden; }
.err-header { background: #1A5F4A; padding: 24px 32px; text-align: center; }
.err-header h1 { color: #fff; font-size: 22px; font-weight: 700; }
.err-header p { color: #7FD858; font-size: 13px; letter-spacing: 0.5px; margin-top: 4px; }
.err-body { padding: 32px; text-align: center; }
.err-icon { font-size: 48px; margin-bottom: 16px; color: #dc3545; }
.err-body h2 { font-size: 20px; margin-bottom: 12px; color: #1a202c; }
.err-body p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 16px; }
.err-footer { border-top: 1px solid #e2e8f0; padding: 16px 32px; text-align: center; }
.err-footer a { color: #2D8659; text-decoration: none; font-size: 14px; font-weight: 500; }
.err-footer a:hover { text-decoration: underline; }
</style></head><body>
<div class="err-card">
  <div class="err-header"><h1>Mowology</h1><p>CRM</p></div>
  <div class="err-body">
    <div class="err-icon">&#9888;</div>
    <h2>' . htmlspecialchars($heading) . '</h2>
    <p>' . htmlspecialchars($message) . '</p>
  </div>
  <div class="err-footer">
    <a href="javascript:history.back()">&larr; Go back</a>
    &nbsp;&middot;&nbsp;
    <a href="/crm/dashboard_appstack.php">Dashboard</a>
  </div>
</div>
</body></html>';
    exit;
}

// ── GET: download ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { renderTripReportError(400, 'Missing Report ID', 'The download link is missing the report ID.'); }

    $stmt = $db->prepare("SELECT * FROM vehicle_trip_reports WHERE id = ?");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) { renderTripReportError(404, 'Report Not Found', 'This trip report does not exist or has been deleted.'); }

    // Only admin/manager or the driver themselves can download
    if ($user['role'] === 'user' && (int)$report['driver_id'] !== (int)$user['id']) {
        renderTripReportError(403, 'Access Denied', 'You do not have permission to download this trip report.');
    }

    if (empty($report['pdf_path'])) {
        renderTripReportError(404, 'PDF Not Yet Generated', 'The PDF for this trip report has not been generated yet. Please try again in a few moments, or contact support if this persists.');
    }

    $fullPath = PROJECT_ROOT . '/' . $report['pdf_path'];
    if (!file_exists($fullPath)) {
        renderTripReportError(404, 'PDF File Missing', 'The PDF file is no longer on disk. It may have been moved or cleaned up. Please regenerate the report or contact support.');
    }

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
