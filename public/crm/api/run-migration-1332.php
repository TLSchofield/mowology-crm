<?php
/**
 * Run migration 1332 (every active user gets the RBAC role matching users.role — Fable audit #3).
 *
 *   https://mowology.ca/crm/api/run-migration-1332.php?check=1  → lists who would get which role, changes nothing
 *   https://mowology.ca/crm/api/run-migration-1332.php          → confirm button; the run is POST + CSRF
 *
 * Idempotent: only users with no user_roles rows are touched. Executes
 * database/migrations/1332_rbac_role_for_every_user.sql.
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
require_once APP_ROOT . '/Core/MigrationRunnerGuard.php';
migrationRunnerGuard(); // admin only; GET shows a confirm button, the run needs POST + CSRF (2026-10-12)
session_write_close();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db   = getDB();
$file = APP_ROOT . '/../database/migrations/1332_rbac_role_for_every_user.sql';
if (!is_file($file)) { http_response_code(404); echo json_encode(['error' => 'migration file not found', 'path' => $file]); exit; }

$pending = fn() => $db->query("
    SELECT u.id, u.role, CASE u.role WHEN 'admin' THEN 'admin' WHEN 'manager' THEN 'manager' ELSE 'staff' END AS gets
    FROM users u
    WHERE (u.is_active = 1 OR u.is_active IS NULL)
      AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id)
    ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['check'])) { echo json_encode(['mode' => 'check', 'would_assign' => $pending()], JSON_PRETTY_PRINT); exit; }

$before = $pending();
$sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
$statements = array_filter(array_map('trim', explode(';', $sql)));
$log = [];
foreach ($statements as $stmt) {
    $head = substr(preg_replace('/\s+/', ' ', $stmt), 0, 70);
    try {
        $n = $db->exec($stmt);
        $log[] = ['ok' => $head, 'rows' => $n];
    } catch (PDOException $e) {
        $log[] = ['error' => $head, 'message' => $e->getMessage()];
    }
}
$u = getCurrentUser();
logActivity((int)($u['id'] ?? 0), null, 'Ran migration 1332 (RBAC role for every user)', json_encode($before));
echo json_encode(['mode' => 'apply', 'assigned' => $before, 'log' => $log, 'still_without_role' => $pending(),
                  'next' => ['https://mowology.ca/crm/api/opcache-reset.php']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
