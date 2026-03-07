<?php
/**
 * Run Migration: Import Logs
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
    CREATE TABLE IF NOT EXISTS import_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_type VARCHAR(50) NOT NULL COMMENT 'contacts, quotes, etc.',
        filename VARCHAR(255) NOT NULL,
        total_rows INT DEFAULT 0,
        imported_count INT DEFAULT 0,
        updated_count INT DEFAULT 0,
        skipped_count INT DEFAULT 0,
        error_count INT DEFAULT 0,
        errors TEXT NULL COMMENT 'JSON array of row-level errors',
        field_mapping TEXT NULL COMMENT 'JSON object of csv_col to db_field',
        imported_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_import_type (import_type),
        INDEX idx_import_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'create import_logs table', $results);

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
