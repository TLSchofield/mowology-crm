<?php
/**
 * Migration 1043 — Property cluster tables.
 * Creates: property_clusters, property_cluster_members, cluster_sessions
 */
declare(strict_types=1);
header('Content-Type: application/json');

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
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db      = getDB();
$results = [];

function tryExec1043(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg     = $e->getMessage();
        $already = strpos($msg, 'Duplicate') !== false || strpos($msg, 'already exists') !== false;
        $results[] = ['step' => $label, 'status' => $already ? 'already_exists' : 'error', 'msg' => $msg];
    }
}

tryExec1043($db, "
CREATE TABLE property_clusters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    notes TEXT NULL,
    default_travel_minutes TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'create property_clusters', $results);

tryExec1043($db, "
CREATE TABLE property_cluster_members (
    cluster_id INT NOT NULL,
    property_id INT NOT NULL,
    apportionment_override DECIMAL(5,2) NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (cluster_id, property_id),
    INDEX idx_property (property_id),
    FOREIGN KEY (cluster_id) REFERENCES property_clusters(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'create property_cluster_members', $results);

tryExec1043($db, "
CREATE TABLE cluster_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cluster_id INT NOT NULL,
    session_date DATE NOT NULL,
    crew_id INT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    total_minutes INT NULL,
    travel_minutes TINYINT UNSIGNED NOT NULL DEFAULT 0,
    time_source ENUM('gps','manual','photo_inferred') NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cluster_date_crew (cluster_id, session_date, crew_id),
    INDEX idx_cluster (cluster_id),
    INDEX idx_date (session_date),
    INDEX idx_crew_date (crew_id, session_date),
    FOREIGN KEY (cluster_id) REFERENCES property_clusters(id) ON DELETE CASCADE,
    FOREIGN KEY (crew_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'create cluster_sessions', $results);

$ok = !in_array('error', array_column($results, 'status'));
echo json_encode(['ok' => $ok, 'results' => $results], JSON_PRETTY_PRINT);
