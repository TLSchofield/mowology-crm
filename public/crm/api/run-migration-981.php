<?php
/**
 * Migration Runner — 981_accounting_phase2
 * Creates bank import tables, accounting alerts, adds bank_account column.
 */
declare(strict_types=1);

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
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); echo '<h1>403 — Admin only</h1>'; exit; }

$db  = getDB();
$sql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/981_accounting_phase2.sql');
if (!$sql) { echo '<p style="color:red">Cannot read migration file.</p>'; exit; }

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Migration 981</title>';
echo '<style>body{font-family:monospace;padding:2rem;background:#0d1117;color:#c9d1d9;}h1{color:#2D8659;}.ok{color:#3fb950;}.err{color:#f85149;}.note{color:#8b949e;}</style></head><body>';
echo '<h1>Migration 981 — Accounting Phase 2</h1><hr>';

$statements  = array_filter(array_map('trim', preg_split('/;(\r?\n|$)/m', $sql)), fn($s) => strlen($s) > 5 && !preg_match('/^--/', $s));
$ok = $err = 0;

foreach ($statements as $stmt) {
    try {
        $db->exec($stmt);
        $ok++;
        echo '<p class="ok">✓ ' . htmlspecialchars(strtok($stmt, "\n")) . '</p>';
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate') || str_contains($msg, 'SQLSTATE[42S21]')) {
            echo '<p class="note">⟳ ' . htmlspecialchars(strtok($stmt, "\n")) . ' (already applied)</p>';
        } else {
            $err++;
            echo '<p class="err">✗ ' . htmlspecialchars(strtok($stmt, "\n")) . '<br>' . htmlspecialchars($msg) . '</p>';
        }
    }
}

echo '<hr><p class="' . ($err > 0 ? 'err' : 'ok') . '">Done — ' . $ok . ' statements. ' . $err . ' errors.</p>';
if (!$err) echo '<p class="ok">✓ Bank import, alert engine, crew profitability — all ready.<br><a href="/crm/accounting/bank-import.php" style="color:#2D8659">→ Go to Bank Import</a></p>';
echo '</body></html>';
