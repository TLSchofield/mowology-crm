<?php
/**
 * Migration 1000: Photo Documentation System — Phase 1
 *
 * Adds denormalized columns to visit_photos for property timeline queries,
 * creates photo_comments and photo_annotations tables for future phases.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if ($user['role'] !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

// ── 1. Add denormalized columns to visit_photos ─────────────────────────────

$columns = [
    ['property_id',      "INT NULL COMMENT 'Denormalized from job_visits→job_plans for fast timeline queries'"],
    ['uploaded_by_name',  "VARCHAR(150) NULL COMMENT 'Crew name at upload time'"],
    ['service_type',      "VARCHAR(100) NULL COMMENT 'Service type from plan at upload time'"],
    ['tags',              "VARCHAR(500) NULL COMMENT 'JSON array of tags'"],
    ['sha256',            "CHAR(64) NULL COMMENT 'File hash for duplicate detection'"],
];

foreach ($columns as [$col, $def]) {
    try {
        $db->exec("ALTER TABLE visit_photos ADD COLUMN {$col} {$def}");
        $results[] = "OK: Added visit_photos.{$col}";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "SKIP: visit_photos.{$col} already exists";
        } else {
            $results[] = "ERROR (visit_photos.{$col}): " . $e->getMessage();
        }
    }
}

// ── 2. Add indexes for timeline queries ──────────────────────────────────────

$indexes = [
    ['idx_vp_property',      'visit_photos', '(property_id)'],
    ['idx_vp_property_date', 'visit_photos', '(property_id, uploaded_at)'],
    ['idx_vp_sha256',        'visit_photos', '(sha256)'],
];

foreach ($indexes as [$name, $table, $cols]) {
    try {
        $db->exec("CREATE INDEX {$name} ON {$table} {$cols}");
        $results[] = "OK: Created index {$name}";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            $results[] = "SKIP: Index {$name} already exists";
        } else {
            $results[] = "ERROR (index {$name}): " . $e->getMessage();
        }
    }
}

// ── 3. Backfill property_id + service_type for existing records ──────────────

try {
    $affected = $db->exec("
        UPDATE visit_photos vp
        JOIN job_visits jv ON jv.id = vp.visit_id
        JOIN job_plans jp ON jp.id = jv.plan_id
        SET vp.property_id = jp.property_id,
            vp.service_type = jp.service_type
        WHERE vp.property_id IS NULL
    ");
    $results[] = "OK: Backfilled property_id/service_type on {$affected} rows";
} catch (PDOException $e) {
    $results[] = "ERROR (backfill): " . $e->getMessage();
}

// ── 4. Backfill uploaded_by_name from users table ────────────────────────────

try {
    $affected = $db->exec("
        UPDATE visit_photos vp
        JOIN users u ON u.id = vp.uploaded_by
        SET vp.uploaded_by_name = u.full_name
        WHERE vp.uploaded_by_name IS NULL AND vp.uploaded_by IS NOT NULL
    ");
    $results[] = "OK: Backfilled uploaded_by_name on {$affected} rows";
} catch (PDOException $e) {
    $results[] = "ERROR (backfill names): " . $e->getMessage();
}

// ── 5. Create visit_photo_comments table ─────────────────────────────────────

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS visit_photo_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            photo_id INT NOT NULL,
            user_id INT NOT NULL,
            comment TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_vpc_photo (photo_id),
            INDEX idx_vpc_user (user_id),
            FOREIGN KEY (photo_id) REFERENCES visit_photos(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = "OK: Created visit_photo_comments table";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        $results[] = "SKIP: visit_photo_comments table already exists";
    } else {
        $results[] = "ERROR (photo_comments): " . $e->getMessage();
    }
}

// ── 6. Create visit_photo_annotations table ──────────────────────────────────

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS visit_photo_annotations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            photo_id INT NOT NULL,
            created_by INT NOT NULL,
            shapes_json TEXT NOT NULL COMMENT 'JSON array of shape descriptors',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_photo_annotation (photo_id),
            FOREIGN KEY (photo_id) REFERENCES visit_photos(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = "OK: Created visit_photo_annotations table";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        $results[] = "SKIP: visit_photo_annotations table already exists";
    } else {
        $results[] = "ERROR (photo_annotations): " . $e->getMessage();
    }
}

echo '<pre>' . implode("\n", $results) . '</pre>';
echo '<p>Done. <a href="/crm/">Back to CRM</a></p>';
