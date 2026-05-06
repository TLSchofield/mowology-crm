<?php
/**
 * Run Migration: Tasks System
 *
 * Creates the tasks table for internal to-do / follow-up tracking.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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
session_write_close();
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
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate')) {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $msg];
        }
    }
}

tryExec($db, "
    CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        due_date DATE NULL,
        due_time TIME NULL,
        priority ENUM('high', 'normal', 'low') DEFAULT 'normal',
        status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
        assigned_to INT NULL COMMENT 'user_id',
        contact_id INT NULL,
        company_id INT NULL,
        property_id INT NULL,
        quote_id INT NULL,
        plan_id INT NULL,
        invoice_id INT NULL,
        is_recurring TINYINT(1) DEFAULT 0,
        recurrence_pattern ENUM('daily', 'weekly', 'biweekly', 'monthly') NULL,
        recurrence_end_date DATE NULL,
        parent_task_id INT NULL COMMENT 'Links recurring instances to template',
        completed_at DATETIME NULL,
        completed_by INT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_task_assigned (assigned_to),
        INDEX idx_task_status (status),
        INDEX idx_task_due (due_date),
        INDEX idx_task_contact (contact_id),
        INDEX idx_task_quote (quote_id),
        INDEX idx_task_plan (plan_id),
        INDEX idx_task_invoice (invoice_id),
        INDEX idx_task_created_by (created_by),
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
        FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
        FOREIGN KEY (plan_id) REFERENCES job_plans(id) ON DELETE SET NULL,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'create tasks table', $results);

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
