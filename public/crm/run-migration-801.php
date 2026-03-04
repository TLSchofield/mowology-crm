<?php
/**
 * Migration 801 runner — Geofence multi-zone schema upgrade
 * ─────────────────────────────────────────────────────────
 * Run once via browser (admin only). Safe to run multiple times.
 * Delete or rename after successful run.
 *
 * Fixes:
 *   1. job_geofences.zone_type      — add if missing
 *   2. job_geofences.plan_id        — make nullable (arrival_border zones have no plan)
 *   3. job_location_samples.work_zone_id — add if missing
 *   4. job_visits.transit_seconds   — add if missing
 *   5. visit_zone_sessions          — create table if missing
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Admin only.');
}

$db = getDB();
$results = [];
$ok = true;

// ── Helper ────────────────────────────────────────────────────────────────────
function runSql(PDO $db, string $label, string $sql): array {
    try {
        $db->exec($sql);
        return ['label' => $label, 'status' => 'ok', 'msg' => 'OK'];
    } catch (PDOException $e) {
        return ['label' => $label, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

function columnExists(PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $col]);
    return (bool)$stmt->fetchColumn();
}

function columnIsNullable(PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("
        SELECT IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $col]);
    return $stmt->fetchColumn() === 'YES';
}

function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

// ── 1. zone_type on job_geofences ─────────────────────────────────────────────
if (!columnExists($db, 'job_geofences', 'zone_type')) {
    $results[] = runSql($db, 'Add job_geofences.zone_type',
        "ALTER TABLE job_geofences
         ADD COLUMN zone_type VARCHAR(30) NOT NULL DEFAULT 'work_zone'
         COMMENT 'arrival_border | work_zone'
         AFTER property_id"
    );
    // Back-fill existing rows
    $results[] = runSql($db, 'Back-fill zone_type = work_zone',
        "UPDATE job_geofences SET zone_type = 'work_zone' WHERE zone_type = ''"
    );
} else {
    $results[] = ['label' => 'job_geofences.zone_type', 'status' => 'skip', 'msg' => 'Column already exists'];
}

// ── 2. Make plan_id nullable ──────────────────────────────────────────────────
if (!columnIsNullable($db, 'job_geofences', 'plan_id')) {
    $results[] = runSql($db, 'Make job_geofences.plan_id nullable',
        "ALTER TABLE job_geofences
         MODIFY COLUMN plan_id INT NULL DEFAULT NULL
         COMMENT 'NULL for arrival_border (property-level); FK to job_plans for work_zone'"
    );
} else {
    $results[] = ['label' => 'job_geofences.plan_id nullable', 'status' => 'skip', 'msg' => 'Already nullable'];
}

// ── 3. work_zone_id on job_location_samples ───────────────────────────────────
if (!columnExists($db, 'job_location_samples', 'work_zone_id')) {
    $results[] = runSql($db, 'Add job_location_samples.work_zone_id',
        "ALTER TABLE job_location_samples
         ADD COLUMN work_zone_id INT NULL DEFAULT NULL
         COMMENT 'FK to job_geofences.id — which work zone this sample fell in (NULL = transit)'
         AFTER geofence_id"
    );
} else {
    $results[] = ['label' => 'job_location_samples.work_zone_id', 'status' => 'skip', 'msg' => 'Column already exists'];
}

// ── 4. transit_seconds on job_visits ─────────────────────────────────────────
if (!columnExists($db, 'job_visits', 'transit_seconds')) {
    $results[] = runSql($db, 'Add job_visits.transit_seconds',
        "ALTER TABLE job_visits
         ADD COLUMN transit_seconds INT NOT NULL DEFAULT 0
         COMMENT 'Seconds inside arrival border but outside any work zone'"
    );
} else {
    $results[] = ['label' => 'job_visits.transit_seconds', 'status' => 'skip', 'msg' => 'Column already exists'];
}

// ── 5. visit_zone_sessions table ─────────────────────────────────────────────
if (!tableExists($db, 'visit_zone_sessions')) {
    $results[] = runSql($db, 'Create visit_zone_sessions', "
        CREATE TABLE visit_zone_sessions (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            visit_id        INT NOT NULL,
            zone_id         INT NOT NULL,
            zone_label      VARCHAR(100) NULL,
            plan_id         INT NULL,
            in_seconds      INT NOT NULL DEFAULT 0,
            entry_count     SMALLINT NOT NULL DEFAULT 0,
            exit_count      SMALLINT NOT NULL DEFAULT 0,
            crew_count      TINYINT NOT NULL DEFAULT 1,
            computed_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_vzs_visit_zone (visit_id, zone_id),
            INDEX idx_vzs_visit  (visit_id),
            INDEX idx_vzs_zone   (zone_id),
            INDEX idx_vzs_plan   (plan_id),
            FOREIGN KEY (visit_id) REFERENCES job_visits(id)    ON DELETE CASCADE,
            FOREIGN KEY (zone_id)  REFERENCES job_geofences(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
          COMMENT='Per-visit per-zone time summary (2-min minimum dwell filter applied).'
    ");
} else {
    $results[] = ['label' => 'visit_zone_sessions table', 'status' => 'skip', 'msg' => 'Table already exists'];
}

// ── Check overall result ──────────────────────────────────────────────────────
foreach ($results as $r) {
    if ($r['status'] === 'error') { $ok = false; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Migration 801</title>
  <style>
    body { font-family: monospace; padding: 2rem; background: #f8f9fa; }
    h1   { color: #1A5F4A; }
    table { border-collapse: collapse; width: 100%; max-width: 800px; margin-top: 1rem; }
    th, td { border: 1px solid #dee2e6; padding: .5rem 1rem; text-align: left; }
    th     { background: #e9ecef; }
    .ok    { color: #155724; background: #d4edda; }
    .skip  { color: #856404; background: #fff3cd; }
    .error { color: #721c24; background: #f8d7da; }
    .banner { padding: 1rem 1.5rem; border-radius: 4px; margin-top: 1.5rem; font-size: 1.1rem; font-weight: bold; }
    .banner.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .banner.danger  { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
  </style>
</head>
<body>
  <h1>Migration 801 — Geofence multi-zone schema</h1>
  <table>
    <tr><th>Step</th><th>Status</th><th>Detail</th></tr>
    <?php foreach ($results as $r): ?>
    <tr class="<?php echo htmlspecialchars($r['status']); ?>">
      <td><?php echo htmlspecialchars($r['label']); ?></td>
      <td><?php echo strtoupper(htmlspecialchars($r['status'])); ?></td>
      <td><?php echo htmlspecialchars($r['msg']); ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <div class="banner <?php echo $ok ? 'success' : 'danger'; ?>">
    <?php echo $ok ? '✓ Migration complete — you can now save arrival_border zones from the contract view.' : '✗ Migration failed — check errors above.'; ?>
  </div>
  <?php if ($ok): ?>
  <p style="margin-top:1rem;color:#6c757d;font-size:.9rem;">
    Remember to delete or rename <code>run-migration-801.php</code> after verifying.
  </p>
  <?php endif; ?>
</body>
</html>
