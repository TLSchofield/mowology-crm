<?php
/**
 * Run Migration 1110: e-Transfer ↔ bank deposit reconciliation link
 *
 * Adds etransfer_notifications.bank_transaction_id / bank_match_confidence,
 * then backfills them for any currently-pending notifications by running
 * EtransferInboxService::matchBankTransaction() against already-imported
 * bank deposits. Idempotent — safe to re-run. Admin only.
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
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Accounting/Services/EtransferInboxService.php';

requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

function tryExec(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate column') || str_contains($msg, 'Duplicate key name')) {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $msg];
        }
    }
}

tryExec($db, "ALTER TABLE etransfer_notifications ADD COLUMN bank_transaction_id INT NULL AFTER matched_invoice_id", 'add bank_transaction_id', $results);
tryExec($db, "ALTER TABLE etransfer_notifications ADD COLUMN bank_match_confidence INT NULL AFTER bank_transaction_id", 'add bank_match_confidence', $results);
tryExec($db, "ALTER TABLE etransfer_notifications ADD INDEX idx_bank_transaction_id (bank_transaction_id)", 'add index', $results);

// ── Backfill: try to link already-pending notifications to a bank deposit ──
$service  = new EtransferInboxService($db);
$backfill = ['checked' => 0, 'linked' => 0];

try {
    $rows = $db->query("
        SELECT * FROM etransfer_notifications
         WHERE status IN ('pending', 'partially_recorded')
           AND bank_transaction_id IS NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

    $upd = $db->prepare("
        UPDATE etransfer_notifications
           SET bank_transaction_id = ?, bank_match_confidence = ?
         WHERE id = ?
    ");

    foreach ($rows as $note) {
        $backfill['checked']++;
        $match = $service->matchBankTransaction($note);
        if ($match) {
            $upd->execute([$match['tx_id'], $match['confidence'], $note['id']]);
            $backfill['linked']++;
        }
    }
} catch (Throwable $e) {
    $results[] = ['step' => 'backfill', 'status' => 'error', 'msg' => $e->getMessage()];
}

echo json_encode([
    'success'  => true,
    'migration' => '1110',
    'steps'    => $results,
    'backfill' => $backfill,
]);
