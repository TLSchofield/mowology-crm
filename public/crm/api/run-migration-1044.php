<?php
/**
 * Migration 1044 — Cluster foreign keys on existing tables.
 * ALTER TABLE calendar_stops: add cluster_id
 * ALTER TABLE job_time_entries: add cluster_session_id, time_source
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

function tryExec1044(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg     = $e->getMessage();
        $already = strpos($msg, 'Duplicate') !== false || strpos($msg, 'already exists') !== false;
        $results[] = ['step' => $label, 'status' => $already ? 'already_exists' : 'error', 'msg' => $msg];
    }
}

// calendar_stops — cluster_id column + index + FK
tryExec1044($db,
    "ALTER TABLE calendar_stops ADD COLUMN cluster_id INT NULL AFTER notes",
    'calendar_stops: add cluster_id', $results);

tryExec1044($db,
    "ALTER TABLE calendar_stops ADD INDEX idx_cluster (cluster_id)",
    'calendar_stops: add cluster_id index', $results);

tryExec1044($db,
    "ALTER TABLE calendar_stops ADD CONSTRAINT fk_cs_cluster FOREIGN KEY (cluster_id) REFERENCES property_clusters(id) ON DELETE SET NULL",
    'calendar_stops: add cluster FK', $results);

// job_time_entries — cluster_session_id column + index + FK
tryExec1044($db,
    "ALTER TABLE job_time_entries ADD COLUMN cluster_session_id INT NULL AFTER visit_id",
    'job_time_entries: add cluster_session_id', $results);

tryExec1044($db,
    "ALTER TABLE job_time_entries ADD INDEX idx_cluster_session (cluster_session_id)",
    'job_time_entries: add cluster_session_id index', $results);

tryExec1044($db,
    "ALTER TABLE job_time_entries ADD CONSTRAINT fk_jte_cluster_session FOREIGN KEY (cluster_session_id) REFERENCES cluster_sessions(id) ON DELETE SET NULL",
    'job_time_entries: add cluster_session FK', $results);

// job_time_entries — time_source column
tryExec1044($db,
    "ALTER TABLE job_time_entries ADD COLUMN time_source ENUM('gps','manual','cluster_apportioned','travel_apportioned','photo_inferred','estimated') NOT NULL DEFAULT 'gps' AFTER auto_started",
    'job_time_entries: add time_source', $results);

$ok = !in_array('error', array_column($results, 'status'));
echo json_encode(['ok' => $ok, 'results' => $results], JSON_PRETTY_PRINT);
