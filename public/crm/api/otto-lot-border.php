<?php
/**
 * "Let Otto draw it" — Otto's property card asks for one property's arrival border from the city
 * lot map (OttoAutoPlaceService::borderOne). POST JSON { property_id, csrf_token }.
 * Same permission as placing a pin (geocode-save.php): a border moves auto-arrival and route proof.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !verifyCSRFToken((string)($in['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Your session expired — reload the page.']);
    exit;
}
if (!userHasAnyPermission(['clients.edit', 'jobs.edit'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Permission denied']);
    exit;
}
$propertyId = (int)($in['property_id'] ?? 0);
if ($propertyId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'No property.']);
    exit;
}
try {
    require_once APP_ROOT . '/Modules/Operations/Services/OttoAutoPlaceService.php';
    echo json_encode((new OttoAutoPlaceService(getDB()))->borderOne($propertyId));
} catch (Throwable $e) {
    error_log('otto-lot-border: ' . get_class($e));
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Otto could not draw it just now — try again.']);
}
