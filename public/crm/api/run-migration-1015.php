<?php
/**
 * Run Migration 1015: per-user quiz pre-shift skip flag
 *
 * Adds quiz_preshift_skip column to users table.
 * When 1, the user bypasses the pre-shift quiz regardless of global setting.
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
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate column')) {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $msg];
        }
    }
}

tryExec($db,
    "ALTER TABLE users
         ADD COLUMN quiz_preshift_skip TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'When 1, this user skips the pre-shift quiz even when globally enabled'",
    'add quiz_preshift_skip to users',
    $results
);

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
