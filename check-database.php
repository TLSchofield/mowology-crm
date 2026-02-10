#!/usr/bin/env php
<?php
/**
 * Database Connection & Schema Check
 *
 * Usage:
 *   php check-database.php
 *
 * This script verifies your database connection and shows current schema status.
 */

declare(strict_types=1);

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Mowology CRM Database Check                              ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Load config
require_once __DIR__ . '/public/app_config/session_config.php';
require_once __DIR__ . '/public/app_config/config.php';

try {
    // Get database connection
    $db = getDB();
    echo "✓ Database connection established\n\n";

    // Check database name
    $result = $db->query("SELECT DATABASE() as db_name");
    $dbName = $result->fetch(PDO::FETCH_ASSOC)['db_name'];
    echo "Database: $dbName\n";

    // Check MySQL version
    $result = $db->query("SELECT VERSION() as version");
    $version = $result->fetch(PDO::FETCH_ASSOC)['version'];
    echo "MySQL Version: $version\n\n";

    // List all tables
    $result = $db->query("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME
    ");
    $tables = $result->fetchAll(PDO::FETCH_COLUMN);

    echo "═══════════════════════════════════════════════════════════\n";
    echo "Current Tables (" . count($tables) . " total)\n";
    echo "═══════════════════════════════════════════════════════════\n\n";

    // Group tables by category
    $categories = [
        'Core' => ['contacts', 'companies', 'properties', 'company_properties', 'quote_requests'],
        'Admin' => ['users', 'sessions', 'password_reset_tokens'],
        'Tracking' => ['lead_events', 'conversion_events', 'roi_attribution'],
        'Logging' => ['consent_log', 'activity_log'],
    ];

    $foundTables = [];
    foreach ($categories as $category => $tableList) {
        echo "$category Tables:\n";
        foreach ($tableList as $table) {
            $exists = in_array($table, $tables);
            $status = $exists ? "✓" : "✗";
            echo "  $status $table\n";
            if ($exists) {
                $foundTables[$table] = true;
            }
        }
        echo "\n";
    }

    // Check for any other tables
    $otherTables = array_diff($tables, ...array_values($categories));
    if (!empty($otherTables)) {
        echo "Other Tables:\n";
        foreach ($otherTables as $table) {
            echo "  • $table\n";
        }
        echo "\n";
    }

    // Detailed table structure
    echo "═══════════════════════════════════════════════════════════\n";
    echo "Critical Table Structures\n";
    echo "═══════════════════════════════════════════════════════════\n\n";

    $criticalTables = ['contacts', 'quote_requests', 'lead_events', 'consent_log'];

    foreach ($criticalTables as $table) {
        if (in_array($table, $tables)) {
            echo "✓ $table:\n";
            try {
                $result = $db->query("DESCRIBE `$table`");
                $columns = $result->fetchAll(PDO::FETCH_ASSOC);
                foreach ($columns as $col) {
                    echo "    - " . str_pad($col['Field'], 30) . " " . $col['Type'];
                    if ($col['Null'] === 'NO') echo " [NOT NULL]";
                    echo "\n";
                }
            } catch (Exception $e) {
                echo "    Error describing table: " . $e->getMessage() . "\n";
            }
        } else {
            echo "✗ $table: TABLE NOT FOUND\n";
        }
        echo "\n";
    }

    // Status summary
    echo "═══════════════════════════════════════════════════════════\n";
    echo "Status Summary\n";
    echo "═══════════════════════════════════════════════════════════\n\n";

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

    $missingTables = array_diff($requiredTables, $tables);
    $existingTables = array_intersect($requiredTables, $tables);

    if (empty($missingTables)) {
        echo "✓ All required tables exist!\n";
        echo "✓ Database is ready for quote submission.\n";
        echo "\nYou can now test: https://www.mowology.ca/jobFlow/getQuote.php\n\n";
    } else {
        echo "✗ Missing " . count($missingTables) . " required tables:\n";
        foreach ($missingTables as $table) {
            echo "  - $table\n";
        }
        echo "\nRun: php apply-migrations-cli.php\n\n";
    }

    echo "✓ Existing tables: " . count($existingTables) . " / " . count($requiredTables) . "\n";
    echo "✗ Missing tables: " . count($missingTables) . " / " . count($requiredTables) . "\n\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "\nMake sure:\n";
    echo "  1. MySQL is running\n";
    echo "  2. Database exists and is configured in public/app_config/config.php\n";
    echo "  3. Database credentials are correct\n\n";
    exit(1);
}
?>
