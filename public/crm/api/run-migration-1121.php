<?php
/**
 * Run Migration 1121: Per-crew visit endorsements
 *
 * Creates visit_endorsements and credits every already-endorsed visit to the crew
 * member it was assigned to. Idempotent — safe to run twice.
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
session_write_close();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS visit_endorsements (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            visit_id    INT NOT NULL,
            user_id     INT NOT NULL,
            created_at  DATETIME NOT NULL,
            UNIQUE KEY uq_visit_user (visit_id, user_id),
            KEY idx_ve_user (user_id),
            CONSTRAINT fk_ve_visit FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE,
            CONSTRAINT fk_ve_user  FOREIGN KEY (user_id)  REFERENCES users(id)      ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = ['step' => 'visit_endorsements table', 'status' => 'ok'];

    $inserted = $db->exec("
        INSERT IGNORE INTO visit_endorsements (visit_id, user_id, created_at)
        SELECT jv.id, jv.assigned_crew_id, COALESCE(jv.completed_at, NOW())
        FROM job_visits jv
        JOIN users u ON u.id = jv.assigned_crew_id
        WHERE jv.is_flagged = 1
    ");
    $results[] = ['step' => 'backfill endorsed visits', 'status' => 'ok', 'rows' => (int)$inserted];

    $orphans = (int)$db->query("
        SELECT COUNT(*) FROM job_visits jv
        WHERE jv.is_flagged = 1
          AND NOT EXISTS (SELECT 1 FROM visit_endorsements ve WHERE ve.visit_id = jv.id)
    ")->fetchColumn();
    $results[] = [
        'step'   => 'endorsed visits with nobody to credit',
        'status' => 'info',
        'rows'   => $orphans,
        'note'   => 'These keep their endorsement; they just show no name.',
    ];

    echo json_encode(['success' => true, 'results' => $results]);
} catch (Throwable $e) {
    error_log('run-migration-1121: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'results' => $results]);
}
