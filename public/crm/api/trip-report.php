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
        // File missing (e.g. wrong stored path or server cleanup) — attempt regeneration
        require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';
        require_once APP_ROOT . '/Modules/Driver/TripReportPdf.php';
        $regen = TripReportPdf::generate($id, $db);
        if (!$regen['success'] || empty($regen['path']) || !file_exists($regen['path'])) {
            renderTripReportError(404, 'PDF File Missing', 'The PDF file could not be found or regenerated. Please contact support.');
        }
        $fullPath = $regen['path'];
        $filename = 'trip_report_' . $report['report_date'] . '.pdf';
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

// All writes go through TripReportService — the same rules the iOS app uses: WHO must log
// is decided per SHIFT (not by the permanent is_driver flag), the vehicle comes from the
// fleet list, an inspection done with no signal is filed under the time it was DONE
// (`performed_at`), and a replay is the same inspection, never a second trip.
require_once APP_ROOT . '/Modules/Driver/Services/TripReportService.php';
$tripService = new TripReportService($db);

$pickVehicle = static function () use ($tripService): string {
    $offered = array_column($tripService->vehicles(), 'id');
    $wanted  = (string)($_POST['vehicle_id'] ?? '');
    if ($wanted !== '' && in_array($wanted, $offered, true)) return $wanted;
    if (count($offered) === 1) return $offered[0];
    if (!$offered) return 'RAM3500-PF8865';            // empty log + no fleet setting: historic default
    throw new InvalidArgumentException('Choose which vehicle you are driving.');
};

try {
    if ($action === 'declare') {
        // "Are you driving a company vehicle this shift?" — both answers are recorded.
        $driving = !empty($_POST['driving']);
        $tripService->declare($driverId, $driving, $driving ? $pickVehicle() : null, 'web', $_POST['performed_at'] ?? null);
        echo json_encode(['success' => true, 'state' => $tripService->shiftState($driverId)]);
        exit;
    }

    if ($action === 'get_state') {
        echo json_encode(['success' => true, 'state' => $tripService->shiftState($driverId), 'open_trip' => $tripService->hasOpenTrip($driverId)]);
        exit;
    }

    if ($action === 'save_pre_trip') {
        $vehicleId = $pickVehicle();
        $result    = $tripService->savePreTrip($driverId, $vehicleId, $_POST, $today);
        $tripService->declare($driverId, true, $vehicleId, 'web', $_POST['performed_at'] ?? null);
        if (!$result['may_drive']) {
            $tripService->alertOfficeUnsafe($result['report_id'], (string)($user['full_name'] ?? $user['name'] ?? "User #{$driverId}"));
        }
        echo json_encode([
            'success'   => true,
            'message'   => 'Pre-trip inspection saved',
            'pdf'       => ['success' => true],
            'report_id' => $result['report_id'],
            'may_drive' => $result['may_drive'],
            'unchecked' => $result['unchecked'],
        ]);
        exit;
    }

    if ($action === 'save_post_trip') {
        $reportId = $tripService->savePostTrip($driverId, $_POST, $today);
        $tripService->declare($driverId, false, null, 'web', $_POST['performed_at'] ?? null);
        echo json_encode([
            'success'   => true,
            'message'   => 'Post-trip report saved',
            'pdf'       => ['success' => true],
            'report_id' => $reportId,
        ]);
        exit;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => 'needs_confirmation']);
    exit;
} catch (Throwable $e) {
    error_log('[trip-report] ' . $action . ' error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save the report']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
