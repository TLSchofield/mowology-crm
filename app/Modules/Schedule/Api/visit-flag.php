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
 * Response 200: { "success": true, "is_flagged": true }
 *
 * Crew can only toggle visits assigned to them; admin can toggle any.
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
    $stmt = $db->prepare('SELECT id, assigned_crew_id, status, is_flagged FROM job_visits WHERE id = ? LIMIT 1');
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
if (!$isAdmin && (int)($visit['assigned_crew_id'] ?? 0) !== $jwtUser['id']) {
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

// ── Toggle ────────────────────────────────────────────────────────────────────
$newFlag = $visit['is_flagged'] ? 0 : 1;

try {
    $db->prepare('UPDATE job_visits SET is_flagged = ? WHERE id = ?')
       ->execute([$newFlag, $visitId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not update flag']);
    exit;
}

echo json_encode(['success' => true, 'is_flagged' => (bool)$newFlag]);
