<?php
/**
 * Visit Summary API (session) — the schedule Timeline's "click a job" modal.
 *
 * GET ?visit_id=42 → { success, summary:{ visit, job, client, property, crew, same_stop, checklist, materials, notes, photos, invoice } }
 *
 * Read-only. Office (schedule.edit) can open any visit; everyone else only a visit they are crew on.
 * All SQL lives in VisitSummaryService.
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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitSummaryService.php';

    requireLogin();
    requirePermission('schedule.view');
    $user = getCurrentUser();
    session_write_close(); // read-only

    $visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
    if ($visitId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'visit_id is required']);
        exit;
    }

    $db = getDB();
    $summary = (new VisitSummaryService($db))->forVisit($visitId);
    if ($summary === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'This visit no longer exists.']);
        exit;
    }

    if (!userHasPermission('schedule.edit')) {
        $me = (int)($user['id'] ?? 0);
        $own = $db->prepare("
            SELECT 1 FROM job_visits jv
            LEFT JOIN calendar_stop_crew csc ON csc.stop_id = jv.stop_id AND csc.user_id = ?
            WHERE jv.id = ? AND (jv.assigned_crew_id = ? OR csc.user_id IS NOT NULL) LIMIT 1
        ");
        $own->execute([$me, $visitId, $me]);
        if (!$own->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You are not on this visit.']);
            exit;
        }
    }

    $summary['me'] = (int)($user['id'] ?? 0);
    $summary['can_edit'] = userHasPermission('jobs.edit');
    echo json_encode(['success' => true, 'summary' => $summary]);
} catch (Throwable $e) {
    error_log('visit-summary.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong on our side.']);
}
