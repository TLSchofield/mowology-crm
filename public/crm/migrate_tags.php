<?php
/**
 * Migration: Create unified tag system (tags + entity_tags)
 *
 * Run once on production, then delete this file.
 * URL: /crm/migrate_tags.php
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if ($user['role'] !== 'admin') {
    die('Admin only');
}

$db = getDB();
$messages = [];

try {
    // ── 1. Tags vocabulary table ──────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS tags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tag_key VARCHAR(100) NOT NULL UNIQUE,
            tag_label VARCHAR(100) NOT NULL,
            tag_group VARCHAR(50) NOT NULL,
            tag_color VARCHAR(7) DEFAULT '#6B7280',
            icon VARCHAR(30) NULL,
            show_on_card TINYINT(1) DEFAULT 0,
            has_value TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_group (tag_group),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $messages[] = '✅ tags table created (or already exists)';

    // ── 2. Entity tags junction table ─────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS entity_tags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(30) NOT NULL,
            entity_id INT NOT NULL,
            tag_id INT NOT NULL,
            tag_value VARCHAR(255) NULL,
            tagged_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE INDEX idx_unique (entity_type, entity_id, tag_id),
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_tag (tag_id),
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
            FOREIGN KEY (tagged_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $messages[] = '✅ entity_tags table created (or already exists)';

    // ── 3. Seed data ──────────────────────────────────────────────────
    $seeds = [
        // Property access/warnings (show on mobile card)
        ['gate_code',    'Gate Code',           'property_access',  '#3b82f6', 'key',            1, 1, 1],
        ['access_note',  'Access Instructions', 'property_access',  '#10b981', 'unlock',         1, 1, 2],
        ['parking',      'Parking',             'property_access',  '#6b7280', 'square',         1, 1, 3],
        ['dog_warning',  'Dog Warning',         'property_warning', '#f59e0b', 'alert-triangle', 1, 1, 4],
        ['contact_note', 'Contact Note',        'property_access',  '#6366f1', 'bell',           1, 1, 5],

        // Service tags
        ['lawn',         'Lawn',                'service',    '#22c55e', null, 0, 0, 1],
        ['hedge',        'Hedge',               'service',    '#16a34a', null, 0, 0, 2],
        ['flowers',      'Flowers',             'service',    '#ec4899', null, 0, 0, 3],
        ['pruning',      'Pruning',             'service',    '#84cc16', null, 0, 0, 4],
        ['cleanup',      'Cleanup',             'service',    '#eab308', null, 0, 0, 5],

        // Condition tags
        ['chafer',       'Chafer Beetle',       'condition',  '#ef4444', null, 0, 0, 1],
        ['pest',         'Pest Issue',          'condition',  '#ef4444', null, 0, 0, 2],
        ['irrigation',   'Irrigation',          'condition',  '#0ea5e9', null, 0, 0, 3],

        // Media tags
        ['favorite',     'Favorite',            'media',      '#ef4444', 'heart', 0, 0, 1],
        ['hero',         'Hero Shot',           'media',      '#8b5cf6', 'star',  0, 0, 2],
        ['insta',        'Instagram',           'media',      '#e11d48', null,    0, 0, 3],
        ['portfolio',    'Portfolio',            'media',      '#0d9488', null,    0, 0, 4],
    ];

    $insertStmt = $db->prepare("
        INSERT IGNORE INTO tags (tag_key, tag_label, tag_group, tag_color, icon, show_on_card, has_value, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $inserted = 0;
    foreach ($seeds as $seed) {
        $insertStmt->execute($seed);
        if ($insertStmt->rowCount() > 0) {
            $inserted++;
        }
    }
    $messages[] = "✅ Seeded {$inserted} new tags (skipped existing)";

    // ── Summary ───────────────────────────────────────────────────────
    $tagCount = $db->query("SELECT COUNT(*) FROM tags")->fetchColumn();
    $messages[] = "📊 Total tags in vocabulary: {$tagCount}";

} catch (PDOException $e) {
    $messages[] = '❌ Error: ' . $e->getMessage();
}

header('Content-Type: text/plain');
echo "Tag System Migration\n";
echo str_repeat('=', 40) . "\n\n";
echo implode("\n", $messages) . "\n";
echo "\n✅ Done. Delete this file after verifying.\n";
