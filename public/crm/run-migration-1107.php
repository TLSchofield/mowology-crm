<?php
/**
 * Migration 1107 — Split e-Transfer payments across multiple invoices
 *
 * Adds:
 *   etransfer_notifications.allocated_amount      — running total assigned so far
 *   invoice_payment_allocations.etransfer_notification_id — links a split back to its e-Transfer
 *
 * Idempotent: each ALTER is wrapped so an "already exists"/"Duplicate column" error
 * is reported, not fatal. Safe to re-run.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

function tryExec1107(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['label' => $label, 'status' => 'OK'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate column') || str_contains($msg, 'Duplicate key name')) {
            $results[] = ['label' => $label, 'status' => 'Already applied'];
        } else {
            $results[] = ['label' => $label, 'status' => 'Note: ' . $msg];
        }
    }
}

tryExec1107($db,
    "ALTER TABLE etransfer_notifications ADD COLUMN allocated_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER amount",
    'Add etransfer_notifications.allocated_amount', $results);

tryExec1107($db,
    "ALTER TABLE invoice_payment_allocations ADD COLUMN etransfer_notification_id BIGINT UNSIGNED NULL AFTER transaction_id",
    'Add invoice_payment_allocations.etransfer_notification_id', $results);

tryExec1107($db,
    "CREATE INDEX idx_ipa_etransfer ON invoice_payment_allocations (etransfer_notification_id)",
    'Index idx_ipa_etransfer', $results);

tryExec1107($db,
    "ALTER TABLE invoice_payment_allocations
        ADD CONSTRAINT fk_ipa_etransfer
        FOREIGN KEY (etransfer_notification_id) REFERENCES etransfer_notifications(id) ON DELETE SET NULL",
    'Add fk_ipa_etransfer foreign key', $results);
?>
<!DOCTYPE html><html><head><title>Migration 1107</title></head><body>
<h2>Migration 1107 — Split e-Transfer payments across multiple invoices</h2>
<table border="1" cellpadding="8" style="border-collapse:collapse">
<tr><th>Step</th><th>Status</th></tr>
<?php foreach ($results as $r): ?>
<tr><td><?= htmlspecialchars($r['label']) ?></td><td><?= htmlspecialchars($r['status']) ?></td></tr>
<?php endforeach; ?>
</table>
<p><a href="/crm/invoices/index.php">&larr; Invoices</a></p>
</body></html>
