<?php
/**
 * Route Reconciliation API — Truck GPS vs Visit Clock-In Conflict Detection
 *
 * Compares truck GPS dwell stops against scheduled visit records to detect:
 *   - truck_at_site_no_clockin: Truck stopped at property but visit not clocked in
 *   - clockin_no_truck: Visit completed but no truck GPS presence detected
 *
 * GET ?date=YYYY-MM-DD  (defaults to today Pacific)
 * Auth: admin/manager only
 */
declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once APP_ROOT . '/Core/ApiResponse.php'; // generic errors to the user, details to the log
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();

// Release session lock immediately — this is a read-only endpoint
session_write_close();

if (!in_array($user['role'], ['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $db = getDB();
    $tz = new DateTimeZone('America/Vancouver');

    // Accepts ?date=YYYY-MM-DD (single day) or ?start=YYYY-MM-DD&end=YYYY-MM-DD (range)
    $startDate = $_GET['start'] ?? $_GET['date'] ?? (new DateTime('now', $tz))->format('Y-m-d');
    $endDate   = $_GET['end'] ?? $startDate;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        throw new Exception('Invalid date format. Use YYYY-MM-DD');
    }

    require_once APP_ROOT . '/Modules/Operations/Services/RouteReconciliationService.php';
    $r = RouteReconciliationService::conflicts($db, $startDate, $endDate);
    echo json_encode(['success' => true] + $r);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => ApiResponse::message($e, 'route-reconciliation')]);
}
