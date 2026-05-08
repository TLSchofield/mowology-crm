<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

$steps = [
    "CREATE TABLE IF NOT EXISTS visit_crew_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visit_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(50) DEFAULT 'crew',
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_visit_user (visit_id, user_id),
        INDEX idx_visit (visit_id),
        INDEX idx_user (user_id),
        FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
];

foreach ($steps as $sql) {
    try {
        $db->exec($sql);
        $results[] = ['sql' => substr($sql, 0, 80), 'status' => 'OK'];
    } catch (PDOException $e) {
        $results[] = ['sql' => substr($sql, 0, 80), 'status' => 'Note: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html><html><head><title>Migration 992</title></head><body>
<h2>Migration 992 — visit_crew_assignments table</h2>
<table border="1" cellpadding="8">
<tr><th>SQL</th><th>Status</th></tr>
<?php foreach ($results as $r): ?>
<tr><td><?= htmlspecialchars($r['sql']) ?>…</td><td><?= htmlspecialchars($r['status']) ?></td></tr>
<?php endforeach; ?>
</table>
<p><a href="/crm/jobs/">← Jobs</a></p>
</body></html>
