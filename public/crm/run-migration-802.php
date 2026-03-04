<?php
/**
 * Migration 802 runner — Multi-day recurrence support
 * Run once via browser (admin only). Safe to run multiple times.
 * Delete or rename after successful run.
 *
 * Changes job_plans.recurrence_day_of_week from TINYINT to VARCHAR(20)
 * so plans can recur on multiple days (e.g. "1,3,5" = Mon/Wed/Fri).
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only.'); }

$db  = getDB();
$ok  = true;
$msg = '';

try {
    // Check current column type
    $stmt = $db->prepare("
        SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_plans' AND COLUMN_NAME = 'recurrence_day_of_week'
        LIMIT 1
    ");
    $stmt->execute();
    $col = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$col) {
        $ok  = false;
        $msg = 'Column recurrence_day_of_week not found in job_plans.';
    } elseif (stripos($col['DATA_TYPE'], 'varchar') !== false) {
        $msg = 'Already VARCHAR — migration skipped (column is: ' . $col['COLUMN_TYPE'] . ')';
    } else {
        $db->exec("
            ALTER TABLE job_plans
                MODIFY COLUMN recurrence_day_of_week VARCHAR(20) NULL
                COMMENT 'Comma-sep days 0-6: \"3\"=Wed, \"1,3,5\"=Mon/Wed/Fri. NULL=plan start day.'
        ");
        $msg = 'SUCCESS — recurrence_day_of_week changed from ' . $col['COLUMN_TYPE'] . ' to VARCHAR(20). '
             . 'Existing single-day values (e.g. 3) are preserved as strings ("3"). ';
    }
} catch (PDOException $e) {
    $ok  = false;
    $msg = 'ERROR: ' . $e->getMessage();
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Migration 802</title>
<style>body{font-family:monospace;padding:2rem;background:#f8f9fa}h1{color:#1A5F4A}
.ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:1rem;border-radius:4px;margin-top:1rem}
.err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:1rem;border-radius:4px;margin-top:1rem}
</style></head><body>
<h1>Migration 802 — Multi-day recurrence</h1>
<div class="<?php echo $ok ? 'ok' : 'err'; ?>">
  <?php echo htmlspecialchars($msg); ?>
</div>
<?php if ($ok): ?>
<p style="margin-top:1rem;color:#6c757d;font-size:.9rem;">
  Remember to delete or rename <code>run-migration-802.php</code> after verifying.
</p>
<?php endif; ?>
</body></html>
