<?php
/**
 * One-shot migration runner for migration 985 (bank reconciliation columns).
 * DELETE THIS FILE after running.
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    requirePermission('expenses.edit');

    $db  = getDB();
    $log = [];
    $ok  = true;

    $steps = [
        'matched_expense_id on accounting_transactions' => "ALTER TABLE accounting_transactions ADD COLUMN matched_expense_id INT NULL AFTER reference_id",
        'match_confidence on accounting_transactions'   => "ALTER TABLE accounting_transactions ADD COLUMN match_confidence TINYINT NULL",
        'matched_at on accounting_transactions'         => "ALTER TABLE accounting_transactions ADD COLUMN matched_at DATETIME NULL",
        'matched_by on accounting_transactions'         => "ALTER TABLE accounting_transactions ADD COLUMN matched_by VARCHAR(20) NULL",
        'match_status on bank_import_rows'              => "ALTER TABLE bank_import_rows ADD COLUMN match_status ENUM('unmatched','auto_matched','suggested','manually_matched','new_expense','true_duplicate') NOT NULL DEFAULT 'unmatched' AFTER is_duplicate",
        'matched_expense_id on bank_import_rows'        => "ALTER TABLE bank_import_rows ADD COLUMN matched_expense_id INT NULL",
        'index idx_at_matched_expense'                  => "CREATE INDEX idx_at_matched_expense ON accounting_transactions(matched_expense_id)",
        'index idx_bir_match_status'                    => "CREATE INDEX idx_bir_match_status ON bank_import_rows(match_status)",
    ];

    foreach ($steps as $label => $sql) {
        try {
            $db->exec($sql);
            $log[] = "✓ $label";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                $log[] = "– $label (already exists, skipped)";
            } else {
                $log[] = "✗ $label: " . $e->getMessage();
                $ok = false;
            }
        }
    }

    echo json_encode(['ok' => $ok, 'log' => $log]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
