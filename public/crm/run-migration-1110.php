<?php
/**
 * Migration 1110 — pipeline_stages table + quotes pipeline columns.
 *
 * Found via the QA crawler: the Quotes page pipeline summary widget calls
 * /crm/api/pipeline.php?action=stats, which references
 * quotes.pipeline_stage/probability/stage_entered_at and a pipeline_stages
 * table that were never migrated — the query 500s (caught, logged, widget
 * shows "--"). See database/migrations/1110_pipeline_stages.sql.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

function colExists1110(PDO $db, string $t, string $c): bool {
    $s = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $s->execute([$t, $c]); return (bool)$s->fetchColumn();
}

$cols = [
    'pipeline_stage'   => "ALTER TABLE quotes ADD COLUMN pipeline_stage VARCHAR(50) DEFAULT NULL",
    'probability'      => "ALTER TABLE quotes ADD COLUMN probability TINYINT DEFAULT NULL",
    'stage_entered_at' => "ALTER TABLE quotes ADD COLUMN stage_entered_at DATETIME DEFAULT NULL",
    'lost_reason'      => "ALTER TABLE quotes ADD COLUMN lost_reason VARCHAR(255) DEFAULT NULL",
];
foreach ($cols as $name => $sql) {
    try {
        if (colExists1110($db, 'quotes', $name)) { $results[] = ["quotes.{$name}", 'skip (exists)']; continue; }
        $db->exec($sql);
        $results[] = ["quotes.{$name}", 'OK'];
    } catch (PDOException $e) {
        $results[] = ["quotes.{$name}", 'ERROR: ' . $e->getMessage()];
    }
}

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS pipeline_stages (
            id int NOT NULL AUTO_INCREMENT,
            stage_key varchar(50) NOT NULL,
            stage_label varchar(100) NOT NULL,
            stage_order int NOT NULL DEFAULT 0,
            stage_color varchar(20) DEFAULT '#6B7280',
            default_probability tinyint DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_stage_key (stage_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = ['pipeline_stages table', 'OK (or already existed)'];
} catch (PDOException $e) {
    $results[] = ['pipeline_stages table', 'ERROR: ' . $e->getMessage()];
}

$seed = [
    ['new', 'New', 0, '#6B7280', 10],
    ['contacted', 'Contacted', 1, '#3B82F6', 25],
    ['quoted', 'Quoted', 2, '#8B5CF6', 50],
    ['negotiating', 'Negotiating', 3, '#F59E0B', 75],
    ['closed_won', 'Closed Won', 4, '#22C55E', 100],
    ['closed_lost', 'Closed Lost', 5, '#EF4444', 0],
];
try {
    $stmt = $db->prepare("INSERT IGNORE INTO pipeline_stages (stage_key, stage_label, stage_order, stage_color, default_probability) VALUES (?, ?, ?, ?, ?)");
    $inserted = 0;
    foreach ($seed as $row) {
        $stmt->execute($row);
        $inserted += $stmt->rowCount();
    }
    $results[] = ['pipeline_stages seed', "OK ({$inserted} row(s) inserted, rest already present)"];
} catch (PDOException $e) {
    $results[] = ['pipeline_stages seed', 'ERROR: ' . $e->getMessage()];
}
?>
<!DOCTYPE html><html><head><title>Migration 1110</title></head><body>
<h2>Migration 1110 — pipeline stages</h2>
<table border="1" cellpadding="8" style="border-collapse:collapse">
<tr><th>Step</th><th>Status</th></tr>
<?php foreach ($results as $r): ?><tr><td><?= htmlspecialchars($r[0]) ?></td><td><?= htmlspecialchars($r[1]) ?></td></tr><?php endforeach; ?>
</table>
<p><a href="/crm/quotes_appstack.php">&larr; Quotes</a></p>
</body></html>
