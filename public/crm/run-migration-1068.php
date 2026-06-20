<?php
/**
 * Migration 1068 — record statement reconciliation on bank import sessions.
 *
 * Lets commit() persist whether an imported statement's transactions reconciled
 * to its opening/closing balance, so unreconciled imports are flagged for review
 * instead of slipping in silently.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

function colExists(PDO $db, string $t, string $c): bool {
    $s = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $s->execute([$t, $c]); return (bool)$s->fetchColumn();
}

$cols = [
    'balance_opening'     => "ALTER TABLE bank_import_sessions ADD COLUMN balance_opening DECIMAL(12,2) NULL",
    'balance_closing'     => "ALTER TABLE bank_import_sessions ADD COLUMN balance_closing DECIMAL(12,2) NULL",
    'balance_discrepancy' => "ALTER TABLE bank_import_sessions ADD COLUMN balance_discrepancy DECIMAL(12,2) NULL",
    'reconciled'          => "ALTER TABLE bank_import_sessions ADD COLUMN reconciled TINYINT(1) NULL",
];
foreach ($cols as $name => $sql) {
    try {
        if (colExists($db, 'bank_import_sessions', $name)) { $results[] = [$name, 'skip (exists)']; continue; }
        $db->exec($sql); $results[] = [$name, 'OK'];
    } catch (PDOException $e) { $results[] = [$name, 'Note: ' . $e->getMessage()]; }
}
?>
<!DOCTYPE html><html><head><title>Migration 1068</title></head><body>
<h2>Migration 1068 — bank import reconciliation columns</h2>
<table border="1" cellpadding="8" style="border-collapse:collapse">
<tr><th>Column</th><th>Status</th></tr>
<?php foreach ($results as $r): ?><tr><td><?= htmlspecialchars($r[0]) ?></td><td><?= htmlspecialchars($r[1]) ?></td></tr><?php endforeach; ?>
</table>
<p><a href="/crm/accounting/">← Accounting</a></p>
</body></html>
