<?php
/**
 * Migration 1012 — Add company_id to contacts + properties
 *
 * Run once via: /crm/api/run-migration-1012.php
 * Protected by CRM login + admin role.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Admin only.');
}

$db  = getDB();
$log = [];

function mig1012_run(PDO $db, string $label, string $sql): string {
    try {
        $db->exec($sql);
        return "OK  $label";
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'already exists') !== false
            || stripos($msg, 'Duplicate column') !== false
            || stripos($msg, 'Duplicate key name') !== false
            || stripos($msg, 'Duplicate foreign key') !== false) {
            return "SKIP  $label — already exists";
        }
        return "ERR  $label — {$msg}";
    }
}

$log[] = mig1012_run($db, 'Add contacts.company_id',
    "ALTER TABLE `contacts`
     ADD COLUMN `company_id` INT NULL DEFAULT NULL
       COMMENT 'FK to companies.id — the company this contact belongs to (for invoicing/billing lookups)'
     AFTER `last_name`"
);
$log[] = mig1012_run($db, 'Add contacts FK to companies',
    "ALTER TABLE `contacts`
     ADD CONSTRAINT `fk_contacts_company`
       FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL"
);
$log[] = mig1012_run($db, 'Add idx_contacts_company',
    "CREATE INDEX `idx_contacts_company` ON `contacts`(`company_id`)"
);

$log[] = mig1012_run($db, 'Add properties.company_id',
    "ALTER TABLE `properties`
     ADD COLUMN `company_id` INT NULL DEFAULT NULL
       COMMENT 'FK to companies.id — the company that pays invoices for work at this property'
     AFTER `site_contact_id`"
);
$log[] = mig1012_run($db, 'Add properties FK to companies',
    "ALTER TABLE `properties`
     ADD CONSTRAINT `fk_properties_company`
       FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL"
);
$log[] = mig1012_run($db, 'Add idx_properties_company',
    "CREATE INDEX `idx_properties_company` ON `properties`(`company_id`)"
);

?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1012</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem}ul{list-style:none;padding:0}li{padding:.35rem 0;border-bottom:1px solid #e0e0e0}.ok{color:#2D8659}.err{color:#c00;font-weight:bold}.skip{color:#999}</style>
</head><body>
<h1>Migration 1012 — contacts + properties .company_id</h1>
<ul>
<?php foreach ($log as $line):
    $cls = str_starts_with($line, 'ERR') ? 'err' : (str_starts_with($line, 'SKIP') ? 'skip' : 'ok');
?>
  <li class="<?= $cls ?>"><?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
</ul>
<p><strong>Next step:</strong> <a href="/crm/api/fix-invoice-link.php?id=15&amp;company_id=4">Fix invoice 15 → link to VML (company 4)</a></p>
<p><a href="/crm/database_appstack.php">← Database Manager</a></p>
</body></html>
