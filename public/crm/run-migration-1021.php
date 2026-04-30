<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

$steps = [
    "ALTER TABLE chart_of_accounts ADD COLUMN account_number VARCHAR(50) NULL DEFAULT NULL COMMENT 'Bank account number for auto-detection during statement import' AFTER expense_category_alias",
    "ALTER TABLE chart_of_accounts ADD INDEX idx_account_number (account_number)",
];

foreach ($steps as $sql) {
    try {
        $db->exec($sql);
        $results[] = ['sql' => substr($sql, 0, 80), 'status' => 'OK'];
    } catch (PDOException $e) {
        $results[] = ['sql' => substr($sql, 0, 80), 'status' => 'Note: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html><html><head><title>Migration 1021</title></head><body>
<h2>Migration 1021 — bank account number</h2>
<table border="1" cellpadding="8">
<tr><th>SQL</th><th>Status</th></tr>
<?php foreach ($results as $r): ?>
<tr><td><?= htmlspecialchars($r['sql']) ?>…</td><td><?= htmlspecialchars($r['status']) ?></td></tr>
<?php endforeach; ?>
</table>
<p><a href="/crm/accounting/chart-of-accounts.php">← Chart of Accounts</a></p>
</body></html>
