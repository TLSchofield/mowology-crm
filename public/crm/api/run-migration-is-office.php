<?php
/**
 * Migration — add crew_location_history.is_office flag.
 *
 * Office pings are home-geofence heartbeats with no active job. They are kept
 * (not dropped) but flagged so route-tracing can exclude them while still
 * recording office presence.
 *
 * Idempotent. Run once via /crm/api/run-migration-is-office.php
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

// Check if the column already exists (MySQL has no ADD COLUMN IF NOT EXISTS)
$exists = $db->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'crew_location_history'
      AND COLUMN_NAME = 'is_office'
")->fetchColumn();

if ((int)$exists > 0) {
    echo "is_office column already exists — nothing to do.\n";
    exit;
}

$db->exec("
    ALTER TABLE crew_location_history
        ADD COLUMN is_office TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Home-geofence heartbeat with no active job — excluded from route tracing'
");

// Index so day_routes can filter cheaply
try {
    $db->exec("CREATE INDEX idx_clh_office ON crew_location_history (crew_id, is_office, timestamp)");
    echo "Added is_office column + idx_clh_office index.\n";
} catch (Throwable $e) {
    echo "Added is_office column (index skipped: " . $e->getMessage() . ").\n";
}
echo "[done]\n";
