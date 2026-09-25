<?php
/**
 * Run Migration 950 — Work Zone GPS Time Attribution System
 *
 * Adds:
 *   - job_geofences.zone_type  (arrival_border | work_zone)
 *   - job_location_samples.work_zone_id  (which inner work zone a ping fell inside)
 *   - visit_zone_sessions  (per-visit, per-zone time summaries)
 *   - job_visits.transit_seconds  (on-site time outside all work zones)
 *   - visit_photos.work_zone_id  (auto-tagging photos to the zone crew was in)
 *
 * Admin-only, idempotent (re-runnable safely).
 */
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
session_write_close();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$statements = [

    // 1. Add zone_type to job_geofences
    // arrival_border = outer polygon that triggers clock-in (no plan required)
    // work_zone      = inner named polygon for time attribution (linked to plan)
    "ALTER TABLE job_geofences
        ADD COLUMN zone_type ENUM('arrival_border','work_zone') NOT NULL DEFAULT 'work_zone' AFTER plan_id",

    // 2. Add work_zone_id to job_location_samples
    // NULL = ping was inside the outer border but not inside any work zone (transit)
    "ALTER TABLE job_location_samples
        ADD COLUMN work_zone_id INT NULL AFTER geofence_id",

    // 3. Per-visit, per-zone time summaries
    "CREATE TABLE IF NOT EXISTS visit_zone_sessions (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        visit_id    INT NOT NULL,
        zone_id     INT NOT NULL,
        zone_label  VARCHAR(100) NULL,
        plan_id     INT NULL,
        in_seconds  INT NOT NULL DEFAULT 0,
        entry_count SMALLINT NOT NULL DEFAULT 0,
        exit_count  SMALLINT NOT NULL DEFAULT 0,
        crew_count  SMALLINT NOT NULL DEFAULT 1,
        computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_visit_zone (visit_id, zone_id),
        INDEX idx_visit (visit_id),
        INDEX idx_zone  (zone_id),
        INDEX idx_plan  (plan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // 4. Transit seconds on job_visits (on-site time outside all work zones)
    "ALTER TABLE job_visits
        ADD COLUMN transit_seconds INT NULL",

    // 5. Zone auto-tagging on photos
    "ALTER TABLE visit_photos
        ADD COLUMN work_zone_id INT NULL",
];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), '1060') !== false ||
            strpos($e->getMessage(), "already exists") !== false) {
            $results[] = "SKIP[$i] Already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $e->getMessage() . ' | SQL: ' . substr(trim($sql), 0, 80);
        }
    }
}

echo "Migration 950 — Work Zone GPS Time Attribution\n\n";
echo implode("\n", $results) . "\n\nDone.";
