<?php
/**
 * Auto-Rollover Guard — Cron Job
 * /app/Modules/Jobs/Cron/auto_rollover.php
 *
 * Runs nightly (recommended: 11 PM) to roll incomplete recurring visits forward one day.
 *
 * For each past-due recurring visit:
 *   - If max rollover days exceeded → skip
 *   - If next day already has a visit for the same plan → skip (collision)
 *   - Otherwise → move scheduled_date forward by 1 day
 *
 * Supports both CLI and web POST execution.
 * Web: POST /crm/cron/auto_rollover.php (admin-only)
 * CLI: php auto_rollover.php
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

// Fatal error handler
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Determine execution mode
$isCli = (php_sapi_name() === 'cli');

// Web mode: POST only + auth check
if (!$isCli) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST method required']);
        exit;
    }

    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    $user = getCurrentUser();

    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }

    header('Content-Type: application/json');
} else {
    // CLI mode: bootstrap DB + functions
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
}

// Load plan functions (ensureCalendarStop, updateVisitStatus, etc.)
require_once CRM_INCLUDES . '/plan-functions.php';

function rolloverJsonRespond(array $data): void {
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

try {
    $db = getDB();

    // System user ID for audit trail (0 = system/cron)
    $systemUserId = 0;
    $today = date('Y-m-d');

    // Load global max rollover days default
    $globalMaxRollover = 3;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'max_rollover_days'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val !== false && is_numeric($val)) {
            $globalMaxRollover = (int)$val;
        }
    } catch (Exception $e) {
        error_log("auto_rollover: Could not read ops_settings: " . $e->getMessage());
    }

    // Find all past-due incomplete recurring visits with rollover enabled
    $stmt = $db->prepare("
        SELECT jv.id AS visit_id, jv.visit_number, jv.plan_id, jv.stop_id,
               jv.scheduled_date, jv.scheduled_time_start, jv.scheduled_time_end,
               jv.assigned_crew_id, jv.status, jv.rollover_count,
               jv.original_scheduled_date, jv.sequence_index,
               jp.is_recurring, jp.plan_end_date, jp.status AS plan_status,
               jp.property_id, jp.default_crew_id, jp.plan_number,
               jp.default_time_start, jp.default_time_end, jp.service_package_id,
               jp.company_id,
               sp.auto_rollover, sp.max_rollover_days AS service_max_rollover
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN service_packages sp ON jp.service_package_id = sp.id
        WHERE jv.status IN ('scheduled', 'in_progress')
          AND jv.scheduled_date < ?
          AND jp.is_recurring = 1
          AND jp.status = 'active'
          AND COALESCE(sp.auto_rollover, 1) = 1
        ORDER BY jv.scheduled_date ASC
    ");
    $stmt->execute([$today]);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [
        'success' => true,
        'visits_found' => count($visits),
        'visits_rolled' => 0,
        'visits_skipped' => 0,
        'details' => [],
        'errors' => []
    ];

    foreach ($visits as $visit) {
        try {
            $visitId = (int)$visit['visit_id'];
            $planId = (int)$visit['plan_id'];
            $nextDay = date('Y-m-d', strtotime($visit['scheduled_date'] . ' +1 day'));
            $rolloverCount = (int)$visit['rollover_count'];
            $originalDate = $visit['original_scheduled_date'] ?? $visit['scheduled_date'];
            $effectiveMax = $visit['service_max_rollover'] !== null
                ? (int)$visit['service_max_rollover']
                : $globalMaxRollover;

            // Check: plan ended?
            if ($visit['plan_end_date'] && $nextDay > $visit['plan_end_date']) {
                skipVisit($db, $visitId, $systemUserId,
                    "Auto-skipped: plan ended on {$visit['plan_end_date']} (originally scheduled {$originalDate})",
                    $visit, $originalDate);
                $results['visits_skipped']++;
                $results['details'][] = "{$visit['visit_number']}: skipped (plan ended)";
                continue;
            }

            // Check: max rollover exceeded?
            if ($rolloverCount >= $effectiveMax) {
                skipVisit($db, $visitId, $systemUserId,
                    "Auto-skipped: exceeded max rollover of {$effectiveMax} days (originally scheduled {$originalDate})",
                    $visit, $originalDate);
                $results['visits_skipped']++;
                $results['details'][] = "{$visit['visit_number']}: skipped (max {$effectiveMax} days exceeded, rollover #{$rolloverCount})";
                continue;
            }

            // Check: collision with existing visit on next day?
            $collisionStmt = $db->prepare("
                SELECT id FROM job_visits
                WHERE plan_id = ? AND scheduled_date = ? AND id != ?
                  AND status NOT IN ('skipped', 'cancelled')
                LIMIT 1
            ");
            $collisionStmt->execute([$planId, $nextDay, $visitId]);
            if ($collisionStmt->fetchColumn()) {
                skipVisit($db, $visitId, $systemUserId,
                    "Auto-skipped: next occurrence already scheduled for {$nextDay} (originally scheduled {$originalDate})",
                    $visit, $originalDate);
                $results['visits_skipped']++;
                $results['details'][] = "{$visit['visit_number']}: skipped (collision on {$nextDay})";
                continue;
            }

            // If in_progress, stop any active timers first
            if ($visit['status'] === 'in_progress') {
                stopActiveTimersForVisit($db, $visitId);
            }

            // Roll forward: update the existing visit record
            $crewId = $visit['assigned_crew_id'] ?? $visit['default_crew_id'];
            $newStopId = ensureCalendarStop(
                (int)$visit['property_id'],
                $nextDay,
                $crewId ? (int)$crewId : null
            );

            $updateStmt = $db->prepare("
                UPDATE job_visits
                SET scheduled_date = ?,
                    stop_id = ?,
                    status = 'scheduled',
                    rollover_count = rollover_count + 1,
                    original_scheduled_date = COALESCE(original_scheduled_date, ?),
                    status_changed_at = NOW(),
                    started_at = NULL
                WHERE id = ?
            ");
            $updateStmt->execute([$nextDay, $newStopId, $visit['scheduled_date'], $visitId]);

            // Log activity
            if (function_exists('logActivityExtended')) {
                logActivityExtended(
                    $systemUserId,
                    'Visit auto-rollover',
                    "Visit {$visit['visit_number']} rolled from {$visit['scheduled_date']} to {$nextDay} (rollover #" . ($rolloverCount + 1) . ", originally {$originalDate})",
                    $visit['company_id'] ?? null,
                    null, null, null,
                    $planId,
                    $visitId
                );
            }

            $results['visits_rolled']++;
            $results['details'][] = "{$visit['visit_number']}: rolled {$visit['scheduled_date']} → {$nextDay} (#{$rolloverCount}+1)";

        } catch (Exception $e) {
            $results['errors'][] = "Visit {$visit['visit_number']}: " . $e->getMessage();
            error_log("auto_rollover error for visit {$visit['visit_id']}: " . $e->getMessage());
        }
    }

    // Clean up orphaned calendar stops (stops with no active visits)
    try {
        $cleanupStmt = $db->prepare("
            UPDATE calendar_stops cs
            SET cs.status = 'completed'
            WHERE cs.stop_date < ?
              AND cs.status = 'scheduled'
              AND NOT EXISTS (
                  SELECT 1 FROM job_visits jv
                  WHERE jv.stop_id = cs.id
                    AND jv.status NOT IN ('skipped', 'cancelled', 'completed')
              )
        ");
        $cleanupStmt->execute([$today]);
        $results['stops_cleaned'] = $cleanupStmt->rowCount();
    } catch (Exception $e) {
        $results['errors'][] = "Stop cleanup: " . $e->getMessage();
        error_log("auto_rollover stop cleanup error: " . $e->getMessage());
    }

    $hasErrors = !empty($results['errors']);
    recordCronRun(
        'auto_rollover',
        $hasErrors ? 'warning' : 'success',
        "{$results['visits_rolled']} rolled, {$results['visits_skipped']} skipped",
        null,
        $hasErrors ? implode('; ', array_slice($results['errors'], 0, 3)) : null,
        !$isCli
    );

    if ($isCli) {
        echo "Auto-rollover complete: {$results['visits_rolled']} rolled, {$results['visits_skipped']} skipped\n";
        foreach ($results['details'] as $detail) {
            echo "  - {$detail}\n";
        }
        if (!empty($results['errors'])) {
            echo "Errors:\n";
            foreach ($results['errors'] as $err) {
                echo "  ! {$err}\n";
            }
        }
    } else {
        rolloverJsonRespond($results);
    }

} catch (Exception $e) {
    $error = 'Auto-rollover fatal error: ' . $e->getMessage();
    error_log($error);
    recordCronRun('auto_rollover', 'error', 'Fatal error', null, $error, !$isCli);
    if ($isCli) {
        echo "FATAL: {$error}\n";
        exit(1);
    } else {
        rolloverJsonRespond(['success' => false, 'error' => $error]);
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Mark a visit as skipped with a rollover reason.
 */
function skipVisit(PDO $db, int $visitId, int $userId, string $reason, array $visit, string $originalDate): void {
    // If in_progress, stop active timers first
    if ($visit['status'] === 'in_progress') {
        stopActiveTimersForVisit($db, $visitId);
    }

    // Mark as skipped
    $stmt = $db->prepare("
        UPDATE job_visits
        SET status = 'skipped',
            status_changed_at = NOW(),
            completion_notes = ?,
            original_scheduled_date = COALESCE(original_scheduled_date, ?)
        WHERE id = ?
    ");
    $stmt->execute([$reason, $originalDate, $visitId]);

    // Log activity
    if (function_exists('logActivityExtended')) {
        logActivityExtended(
            $userId,
            'Visit auto-skipped',
            $reason . " (visit {$visit['visit_number']})",
            $visit['company_id'] ?? null,
            null, null, null,
            (int)$visit['plan_id'],
            $visitId
        );
    }
}

/**
 * Stop all active job time entries for a visit.
 * Used when rolling over an in_progress visit — we can't use stopVisitTimer()
 * because it requires a specific userId and the cron doesn't know who started it.
 */
function stopActiveTimersForVisit(PDO $db, int $visitId): void {
    $stmt = $db->prepare("
        UPDATE job_time_entries
        SET end_time = NOW(),
            duration_minutes = TIMESTAMPDIFF(MINUTE, start_time, NOW()),
            status = 'completed',
            notes = CONCAT(COALESCE(notes, ''), ' [Auto-stopped by rollover cron]')
        WHERE visit_id = ? AND status = 'active' AND end_time IS NULL
    ");
    $stmt->execute([$visitId]);
}
