<?php
/**
 * Temporary migration runner for 201_drop_legacy_jobs.sql
 * DELETE THIS FILE AFTER RUNNING
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if ($user['role'] !== 'admin') { die('Admin only'); }

$db = getDB();

echo "<pre>\n";
echo "=== Migration 201: Drop Legacy Jobs Tables ===\n\n";

// Execute each statement individually for better error reporting
$statements = [
    // 1. Drop dependent tables
    "DROP TABLE IF EXISTS job_proof_of_work",
    "DROP TABLE IF EXISTS job_photos",
    "DROP TABLE IF EXISTS job_notes",

    // 2. Drop FK + columns from job_time_entries
    // First, find the actual FK constraint name
    "SET @fk_name = NULL",
];

// We need to dynamically find FK constraint names since MySQL auto-generates them
echo "Step 1: Finding FK constraint names...\n";

// Find job_time_entries FK on job_id
try {
    $stmt = $db->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'job_time_entries'
        AND COLUMN_NAME = 'job_id'
        AND REFERENCED_TABLE_NAME = 'jobs'
    ");
    $fk = $stmt->fetch(PDO::FETCH_ASSOC);
    $jteFkName = $fk ? $fk['CONSTRAINT_NAME'] : null;
    echo "  job_time_entries FK name: " . ($jteFkName ?: 'NOT FOUND') . "\n";
} catch (Exception $e) {
    echo "  Error finding job_time_entries FK: " . $e->getMessage() . "\n";
    $jteFkName = null;
}

// Find crew_location_history FK on job_id
try {
    $stmt = $db->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'crew_location_history'
        AND COLUMN_NAME = 'job_id'
        AND REFERENCED_TABLE_NAME = 'jobs'
    ");
    $fk = $stmt->fetch(PDO::FETCH_ASSOC);
    $clhFkName = $fk ? $fk['CONSTRAINT_NAME'] : null;
    echo "  crew_location_history FK name: " . ($clhFkName ?: 'NOT FOUND') . "\n";
} catch (Exception $e) {
    echo "  Error finding crew_location_history FK: " . $e->getMessage() . "\n";
    $clhFkName = null;
}

echo "\nStep 2: Disabling FK checks...\n";
$db->exec("SET FOREIGN_KEY_CHECKS = 0");
echo "  Done.\n";

// Build dynamic statement list
$migrationSteps = [];

// Drop dependent tables
$migrationSteps[] = ['DROP TABLE IF EXISTS job_proof_of_work', 'Drop job_proof_of_work table'];
$migrationSteps[] = ['DROP TABLE IF EXISTS job_photos', 'Drop job_photos table'];
$migrationSteps[] = ['DROP TABLE IF EXISTS job_notes', 'Drop job_notes table'];

// job_time_entries: drop FK, index, column
if ($jteFkName) {
    $migrationSteps[] = ["ALTER TABLE job_time_entries DROP FOREIGN KEY `{$jteFkName}`", 'Drop job_time_entries FK on job_id'];
}
// Check if index exists before dropping
try {
    $idx = $db->query("SHOW INDEX FROM job_time_entries WHERE Key_name = 'idx_job'")->fetch();
    if ($idx) {
        $migrationSteps[] = ['ALTER TABLE job_time_entries DROP INDEX idx_job', 'Drop job_time_entries idx_job index'];
    }
} catch (Exception $e) {}

// Check if column exists
try {
    $col = $db->query("SHOW COLUMNS FROM job_time_entries LIKE 'job_id'")->fetch();
    if ($col) {
        $migrationSteps[] = ['ALTER TABLE job_time_entries DROP COLUMN job_id', 'Drop job_time_entries.job_id column'];
    }
} catch (Exception $e) {}

// crew_location_history: drop FK, index, column
if ($clhFkName) {
    $migrationSteps[] = ["ALTER TABLE crew_location_history DROP FOREIGN KEY `{$clhFkName}`", 'Drop crew_location_history FK on job_id'];
}
try {
    $idx = $db->query("SHOW INDEX FROM crew_location_history WHERE Key_name = 'idx_job'")->fetch();
    if ($idx) {
        $migrationSteps[] = ['ALTER TABLE crew_location_history DROP INDEX idx_job', 'Drop crew_location_history idx_job index'];
    }
} catch (Exception $e) {}
try {
    $col = $db->query("SHOW COLUMNS FROM crew_location_history LIKE 'job_id'")->fetch();
    if ($col) {
        $migrationSteps[] = ['ALTER TABLE crew_location_history DROP COLUMN job_id', 'Drop crew_location_history.job_id column'];
    }
} catch (Exception $e) {}

// invoices: drop column (no FK)
try {
    $col = $db->query("SHOW COLUMNS FROM invoices LIKE 'job_id'")->fetch();
    if ($col) {
        $migrationSteps[] = ['ALTER TABLE invoices DROP COLUMN job_id', 'Drop invoices.job_id column'];
    }
} catch (Exception $e) {}

// invoice_line_items: drop column (no FK)
try {
    $col = $db->query("SHOW COLUMNS FROM invoice_line_items LIKE 'job_id'")->fetch();
    if ($col) {
        $migrationSteps[] = ['ALTER TABLE invoice_line_items DROP COLUMN job_id', 'Drop invoice_line_items.job_id column'];
    }
} catch (Exception $e) {}

// activity_log: drop index + column (no FK)
try {
    $idx = $db->query("SHOW INDEX FROM activity_log WHERE Key_name = 'idx_job'")->fetch();
    if ($idx) {
        $migrationSteps[] = ['ALTER TABLE activity_log DROP INDEX idx_job', 'Drop activity_log idx_job index'];
    }
} catch (Exception $e) {}
try {
    $col = $db->query("SHOW COLUMNS FROM activity_log LIKE 'job_id'")->fetch();
    if ($col) {
        $migrationSteps[] = ['ALTER TABLE activity_log DROP COLUMN job_id', 'Drop activity_log.job_id column'];
    }
} catch (Exception $e) {}

// roi_attribution: drop column (no FK)
try {
    $col = $db->query("SHOW COLUMNS FROM roi_attribution LIKE 'job_id'")->fetch();
    if ($col) {
        $migrationSteps[] = ['ALTER TABLE roi_attribution DROP COLUMN job_id', 'Drop roi_attribution.job_id column'];
    }
} catch (Exception $e) {}

// Drop the jobs table itself
$migrationSteps[] = ['DROP TABLE IF EXISTS jobs', 'Drop jobs table'];

echo "\nStep 3: Running " . count($migrationSteps) . " migration steps...\n\n";

$success = 0;
$failed = 0;
foreach ($migrationSteps as $i => $step) {
    $sql = $step[0];
    $desc = $step[1];
    $num = $i + 1;

    try {
        $db->exec($sql);
        echo "  [{$num}] ✓ {$desc}\n";
        $success++;
    } catch (Exception $e) {
        echo "  [{$num}] ✗ {$desc}\n";
        echo "      Error: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\nStep 4: Re-enabling FK checks...\n";
$db->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "  Done.\n";

echo "\n=== Migration Complete ===\n";
echo "Success: {$success} / " . ($success + $failed) . "\n";
echo "Failed: {$failed}\n";

// Verification
echo "\n=== Verification ===\n";

// Check jobs table is gone
try {
    $db->query("SELECT 1 FROM jobs LIMIT 1");
    echo "  ✗ jobs table still exists!\n";
} catch (Exception $e) {
    echo "  ✓ jobs table dropped\n";
}

// Check dependent tables are gone
foreach (['job_proof_of_work', 'job_photos', 'job_notes'] as $table) {
    try {
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
        echo "  ✗ {$table} table still exists!\n";
    } catch (Exception $e) {
        echo "  ✓ {$table} table dropped\n";
    }
}

// Check job_id columns are gone
$checkCols = [
    'job_time_entries' => 'job_id',
    'crew_location_history' => 'job_id',
    'invoices' => 'job_id',
    'invoice_line_items' => 'job_id',
    'activity_log' => 'job_id',
    'roi_attribution' => 'job_id',
];
foreach ($checkCols as $table => $col) {
    try {
        $result = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'")->fetch();
        if ($result) {
            echo "  ✗ {$table}.{$col} still exists!\n";
        } else {
            echo "  ✓ {$table}.{$col} dropped\n";
        }
    } catch (Exception $e) {
        echo "  ? {$table}: " . $e->getMessage() . "\n";
    }
}

// Check new tables still exist
foreach (['job_plans', 'job_visits', 'calendar_stops', 'visit_notes', 'visit_photos', 'plan_notes'] as $table) {
    try {
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
        echo "  ✓ {$table} exists (new table)\n";
    } catch (Exception $e) {
        echo "  ✗ {$table} MISSING!\n";
    }
}

echo "\n</pre>";
echo "<p><strong>⚠️ DELETE THIS FILE NOW: run-migration-201.php</strong></p>";
