<?php
/**
 * Run migration 1341 (portal links expire; portal email changes wait for confirmation — security review #14).
 *
 *   https://mowology.ca/crm/api/run-migration-1341.php?check=1  → reports what exists, changes nothing
 *   https://mowology.ca/crm/api/run-migration-1341.php          → confirm button; the run is POST + CSRF
 *
 * Idempotent: ADD COLUMN skipped when it exists; the stamp only fills empty dates. Existing tokens get NOW()
 * so nobody is cut off. Executes
 * database/migrations/1341_portal_token_expiry_email_confirm.sql.
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
$file = APP_ROOT . '/../database/migrations/1341_portal_token_expiry_email_confirm.sql';
if (!is_file($file)) { http_response_code(404); echo json_encode(['error' => 'migration file not found']); exit; }

$hasColumn = function (string $t, string $c) use ($db) {
    $s = $db->prepare("SHOW COLUMNS FROM `{$t}` LIKE ?"); $s->execute([$c]); return $s->rowCount() > 0;
};
$state = fn() => ['contacts.portal_token_issued_at' => $hasColumn('contacts', 'portal_token_issued_at'),
                   'contact_email_changes' => $db->query("SHOW TABLES LIKE 'contact_email_changes'")->rowCount() > 0,
                   'tokens_without_date' => $hasColumn('contacts', 'portal_token_issued_at')
                       ? (int)$db->query("SELECT COUNT(*) FROM contacts WHERE portal_token IS NOT NULL AND portal_token_issued_at IS NULL")->fetchColumn() : null];

if (isset($_GET['check'])) { echo json_encode(['mode' => 'check', 'state' => $state()], JSON_PRETTY_PRINT); exit; }

$sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
$statements = array_filter(array_map('trim', explode(';', $sql)));
$log = [];
foreach ($statements as $stmt) {
    $head = substr(preg_replace('/\s+/', ' ', $stmt), 0, 70);
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
$u = getCurrentUser();
logActivity((int)($u['id'] ?? 0), null, 'Ran migration 1341 (portal token expiry + email confirm)', json_encode($log));
echo json_encode(['mode' => 'apply', 'log' => $log, 'state' => $state(),
                  'next' => ['https://mowology.ca/crm/api/opcache-reset.php']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
