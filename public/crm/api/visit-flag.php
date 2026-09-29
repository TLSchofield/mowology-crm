<?php
/**
 * Visit Flag API
 * ──────────────
 * Toggles the crew endorsement flag (is_flagged) on a job visit.
 *
 * POST { visit_id: int, csrf_token: string }
 *
 * Returns: { success: true, is_flagged: bool }
 *
 * Access: crew must own the visit OR admin.
 * Locked visits cannot be re-flagged.
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

    requireLogin();
    $user = getCurrentUser();

    $isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $visitId   = isset($_POST['visit_id'])   ? (int)$_POST['visit_id']   : 0;
    $csrfToken = $_POST['csrf_token'] ?? '';

    if ($visitId < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'visit_id required']);
        exit;
    }

    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    session_write_close();

    $db = getDB();

    // Load visit
    $stmt = $db->prepare("SELECT id, stop_id, assigned_crew_id, status, is_flagged, social_draft_id FROM job_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        http_response_code(404);
        echo json_encode(['error' => 'Visit not found']);
        exit;
    }

    // Auth: the assigned crew member, anyone on the stop's crew, or office
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitWorkService.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitEndorsementService.php';
    $stopCrew = (new VisitWorkService($db))->stopCrewIds(isset($visit['stop_id']) ? (int)$visit['stop_id'] : null);
    if (!VisitWorkService::canAccess($visit, (int)$user['id'], (bool)$isAdmin, $stopCrew)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized']);
        exit;
    }

    // Locked statuses: admin can still flag, crew cannot
    $lockedStatuses = ['completed', 'cancelled', 'skipped'];
    if (!$isAdmin && in_array(strtolower((string)($visit['status'] ?? '')), $lockedStatuses, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Visit is locked']);
        exit;
    }

    // Toggle — each crew member has their own endorsement
    $endorsement = (new VisitEndorsementService($db))->toggle($visitId, (int)$user['id'], (bool)$isAdmin);
    $newFlag     = $endorsement['mine'] ? 1 : 0;

    $responseExtra = [
        'visit_endorsed' => $endorsement['endorsed'],
        'endorsed_by'    => $endorsement['endorsed_by'],
    ];

    // The first endorsement of a completed visit auto-generates a social draft
    if ($endorsement['newly_endorsed'] && strtolower((string)($visit['status'] ?? '')) === 'completed') {
        require_once APP_ROOT . '/Modules/Social/Services/SocialHashtagEngine.php';
        require_once APP_ROOT . '/Modules/Social/Services/SocialCardGenerator.php';
        require_once APP_ROOT . '/Modules/Social/Services/SocialDraftPipeline.php';
        try {
            $postId = SocialDraftPipeline::triggerFromVisit($visitId, $db);
            // flagged_at + social_draft_id are set inside triggerFromVisit
            $responseExtra['social_draft_id'] = $postId;
        } catch (\Throwable $e) {
            // Non-fatal — flag toggle still succeeds, pipeline failure logged
            error_log("SocialDraftPipeline failed for visit $visitId: " . $e->getMessage());
        }
    }

    // Endorsed → into the portfolio approval queue (no-op until both photos exist).
    if ($newFlag === 1) {
        require_once APP_ROOT . '/Modules/Jobs/Services/VisitLifecycleService.php';
        VisitLifecycleService::queueForPortfolio($visitId, (int)$user['id']);
    }

    echo json_encode(array_merge(
        ['success' => true, 'is_flagged' => (bool)$newFlag],
        $responseExtra
    ));

} catch (Throwable $e) {
    error_log("visit-flag.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
