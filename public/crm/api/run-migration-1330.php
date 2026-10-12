<?php
/**
 * Run migration 1330 (vendor knowledge commons) from any logged-in admin browser — phone included.
 *
 *   https://mowology.ca/crm/api/run-migration-1330.php          → applies, reports per statement
 *   https://mowology.ca/crm/api/run-migration-1330.php?check=1  → reports what exists, changes nothing
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS / INSERT IGNORE pass; ALTER ADD COLUMN steps are skipped
 * when the column already exists. Executes database/migrations/1330_vendor_knowledge_commons.sql.
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
session_write_close();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db   = getDB();
$file = APP_ROOT . '/../database/migrations/1330_vendor_knowledge_commons.sql';
if (!is_file($file)) { http_response_code(404); echo json_encode(['error' => 'migration file not found', 'path' => $file]); exit; }

$hasTable  = fn(string $t) => $db->query("SHOW TABLES LIKE " . $db->quote($t))->rowCount() > 0;
$hasColumn = function (string $t, string $c) use ($db) {
    $s = $db->prepare("SHOW COLUMNS FROM `{$t}` LIKE ?"); $s->execute([$c]); return $s->rowCount() > 0;
};

$state = fn() => [
    'kb_vendors'                   => $hasTable('kb_vendors'),
    'kb_vendor_facts'              => $hasTable('kb_vendor_facts'),
    'kb_tenants'                   => $hasTable('kb_tenants'),
    'kb_category_map'              => $hasTable('kb_category_map'),
    'kb_cache'                     => $hasTable('kb_cache'),
    'vendors.kb_vendor_id'         => $hasColumn('vendors', 'kb_vendor_id'),
    'expense_suggestions.made_by'  => $hasColumn('expense_suggestions', 'made_by'),
];

if (isset($_GET['check'])) { echo json_encode(['mode' => 'check', 'state' => $state()], JSON_PRETTY_PRINT); exit; }

$sql = file_get_contents($file);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);                 // strip comment lines
$statements = array_filter(array_map('trim', explode(';', $sql)));
$log = [];
foreach ($statements as $stmt) {
    $head = substr(preg_replace('/\s+/', ' ', $stmt), 0, 70);
    // Skip ALTERs whose first column already exists (MySQL 5.7 has no ADD COLUMN IF NOT EXISTS).
    if (preg_match('/^ALTER TABLE\s+(\w+)\s+ADD COLUMN\s+(\w+)/i', $stmt, $m) && $hasColumn($m[1], $m[2])) {
        $log[] = ['skip' => $head, 'why' => "{$m[1]}.{$m[2]} exists"]; continue;
    }
    try {
        $db->exec($stmt);
        $log[] = ['ok' => $head];
    } catch (PDOException $e) {
        $log[] = ['error' => $head, 'message' => $e->getMessage()];
    }
}
echo json_encode(['mode' => 'apply', 'statements' => count($statements), 'log' => $log, 'state' => $state(),
                  'next' => ['https://mowology.ca/crm/api/kb-backfill.php', 'https://mowology.ca/crm/api/opcache-reset.php']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
