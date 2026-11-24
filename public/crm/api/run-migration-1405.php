<?php
/**
 * Run migration 1405 (Mia page drafts: adds mia_content_drafts.body).
 *
 *   https://mowology.ca/crm/api/run-migration-1405.php?check=1  → reports what exists, changes nothing
 *   https://mowology.ca/crm/api/run-migration-1405.php          → confirm button; the run is POST + CSRF
 *
 * Adds one nullable column. Running it twice is harmless (the second run reports 'Duplicate column name'). Nothing reads
 * the column until page generation is used. Executes database/migrations/1405_mia_draft_body.sql.
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
migrationRunnerGuard(); // admin only; GET shows a confirm button, the run needs POST + CSRF
session_write_close();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db   = getDB();
$file = APP_ROOT . '/../database/migrations/1405_mia_draft_body.sql';
if (!is_file($file)) { http_response_code(404); echo json_encode(['error' => 'migration file not found']); exit; }

$tables = ['mia_content_drafts'];
$state  = function () use ($db, $tables) {
    $s = [];
    foreach ($tables as $t) { $s[$t . '.body'] = $db->query("SHOW COLUMNS FROM {$t} LIKE 'body'")->rowCount() > 0; }
    return $s;
};

if (isset($_GET['check'])) { echo json_encode(['mode' => 'check', 'state' => $state()], JSON_PRETTY_PRINT); exit; }

$sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
$statements = array_filter(array_map('trim', explode(';', $sql)));
$log = [];
foreach ($statements as $stmt) {
    $head = substr(preg_replace('/\s+/', ' ', $stmt), 0, 70);
    try {
        $db->exec($stmt);
        $log[] = ['ok' => $head];
    } catch (PDOException $e) {
        $log[] = ['error' => $head, 'message' => $e->getMessage()];
    }
}
$u = getCurrentUser();
logActivity((int)($u['id'] ?? 0), null, 'Ran migration 1405 (Mia draft body)', json_encode($log));
echo json_encode(['mode' => 'apply', 'log' => $log, 'state' => $state(),
                  'next' => ['https://mowology.ca/crm/api/opcache-reset.php']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
