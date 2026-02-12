<?php
/**
 * Quick Fix: Add missing columns to properties table
 *
 * This script adds the missing total_lawn_sqft and total_driveway_sqft columns
 * to the properties table. This is needed to fix the quote-workflow.php error.
 *
 * Usage: Access this file directly in browser or run via CLI:
 * php /public/crm/api/fix-properties-columns.php
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';

// Only allow admin/authorized users or CLI
$isCLI = php_sapi_name() === 'cli';
if (!$isCLI) {
    requireLogin();
    $user = getCurrentUser();
    if ($user['role'] !== 'admin') {
        die('Admin access required');
    }
}

$db = getDB();
$success = true;
$messages = [];

try {
    // Check if total_lawn_sqft column exists
    $result = $db->query("SHOW COLUMNS FROM `properties` LIKE 'total_lawn_sqft'");
    $columnLawnExists = $result && $result->rowCount() > 0;

    // Check if total_driveway_sqft column exists
    $result = $db->query("SHOW COLUMNS FROM `properties` LIKE 'total_driveway_sqft'");
    $columnDrivewayExists = $result && $result->rowCount() > 0;

    // Check if measurements_updated_at column exists
    $result = $db->query("SHOW COLUMNS FROM `properties` LIKE 'measurements_updated_at'");
    $columnTimestampExists = $result && $result->rowCount() > 0;

    if (!$columnLawnExists || !$columnDrivewayExists || !$columnTimestampExists) {
        $messages[] = "Adding missing columns to properties table...";

        // MySQL 5.7 compatible: Add columns one at a time, checking each first
        if (!$columnLawnExists) {
            try {
                $db->exec("ALTER TABLE `properties` ADD COLUMN `total_lawn_sqft` DECIMAL(12,2) DEFAULT NULL COMMENT 'Total lawn area from measurements'");
                $messages[] = "✅ Added total_lawn_sqft column";
            } catch (PDOException $e) {
                $messages[] = "⚠️  total_lawn_sqft column: " . $e->errorInfo[2];
            }
        }

        if (!$columnDrivewayExists) {
            try {
                $db->exec("ALTER TABLE `properties` ADD COLUMN `total_driveway_sqft` DECIMAL(12,2) DEFAULT NULL COMMENT 'Total driveway/walkway area'");
                $messages[] = "✅ Added total_driveway_sqft column";
            } catch (PDOException $e) {
                $messages[] = "⚠️  total_driveway_sqft column: " . $e->errorInfo[2];
            }
        }

        if (!$columnTimestampExists) {
            try {
                $db->exec("ALTER TABLE `properties` ADD COLUMN `measurements_updated_at` TIMESTAMP NULL DEFAULT NULL");
                $messages[] = "✅ Added measurements_updated_at column";
            } catch (PDOException $e) {
                $messages[] = "⚠️  measurements_updated_at column: " . $e->errorInfo[2];
            }
        }
    } else {
        $messages[] = "✅ All columns already exist";
    }

    // Verify all columns now exist
    $result = $db->query("SHOW COLUMNS FROM `properties` LIKE 'total_lawn_sqft'");
    if ($result && $result->rowCount() > 0) {
        $messages[] = "✅ Verification passed: total_lawn_sqft column exists";
    } else {
        $success = false;
        $messages[] = "❌ Verification failed: total_lawn_sqft column not found";
    }

} catch (PDOException $e) {
    $success = false;
    $messages[] = "❌ Database Error: " . $e->getMessage();
    error_log("Properties column fix error: " . $e->getMessage());
}

// Output results
if ($isCLI) {
    echo "Properties Column Fix\n";
    echo str_repeat("=", 60) . "\n";
    foreach ($messages as $msg) {
        echo $msg . "\n";
    }
    echo str_repeat("=", 60) . "\n";
    echo $success ? "Status: SUCCESS\n" : "Status: FAILED\n";
    exit($success ? 0 : 1);
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'messages' => $messages
    ]);
}
