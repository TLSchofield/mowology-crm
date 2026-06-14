<?php
/**
 * One-shot backfill: sync calendar_stops.status from job_visits.status.
 *
 * Finds stops where all visits are completed/skipped/cancelled but the
 * stop itself is still 'scheduled' or 'in_progress', and marks them
 * 'completed'. Also marks stops with at least one in_progress visit.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
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
    die('Admin only.');
}

header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

echo "=== Backfill calendar_stops.status from job_visits ===\n\n";

// 1. Mark stops as 'completed' where ALL visits are done
$completed = $db->exec("
    UPDATE calendar_stops cs
    INNER JOIN (
        SELECT jv.stop_id
        FROM job_visits jv
        WHERE jv.stop_id IS NOT NULL
        GROUP BY jv.stop_id
        HAVING SUM(jv.status NOT IN ('completed','skipped','cancelled')) = 0
           AND SUM(jv.status = 'completed') > 0
    ) done ON done.stop_id = cs.id
    SET cs.status = 'completed', cs.updated_at = NOW()
    WHERE cs.status != 'completed'
");
echo "[OK] Marked {$completed} stops as 'completed'\n";

// 2. Mark stops as 'in_progress' where at least one visit is in_progress
$inProgress = $db->exec("
    UPDATE calendar_stops cs
    INNER JOIN (
        SELECT DISTINCT jv.stop_id
        FROM job_visits jv
        WHERE jv.stop_id IS NOT NULL AND jv.status = 'in_progress'
    ) active ON active.stop_id = cs.id
    SET cs.status = 'in_progress', cs.updated_at = NOW()
    WHERE cs.status = 'scheduled'
");
echo "[OK] Marked {$inProgress} stops as 'in_progress'\n";

// 3. Summary
echo "\n=== Current state ===\n";
$counts = $db->query("
    SELECT cs.status, COUNT(*) c
    FROM calendar_stops cs
    WHERE cs.stop_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY cs.status
    ORDER BY FIELD(cs.status, 'scheduled','in_progress','completed','skipped','cancelled')
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($counts as $r) {
    echo "  {$r['status']}: {$r['c']} (last 30 days)\n";
}

// Today specifically
echo "\n=== Today (" . date('Y-m-d') . ") ===\n";
$today = $db->query("
    SELECT cs.status, COUNT(*) c
    FROM calendar_stops cs
    WHERE cs.stop_date = CURDATE()
    GROUP BY cs.status
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($today as $r) {
    echo "  {$r['status']}: {$r['c']}\n";
}

echo "\nDone.\n";
