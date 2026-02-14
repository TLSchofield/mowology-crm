<?php
/**
 * Migration 300: Unified Media System
 *
 * Adds new columns to media_assets and creates media_links, media_variants, media_derivatives tables.
 * Safe to run multiple times (uses IF NOT EXISTS and column existence checks).
 *
 * Usage: Visit this URL as admin in your browser.
 */

require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required.');
}

$db = getDB();
$results = [];

// Helper: check if column exists
function colExists(PDO $db, string $table, string $col): bool {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeCol = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
    $stmt = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
    return $stmt && $stmt->rowCount() > 0;
}

// Helper: check if table exists
function tblExists(PDO $db, string $table): bool {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $stmt = $db->query("SHOW TABLES LIKE '{$safeTable}'");
    return $stmt && $stmt->rowCount() > 0;
}

// --- 1. ALTER media_assets ---
$newCols = [
    'uuid'             => "CHAR(36) NULL AFTER id",
    'sha256'           => "CHAR(64) NULL COMMENT 'SHA-256 hash for dedup'",
    'context_type'     => "VARCHAR(50) DEFAULT 'cms' COMMENT 'Primary context'",
    'captured_at'      => "DATETIME NULL COMMENT 'When photo was taken (EXIF)'",
    'gps_lat'          => "DECIMAL(10,8) NULL",
    'gps_lng'          => "DECIMAL(11,8) NULL",
    'gps_accuracy_m'   => "DECIMAL(8,2) NULL",
    'gps_source'       => "VARCHAR(20) DEFAULT 'none'",
    'address_text'     => "VARCHAR(500) NULL",
    'address_source'   => "VARCHAR(20) DEFAULT 'none'",
    'pow_stamp_applied'=> "TINYINT(1) DEFAULT 0",
    'original_bytes'   => "BIGINT UNSIGNED NULL",
    'status'           => "VARCHAR(20) DEFAULT 'ready'",
];

foreach ($newCols as $col => $def) {
    if (!colExists($db, 'media_assets', $col)) {
        try {
            $db->exec("ALTER TABLE media_assets ADD COLUMN `$col` $def");
            $results[] = "Added column media_assets.$col";
        } catch (PDOException $e) {
            $results[] = "ERROR adding media_assets.$col: " . $e->getMessage();
        }
    } else {
        $results[] = "Column media_assets.$col already exists (skipped)";
    }
}

// Add indexes (suppress errors if they exist)
$indexes = [
    "ALTER TABLE media_assets ADD UNIQUE INDEX idx_ma_uuid (uuid)",
    "ALTER TABLE media_assets ADD INDEX idx_ma_sha256 (sha256)",
    "ALTER TABLE media_assets ADD INDEX idx_ma_context_type (context_type)",
    "ALTER TABLE media_assets ADD INDEX idx_ma_captured_at (captured_at)",
    "ALTER TABLE media_assets ADD INDEX idx_ma_status (status)",
];
foreach ($indexes as $sql) {
    try {
        $db->exec($sql);
        $results[] = "Index added: " . substr($sql, 30);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            $results[] = "Index already exists (skipped)";
        } else {
            $results[] = "Index error: " . $e->getMessage();
        }
    }
}

// --- 2. media_links ---
if (!tblExists($db, 'media_links')) {
    try {
        $db->exec("
            CREATE TABLE media_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                media_id INT NOT NULL,
                context_type VARCHAR(50) NOT NULL,
                context_id INT NOT NULL DEFAULT 0,
                category VARCHAR(50) NULL,
                sort_order INT DEFAULT 0,
                visibility VARCHAR(20) DEFAULT 'internal',
                notes TEXT NULL,
                linked_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ml_media (media_id),
                INDEX idx_ml_context (context_type, context_id),
                INDEX idx_ml_category (category),
                INDEX idx_ml_sort (context_type, context_id, sort_order),
                FOREIGN KEY (media_id) REFERENCES media_assets(id) ON DELETE CASCADE,
                FOREIGN KEY (linked_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $results[] = "Created table: media_links";
    } catch (PDOException $e) {
        $results[] = "ERROR creating media_links: " . $e->getMessage();
    }
} else {
    $results[] = "Table media_links already exists (skipped)";
}

// --- 3. media_variants ---
if (!tblExists($db, 'media_variants')) {
    try {
        $db->exec("
            CREATE TABLE media_variants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                media_id INT NOT NULL,
                variant_type VARCHAR(30) NOT NULL,
                format VARCHAR(10) NOT NULL,
                width INT UNSIGNED NOT NULL,
                height INT UNSIGNED NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_size BIGINT UNSIGNED NULL,
                quality TINYINT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_mv_media_type (media_id, variant_type),
                INDEX idx_mv_media_format (media_id, format),
                UNIQUE INDEX idx_mv_unique (media_id, variant_type, width, format),
                FOREIGN KEY (media_id) REFERENCES media_assets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $results[] = "Created table: media_variants";
    } catch (PDOException $e) {
        $results[] = "ERROR creating media_variants: " . $e->getMessage();
    }
} else {
    $results[] = "Table media_variants already exists (skipped)";
}

// --- 4. media_derivatives ---
if (!tblExists($db, 'media_derivatives')) {
    try {
        $db->exec("
            CREATE TABLE media_derivatives (
                id INT AUTO_INCREMENT PRIMARY KEY,
                media_id INT NOT NULL,
                derivative_type VARCHAR(50) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_size BIGINT UNSIGNED NULL,
                width INT UNSIGNED NULL,
                height INT UNSIGNED NULL,
                stamp_data TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_md_media (media_id),
                INDEX idx_md_type (derivative_type),
                FOREIGN KEY (media_id) REFERENCES media_assets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $results[] = "Created table: media_derivatives";
    } catch (PDOException $e) {
        $results[] = "ERROR creating media_derivatives: " . $e->getMessage();
    }
} else {
    $results[] = "Table media_derivatives already exists (skipped)";
}

// --- 5. Backfill UUIDs for existing media_assets ---
try {
    $nullUuids = $db->query("SELECT id FROM media_assets WHERE uuid IS NULL");
    $count = 0;
    $updateStmt = $db->prepare("UPDATE media_assets SET uuid = ? WHERE id = ?");
    while ($row = $nullUuids->fetch(PDO::FETCH_ASSOC)) {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        $updateStmt->execute([$uuid, $row['id']]);
        $count++;
    }
    $results[] = "Backfilled UUIDs for $count existing media_assets rows";
} catch (PDOException $e) {
    $results[] = "UUID backfill error: " . $e->getMessage();
}

// --- 6. Track migration ---
try {
    $db->exec("INSERT INTO migrations_log (migration_file, executed_at) VALUES ('300_unified_media_system.sql', NOW()) ON DUPLICATE KEY UPDATE executed_at = NOW()");
    $results[] = "Migration logged in migrations_log";
} catch (PDOException $e) {
    $results[] = "Migration log entry skipped: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html><head><title>Migration 300 Results</title></head>
<body style="font-family:monospace;padding:2rem;max-width:800px;margin:0 auto;">
<h2>Migration 300: Unified Media System</h2>
<ul>
<?php foreach ($results as $r): ?>
    <li style="margin-bottom:4px;<?php echo strpos($r, 'ERROR') !== false ? 'color:red;font-weight:bold;' : ''; ?>">
        <?php echo htmlspecialchars($r); ?>
    </li>
<?php endforeach; ?>
</ul>
<p><a href="/cms/cms-media_appstack.php">Back to Media Library</a></p>
</body></html>
