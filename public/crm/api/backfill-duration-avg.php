<?php
/**
 * Backfill: populate job_visits.actual_duration_minutes and update
 * job_plans.estimated_duration_minutes with rolling average of actual visit times.
 *
 * Safe to run multiple times. Only processes completed visits with timer data.
 * Visit /crm/api/backfill-duration-avg.php (admin only).
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

$db = getDB();
$log = [];

// 1. Update actual_duration_minutes for all completed visits with timer data
$visitStmt = $db->query("
    SELECT DISTINCT jv.id AS visit_id
    FROM job_visits jv
    JOIN job_time_entries jte ON jte.visit_id = jv.id
    WHERE jv.status = 'completed'
      AND jte.status IN ('completed', 'edited')
      AND jte.duration_minutes > 0
");
$visitIds = array_column($visitStmt->fetchAll(PDO::FETCH_ASSOC), 'visit_id');

$visitsUpdated = 0;
foreach ($visitIds as $vid) {
    $db->prepare("
        UPDATE job_visits
        SET actual_duration_minutes = (
            SELECT COALESCE(SUM(duration_minutes), 0)
            FROM job_time_entries
            WHERE visit_id = ? AND status IN ('completed', 'edited')
        )
        WHERE id = ?
    ")->execute([$vid, $vid]);
    $visitsUpdated++;
}
$log[] = "Updated actual_duration_minutes on {$visitsUpdated} visits.";

// 2. Update estimated_duration_minutes on each plan with completed visits
$planStmt = $db->query("
    SELECT DISTINCT jv.plan_id
    FROM job_visits jv
    JOIN job_time_entries jte ON jte.visit_id = jv.id
    WHERE jv.status = 'completed'
      AND jte.status IN ('completed', 'edited')
      AND jte.duration_minutes > 0
");
$planIds = array_column($planStmt->fetchAll(PDO::FETCH_ASSOC), 'plan_id');

$plansUpdated = 0;
$planDetails = [];
foreach ($planIds as $pid) {
    $rollAvgStmt = $db->prepare("
        SELECT AVG(visit_total) AS avg_duration
        FROM (
            SELECT SUM(jte.duration_minutes) AS visit_total
            FROM job_visits jv
            JOIN job_time_entries jte ON jte.visit_id = jv.id
            WHERE jv.plan_id = ?
              AND jv.status = 'completed'
              AND jte.status IN ('completed', 'edited')
            GROUP BY jv.id
            HAVING SUM(jte.duration_minutes) > 0
        ) AS per_visit
    ");
    $rollAvgStmt->execute([$pid]);
    $row = $rollAvgStmt->fetch(PDO::FETCH_ASSOC);
    $newEst = ($row && $row['avg_duration'] !== null) ? (int)round((float)$row['avg_duration']) : 0;

    if ($newEst > 0) {
        // Fetch old value for the log
        $oldRow = $db->prepare("SELECT estimated_duration_minutes FROM job_plans WHERE id = ?");
        $oldRow->execute([$pid]);
        $old = (int)($oldRow->fetchColumn() ?: 0);

        $db->prepare("UPDATE job_plans SET estimated_duration_minutes = ? WHERE id = ?")->execute([$newEst, $pid]);
        $planDetails[] = "Plan #{$pid}: {$old} → {$newEst} min";
        $plansUpdated++;
    }
}
$log[] = "Updated estimated_duration_minutes on {$plansUpdated} plans.";
$log = array_merge($log, $planDetails);

echo json_encode(['success' => true, 'log' => $log]);
