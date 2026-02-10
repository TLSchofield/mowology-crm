#!/usr/bin/env php
<?php
/**
 * Command-line Database Migration Tool
 *
 * Usage:
 *   php apply-migrations-cli.php
 *
 * This script applies all database migrations to sync with production schema.
 */

declare(strict_types=1);

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Mowology CRM Database Migration Tool                      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Load config
require_once __DIR__ . '/public/app_config/session_config.php';
require_once __DIR__ . '/public/app_config/config.php';

try {
    // Get database connection
    $db = getDB();
    echo "✓ Database connection established\n";

    // Read migration file
    $migrationFile = __DIR__ . '/database/APPLY_ALL_MIGRATIONS.sql';

    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }

    echo "✓ Migration file loaded: $migrationFile\n\n";

    // Read SQL content
    $sql = file_get_contents($migrationFile);

    // Split statements (handle comments and empty lines)
    $lines = explode("\n", $sql);
    $statements = [];
    $currentStatement = '';

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and empty lines
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }

        $currentStatement .= ' ' . $line;

        // Statement ends with semicolon
        if (substr($line, -1) === ';') {
            $statements[] = rtrim($currentStatement, ';');
            $currentStatement = '';
        }
    }

    if (!empty($currentStatement)) {
        $statements[] = rtrim($currentStatement, ';');
    }

    // Filter out empty statements
    $statements = array_filter($statements, function($s) {
        return !empty(trim($s));
    });

    echo "Found " . count($statements) . " SQL statements to execute\n";
    echo "Executing migrations...\n\n";

    // Execute statements
    $successCount = 0;
    $warningCount = 0;
    $errorCount = 0;

    foreach ($statements as $index => $statement) {
        $statementNum = $index + 1;

        try {
            $db->exec($statement);
            $successCount++;
            echo "  [" . str_pad((string)$statementNum, 3, " ", STR_PAD_LEFT) . "] ✓ OK\n";
        } catch (PDOException $e) {
            $msg = $e->getMessage();

            // Some errors are expected (e.g., table already exists)
            if (
                strpos($msg, 'already exists') !== false ||
                strpos($msg, 'Duplicate column') !== false
            ) {
                $warningCount++;
                echo "  [" . str_pad((string)$statementNum, 3, " ", STR_PAD_LEFT) . "] ⚠ SKIPPED (table/column already exists)\n";
            } else {
                $errorCount++;
                echo "  [" . str_pad((string)$statementNum, 3, " ", STR_PAD_LEFT) . "] ✗ ERROR: " . substr($msg, 0, 80) . "\n";
            }
        } catch (Exception $e) {
            $errorCount++;
            echo "  [" . str_pad((string)$statementNum, 3, " ", STR_PAD_LEFT) . "] ✗ ERROR: " . substr($e->getMessage(), 0, 80) . "\n";
        }
    }

    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  Migration Results                                         ║\n";
    echo "╠════════════════════════════════════════════════════════════╣\n";
    echo "║  Successful: " . str_pad($successCount, 45) . " ║\n";
    echo "║  Skipped:    " . str_pad($warningCount, 45) . " ║\n";
    echo "║  Errors:     " . str_pad($errorCount, 45) . " ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n\n";

    // Verify tables
    echo "Verifying tables...\n";
    $result = $db->query("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME
    ");

    $tables = $result->fetchAll(PDO::FETCH_COLUMN);

    $requiredTables = [
        'contacts',
        'companies',
        'properties',
        'quote_requests',
        'users',
        'sessions',
        'lead_events',
        'conversion_events',
        'consent_log',
        'activity_log'
    ];

    echo "\nRequired tables:\n";
    $tablesOK = true;
    foreach ($requiredTables as $table) {
        $exists = in_array($table, $tables);
        echo "  " . ($exists ? "✓" : "✗") . " $table\n";
        if (!$exists) {
            $tablesOK = false;
        }
    }

    echo "\nAll tables in database (" . count($tables) . " total):\n";
    foreach ($tables as $table) {
        echo "  • $table\n";
    }

    echo "\n";
    if ($tablesOK) {
        echo "✓ Migration completed successfully!\n";
        echo "✓ All required tables exist.\n";
        echo "\nYou can now test the quote form:\n";
        echo "  https://www.mowology.ca/jobFlow/getQuote.php\n";
        echo "\nAfter testing, delete public/crm/api/apply-migrations.php for security.\n\n";
        exit(0);
    } else {
        echo "✗ Migration has errors. Some required tables are missing.\n\n";
        exit(1);
    }

} catch (Exception $e) {
    echo "\n✗ Fatal Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n\n";
    exit(1);
}
?>
