<?php
/**
 * Run Migration 931 — CMS Phase 3
 * Creates: cms_form_submissions, cms_site_settings
 * Registers: form_capture, columns, before_after, custom, portfolio_showcase, service_grid, stats_banner block types
 * Admin-only, idempotent.
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

// 1. Create cms_form_submissions
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cms_form_submissions (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            form_id     VARCHAR(100) NOT NULL DEFAULT 'cms-contact',
            name        VARCHAR(150) NOT NULL DEFAULT '',
            email       VARCHAR(255) NOT NULL DEFAULT '',
            phone       VARCHAR(50)  DEFAULT NULL,
            message     TEXT         DEFAULT NULL,
            source_url  VARCHAR(512) DEFAULT NULL,
            ip_address  VARCHAR(45)  DEFAULT NULL,
            status      ENUM('new','read') NOT NULL DEFAULT 'new',
            read_at     DATETIME     DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cfs_form_id  (form_id),
            INDEX idx_cfs_status   (status),
            INDEX idx_cfs_created  (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = "OK   cms_form_submissions created/verified.";
} catch (PDOException $e) {
    $results[] = "ERR  cms_form_submissions: " . $e->getMessage();
}

// 2. Create cms_site_settings
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cms_site_settings (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            setting_key   VARCHAR(100) NOT NULL,
            setting_value MEDIUMTEXT   DEFAULT NULL,
            updated_at    DATETIME     DEFAULT NULL,
            UNIQUE KEY uq_css_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = "OK   cms_site_settings created/verified.";
} catch (PDOException $e) {
    $results[] = "ERR  cms_site_settings: " . $e->getMessage();
}

// 3. Register Phase 3 block types
$blockTypes = [
    ['block_type' => 'form_capture',       'label' => 'Form Capture',       'description' => 'Embeds a contact/lead capture form into any page',                    'supports_variants' => 0, 'available_variants_json' => null],
    ['block_type' => 'columns',            'label' => 'Two-Column Layout',  'description' => 'Side-by-side content columns with image, text, and optional button',  'supports_variants' => 0, 'available_variants_json' => null],
    ['block_type' => 'before_after',       'label' => 'Before / After',     'description' => 'Side-by-side image comparison pairs with captions',                   'supports_variants' => 0, 'available_variants_json' => null],
    ['block_type' => 'custom',             'label' => 'Custom HTML',        'description' => 'Raw HTML block — admin use only',                                      'supports_variants' => 0, 'available_variants_json' => null],
    ['block_type' => 'portfolio_showcase', 'label' => 'Portfolio Showcase', 'description' => 'Showcase portfolio items with images and links',                       'supports_variants' => 0, 'available_variants_json' => null],
    ['block_type' => 'service_grid',       'label' => 'Service Grid',       'description' => '3-column service overview cards with icons and links',                 'supports_variants' => 0, 'available_variants_json' => null],
    ['block_type' => 'stats_banner',       'label' => 'Stats Banner',       'description' => 'High-impact horizontal row of numbers and labels',                     'supports_variants' => 1, 'available_variants_json' => '["green","dark","light"]'],
];

$stmt = $db->prepare("
    INSERT INTO cms_block_types (block_type, label, description, is_active, supports_variants, available_variants_json, created_at)
    VALUES (?, ?, ?, 1, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        label = VALUES(label),
        description = VALUES(description),
        is_active = 1
");

foreach ($blockTypes as $bt) {
    try {
        $stmt->execute([
            $bt['block_type'],
            $bt['label'],
            $bt['description'],
            $bt['supports_variants'],
            $bt['available_variants_json'],
        ]);
        $results[] = "OK   Block type '{$bt['block_type']}' registered/updated.";
    } catch (PDOException $e) {
        $results[] = "ERR  Block type '{$bt['block_type']}': " . $e->getMessage();
    }
}

echo "Migration 931 — CMS Phase 3 (Form Capture + Site Settings + Block Types)\n";
echo str_repeat('─', 60) . "\n\n";
echo implode("\n", $results) . "\n\nDone.";
