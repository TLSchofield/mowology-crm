<?php
/**
 * Migration: Billable Extras on job_visits
 * ─────────────────────────────────────────
 * Adds extras_minutes, extras_amount, extras_note to job_visits.
 * Also seeds the default extras_rate_per_5min in ops_settings.
 *
 * Run once via: POST /crm/api/run-migration-extras.php
 * Requires admin login.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
requirePermission('settings.edit');

header('Content-Type: application/json');

$db  = getDB();
$log = [];
$ok  = true;

function colExists(PDO $db, string $table, string $col): bool {
    $r = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")->rowCount();
    return $r > 0;
}

try {
    // 1. extras_minutes
    if (!colExists($db, 'job_visits', 'extras_minutes')) {
        $db->exec("ALTER TABLE job_visits ADD COLUMN extras_minutes INT NOT NULL DEFAULT 0 COMMENT 'Billable extra time in minutes (rounded up to 5-min blocks)'");
        $log[] = '✓ Added job_visits.extras_minutes';
    } else {
        $log[] = '– job_visits.extras_minutes already exists';
    }

    // 2. extras_amount
    if (!colExists($db, 'job_visits', 'extras_amount')) {
        $db->exec("ALTER TABLE job_visits ADD COLUMN extras_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Billable extras dollar value'");
        $log[] = '✓ Added job_visits.extras_amount';
    } else {
        $log[] = '– job_visits.extras_amount already exists';
    }

    // 3. extras_note
    if (!colExists($db, 'job_visits', 'extras_note')) {
        $db->exec("ALTER TABLE job_visits ADD COLUMN extras_note TEXT NULL COMMENT 'Crew note to client — appears at top of invoice email'");
        $log[] = '✓ Added job_visits.extras_note';
    } else {
        $log[] = '– job_visits.extras_note already exists';
    }

    // 4. Seed default extras rate in ops_settings (5.00 CAD per 5-min block = $60/hr)
    $db->prepare("
        INSERT INTO ops_settings (setting_key, setting_value, description, updated_by)
        VALUES ('extras_rate_per_5min', '5.00', 'Billable extras rate per 5-minute block (CAD)', ?)
        ON DUPLICATE KEY UPDATE description = VALUES(description)
    ")->execute([$_SESSION['user_id'] ?? 1]);
    $log[] = '✓ Seeded ops_settings.extras_rate_per_5min (default $5.00 — update in Settings)';

} catch (PDOException $e) {
    $ok  = false;
    $log[] = '✗ ERROR: ' . $e->getMessage();
}

echo json_encode(['success' => $ok, 'log' => $log]);
