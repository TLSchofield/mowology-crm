<?php
/**
 * One-time Migration Runner — Migration 800: Polygon Geofence Tracking Module
 * Run once via authenticated POST, then DELETE this file.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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
requireLogin();
$user = getCurrentUser();
session_write_close();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();
$results = [];

// ── 1. Create job_geofences table ─────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS job_geofences (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        plan_id         INT NOT NULL,
        property_id     INT NOT NULL,
        polygon_json    TEXT NOT NULL,
        vertex_count    TINYINT UNSIGNED NOT NULL DEFAULT 3,
        bbox_lat_min    DECIMAL(10,8) NOT NULL,
        bbox_lat_max    DECIMAL(10,8) NOT NULL,
        bbox_lng_min    DECIMAL(11,8) NOT NULL,
        bbox_lng_max    DECIMAL(11,8) NOT NULL,
        area_sqm        DECIMAL(12,2) NULL,
        drawn_by        INT NULL,
        drawn_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_by      INT NULL,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        label           VARCHAR(100) NULL,
        notes           TEXT NULL,
        INDEX idx_jgf_plan     (plan_id),
        INDEX idx_jgf_property (property_id),
        FOREIGN KEY (plan_id)    REFERENCES job_plans(id)  ON DELETE CASCADE,
        FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
        FOREIGN KEY (drawn_by)   REFERENCES users(id)      ON DELETE SET NULL,
        FOREIGN KEY (updated_by) REFERENCES users(id)      ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'job_geofences table', 'status' => 'ok'];
} catch (Exception $e) {
    $results[] = ['step' => 'job_geofences table', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 2. Create job_location_samples table ─────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS job_location_samples (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        visit_id        INT NOT NULL,
        geofence_id     INT NULL,
        sampled_at      DATETIME NOT NULL,
        lat             DECIMAL(10,8) NOT NULL,
        lng             DECIMAL(11,8) NOT NULL,
        accuracy_m      DECIMAL(8,2)  NULL,
        speed_mps       DECIMAL(6,2)  NULL,
        heading         DECIMAL(5,2)  NULL,
        altitude_m      DECIMAL(8,2)  NULL,
        battery_pct     TINYINT       NULL,
        in_zone         TINYINT(1)    NOT NULL DEFAULT 0,
        zone_checked    TINYINT(1)    NOT NULL DEFAULT 0,
        source          VARCHAR(30)   DEFAULT 'gps',
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_jls_visit        (visit_id),
        INDEX idx_jls_visit_ts     (visit_id, sampled_at),
        INDEX idx_jls_geofence     (geofence_id),
        INDEX idx_jls_zone_pending (visit_id, zone_checked),
        FOREIGN KEY (visit_id)    REFERENCES job_visits(id)    ON DELETE CASCADE,
        FOREIGN KEY (geofence_id) REFERENCES job_geofences(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'job_location_samples table', 'status' => 'ok'];
} catch (Exception $e) {
    $results[] = ['step' => 'job_location_samples table', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 3. Create job_geofence_events table ───────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS job_geofence_events (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        visit_id        INT NOT NULL,
        geofence_id     INT NOT NULL,
        event_type      ENUM('entry', 'exit') NOT NULL,
        event_time      DATETIME NOT NULL,
        lat             DECIMAL(10,8) NOT NULL,
        lng             DECIMAL(11,8) NOT NULL,
        accuracy_m      DECIMAL(8,2)  NULL,
        duration_seconds INT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_jge_visit    (visit_id),
        INDEX idx_jge_geofence (geofence_id),
        INDEX idx_jge_type     (event_type),
        FOREIGN KEY (visit_id)    REFERENCES job_visits(id)    ON DELETE CASCADE,
        FOREIGN KEY (geofence_id) REFERENCES job_geofences(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'job_geofence_events table', 'status' => 'ok'];
} catch (Exception $e) {
    $results[] = ['step' => 'job_geofence_events table', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 4. Create job_geofence_sessions table ─────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS job_geofence_sessions (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        visit_id            INT NOT NULL UNIQUE,
        geofence_id         INT NULL,
        tracking_active     TINYINT(1) NOT NULL DEFAULT 0,
        total_seconds       INT NOT NULL DEFAULT 0,
        in_zone_seconds     INT NOT NULL DEFAULT 0,
        out_zone_seconds    INT NOT NULL DEFAULT 0,
        unknown_seconds     INT NOT NULL DEFAULT 0,
        sample_count        INT NOT NULL DEFAULT 0,
        entry_count         SMALLINT NOT NULL DEFAULT 0,
        exit_count          SMALLINT NOT NULL DEFAULT 0,
        confidence_score    TINYINT UNSIGNED NULL,
        status              ENUM('pending','computing','complete','error') DEFAULT 'pending',
        computed_at         TIMESTAMP NULL,
        error_msg           TEXT NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_jgs_geofence (geofence_id),
        FOREIGN KEY (visit_id)    REFERENCES job_visits(id)    ON DELETE CASCADE,
        FOREIGN KEY (geofence_id) REFERENCES job_geofences(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'job_geofence_sessions table', 'status' => 'ok'];
} catch (Exception $e) {
    $results[] = ['step' => 'job_geofence_sessions table', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 5. Add columns to job_plans ───────────────────────────────────────────────
$col = $db->query("SHOW COLUMNS FROM job_plans LIKE 'geofence_enabled'")->fetch();
if (!$col) {
    try {
        $db->exec("ALTER TABLE job_plans
            ADD COLUMN geofence_enabled TINYINT(1) NULL DEFAULT NULL
                COMMENT 'NULL=inherit global/service default, 1=force on, 0=force off'");
        $results[] = ['step' => 'job_plans.geofence_enabled', 'status' => 'added'];
    } catch (Exception $e) {
        $results[] = ['step' => 'job_plans.geofence_enabled', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'job_plans.geofence_enabled', 'status' => 'already exists'];
}

$col = $db->query("SHOW COLUMNS FROM job_plans LIKE 'active_geofence_id'")->fetch();
if (!$col) {
    try {
        $db->exec("ALTER TABLE job_plans
            ADD COLUMN active_geofence_id INT NULL DEFAULT NULL
                COMMENT 'FK to job_geofences.id — the current active polygon'");
        $results[] = ['step' => 'job_plans.active_geofence_id', 'status' => 'added'];
    } catch (Exception $e) {
        $results[] = ['step' => 'job_plans.active_geofence_id', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'job_plans.active_geofence_id', 'status' => 'already exists'];
}

// ── 6. Add columns to job_visits ──────────────────────────────────────────────
$col = $db->query("SHOW COLUMNS FROM job_visits LIKE 'geofence_enabled'")->fetch();
if (!$col) {
    try {
        $db->exec("ALTER TABLE job_visits
            ADD COLUMN geofence_enabled TINYINT(1) NULL DEFAULT NULL
                COMMENT 'NULL=inherit from plan, 1=force on, 0=force off'");
        $results[] = ['step' => 'job_visits.geofence_enabled', 'status' => 'added'];
    } catch (Exception $e) {
        $results[] = ['step' => 'job_visits.geofence_enabled', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'job_visits.geofence_enabled', 'status' => 'already exists'];
}

$col = $db->query("SHOW COLUMNS FROM job_visits LIKE 'in_zone_minutes'")->fetch();
if (!$col) {
    try {
        $db->exec("ALTER TABLE job_visits
            ADD COLUMN in_zone_minutes SMALLINT UNSIGNED NULL DEFAULT NULL
                COMMENT 'Cached in-zone minutes from geofence_sessions'");
        $results[] = ['step' => 'job_visits.in_zone_minutes', 'status' => 'added'];
    } catch (Exception $e) {
        $results[] = ['step' => 'job_visits.in_zone_minutes', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'job_visits.in_zone_minutes', 'status' => 'already exists'];
}

$col = $db->query("SHOW COLUMNS FROM job_visits LIKE 'geofence_confidence'")->fetch();
if (!$col) {
    try {
        $db->exec("ALTER TABLE job_visits
            ADD COLUMN geofence_confidence TINYINT UNSIGNED NULL DEFAULT NULL
                COMMENT 'Cached confidence score 0–100 from geofence_sessions'");
        $results[] = ['step' => 'job_visits.geofence_confidence', 'status' => 'added'];
    } catch (Exception $e) {
        $results[] = ['step' => 'job_visits.geofence_confidence', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'job_visits.geofence_confidence', 'status' => 'already exists'];
}

// ── 7. Insert settings into time_clock_settings ───────────────────────────────
$settings = [
    'geofence.global_enabled'       => '0',
    'geofence.services_enabled'     => '[]',
    'geofence.sample_interval_sec'  => '30',
    'geofence.min_accuracy_m'       => '30',
    'geofence.max_accuracy_m'       => '150',
    'geofence.show_ui_badge'        => '1',
    'geofence.pow_section_enabled'  => '1',
];
$settingsOk = 0;
$settingsSkip = 0;
foreach ($settings as $key => $val) {
    try {
        $exists = $db->prepare("SELECT 1 FROM time_clock_settings WHERE setting_key = ?")->execute([$key]);
        $row = $db->prepare("SELECT 1 FROM time_clock_settings WHERE setting_key = ?");
        $row->execute([$key]);
        if (!$row->fetch()) {
            $db->prepare("INSERT INTO time_clock_settings (setting_key, setting_value) VALUES (?, ?)")
               ->execute([$key, $val]);
            $settingsOk++;
        } else {
            $settingsSkip++;
        }
    } catch (Exception $e) {
        $results[] = ['step' => "setting $key", 'status' => 'error', 'msg' => $e->getMessage()];
    }
}
$results[] = ['step' => 'time_clock_settings', 'status' => "inserted $settingsOk, skipped $settingsSkip"];

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
