<?php
/**
 * ONE-TIME ADMIN REPAIR — Cancel phantom past-dated scheduled visits left behind
 * by the recurrence-edit backfill bug.
 *
 * Problem this fixes:
 *   Before the fix in PlanFunctions::updateJobPlan(), changing a plan's recurrence
 *   (e.g. 7-day -> 14-day) reset visits_generated_through to NULL, which put
 *   generateVisits() into "fresh mode" and backfilled up to 90 days of NEW
 *   past-dated visits at the new cadence. These show as "Overdue" / "GPS Conflict"
 *   alongside the real completed visits from the old schedule. They are revenue-
 *   risky (real job_visits rows, invoiceable) and pollute the crew schedule.
 *
 * What it does:
 *   For a given plan, finds visits that are status='scheduled' AND dated before
 *   today. These were never worked (real past visits are 'completed'/'skipped'),
 *   so they can be safely cancelled.
 *
 * Safety:
 *   - Admin only.
 *   - DRY-RUN by default. Lists exactly what it would cancel (with created_at and
 *     sequence_index so you can confirm they are the backfill phantoms). Changes
 *     nothing until you pass &apply=1.
 *   - Only touches status='scheduled' rows dated < today (never completed/skipped/
 *     invoiced/started visits).
 *   - Sets status='cancelled' (reversible, keeps the audit trail) — never deletes.
 *
 * Usage:
 *   /crm/api/cleanup-phantom-visits.php?plan_id=91            (dry run)
 *   /crm/api/cleanup-phantom-visits.php?plan_id=91&apply=1    (commit)
 *
 * DELETE THIS FILE after the affected plans are cleaned up.
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

header('Content-Type: application/json; charset=utf-8');

try {
    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin only']);
        exit;
    }

    $planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
    $apply  = isset($_GET['apply']) && $_GET['apply'] === '1';

    if ($planId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'plan_id required']);
        exit;
    }

    $db = getDB();

    // Candidate phantom rows: scheduled, dated before today. Never touch
    // completed / skipped / cancelled / started / invoiced visits.
    $sel = $db->prepare("
        SELECT jv.id, jv.visit_number, jv.scheduled_date, jv.status,
               jv.sequence_index, jv.created_at, jv.started_at, jv.invoice_id
        FROM job_visits jv
        WHERE jv.plan_id = ?
          AND jv.status = 'scheduled'
          AND jv.scheduled_date < CURDATE()
          AND jv.started_at IS NULL
          AND jv.invoice_id IS NULL
        ORDER BY jv.scheduled_date ASC, jv.sequence_index ASC
    ");
    $sel->execute([$planId]);
    $candidates = $sel->fetchAll(PDO::FETCH_ASSOC);

    $result = [
        'plan_id'          => $planId,
        'mode'             => $apply ? 'APPLY' : 'DRY-RUN',
        'candidate_count'  => count($candidates),
        'candidates'       => $candidates,
    ];

    if (!$apply) {
        $result['note'] = 'Dry run — nothing changed. Confirm these are the backfill '
                        . 'phantoms (high sequence_index on past dates, recent created_at), '
                        . 'then re-run with &apply=1.';
        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    if (empty($candidates)) {
        $result['cancelled'] = 0;
        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    $ids = array_map(static fn($r) => (int)$r['id'], $candidates);
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $db->beginTransaction();
    $upd = $db->prepare("
        UPDATE job_visits
        SET status = 'cancelled', status_changed_at = NOW()
        WHERE id IN ({$ph})
    ");
    $upd->execute($ids);
    $cancelled = $upd->rowCount();
    $db->commit();

    $result['cancelled'] = $cancelled;
    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
