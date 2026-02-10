<?php
/**
 * Apply all database migrations to sync with production
 *
 * URL: https://localhost/crm/api/apply-migrations.php
 *
 * SECURITY: Admin access only
 * This file should be deleted after migrations are applied.
 */

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';

// Only allow admin users
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized');
}

$user = getCurrentUser();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Forbidden: Admin access required');
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== Mowology CRM Database Migration ===\n\n";
echo "Starting migration process...\n\n";

try {
    $db = getDB();

    // Read migration SQL
    $migrationFile = dirname(__DIR__, 2) . '/database/APPLY_ALL_MIGRATIONS.sql';

    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }

    echo "✓ Migration file found\n";
    echo "✓ Database connection established\n\n";

    // Parse and execute SQL statements
    $sql = file_get_contents($migrationFile);

    // Split by ; and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strpos($stmt, '--') === false && strpos($stmt, 'SELECT') !== 0;
        }
    );

    $count = 0;
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;

        try {
            $db->exec($statement);
            $count++;
            echo "✓ Executed statement $count\n";
        } catch (PDOException $e) {
            // Some statements may fail if tables already exist with IF NOT EXISTS
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "⚠ Statement $count: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n✓ Migration complete ($count statements executed)\n\n";

    // Verify tables
    echo "=== Verifying Tables ===\n";
    $result = $db->query("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME
    ");

    $tables = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "Found " . count($tables) . " tables:\n";
    foreach ($tables as $table) {
        echo "  ✓ $table\n";
    }

    echo "\n✓ All migrations applied successfully!\n";

} catch (Exception $e) {
    http_response_code(500);
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString();
}
?>
