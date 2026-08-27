<?php
/**
 * Run Migration 1114: Crew Service Recommendations
 *
 * Extends the Field Observations feature (602/605) so crew can photograph work
 * that needs doing, tap a service package, and generate a priced Quote.
 *
 * Self-healing: migration 602 never had a runner, so this creates its tables if
 * they are absent and only adds the new columns if they are missing. Safe to run
 * repeatedly. Admin only.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();

/** Does $table.$column already exist? */
function mw1114HasColumn(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

/** Does $table already carry an index named $index? */
function mw1114HasIndex(PDO $db, string $table, string $index): bool
{
    $stmt = $db->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
    $stmt->execute([$index]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

$applied = [];
$skipped = [];

try {
    // ── Safety net: ensure the 602 tables exist ──────────────────────────────
    $existingTables = [];
    foreach (['field_observations', 'observation_product_rules'] as $t) {
        $existingTables[$t] = (bool)$db->query("SHOW TABLES LIKE " . $db->quote($t))->fetch();
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS field_observations (
          id INT AUTO_INCREMENT PRIMARY KEY,
          visit_id INT NULL,
          property_id INT NOT NULL,
          contact_id INT NOT NULL,
          observation_type VARCHAR(50) NOT NULL,
          observation_value VARCHAR(255) NULL,
          notes TEXT NULL,
          photo_media_id INT NULL,
          recommended_product_id INT NULL,
          status VARCHAR(20) DEFAULT 'pending',
          auto_send TINYINT(1) DEFAULT 0,
          email_sent_at DATETIME NULL,
          dismissed_reason VARCHAR(255) NULL,
          created_by INT NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_status (status),
          KEY idx_contact (contact_id),
          KEY idx_property (property_id),
          KEY idx_product (recommended_product_id),
          KEY idx_visit (visit_id),
          KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $applied[] = $existingTables['field_observations']
        ? 'field_observations already existed'
        : 'CREATED field_observations (602 had never been applied)';

    $db->exec("
        CREATE TABLE IF NOT EXISTS observation_product_rules (
          id INT AUTO_INCREMENT PRIMARY KEY,
          observation_type VARCHAR(50) NOT NULL,
          condition_match VARCHAR(255) NULL,
          recommended_product_id INT NOT NULL,
          email_subject VARCHAR(255) NOT NULL,
          email_body_template TEXT NOT NULL,
          auto_send TINYINT(1) DEFAULT 0,
          is_active TINYINT(1) DEFAULT 1,
          priority INT DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_type (observation_type),
          KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $applied[] = $existingTables['observation_product_rules']
        ? 'observation_product_rules already existed'
        : 'CREATED observation_product_rules (602 had never been applied)';

    // ── Column additions ─────────────────────────────────────────────────────
    $columns = [
        ['products', 'field_recommendable', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = tappable chip on the crew job card'"],
        ['products', 'field_auto_send',     "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = fixed-price, emails client without office review'"],
        ['products', 'field_label',         "VARCHAR(80) NULL COMMENT 'Short chip text; falls back to name'"],
        ['products', 'field_sort_order',    "INT NOT NULL DEFAULT 0"],
        ['field_observations', 'quote_id',          "INT NULL COMMENT 'FK to quotes'"],
        ['field_observations', 'recommended_price', "DECIMAL(10,2) NULL COMMENT 'Price snapshot at capture time'"],
        ['field_observations', 'auto_sent',         "TINYINT(1) NOT NULL DEFAULT 0"],
        ['field_observations', 'source',            "VARCHAR(20) NOT NULL DEFAULT 'observation' COMMENT 'observation | service'"],
    ];

    foreach ($columns as [$table, $column, $definition]) {
        if (mw1114HasColumn($db, $table, $column)) {
            $skipped[] = "{$table}.{$column} (already present)";
            continue;
        }
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        $applied[] = "{$table}.{$column}";
    }

    // ── Indexes (MySQL 5.7 has no CREATE INDEX IF NOT EXISTS) ────────────────
    $indexes = [
        ['field_observations', 'idx_fo_status_created',   '(status, created_at)'],
        ['field_observations', 'idx_fo_dup_guard',        '(property_id, recommended_product_id, created_at)'],
        ['products',           'idx_products_field_reco', '(field_recommendable, field_sort_order)'],
    ];

    foreach ($indexes as [$table, $index, $cols]) {
        if (mw1114HasIndex($db, $table, $index)) {
            $skipped[] = "{$table}.{$index} (already present)";
            continue;
        }
        $db->exec("CREATE INDEX `{$index}` ON `{$table}` {$cols}");
        $applied[] = "{$table}.{$index}";
    }

    echo json_encode([
        'success'   => true,
        'migration' => '1114',
        'applied'   => $applied,
        'skipped'   => $skipped,
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'migration' => '1114',
        'applied'   => $applied,
        'error'     => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
