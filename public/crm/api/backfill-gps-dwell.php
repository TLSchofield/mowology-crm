<?php
/**
 * Backfill: compute GPS dwell time for completed visits that have GPS points
 * but no gps_dwell_minutes recorded yet, then update plan GPS averages.
 *
 * Safe to run multiple times — skips visits already processed.
 * Admin only. Visit /crm/api/backfill-gps-dwell.php
 *
 * GET  → show status (counts only, no writes)
 * POST → run backfill
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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
session_write_close();

if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

// Check migration has run (columns must exist)
$db = getDB();
try {
    $colCheck = $db->query("SHOW COLUMNS FROM job_visits LIKE 'gps_dwell_minutes'");
    if ($colCheck->rowCount() === 0) {
        echo json_encode(['error' => 'Migration 902 not yet applied — run 902_gps_dwell_time.sql first']);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['error' => 'Column check failed: ' . $e->getMessage()]);
    exit;
}

// GET → diagnostic count only
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pending = (int)$db->query("
        SELECT COUNT(DISTINCT vgp.visit_id)
        FROM visit_gps_points vgp
        JOIN job_visits jv ON jv.id = vgp.visit_id
        WHERE jv.status = 'completed'
          AND jv.gps_dwell_minutes IS NULL
    ")->fetchColumn();

    $already = (int)$db->query("
        SELECT COUNT(*) FROM job_visits
        WHERE status = 'completed' AND gps_dwell_minutes IS NOT NULL
    ")->fetchColumn();

    echo json_encode([
        'ready'         => true,
        'pending_visits'  => $pending,
        'already_computed' => $already,
        'note' => 'POST to this endpoint to run the backfill',
    ]);
    exit;
}

// POST → run backfill
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'GET or POST only']);
    exit;
}

require_once APP_ROOT . '/Services/Scheduling/DwellTimeService.php';

$result = DwellTimeService::backfillAllVisits();

echo json_encode([
    'success'       => true,
    'updated'       => $result['updated'],
    'no_data'       => $result['no_data'],
    'plans_updated' => $result['plans_updated'],
    'message'       => "Computed GPS dwell for {$result['updated']} visits, updated {$result['plans_updated']} plan averages. {$result['no_data']} visits had insufficient GPS data.",
]);
