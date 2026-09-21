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

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $jwtUser = requireJwt();
    if (!jwtUserHasPermission($jwtUser, 'clients.view')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Your account can't look up clients. Ask the office."]);
        exit;
    }

    $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $today   = date('Y-m-d');
    $service = new FieldSearchService(getDB());

    switch ((string)($_GET['mode'] ?? 'search')) {
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
