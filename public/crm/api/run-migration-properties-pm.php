<?php
/**
 * Ensure properties.property_manager_id exists (PM-firm link used by the
 * property-edit UI + the invoice billing-entity bridge). Admin-only, idempotent.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db = getDB();
$results = [];

function tryExecPM(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $already = strpos($msg, 'Duplicate') !== false
                || strpos($msg, 'already exists') !== false
                || strpos($msg, 'check that column/key exists') !== false;
        $results[] = ['step' => $label, 'status' => $already ? 'already_exists' : 'error', 'msg' => $msg];
    }
}

$exists = (bool)$db->query("SHOW COLUMNS FROM properties LIKE 'property_manager_id'")->fetch();
$results[] = ['step' => 'pre-check property_manager_id', 'status' => $exists ? 'present' : 'absent'];

if (!$exists) {
    tryExecPM($db, "ALTER TABLE properties ADD COLUMN property_manager_id INT NULL DEFAULT NULL AFTER site_contact_id", 'add property_manager_id', $results);
    tryExecPM($db, "ALTER TABLE properties ADD CONSTRAINT fk_properties_pm FOREIGN KEY (property_manager_id) REFERENCES companies(id) ON DELETE SET NULL", 'add FK to companies', $results);
    tryExecPM($db, "ALTER TABLE properties ADD INDEX idx_properties_pm (property_manager_id)", 'add index', $results);
}

$ok = !in_array('error', array_column($results, 'status'));
echo json_encode(['ok' => $ok, 'results' => $results], JSON_PRETTY_PRINT);
