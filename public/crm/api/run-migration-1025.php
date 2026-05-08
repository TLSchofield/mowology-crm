<?php
/**
 * Migration 1025 — Photos: schema alignment + portfolio prep
 *
 * Fixes the production gap where visit-photo-upload.php was inserting into
 * columns that didn't exist (property_id, gps_*, etc.) — MySQL silently
 * ignored them and the property timeline query returned 0 rows.
 *
 * Also adds columns for the client portal enhancement:
 *   - consent_portfolio — client OK to feature in public portfolio
 *   - before_after_pair_id — pair a 'before' photo with its 'after'
 *   - visibility — internal/client/public audience scoping
 */
declare(strict_types=1);

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
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$statements = [
    // ── Schema alignment — columns the upload code was trying to write ────
    "ALTER TABLE visit_photos ADD COLUMN property_id INT NULL
        COMMENT 'Denormalized from visit->plan->property_id for timeline queries'",
    "ALTER TABLE visit_photos ADD COLUMN uploaded_by_name VARCHAR(120) NULL
        COMMENT 'users.full_name snapshot at upload time'",
    "ALTER TABLE visit_photos ADD COLUMN service_type VARCHAR(60) NULL
        COMMENT 'job_plans.service_type snapshot'",
    "ALTER TABLE visit_photos ADD COLUMN sha256 CHAR(64) NULL
        COMMENT 'File hash for dedup detection'",
    "ALTER TABLE visit_photos ADD COLUMN work_zone_id INT NULL
        COMMENT 'Geofence work zone classification at upload time'",

    // ── GPS persistence (was computed but thrown away) ─────────────────────
    "ALTER TABLE visit_photos ADD COLUMN gps_lat DECIMAL(10,7) NULL
        COMMENT 'Phone latitude at upload time'",
    "ALTER TABLE visit_photos ADD COLUMN gps_lng DECIMAL(10,7) NULL
        COMMENT 'Phone longitude at upload time'",
    "ALTER TABLE visit_photos ADD COLUMN gps_accuracy_m DECIMAL(6,1) NULL
        COMMENT 'GPS accuracy radius in meters'",
    "ALTER TABLE visit_photos ADD COLUMN captured_at DATETIME NULL
        COMMENT 'EXIF capture time (vs uploaded_at which is arrival time)'",

    // ── Client portal / portfolio prep ─────────────────────────────────────
    "ALTER TABLE visit_photos ADD COLUMN consent_portfolio TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Client consent for public portfolio use. NULL=not asked, 0=declined, 1=granted'",
    "ALTER TABLE visit_photos ADD COLUMN consent_requested_at DATETIME NULL",
    "ALTER TABLE visit_photos ADD COLUMN consent_decided_at DATETIME NULL",
    "ALTER TABLE visit_photos ADD COLUMN before_after_pair_id INT NULL
        COMMENT 'Self-FK: pair a before-photo with its after-photo. Either row can reference the other.'",
    "ALTER TABLE visit_photos ADD COLUMN visibility ENUM('internal','client','public') NOT NULL DEFAULT 'client'
        COMMENT 'internal=staff only, client=visible in client portal, public=portfolio-eligible'",

    // ── Indexes for new query patterns ─────────────────────────────────────
    "CREATE INDEX idx_vp_property_id ON visit_photos(property_id)",
    "CREATE INDEX idx_vp_sha256 ON visit_photos(sha256)",
    "CREATE INDEX idx_vp_consent ON visit_photos(consent_portfolio)",
    "CREATE INDEX idx_vp_pair ON visit_photos(before_after_pair_id)",
    "CREATE INDEX idx_vp_visibility ON visit_photos(visibility)",

    // ── Backfill property_id on existing rows ──────────────────────────────
    "UPDATE visit_photos vp
        JOIN job_visits jv ON jv.id = vp.visit_id
        JOIN job_plans jp ON jp.id = jv.plan_id
        SET vp.property_id = jp.property_id
        WHERE vp.property_id IS NULL AND jp.property_id IS NOT NULL",

    // ── Backfill service_type on existing rows ─────────────────────────────
    "UPDATE visit_photos vp
        JOIN job_visits jv ON jv.id = vp.visit_id
        JOIN job_plans jp ON jp.id = jv.plan_id
        SET vp.service_type = jp.service_type
        WHERE vp.service_type IS NULL AND jp.service_type IS NOT NULL",

    // ── Backfill uploaded_by_name from users table ─────────────────────────
    "UPDATE visit_photos vp
        JOIN users u ON u.id = vp.uploaded_by
        SET vp.uploaded_by_name = u.full_name
        WHERE vp.uploaded_by_name IS NULL AND u.full_name IS NOT NULL",
];

foreach ($statements as $i => $sql) {
    try {
        $affected = $db->exec($sql);
        $snippet = substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 90);
        $results[] = "OK  [$i] {$snippet}..." . ($affected !== false ? " ({$affected} rows)" : '');
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false ||
            strpos($msg, 'Duplicate key name') !== false ||
            strpos($msg, '1060') !== false ||
            strpos($msg, '1061') !== false) {
            $results[] = "SKIP[$i] Already applied — " . substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $msg . ' | SQL: ' . substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 80);
        }
    }
}

// Summary
$total = (int)$db->query("SELECT COUNT(*) FROM visit_photos")->fetchColumn();
$withProp = (int)$db->query("SELECT COUNT(*) FROM visit_photos WHERE property_id IS NOT NULL")->fetchColumn();
$withGps = (int)$db->query("SELECT COUNT(*) FROM visit_photos WHERE gps_lat IS NOT NULL")->fetchColumn();

echo "Migration 1025 — Photos Schema Alignment:\n\n";
echo implode("\n", $results);
echo "\n\n--- Coverage ---\n";
echo "Total photos: {$total}\n";
echo "With property_id: {$withProp} (" . ($total > 0 ? round($withProp / $total * 100, 1) : 0) . "%)\n";
echo "With GPS: {$withGps} (" . ($total > 0 ? round($withGps / $total * 100, 1) : 0) . "%)\n";
echo "\nDone.\n";
