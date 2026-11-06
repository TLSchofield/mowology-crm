<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/search.php
 *
 * Mobile Schedule API — field search (iOS Search tab).
 *
 * GET /api/schedule/search?q=balac[&lat=..&lng=..]   → { success, query, results:[property card…] }
 * GET /api/schedule/search?mode=nearby&lat=..&lng=..  → { success, results:[…] }   (empty-search state)
 * GET /api/schedule/search?mode=property&id=22[&lat&lng]
 *                                                     → { success, property:{card + notes, plans[].history, upcoming[]} }
 * GET /api/schedule/search?mode=owner&q=linda          → { success, query, groups, answer }
 * GET /api/schedule/search?mode=contact&id=473        → { success, contact, properties, quotes, invoices, messages }
 * POST /api/schedule/search?mode=pick {q,kind,id}       → records an owner search selection
 * owner / contact / pick need billing.view (admin/manager); crew keep the property search below.
 *
 * (?mode=, never ?action= — the /api router's rewrite owns `action` on GET.)
 * Authorization: Bearer <jwt>. Needs clients.view. Crew-safe: no prices, no invoices, no crew names.
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
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/plan-functions.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/ServiceHistoryService.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/FieldSearchService.php';
    require_once APP_ROOT . '/Modules/Search/Services/UniversalSearchService.php';
    require_once APP_ROOT . '/Modules/Search/Services/ClientFactAnswerer.php';

    $mode = (string)($_GET['mode'] ?? 'search');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'pick')) {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $jwtUser = requireJwt();
    $can = static function (string $perm) use ($jwtUser): bool { return jwtUserHasPermission($jwtUser, $perm); };
    if ($mode === 'pick') {
        if (!$can('billing.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Owner search needs billing access.']);
            exit;
        }
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        (new UniversalSearchService(getDB()))->recordPick((int)$jwtUser['id'], (string)($body['q'] ?? ''), (string)($body['kind'] ?? ''), (int)($body['id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }
    if ($mode === 'contact') {
        if (!$can('billing.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Owner search needs billing access.']);
            exit;
        }
        $page = (new UniversalSearchService(getDB()))->contact((int)($_GET['id'] ?? 0), $can);
        if ($page === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'That client is no longer available.']);
            exit;
        }
        echo json_encode(['success' => true] + $page);
        exit;
    }
    if ($mode === 'owner') {
        if (!$can('billing.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Owner search needs billing access.']);
            exit;
        }
        $q = UniversalSearchService::normalize((string)($_GET['q'] ?? ''));
        $groups=[];$answer=null;
        if (mb_strlen($q) >= 2) {
            $groups=(new UniversalSearchService(getDB()))->search($q,$can,(int)$jwtUser['id'])['groups'];
            $answer=(new ClientFactAnswerer(getDB()))->answer($q,$can);
        }
        echo json_encode(['success'=>true,'query'=>$q,'groups'=>$groups,'answer'=>$answer]);
        exit;
    }
    if (!jwtUserHasPermission($jwtUser, 'clients.view')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Your account can't look up clients. Ask the office."]);
        exit;
    }

    $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $today   = date('Y-m-d');
    $service = new FieldSearchService(getDB());

    switch ($mode) {
        case 'nearby':
            echo json_encode([
                'success' => true,
                'results' => ($lat === null || $lng === null) ? [] : $service->nearby($lat, $lng, $today),
            ]);
            break;

        case 'property':
            $property = $service->property((int)($_GET['id'] ?? 0), $lat, $lng, $today);
            if ($property === null) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'That property is no longer available.']);
                break;
            }
            echo json_encode(['success' => true, 'property' => $property]);
            break;

        default:
            $q = FieldSearchService::normalize($_GET['q'] ?? '');
            echo json_encode([
                'success' => true,
                'query'   => $q,
                'results' => $service->search($q, $lat, $lng, $today),
            ]);
    }

} catch (Throwable $e) {
    error_log('schedule/search API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
