<?php
/**
 * One-time backfill: populate calendar_stop_crew from plan_crew_assignments
 * for all future scheduled stops that are missing secondary crew members.
 *
 * Run once via: GET /crm/api/backfill-stop-crew.php
 * Safe to run multiple times — uses INSERT IGNORE.
 * DELETE after verifying it has run successfully.
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

// Admin only
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();

// Step 1: Find all future scheduled stops that have visits from plans with multi-crew assignments
// and INSERT IGNORE the missing crew into calendar_stop_crew
$stmt = $db->prepare("
    INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id)
    SELECT DISTINCT jv.stop_id, pca.user_id
    FROM job_visits jv
    JOIN plan_crew_assignments pca ON pca.plan_id = jv.plan_id
    WHERE jv.status = 'scheduled'
      AND jv.scheduled_date >= CURDATE()
      AND jv.stop_id IS NOT NULL
");
$stmt->execute();
$inserted = $stmt->rowCount();

// Step 2: Also ensure calendar_stops.crew_id matches the lead crew from plan_crew_assignments
// for stops that are only linked to a single plan (safe to update)
$updStmt = $db->prepare("
    UPDATE calendar_stops cs
    INNER JOIN (
        SELECT jv.stop_id, pca.user_id AS lead_user_id
        FROM job_visits jv
        JOIN plan_crew_assignments pca ON pca.plan_id = jv.plan_id AND pca.role = 'lead'
        WHERE jv.status = 'scheduled'
          AND jv.scheduled_date >= CURDATE()
          AND jv.stop_id IS NOT NULL
        GROUP BY jv.stop_id
        HAVING COUNT(DISTINCT jv.plan_id) = 1
    ) AS solo ON solo.stop_id = cs.id
    SET cs.crew_id = solo.lead_user_id, cs.updated_at = NOW()
    WHERE cs.crew_id IS NULL OR cs.crew_id != solo.lead_user_id
");
$updStmt->execute();
$updated = $updStmt->rowCount();

// Step 3: Count how many stops now have multi-crew in calendar_stop_crew
$countStmt = $db->query("
    SELECT COUNT(DISTINCT stop_id) AS stops_with_multi_crew
    FROM calendar_stop_crew
    GROUP BY stop_id
    HAVING COUNT(*) > 1
");
$multiCrewStops = $countStmt->rowCount();

echo json_encode([
    'success'              => true,
    'crew_rows_inserted'   => $inserted,
    'stops_crew_updated'   => $updated,
    'stops_with_multi_crew_after' => $multiCrewStops,
    'message'              => "Backfill complete. {$inserted} crew rows inserted, {$updated} stop lead crew updated.",
]);
