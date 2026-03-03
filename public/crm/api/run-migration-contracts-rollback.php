<?php
/**
 * Rollback — Contracts Auto-Migration
 * Detaches all job_plans from their auto-created contracts and deletes those contracts.
 * Does NOT drop the contracts table or the contract_id column (schema stays intact).
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

try {
    $db->beginTransaction();

    // How many plans are currently linked to contracts?
    $planCount = (int)$db->query("SELECT COUNT(*) FROM job_plans WHERE contract_id IS NOT NULL")->fetchColumn();
    $results[] = "Plans currently linked to contracts: {$planCount}";

    // How many contracts exist?
    $contractCount = (int)$db->query("SELECT COUNT(*) FROM contracts")->fetchColumn();
    $results[] = "Contracts currently in table: {$contractCount}";

    // 1. Null out contract_id on all plans
    $stmt = $db->prepare("UPDATE job_plans SET contract_id = NULL WHERE contract_id IS NOT NULL");
    $stmt->execute();
    $results[] = "OK  Detached {$stmt->rowCount()} plan(s) from contracts (contract_id set to NULL)";

    // 2. Delete all contract records
    $deleted = $db->exec("DELETE FROM contracts");
    $results[] = "OK  Deleted {$deleted} contract record(s)";

    $db->commit();
    $results[] = '';
    $results[] = 'Rollback complete. All plans are now standalone (no contract linkage).';

} catch (PDOException $e) {
    $db->rollBack();
    $results[] = 'ERR ' . $e->getMessage();
    $results[] = 'Rollback failed — no changes made.';
}

echo "Contracts Rollback\n\n" . implode("\n", $results) . "\n\nDone.";
