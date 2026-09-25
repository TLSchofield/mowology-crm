<?php
declare(strict_types=1);
header('Content-Type: application/json');

// Bootstrap — upward search for app/Core/paths.php
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
session_write_close();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

function tryCreate(PDO $db, string $sql, string $label, array &$results): void
{
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'created'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || $e->getCode() === '42S01') {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $msg];
        }
    }
}

function trySeed(PDO $db, string $sql, string $label, array &$results): void
{
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'seeded'];
    } catch (PDOException $e) {
        $results[] = ['step' => $label, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

// ── 1. referral_links ─────────────────────────────────────────────────────────
tryCreate($db, "
    CREATE TABLE referral_links (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        contact_id          INT NOT NULL,
        referral_code       VARCHAR(20) NOT NULL,
        visits              INT DEFAULT 0,
        invites_sent        INT DEFAULT 0,
        last_invite_sent_at DATETIME NULL,
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code (referral_code),
        INDEX idx_contact (contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'referral_links table', $results);

// ── 2. referrals ──────────────────────────────────────────────────────────────
tryCreate($db, "
    CREATE TABLE referrals (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        referral_code        VARCHAR(20) NOT NULL,
        referrer_contact_id  INT NOT NULL,
        referred_name        VARCHAR(150) NULL,
        referred_email       VARCHAR(200) NULL,
        referred_phone       VARCHAR(30)  NULL,
        quote_request_id     INT NULL,
        referred_contact_id  INT NULL,
        first_visit_id       INT NULL,
        status               ENUM('pending','converted','rewarded','cancelled') NOT NULL DEFAULT 'pending',
        first_job_completed_at DATETIME NULL,
        reward_issued_at     DATETIME NULL,
        created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_code     (referral_code),
        INDEX idx_referrer (referrer_contact_id),
        INDEX idx_referred (referred_contact_id),
        INDEX idx_status   (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'referrals table', $results);

// ── 3. referral_rewards ───────────────────────────────────────────────────────
tryCreate($db, "
    CREATE TABLE referral_rewards (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        referral_id          INT NOT NULL,
        contact_id           INT NOT NULL,
        reward_type          ENUM('free_product_trial','credit','discount') NOT NULL DEFAULT 'free_product_trial',
        reward_product_id    INT NULL,
        reward_value         DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        status               ENUM('pending','applied','cancelled') NOT NULL DEFAULT 'pending',
        applied_to_invoice_id INT NULL,
        admin_note           TEXT NULL,
        created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_referral (referral_id),
        INDEX idx_contact  (contact_id),
        INDEX idx_status   (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'referral_rewards table', $results);

// ── 4. ops_settings seeds ─────────────────────────────────────────────────────
$seeds = [
    ['referral_program_enabled',      '0',               'Enable/disable the referral program (0 or 1)'],
    ['referral_program_name',         'Refer a Friend',  'Display name shown in referral emails'],
    ['referral_reward_type',          'free_product_trial', 'Reward type: free_product_trial | credit | discount'],
    ['referral_reward_product_id',    '',                'Product ID to offer as free trial reward (blank = auto-suggest)'],
    ['referral_referred_reward_value','0.00',            'Optional reward value for the referred client (0 = none)'],
];

foreach ($seeds as [$key, $value, $description]) {
    trySeed($db, "
        INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
        VALUES (" . $db->quote($key) . ", " . $db->quote($value) . ", " . $db->quote($description) . ")
    ", "ops_settings.{$key}", $results);
}

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
