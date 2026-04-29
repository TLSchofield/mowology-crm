<?php
/**
 * Migration 986 — deduplicate chart_of_accounts + add UNIQUE KEY on code.
 * DELETE THIS FILE after running.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    requirePermission('expenses.edit');

    $db  = getDB();
    $log = [];

    // Step 1: Find duplicate account codes
    $dupes = $db->query("
        SELECT code, COUNT(*) AS cnt, MIN(id) AS keep_id, MAX(id) AS drop_id
        FROM chart_of_accounts
        GROUP BY code
        HAVING cnt > 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    $log[] = count($dupes) . ' duplicate code(s) found';

    // Step 2: For each duplicate, re-point all FK references to the keeper, then delete the dupe
    $tables = [
        ['accounting_transactions', 'account_id'],
        ['bank_import_rows',        'account_id'],
        ['transaction_rules',       'account_id'],
        ['expense_budgets',         'account_id'],
    ];

    foreach ($dupes as $d) {
        $keep = (int)$d['keep_id'];
        $drop = (int)$d['drop_id'];

        foreach ($tables as [$table, $col]) {
            try {
                $cnt = $db->prepare("UPDATE `$table` SET `$col` = ? WHERE `$col` = ?");
                $cnt->execute([$keep, $drop]);
                if ($cnt->rowCount() > 0) {
                    $log[] = "  Re-pointed {$cnt->rowCount()} row(s) in $table from id=$drop to id=$keep";
                }
            } catch (PDOException $e) {
                $log[] = "  Skip $table (table or column may not exist): " . $e->getMessage();
            }
        }

        // Also fix parent_id self-references in chart_of_accounts
        $db->prepare("UPDATE chart_of_accounts SET parent_id = ? WHERE parent_id = ?")
           ->execute([$keep, $drop]);

        $db->prepare("DELETE FROM chart_of_accounts WHERE id = ?")->execute([$drop]);
        $log[] = "  Deleted duplicate id=$drop (code={$d['code']}, kept id=$keep)";
    }

    // Step 3: Add UNIQUE KEY on code (skip if already exists)
    try {
        $db->exec("ALTER TABLE chart_of_accounts ADD UNIQUE KEY uq_coa_code (code)");
        $log[] = 'Added UNIQUE KEY uq_coa_code on chart_of_accounts.code';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key') !== false ||
            strpos($e->getMessage(), 'already exists') !== false) {
            $log[] = 'UNIQUE KEY already exists — skipped';
        } else {
            $log[] = 'UNIQUE KEY error: ' . $e->getMessage();
        }
    }

    echo json_encode(['ok' => true, 'log' => $log]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
