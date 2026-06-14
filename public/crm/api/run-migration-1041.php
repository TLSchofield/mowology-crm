<?php
/**
 * Run Migration 1041 — Purchase Tasks: contact_id + property_id
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
requirePermission('admin');

$db = getDB();
header('Content-Type: text/plain');

$steps = [];

try {
    // Check contact_id
    $cols = $db->query("SHOW COLUMNS FROM purchase_tasks LIKE 'contact_id'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE purchase_tasks ADD COLUMN contact_id INT NULL DEFAULT NULL AFTER notes");
        $steps[] = "Added contact_id";
    } else {
        $steps[] = "contact_id already exists — skipped";
    }

    // Check property_id
    $cols = $db->query("SHOW COLUMNS FROM purchase_tasks LIKE 'property_id'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE purchase_tasks ADD COLUMN property_id INT NULL DEFAULT NULL AFTER contact_id");
        $steps[] = "Added property_id";
    } else {
        $steps[] = "property_id already exists — skipped";
    }

    // Indexes (CREATE INDEX doesn't support IF NOT EXISTS in MySQL 8)
    try {
        $db->exec("ALTER TABLE purchase_tasks ADD INDEX idx_pt_contact (contact_id)");
        $steps[] = "Added idx_pt_contact";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate key name')) {
            $steps[] = "idx_pt_contact already exists — skipped";
        } else throw $e;
    }

    try {
        $db->exec("ALTER TABLE purchase_tasks ADD INDEX idx_pt_property (property_id)");
        $steps[] = "Added idx_pt_property";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate key name')) {
            $steps[] = "idx_pt_property already exists — skipped";
        } else throw $e;
    }

    // FK constraints
    try {
        $db->exec("ALTER TABLE purchase_tasks ADD CONSTRAINT fk_pt_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL");
        $steps[] = "Added fk_pt_contact";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate key name') || str_contains($e->getMessage(), 'already exists')) {
            $steps[] = "fk_pt_contact already exists — skipped";
        } else throw $e;
    }

    try {
        $db->exec("ALTER TABLE purchase_tasks ADD CONSTRAINT fk_pt_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL");
        $steps[] = "Added fk_pt_property";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate key name') || str_contains($e->getMessage(), 'already exists')) {
            $steps[] = "fk_pt_property already exists — skipped";
        } else throw $e;
    }

    echo "Migration 1041 complete.\n\n";
    foreach ($steps as $s) echo "  • {$s}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n\nCompleted steps so far:\n";
    foreach ($steps as $s) echo "  • {$s}\n";
}
