<?php
/**
 * Migration Runner — 980_accounting_system
 * Run once to create the accounting system tables + seed CoA and rules.
 *
 * Access: /crm/api/run-migration-980.php
 * Requires: admin login
 */
declare(strict_types=1);

// Bootstrap
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<h1>403 — Admin only</h1>';
    exit;
}

$db = getDB();

$sql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/980_accounting_system.sql');
if (!$sql) {
    echo '<p style="color:red">Cannot read migration file.</p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Migration 980 — Accounting System</title>
<style>
  body { font-family: monospace; padding: 2rem; background: #0d1117; color: #c9d1d9; }
  h1   { color: #2D8659; }
  .ok  { color: #3fb950; }
  .err { color: #f85149; }
  .note{ color: #8b949e; }
  pre  { background: #161b22; padding: 1rem; border-radius: 6px; overflow-x: auto; }
</style>
</head>
<body>
<h1>Migration 980 — Mowology Accounting System</h1>
<p class="note">Running as: <?= htmlspecialchars($user['email'] ?? '') ?></p>
<hr>
<?php

// Split on statement delimiter and execute each
$statements = array_filter(
    array_map('trim', preg_split('/;(\r?\n|$)/m', $sql)),
    fn($s) => strlen($s) > 5 && !preg_match('/^--/', $s)
);

$successCount = 0;
$errorCount   = 0;
$log          = [];

foreach ($statements as $stmt) {
    try {
        $db->exec($stmt);
        $successCount++;
        // Show first line as summary
        $firstLine = strtok($stmt, "\n");
        $log[] = ['ok' => true, 'stmt' => $firstLine];
    } catch (PDOException $e) {
        // Silently skip "already exists" and "Duplicate entry" — migration is idempotent
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') ||
            str_contains($msg, 'Duplicate entry') ||
            str_contains($msg, 'SQLSTATE[42S01]')) {
            $log[] = ['ok' => true, 'stmt' => strtok($stmt, "\n"), 'skipped' => true];
            continue;
        }
        $errorCount++;
        $log[] = ['ok' => false, 'stmt' => strtok($stmt, "\n"), 'error' => $msg];
    }
}

foreach ($log as $entry) {
    $icon   = $entry['ok'] ? '✓' : '✗';
    $class  = $entry['ok'] ? 'ok' : 'err';
    $skip   = !empty($entry['skipped']) ? ' <span class="note">(already exists)</span>' : '';
    $err    = !$entry['ok'] ? '<br><span class="err">' . htmlspecialchars($entry['error']) . '</span>' : '';
    echo "<p class=\"$class\">$icon " . htmlspecialchars($entry['stmt']) . "$skip$err</p>\n";
}

?>
<hr>
<p class="<?= $errorCount > 0 ? 'err' : 'ok' ?>">
    Done — <?= $successCount ?> statements succeeded, <?= $errorCount ?> failed.
</p>
<?php if ($errorCount === 0): ?>
<p class="ok">✓ Chart of Accounts, Transaction Ledger, Rules Engine, Tax Rates — all ready.</p>
<p class="note">Next step: go to <a href="/crm/accounting_appstack.php" style="color:#2D8659">Accounting Dashboard</a>
   and click <strong>Sync Ledger</strong> to import your existing invoices and expenses.</p>
<?php endif; ?>
</body>
</html>
