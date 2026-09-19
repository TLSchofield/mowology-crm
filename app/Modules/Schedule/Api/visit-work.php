<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/visit-work.php
 *
 * Mobile proof-of-work API — checklist, materials used, notes.
 * Authorization: Bearer <jwt>
 *
 * GET  ?visit_id=123
 * POST {action: 'save_checklist', visit_id, items: [{item, checked, note}]}
 * POST {action: 'save_materials', visit_id, items: [{name, qty, unit, rate_per_unit, note}]}
 * POST {action: 'add_note',       visit_id, content, note_type?, visible_to_customer?}
 *
 * Every write answers with the full refreshed state so the client never has to
 * merge. Thin controller — the rules live in VisitWorkService.
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

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitWorkService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $isAdmin = jwtIsAdmin((string)$jwtUser['role']);

    $isGet = $_SERVER['REQUEST_METHOD'] === 'GET';
    $input = $isGet ? [] : (json_decode(file_get_contents('php://input'), true) ?? []);

    $visitId = (int)($isGet ? ($_GET['visit_id'] ?? 0) : ($input['visit_id'] ?? 0));
    if ($visitId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'visit_id is required']);
        exit;
    }

    $service = new VisitWorkService(getDB());
    $visit   = $service->loadVisit($visitId);
    if (!$visit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'That visit is no longer available.']);
        exit;
    }

    $stopCrew = $service->stopCrewIds(isset($visit['stop_id']) ? (int)$visit['stop_id'] : null);
    if (!VisitWorkService::canAccess($visit, $userId, $isAdmin, $stopCrew)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This visit is assigned to another crew member.']);
        exit;
    }

    if (!$isGet) {
        // Same rule as the web PoW screen: a locked visit is read-only for crew.
        if ($visit['locked_at'] !== null && !$isAdmin) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'This visit is locked. Ask the office to unlock it before making changes.',
            ]);
            exit;
        }

        $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
        $action = trim((string)($input['action'] ?? ''));

        switch ($action) {
            case 'save_checklist':
                if (!is_array($input['items'] ?? null)) {
                    throw new InvalidArgumentException('items must be an array');
                }
                $service->saveChecklist($visitId, $userId, $input['items'], $ip);
                break;

            case 'save_materials':
                if (!is_array($input['items'] ?? null)) {
                    throw new InvalidArgumentException('items must be an array');
                }
                $service->saveMaterials($visitId, $userId, $input['items'], $ip);
                break;

            case 'add_note':
                $service->addNote(
                    $visitId,
                    $userId,
                    (string)($input['content'] ?? ''),
                    (string)($input['note_type'] ?? 'general'),
                    !empty($input['visible_to_customer'])
                );
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
                exit;
        }

        $visit = $service->loadVisit($visitId);
    }

    echo json_encode(['success' => true] + $service->getState($visit));

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('visit-work.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save. Please try again.']);
}
