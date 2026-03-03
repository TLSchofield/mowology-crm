<?php
/**
 * One-time Migration Runner — Migration 932
 * Creates vehicle_trip_reports table for daily pre/post trip safety inspections.
 * Run once via authenticated POST/GET, then DELETE this file.
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();
$results = [];

// ── Step 1: Create vehicle_trip_reports table ─────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS vehicle_trip_reports (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        driver_id           INT NOT NULL,
        vehicle_id          VARCHAR(30) NOT NULL DEFAULT 'RAM3500-PF8865',
        report_date         DATE NOT NULL,

        -- PRE-TRIP (completed morning)
        pre_trip_at         DATETIME,
        odometer_start      INT,

        -- Running order checklist
        chk_leaks           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Check beneath truck for leaks',
        chk_hitch           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Hitch secure & light plug attached',
        chk_rear_gate       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Rear truck gate secure',
        chk_loads_secure    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Loads secure',
        chk_trailer_lights  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Trailer lights',
        chk_truck_brakes    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Truck brakes',
        chk_truck_lights    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Truck lights',
        chk_mirrors         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Mirrors',
        chk_tire_pressure   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Tire pressure',
        chk_washer_wipers   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Washer fluids & wipers',

        -- Defects
        defects_critical    TEXT                       COMMENT 'Defects that MUST be fixed before driving',
        defect_unhitch      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Unhitch trailer required',
        defects_non_urgent  TEXT                       COMMENT 'Non-urgent defects / repairs',
        safe_to_drive       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Driver acknowledgment of safe-to-drive',

        -- POST-TRIP (completed end of day)
        post_trip_at        DATETIME,
        odometer_end        INT,
        end_of_day_remarks  TEXT,

        -- Hours of Service (MVA 37.18.01b Cycle 1)
        hos_on_duty_driving VARCHAR(100)              COMMENT 'On-duty driving time: start to finish',
        hos_on_duty_other   VARCHAR(100)              COMMENT 'On-duty other hours',
        hos_off_duty        VARCHAR(100)              COMMENT 'Off-duty hours',

        -- Status & PDF
        status              ENUM('pre_pending','pre_complete','complete') NOT NULL DEFAULT 'pre_pending',
        pdf_path            VARCHAR(500),
        pdf_generated_at    DATETIME,

        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE KEY uq_driver_date (driver_id, report_date),
        INDEX idx_report_date (report_date),
        INDEX idx_status (status),
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'vehicle_trip_reports table', 'status' => 'created_or_exists'];
} catch (PDOException $e) {
    $results[] = ['step' => 'vehicle_trip_reports table', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── Step 2: Create storage directory ─────────────────────────────────────────
$pdfDir = defined('STORAGE_ROOT') ? STORAGE_ROOT . '/pdfs/trip-reports' : null;
if ($pdfDir) {
    if (!is_dir($pdfDir)) {
        $created = @mkdir($pdfDir, 0755, true);
        $results[] = ['step' => 'storage/pdfs/trip-reports dir', 'status' => $created ? 'created' : 'failed'];
    } else {
        $results[] = ['step' => 'storage/pdfs/trip-reports dir', 'status' => 'already_exists'];
    }
} else {
    $results[] = ['step' => 'storage/pdfs/trip-reports dir', 'status' => 'skipped — STORAGE_ROOT not defined'];
}

echo json_encode(['migration' => '932', 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
