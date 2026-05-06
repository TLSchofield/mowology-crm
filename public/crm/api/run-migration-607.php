<?php
/**
 * Run Migration 607: Add expenses permissions to RBAC
 *
 * The RBAC migration (400) seeded roles/permissions but omitted the expenses.*
 * permission family entirely — they only existed in the legacy _legacyPermissions()
 * fallback in authz.php. Users with RBAC roles assigned (non-empty $perms) never
 * hit that fallback, so no crew member could see or use the expenses/receipt feature.
 *
 * This migration:
 *  1. Adds expenses.view, expenses.edit, expenses.send, expenses.approve to permissions
 *  2. Grants expenses.view + expenses.edit to the 'manager' and 'staff' RBAC roles
 *  3. Grants expenses.view to the 'viewer' RBAC role
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
session_write_close();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

$steps = [

    // 1. Insert the four expenses permissions (INSERT IGNORE = safe to re-run)
    'Add expenses.view permission' =>
        "INSERT IGNORE INTO `permissions` (`key`, `description`)
         VALUES ('expenses.view', 'View expenses list and individual expense records')",

    'Add expenses.edit permission' =>
        "INSERT IGNORE INTO `permissions` (`key`, `description`)
         VALUES ('expenses.edit', 'Create, edit, and attach receipts to expenses')",

    'Add expenses.send permission' =>
        "INSERT IGNORE INTO `permissions` (`key`, `description`)
         VALUES ('expenses.send', 'Forward expenses to accounting / mark as sent')",

    'Add expenses.approve permission' =>
        "INSERT IGNORE INTO `permissions` (`key`, `description`)
         VALUES ('expenses.approve', 'Approve or reject submitted expenses')",

    // 2. Grant expenses.view + expenses.edit to admin (catch-all via cross join)
    'Admin gets all expenses permissions' =>
        "INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
         SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
         WHERE r.name = 'admin'
           AND p.`key` IN ('expenses.view','expenses.edit','expenses.send','expenses.approve')",

    // 3. Grant expenses.view + expenses.edit to manager
    'Manager gets expenses.view + expenses.edit' =>
        "INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
         SELECT r.id, p.id FROM roles r JOIN permissions p
             ON p.`key` IN ('expenses.view','expenses.edit','expenses.send')
         WHERE r.name = 'manager'",

    // 4. Grant expenses.view + expenses.edit to staff (crew members)
    'Staff gets expenses.view + expenses.edit' =>
        "INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
         SELECT r.id, p.id FROM roles r JOIN permissions p
             ON p.`key` IN ('expenses.view','expenses.edit')
         WHERE r.name = 'staff'",

    // 5. Viewer gets read-only
    'Viewer gets expenses.view' =>
        "INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
         SELECT r.id, p.id FROM roles r JOIN permissions p
             ON p.`key` = 'expenses.view'
         WHERE r.name = 'viewer'",

];

foreach ($steps as $label => $sql) {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok', 'msg' => ''];
    } catch (PDOException $e) {
        $results[] = ['step' => $label, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}
?><!DOCTYPE html><html><head><title>Migration 607</title>
<style>body{font-family:monospace;padding:20px;background:#f8f9fa;}
.ok{color:#2D8659;} .skip{color:#888;} .error{color:#c0392b;}
h2{font-family:sans-serif;}</style></head><body>
<h2>Migration 607 — Expenses Permissions for RBAC</h2>
<?php foreach ($results as $r): ?>
  <p class="<?php echo $r['status']; ?>">
    <?php echo $r['status'] === 'ok' ? '✓' : '✗'; ?>
    <?php echo htmlspecialchars($r['step']); ?>
    <?php if ($r['msg']): ?><span style="color:#888;font-size:.9em"> (<?php echo htmlspecialchars($r['msg']); ?>)</span><?php endif; ?>
  </p>
<?php endforeach; ?>
<p><strong>Done. PERM_CACHE_VERSION has been bumped in authz.php — all user sessions will pick up new permissions automatically.</strong></p>
</body></html>
