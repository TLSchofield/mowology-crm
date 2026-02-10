<?php
/**
 * Apply Migration 116: Add lead_event_id to quote_requests
 *
 * URL: https://www.mowology.ca/jobFlow/apply-migration-116.php
 *
 * This adds the missing lead_event_id column that's needed for ROI tracking
 */

header('Content-Type: text/plain; charset=utf-8');

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Applying Migration 116: Add lead_event_id Column          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';

try {
    $db = getDB();
    echo "✓ Database connection established\n\n";

    // Check if column already exists
    $result = $db->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'quote_requests'
        AND COLUMN_NAME = 'lead_event_id'
    ");

    if ($result->rowCount() > 0) {
        echo "✓ lead_event_id column already exists\n";
        echo "✓ No action needed\n\n";
        exit(0);
    }

    echo "Adding lead_event_id column to quote_requests table...\n\n";

    // Add the column
    $db->exec("
        ALTER TABLE `quote_requests`
        ADD COLUMN `lead_event_id` INT DEFAULT NULL AFTER `id`
    ");
    echo "✓ Column added\n";

    // Add foreign key constraint
    $db->exec("
        ALTER TABLE `quote_requests`
        ADD CONSTRAINT `fk_quote_requests_lead_events`
        FOREIGN KEY (`lead_event_id`) REFERENCES `lead_events`(`id`) ON DELETE SET NULL
    ");
    echo "✓ Foreign key constraint added\n";

    // Add index for performance
    $db->exec("
        ALTER TABLE `quote_requests`
        ADD INDEX `idx_lead_event` (`lead_event_id`)
    ");
    echo "✓ Index added\n\n";

    // Verify
    $result = $db->query("DESCRIBE `quote_requests` `lead_event_id`");
    if ($result->rowCount() > 0) {
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║  ✓ Migration 116 Applied Successfully!                    ║\n";
        echo "║                                                            ║\n";
        echo "║  The quote_requests table now has:                        ║\n";
        echo "║  • lead_event_id column for ROI tracking                  ║\n";
        echo "║  • Foreign key to lead_events table                       ║\n";
        echo "║  • Index for performance                                  ║\n";
        echo "║                                                            ║\n";
        echo "║  Quote submission will now work correctly!                ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
    exit(1);
}
?>
