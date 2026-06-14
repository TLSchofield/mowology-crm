<?php
/**
 * Run Migration 1042 — Purchase Tasks: vendor_id + vendor_location_id FKs
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
requirePermission('admin');

$db = getDB();
header('Content-Type: text/plain');

$steps = [];

try {
    // vendor_id
    $cols = $db->query("SHOW COLUMNS FROM purchase_tasks LIKE 'vendor_id'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE purchase_tasks ADD COLUMN vendor_id INT NULL DEFAULT NULL AFTER location_label");
        $steps[] = "Added vendor_id";
    } else {
        $steps[] = "vendor_id already exists — skipped";
    }

    // vendor_location_id
    $cols = $db->query("SHOW COLUMNS FROM purchase_tasks LIKE 'vendor_location_id'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE purchase_tasks ADD COLUMN vendor_location_id INT NULL DEFAULT NULL AFTER vendor_id");
        $steps[] = "Added vendor_location_id";
    } else {
        $steps[] = "vendor_location_id already exists — skipped";
    }

    // Indexes
    foreach ([
        ['idx_pt_vendor',     'ALTER TABLE purchase_tasks ADD INDEX idx_pt_vendor (vendor_id)'],
        ['idx_pt_vendor_loc', 'ALTER TABLE purchase_tasks ADD INDEX idx_pt_vendor_loc (vendor_location_id)'],
    ] as [$name, $sql]) {
        try {
            $db->exec($sql);
            $steps[] = "Added {$name}";
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate key name')) {
                $steps[] = "{$name} already exists — skipped";
            } else throw $e;
        }
    }

    // FK constraints
    foreach ([
        ['fk_pt_vendor',          "ALTER TABLE purchase_tasks ADD CONSTRAINT fk_pt_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL"],
        ['fk_pt_vendor_location', "ALTER TABLE purchase_tasks ADD CONSTRAINT fk_pt_vendor_location FOREIGN KEY (vendor_location_id) REFERENCES vendor_locations(id) ON DELETE SET NULL"],
    ] as [$name, $sql]) {
        try {
            $db->exec($sql);
            $steps[] = "Added {$name}";
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate key name') || str_contains($e->getMessage(), 'already exists')) {
                $steps[] = "{$name} already exists — skipped";
            } else throw $e;
        }
    }

    echo "Migration 1042 complete.\n\n";
    foreach ($steps as $s) echo "  • {$s}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n\nCompleted steps so far:\n";
    foreach ($steps as $s) echo "  • {$s}\n";
}
