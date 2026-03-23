<?php
/**
 * Run Migration 990: Quiz Certification System
 * Admin-only, requires active CRM session.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

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
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Admin only';
    exit;
}

session_write_close();

$sql = file_get_contents(
    dirname(__DIR__, 3) . '/database/migrations/990_quiz_certification_system.sql'
);

// Split on semicolons, skip comments and blanks
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => $s !== '' && !str_starts_with(ltrim($s), '--')
);

$db = getDB();
$ok = 0; $errors = 0;
foreach ($statements as $i => $stmt) {
    if (empty(trim($stmt))) continue;
    try {
        $db->exec($stmt);
        echo "OK: statement " . ($i + 1) . "\n";
        $ok++;
    } catch (PDOException $e) {
        // Duplicate key / already exists = acceptable for idempotent seeds
        if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "SKIP (duplicate): statement " . ($i + 1) . "\n";
            $ok++;
        } else {
            echo "ERROR statement " . ($i + 1) . ": " . $e->getMessage() . "\n";
            $errors++;
        }
    }
}
echo "--- Migration 990 complete: {$ok} OK, {$errors} errors ---\n";
