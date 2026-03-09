<?php
/**
 * Migration 1001: CMS Multi-Tenant Foundation
 *
 * Creates cms_sites and cms_site_users tables.
 * Adds site_id column to existing CMS tables.
 * Seeds mowology.ca as site_id=1.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if ($user['role'] !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

// ── 1. Create cms_sites table ─────────────────────────────────────────────────

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cms_sites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(100) NOT NULL UNIQUE,
            domain VARCHAR(255) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            tagline VARCHAR(255) DEFAULT '',
            logo_path VARCHAR(500) DEFAULT '',
            favicon_dir VARCHAR(500) DEFAULT '',
            phone_display VARCHAR(50) DEFAULT '',
            phone_tel VARCHAR(20) DEFAULT '',
            email VARCHAR(255) DEFAULT '',
            locale VARCHAR(10) DEFAULT 'en_CA',
            timezone VARCHAR(50) DEFAULT 'America/Vancouver',
            theme_id INT DEFAULT NULL,
            plan_tier VARCHAR(20) DEFAULT 'free',
            onboarding_complete TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = 'OK: Created cms_sites table';
} catch (PDOException $e) {
    $results[] = 'SKIP: cms_sites — ' . $e->getMessage();
}

// ── 2. Seed mowology.ca as site_id=1 ────────────────────────────────────────

try {
    $check = $db->query("SELECT COUNT(*) FROM cms_sites WHERE slug = 'mowology'")->fetchColumn();
    if ($check == 0) {
        $db->exec("
            INSERT INTO cms_sites (id, slug, domain, name, tagline, logo_path, phone_display, phone_tel, email, locale, timezone, plan_tier, onboarding_complete, is_active)
            VALUES (1, 'mowology', 'mowology.ca', 'Mowology', 'A HIGHER DEGREE OF SERVICE', '/assets/img/logo/mowology-logo.jpg', '778-846-9273', '7788469273', 'office@mowology.ca', 'en_CA', 'America/Vancouver', 'enterprise', 1, 1)
        ");
        $results[] = 'OK: Seeded mowology.ca as site_id=1';
    } else {
        $results[] = 'SKIP: mowology.ca already exists';
    }
} catch (PDOException $e) {
    $results[] = 'ERROR: Seeding mowology.ca — ' . $e->getMessage();
}

// ── 3. Seed talkinghands.ca as site_id=2 ─────────────────────────────────────

try {
    $check = $db->query("SELECT COUNT(*) FROM cms_sites WHERE slug = 'talkinghands'")->fetchColumn();
    if ($check == 0) {
        $db->exec("
            INSERT INTO cms_sites (slug, domain, name, tagline, phone_display, phone_tel, email, locale, timezone, plan_tier, onboarding_complete, is_active)
            VALUES ('talkinghands', 'talkinghands.ca', 'Sun Wukong Wing Tsun Kuen', 'Wing Chun Classes in Vancouver', '', '', 'info@talkinghands.ca', 'en_CA', 'America/Vancouver', 'enterprise', 0, 1)
        ");
        $results[] = 'OK: Seeded talkinghands.ca as site_id=2';
    } else {
        $results[] = 'SKIP: talkinghands.ca already exists';
    }
} catch (PDOException $e) {
    $results[] = 'ERROR: Seeding talkinghands.ca — ' . $e->getMessage();
}

// ── 4. Create cms_site_users table ────────────────────────────────────────────

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cms_site_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            site_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) DEFAULT '',
            last_name VARCHAR(100) DEFAULT '',
            role VARCHAR(20) DEFAULT 'editor',
            last_login_at TIMESTAMP NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_site_email (site_id, email),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = 'OK: Created cms_site_users table';
} catch (PDOException $e) {
    $results[] = 'SKIP: cms_site_users — ' . $e->getMessage();
}

// ── 5. Add site_id to existing CMS tables ────────────────────────────────────

$tablesNeedingSiteId = [
    'cms_pages',
    'cms_menus',
    'cms_page_redirects',
];

foreach ($tablesNeedingSiteId as $table) {
    // Check if table exists first
    try {
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
    } catch (PDOException $e) {
        $results[] = "SKIP: {$table} does not exist";
        continue;
    }

    // Add site_id column
    try {
        $db->exec("ALTER TABLE {$table} ADD COLUMN site_id INT NOT NULL DEFAULT 1");
        $results[] = "OK: Added {$table}.site_id";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "SKIP: {$table}.site_id already exists";
        } else {
            $results[] = "ERROR: {$table}.site_id — " . $e->getMessage();
        }
    }

    // Add index
    try {
        $db->exec("CREATE INDEX idx_{$table}_site_id ON {$table} (site_id)");
        $results[] = "OK: Added index on {$table}.site_id";
    } catch (PDOException $e) {
        $results[] = "SKIP: Index on {$table}.site_id — " . $e->getMessage();
    }
}

// ── 6. Add site_id to media_assets if it exists ──────────────────────────────

try {
    $db->query("SELECT 1 FROM media_assets LIMIT 1");
    try {
        $db->exec("ALTER TABLE media_assets ADD COLUMN site_id INT NOT NULL DEFAULT 1");
        $results[] = 'OK: Added media_assets.site_id';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = 'SKIP: media_assets.site_id already exists';
        } else {
            $results[] = 'ERROR: media_assets.site_id — ' . $e->getMessage();
        }
    }
    try {
        $db->exec("CREATE INDEX idx_media_assets_site_id ON media_assets (site_id)");
        $results[] = 'OK: Added index on media_assets.site_id';
    } catch (PDOException $e) {
        $results[] = 'SKIP: Index on media_assets.site_id — ' . $e->getMessage();
    }
} catch (PDOException $e) {
    $results[] = 'SKIP: media_assets table does not exist';
}

// ── 7. Add compound index for page slug lookups ──────────────────────────────

try {
    $db->exec("CREATE INDEX idx_cms_pages_site_slug ON cms_pages (site_id, slug)");
    $results[] = 'OK: Added compound index on cms_pages(site_id, slug)';
} catch (PDOException $e) {
    $results[] = 'SKIP: Compound index on cms_pages — ' . $e->getMessage();
}

// ── Output results ────────────────────────────────────────────────────────────

header('Content-Type: text/html');
echo '<h2>Migration 1001: CMS Multi-Tenant Foundation</h2>';
echo '<pre>';
foreach ($results as $r) {
    $color = str_starts_with($r, 'OK') ? 'green' : (str_starts_with($r, 'ERROR') ? 'red' : '#888');
    echo "<span style='color:{$color}'>{$r}</span>\n";
}
echo '</pre>';
echo '<p><strong>Done.</strong> ' . count($results) . ' operations.</p>';
