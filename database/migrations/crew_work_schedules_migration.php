<?php
/**
 * Migration: crew_work_schedules
 * Creates the table for admin-defined recurring weekly work schedules per employee.
 * Run once via browser (admin only), then delete this file.
 */
require_once dirname(__DIR__, 2) . '/public/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if ($user['role'] !== 'admin') die('Admin only.');

$db = getDB();

$sql = "
CREATE TABLE IF NOT EXISTS crew_work_schedules (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED    NOT NULL,
    day_of_week   TINYINT UNSIGNED NOT NULL COMMENT '0=Sun 1=Mon 2=Tue 3=Wed 4=Thu 5=Fri 6=Sat',
    start_time    TIME            NOT NULL,
    end_time      TIME            NOT NULL,
    notes         VARCHAR(255)    NULL,
    created_by    INT UNSIGNED    NOT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_user_day (user_id, day_of_week),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

try {
    $db->exec($sql);
    echo '<pre>Migration successful: crew_work_schedules table created (or already existed).</pre>';
} catch (PDOException $e) {
    echo '<pre>Error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
}
