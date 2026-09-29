<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/visit-flag.php
 *
 * Mobile Schedule API — Visit Endorsement Flag Toggle
 *
 * POST /api/schedule/visit-flag
 * Authorization: Bearer <jwt>
 * Content-Type: application/json
 *
 * Body: { "visit_id": 42 }
 *
 * Response 200: { "success": true, "is_flagged": true, "visit_endorsed": true, "endorsed_by": ["Name"] }
 *   is_flagged = the caller's own endorsement; visit_endorsed = anyone's.
 *
 * Crew can toggle visits assigned to them or to a stop they are on; admin can toggle any.
 * Locked visits (status = completed/cancelled) cannot be unflagged by crew.
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$jwtUser = requireJwt();

// ── Parse input ───────────────────────────────────────────────────────────────
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$visitId = isset($body['visit_id']) ? (int)$body['visit_id'] : 0;

if ($visitId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'visit_id is required']);
    exit;
}

$isAdmin = jwtIsAdmin($jwtUser['role']);

// ── Load visit ────────────────────────────────────────────────────────────────
try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, stop_id, assigned_crew_id, status, is_flagged FROM job_visits WHERE id = ? LIMIT 1');
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

if (!$visit) {
    http_response_code(404);
    echo json_encode(['error' => 'Visit not found']);
    exit;
}

// ── Ownership check ───────────────────────────────────────────────────────────
// Anyone on the stop's crew may endorse, not only the lead the visit is assigned to.
require_once APP_ROOT . '/Modules/Jobs/Services/VisitWorkService.php';
$stopCrew = (new VisitWorkService($db))->stopCrewIds(isset($visit['stop_id']) ? (int)$visit['stop_id'] : null);
if (!VisitWorkService::canAccess($visit, (int)$jwtUser['id'], $isAdmin, $stopCrew)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not your visit']);
    exit;
}

// ── Lock check (crew only) ────────────────────────────────────────────────────
$lockedStatuses = ['completed', 'cancelled', 'skipped'];
if (!$isAdmin && in_array(strtolower((string)$visit['status']), $lockedStatuses, true)) {
    http_response_code(409);
    echo json_encode(['error' => 'Visit is locked']);
    exit;
}

// ── Toggle — each crew member has their own endorsement ───────────────────────
require_once APP_ROOT . '/Modules/Jobs/Services/VisitEndorsementService.php';

try {
    $result = (new VisitEndorsementService($db))->toggle($visitId, (int)$jwtUser['id'], $isAdmin);
} catch (Throwable $e) {
    error_log('[schedule/visit-flag] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not update flag']);
    exit;
}

// Endorsed → into the portfolio approval queue (idempotent; no-op until both photos exist).
if ($result['mine']) {
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitLifecycleService.php';
    VisitLifecycleService::queueForPortfolio($visitId, (int)$jwtUser['id']);
}

// is_flagged is THIS user's heart; visit_endorsed is "anyone endorsed".
echo json_encode([
    'success'        => true,
    'is_flagged'     => $result['mine'],
    'visit_endorsed' => $result['endorsed'],
    'endorsed_by'    => $result['endorsed_by'],
]);
