<?php
/**
 * Run Migration 550: HR Employee Fields
 * MySQL 8.0 compatible — adds columns one at a time, ignores "already exists" errors
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

// Add a single column, skip if it already exists (error 1060)
function addCol(PDO $db, string $label, string $sql): array {
    try {
        $db->exec($sql);
        return ['step' => $label, 'status' => 'ok', 'msg' => 'added'];
    } catch (PDOException $e) {
        $code = (int)$e->getCode();
        $msg  = $e->getMessage();
        // 1060 = Duplicate column name (already exists)
        if (str_contains($msg, 'Duplicate column') || str_contains($msg, '1060')) {
            return ['step' => $label, 'status' => 'skip', 'msg' => 'already exists'];
        }
        return ['step' => $label, 'status' => 'error', 'msg' => $msg];
    }
}

$results[] = addCol($db, 'address',               "ALTER TABLE `users` ADD COLUMN `address`               VARCHAR(255) NULL AFTER `notes`");
$results[] = addCol($db, 'city',                  "ALTER TABLE `users` ADD COLUMN `city`                  VARCHAR(100) NULL AFTER `address`");
$results[] = addCol($db, 'province',              "ALTER TABLE `users` ADD COLUMN `province`              VARCHAR(50)  NULL AFTER `city`");
$results[] = addCol($db, 'postal_code',           "ALTER TABLE `users` ADD COLUMN `postal_code`           VARCHAR(10)  NULL AFTER `province`");
$results[] = addCol($db, 'date_of_birth',         "ALTER TABLE `users` ADD COLUMN `date_of_birth`         DATE         NULL AFTER `postal_code`");
$results[] = addCol($db, 'sin_encrypted',         "ALTER TABLE `users` ADD COLUMN `sin_encrypted`         TEXT         NULL AFTER `date_of_birth`");
$results[] = addCol($db, 'bank_transit',          "ALTER TABLE `users` ADD COLUMN `bank_transit`          VARCHAR(5)   NULL AFTER `sin_encrypted`");
$results[] = addCol($db, 'bank_institution',      "ALTER TABLE `users` ADD COLUMN `bank_institution`      VARCHAR(3)   NULL AFTER `bank_transit`");
$results[] = addCol($db, 'bank_account_encrypted',"ALTER TABLE `users` ADD COLUMN `bank_account_encrypted` TEXT        NULL AFTER `bank_institution`");
$results[] = addCol($db, 'td1_federal',           "ALTER TABLE `users` ADD COLUMN `td1_federal`           DECIMAL(10,2) NULL AFTER `bank_account_encrypted`");
$results[] = addCol($db, 'td1_provincial',        "ALTER TABLE `users` ADD COLUMN `td1_provincial`        DECIMAL(10,2) NULL AFTER `td1_federal`");
$results[] = addCol($db, 'td1_notes',             "ALTER TABLE `users` ADD COLUMN `td1_notes`             TEXT         NULL AFTER `td1_provincial`");
$results[] = addCol($db, 'install_link_sent_at',  "ALTER TABLE `users` ADD COLUMN `install_link_sent_at`  DATETIME     NULL AFTER `td1_notes`");
$results[] = addCol($db, 'install_link_sent_by',  "ALTER TABLE `users` ADD COLUMN `install_link_sent_by`  INT          NULL AFTER `install_link_sent_at`");

// Permissions (INSERT IGNORE is safe)
foreach ([
    'HR permissions'          => "INSERT IGNORE INTO `permissions` (`key`, `description`) VALUES ('hr.view','View employee HR profile'),('hr.edit','Edit sensitive HR fields')",
    'Admin HR permissions'    => "INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name='admin' AND p.`key` IN ('hr.view','hr.edit')",
    'Manager HR view'         => "INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.`key`='hr.view' WHERE r.name='manager'",
] as $label => $sql) {
    try { $db->exec($sql); $results[] = ['step' => $label, 'status' => 'ok', 'msg' => '']; }
    catch (PDOException $e) { $results[] = ['step' => $label, 'status' => 'error', 'msg' => $e->getMessage()]; }
}
?><!DOCTYPE html><html><head><title>Migration 550</title>
<style>body{font-family:monospace;padding:20px;background:#f8f9fa;}
.ok{color:#2D8659;} .skip{color:#888;} .error{color:#c0392b;}
h2{font-family:sans-serif;}</style></head><body>
<h2>Migration 550 — HR Employee Fields</h2>
<?php foreach ($results as $r): ?>
  <p class="<?php echo $r['status']; ?>">
    <?php echo $r['status']==='ok'?'✓':($r['status']==='skip'?'—':'✗'); ?>
    <?php echo htmlspecialchars($r['step']); ?>
    <?php if ($r['msg']): ?><span style="color:#888;font-size:.9em"> (<?php echo htmlspecialchars($r['msg']); ?>)</span><?php endif; ?>
  </p>
<?php endforeach; ?>
<p><strong>Done.</strong></p>
</body></html>
