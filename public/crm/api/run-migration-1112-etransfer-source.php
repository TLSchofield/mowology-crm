<?php
/**
 * Run Migration 1112: etransfer_notifications.source
 *
 * Adds the `source` column so the Yardi/Tribe EFT remittance poller
 * (YardiEftInboxService) can reuse the same table + "Pending e-Transfers"
 * panel as the Interac poller instead of a parallel one. Idempotent — safe
 * to re-run. Admin only.
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

tryExec($db, "ALTER TABLE etransfer_notifications ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'interac' AFTER mailbox", 'add source', $results);
tryExec($db, "ALTER TABLE etransfer_notifications ADD INDEX idx_source (source)", 'add index', $results);

echo json_encode(['success' => true, 'migration' => '1112', 'steps' => $results]);
