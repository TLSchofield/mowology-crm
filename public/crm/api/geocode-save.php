<?php
/**
 * Save Property Geocode Coordinates
 *
 * POST { property_id, lat, lng, csrf_token }
 *   Saves latitude/longitude for a property after client-side geocoding
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$jsonData = json_decode(file_get_contents('php://input'), true);
if (!$jsonData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$propertyId = intval($jsonData['property_id'] ?? 0);
$lat = floatval($jsonData['lat'] ?? 0);
$lng = floatval($jsonData['lng'] ?? 0);

if (!$propertyId || !$lat || !$lng) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid property or coordinates']);
    exit;
}

try {
    $db = getDB();
    $db->prepare("UPDATE properties SET latitude = ?, longitude = ?, geocoded_at = NOW() WHERE id = ?")
       ->execute([$lat, $lng, $propertyId]);
    echo json_encode(['success' => true, 'lat' => $lat, 'lng' => $lng]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save coordinates']);
}
