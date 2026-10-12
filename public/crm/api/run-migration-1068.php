<?php
/**
 * Migration 1068 — jobs.create_field permission.
 *
 * Idempotent + admin-only. Seeds the RBAC permission used by the field
 * "Add job" flow and grants it to admin/manager/staff roles. The legacy
 * users.role fallback already lives in app/Core/Auth/authz.php (which also
 * bumped PERM_CACHE_VERSION so sessions pick it up). Safe to run repeatedly.
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

$db   = getDB();
$done = [];

try {
    // RBAC tables may not exist on minimal installs — degrade gracefully.
    $hasRbac = $db->query("SHOW TABLES LIKE 'permissions'")->rowCount() > 0;
    if (!$hasRbac) {
        echo json_encode(['success' => true, 'note' => 'RBAC tables absent — legacy role map covers crew.', 'done' => []]);
        exit;
    }

    $db->prepare(
        "INSERT IGNORE INTO `permissions` (`key`, `description`)
         VALUES ('jobs.create_field', 'Create a job or add a visit from the field (crew mobile app)')"
    )->execute();
    $done['permission'] = 'ensured';

    $granted = $db->prepare(
        "INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
         SELECT r.id, p.id
         FROM roles r
         JOIN permissions p ON p.`key` = 'jobs.create_field'
         WHERE r.name IN ('admin', 'manager', 'staff')"
    );
    $granted->execute();
    $done['role_grants_added'] = $granted->rowCount();

    echo json_encode(['success' => true, 'done' => $done]);

} catch (Throwable $e) {
    error_log('run-migration-1068 error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
