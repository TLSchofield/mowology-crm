<?php
/**
 * Field Search API (session) — the Android / web Search tab.
 *
 * GET ?q=balac[&lat&lng]            → { success, results:[property card…] }
 * GET ?mode=nearby&lat&lng          → { success, results:[…] }        (before anything is typed)
 * GET ?mode=property&id=22[&lat&lng]→ { success, property:{…, plans[].history_html, upcoming[]} }
 *
 * Same FieldSearchService as the iOS endpoint (/api/schedule/search) — same matching, same
 * field ranking (today's stops, then nearest), same crew-safe payload.
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
    require_once CRM_INCLUDES . '/plan-functions.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/ServiceHistoryService.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/FieldSearchService.php';
    require_once dirname(__DIR__) . '/partials/service-history.php';

    requireLogin();
    requirePermission('clients.view');
    session_write_close(); // read-only; search fires as the user types

    $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $today   = date('Y-m-d');
    $service = new FieldSearchService(getDB());

    switch ((string)($_GET['mode'] ?? 'search')) {
        case 'nearby':
            echo json_encode(['success' => true, 'results' => ($lat === null || $lng === null) ? [] : $service->nearby($lat, $lng, $today)]);
            break;

        case 'property':
            $property = $service->property((int)($_GET['id'] ?? 0), $lat, $lng, $today);
            if ($property === null) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'That property is no longer available.']);
                break;
            }
            // The two-week grid is rendered here so the page and the job cards share one renderer.
            foreach ($property['plans'] as &$plan) {
                $plan['history_html'] = mwServiceHistoryGrid($plan['history'] ?? null);
                unset($plan['history']);
            }
            unset($plan);
            echo json_encode(['success' => true, 'property' => $property]);
            break;

        default:
            $q = FieldSearchService::normalize($_GET['q'] ?? '');
            echo json_encode(['success' => true, 'query' => $q, 'results' => $service->search($q, $lat, $lng, $today)]);
    }
} catch (Throwable $e) {
    error_log('field-search.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
