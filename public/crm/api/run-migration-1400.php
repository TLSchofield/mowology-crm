<?php
/**
 * Run migration 1400 (Mia action ledger: mia_actions, mia_action_events, mia_decisions, mia_memory,
 * mia_llm_usage).
 *
 *   https://mowology.ca/crm/api/run-migration-1400.php?check=1  → reports what exists, changes nothing
 *   https://mowology.ca/crm/api/run-migration-1400.php          → confirm button; the run is POST + CSRF
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS only. Nothing reads these tables until the Phase 1 services
 * ship, so running it early changes nothing visible. Executes database/migrations/1400_mia_action_ledger.sql.
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
$file = APP_ROOT . '/../database/migrations/1400_mia_action_ledger.sql';
if (!is_file($file)) { http_response_code(404); echo json_encode(['error' => 'migration file not found']); exit; }

$tables = ['mia_actions', 'mia_action_events', 'mia_decisions', 'mia_memory', 'mia_llm_usage'];
$state  = function () use ($db, $tables) {
    $s = [];
    foreach ($tables as $t) { $s[$t] = $db->query("SHOW TABLES LIKE '{$t}'")->rowCount() > 0; }
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
logActivity((int)($u['id'] ?? 0), null, 'Ran migration 1400 (Mia action ledger)', json_encode($log));
echo json_encode(['mode' => 'apply', 'log' => $log, 'state' => $state(),
                  'next' => ['https://mowology.ca/crm/api/opcache-reset.php']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
