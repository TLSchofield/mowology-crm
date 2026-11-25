<?php
/**
 * API: /crm/api/check-address.php
 *
 * Checks if a property address already exists in the database.
 * Returns the existing property info if found.
 *
 * GET /crm/api/check-address.php?address=123+Main+Street
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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';

requireLogin();
session_write_close();

$address = trim($_GET['address'] ?? '');
if (empty($address)) {
    echo json_encode(['exists' => false]);
    exit;
}

$db = getDB();

// Check for exact match or close match (case-insensitive)
$stmt = $db->prepare("
    SELECT p.id as property_id, p.address, p.city, p.postal_code,
           c.first_name, c.last_name
    FROM properties p
    LEFT JOIN contacts c ON p.site_contact_id = c.id
    WHERE LOWER(p.address) = LOWER(?)
    LIMIT 1
");
$stmt->execute([$address]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    $contactName = trim(($existing['first_name'] ?? '') . ' ' . ($existing['last_name'] ?? ''));
    echo json_encode([
        'exists' => true,
        'property_id' => (int)$existing['property_id'],
        'address' => $existing['address'],
        'city' => $existing['city'],
        'contact_name' => $contactName ?: null,
    ]);
} else {
    echo json_encode(['exists' => false]);
}
