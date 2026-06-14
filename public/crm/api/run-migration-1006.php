<?php
/**
 * Migration 1006 runner — is_favorite on visit_photos
 * Admin-only. Safe to run multiple times.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

try {
    $cols = $db->query("SHOW COLUMNS FROM visit_photos LIKE 'is_favorite'")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($cols)) {
        $db->exec("ALTER TABLE visit_photos ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0");
        $results[] = ['ok', 'Added is_favorite column to visit_photos'];
    } else {
        $results[] = ['skip', 'is_favorite column already exists — skipped'];
    }

    $idx = $db->query("SHOW INDEX FROM visit_photos WHERE Key_name = 'idx_visit_photos_is_favorite'")->fetchAll();
    if (empty($idx)) {
        $db->exec("CREATE INDEX idx_visit_photos_is_favorite ON visit_photos (is_favorite)");
        $results[] = ['ok', 'Created index on is_favorite'];
    } else {
        $results[] = ['skip', 'Index already exists — skipped'];
    }

} catch (Exception $e) {
    $results[] = ['err', 'Error: ' . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Migration 1006</title>
<style>body{font-family:monospace;padding:2rem;background:#f8f9fa;}h2{margin-bottom:1rem;}
.ok{color:#198754;}.skip{color:#6c757d;}.err{color:#dc3545;font-weight:700;}
</style></head><body>
<h2>Migration 1006 — visit_photos.is_favorite</h2>
<?php foreach ($results as [$type, $msg]): ?>
<p class="<?= $type ?>">
  <?= $type === 'ok' ? '✓' : ($type === 'err' ? '✗' : '·') ?>
  <?= htmlspecialchars($msg) ?>
</p>
<?php endforeach; ?>
<hr><p><a href="/crm/database_appstack.php">← Database Manager</a></p>
</body></html>
