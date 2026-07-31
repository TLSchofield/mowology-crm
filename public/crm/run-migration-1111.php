<?php
/**
 * Migration 1111 — expense_line_items discount/adjustment columns.
 *
 * Fixes the receipt-scan bug where a discount/markdown line on a receipt
 * (e.g. "Discount: Landscapers (25%) -$14.99") replaced the actual purchased
 * item in the parsed line items instead of netting against it. See
 * database/migrations/1111_expense_line_items_discount_columns.sql.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

function colExists1111(PDO $db, string $t, string $c): bool {
    $s = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $s->execute([$t, $c]); return (bool)$s->fetchColumn();
}

$cols = [
    'original_unit_price' => "ALTER TABLE expense_line_items ADD COLUMN original_unit_price DECIMAL(10,2) NULL COMMENT 'Pre-discount unit/list price, when a discount was netted into this line' AFTER unit_price",
    'is_adjustment'       => "ALTER TABLE expense_line_items ADD COLUMN is_adjustment TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Standalone discount/deposit/coupon row that is not a purchasable product' AFTER original_unit_price",
];
foreach ($cols as $name => $sql) {
    try {
        if (colExists1111($db, 'expense_line_items', $name)) { $results[] = ["expense_line_items.{$name}", 'skip (exists)']; continue; }
        $db->exec($sql);
        $results[] = ["expense_line_items.{$name}", 'OK'];
    } catch (PDOException $e) {
        $results[] = ["expense_line_items.{$name}", 'ERROR: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html><html><head><title>Migration 1111</title></head><body>
<h2>Migration 1111 — expense line item discount/adjustment columns</h2>
<table border="1" cellpadding="8" style="border-collapse:collapse">
<tr><th>Step</th><th>Status</th></tr>
<?php foreach ($results as $r): ?><tr><td><?= htmlspecialchars($r[0]) ?></td><td><?= htmlspecialchars($r[1]) ?></td></tr><?php endforeach; ?>
</table>
<p><a href="/crm/expenses_appstack.php">&larr; Expenses</a></p>
</body></html>
