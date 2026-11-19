<?php
/**
 * Run migration 1390 (supplier lists read automatically for any supplier) from any logged-in admin browser — phone included.
 *
 *   https://mowology.ca/crm/api/run-migration-1390.php          → applies, reports per statement
 *   https://mowology.ca/crm/api/run-migration-1390.php?check=1  → reports what exists, changes nothing
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS / INSERT IGNORE pass (checked twice on a local MySQL 5.7
 * with COMPACT rows and strict mode); ALTER ADD COLUMN steps, if ever added, are skipped when the column exists. Executes database/migrations/1390_supplier_list_auto_formats.sql.
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
$file = APP_ROOT . '/../database/migrations/1390_supplier_list_auto_formats.sql';
if (!is_file($file)) { http_response_code(404); echo json_encode(['error' => 'migration file not found', 'path' => $file]); exit; }

$hasTable  = fn(string $t) => $db->query("SHOW TABLES LIKE " . $db->quote($t))->rowCount() > 0;
$hasColumn = function (string $t, string $c) use ($db) {
    // SHOW COLUMNS ... LIKE ? cannot be prepared with native prepares; names here are our own constants.
    try {
        foreach ($db->query("SHOW COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC) as $r) if (strcasecmp((string)$r['Field'], $c) === 0) return true;
    } catch (Throwable $e) { /* table missing */ }
    return false;
};

$state = fn() => [
    'formats.needs_review'    => $hasColumn('supplier_list_formats', 'needs_review'),
    'formats.auto_inferred'   => $hasColumn('supplier_list_formats', 'auto_inferred'),
    'formats.inferred_confidence' => $hasColumn('supplier_list_formats', 'inferred_confidence'),
    'lists.needs_review'      => $hasColumn('supplier_price_lists', 'needs_review'),
    'supplier_list_skips'     => $hasTable('supplier_list_skips'),
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
                  'next' => ['https://mowology.ca/crm/api/opcache-reset.php', 'https://mowology.ca/crm/suppliers/price-lists.php']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
