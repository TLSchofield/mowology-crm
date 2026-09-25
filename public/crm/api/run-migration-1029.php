<?php
/**
 * Migration 1029 — Purchase Tasks (Procurement Workflow)
 *
 * Creates two tables:
 *   purchase_tasks       — vendor runs / supply pickups visible on schedule
 *   purchase_task_items  — individual items within a purchase task
 *
 * Idempotent — checks information_schema before CREATE TABLE.
 * Run once via: /crm/api/run-migration-1029.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1029_table_exists(PDO $db, string $table): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function mig1029_run(PDO $db, string $label, string $sql): string {
    try {
        $db->exec($sql);
        return "OK {$label}";
    } catch (\PDOException $e) {
        return "ERR {$label} — " . $e->getMessage();
    }
}

// ── 1. purchase_tasks ──────────────────────────────────────────────────────────
if (mig1029_table_exists($db, 'purchase_tasks')) {
    $log[] = "SKIP purchase_tasks — table already exists";
} else {
    $log[] = mig1029_run($db, 'CREATE purchase_tasks', "
        CREATE TABLE purchase_tasks (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            task_number         VARCHAR(20)   NOT NULL DEFAULT '',
            title               VARCHAR(255)  NOT NULL DEFAULT '',
            task_date           DATE          NOT NULL,
            vendor_name         VARCHAR(255)  NULL,
            location_address    VARCHAR(512)  NULL,
            location_label      VARCHAR(255)  NULL,
            lat                 DECIMAL(10,7) NULL,
            lng                 DECIMAL(11,7) NULL,
            purchase_status     ENUM('pending','in_transit','purchased','cancelled') NOT NULL DEFAULT 'pending',
            procurement_mode    ENUM('vendor_run','supplier_pickup','online_order','other') NOT NULL DEFAULT 'vendor_run',
            priority            ENUM('normal','urgent','low') NOT NULL DEFAULT 'normal',
            estimated_total     DECIMAL(10,2) NULL DEFAULT NULL,
            assigned_to_id      INT NULL DEFAULT NULL,
            notes               TEXT NULL DEFAULT NULL,
            created_by_id       INT NULL DEFAULT NULL,
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pt_task_date      (task_date),
            INDEX idx_pt_assigned_to    (assigned_to_id),
            INDEX idx_pt_status         (purchase_status),
            INDEX idx_pt_created_by     (created_by_id),
            FOREIGN KEY fk_pt_assigned  (assigned_to_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY fk_pt_created   (created_by_id)  REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
          COMMENT='Crew vendor runs and supply pickups visible on the schedule.'
    ");
}

// ── 2. purchase_task_items ─────────────────────────────────────────────────────
if (mig1029_table_exists($db, 'purchase_task_items')) {
    $log[] = "SKIP purchase_task_items — table already exists";
} else {
    $log[] = mig1029_run($db, 'CREATE purchase_task_items', "
        CREATE TABLE purchase_task_items (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            task_id         INT           NOT NULL,
            description     VARCHAR(512)  NOT NULL DEFAULT '',
            quantity        DECIMAL(8,2)  NOT NULL DEFAULT 1,
            unit            VARCHAR(50)   NULL,
            unit_price      DECIMAL(10,2) NULL DEFAULT NULL,
            is_purchased    TINYINT(1)    NOT NULL DEFAULT 0,
            notes           VARCHAR(512)  NULL,
            sort_order      SMALLINT      NOT NULL DEFAULT 0,
            created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pti_task      (task_id),
            INDEX idx_pti_purchased (is_purchased),
            FOREIGN KEY fk_pti_task (task_id) REFERENCES purchase_tasks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
          COMMENT='Individual items within a purchase task (supply list).'
    ");
}

$failed = array_filter($log, function($l) { return strpos($l, 'ERR') === 0; });
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1029</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1029 — Purchase Tasks</h1>
<p>Creates <code>purchase_tasks</code> and <code>purchase_task_items</code> tables for the crew procurement workflow on the schedule page.</p>
<ul>
<?php foreach ($log as $line):
    $cls = 'ok';
    if (strpos($line, 'ERR') === 0) $cls = 'err';
    elseif (strpos($line, 'SKIP') === 0) $cls = 'skip';
?>
  <li class="<?= $cls ?>"><?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
</ul>
<?php if (empty($failed)): ?>
  <p class="ok" style="margin-top:1rem">Migration complete.</p>
<?php else: ?>
  <p class="err" style="margin-top:1rem"><?= count($failed) ?> step(s) failed — see above.</p>
<?php endif; ?>
<p style="margin-top:1.5rem"><a href="/crm/database_appstack.php">&larr; Database Manager</a></p>
</body></html>
