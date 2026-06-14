<?php
/**
 * One-shot backfill: populate visit_margin_snapshots for all completed visits.
 *
 * Finds completed job_visits that don't have a margin snapshot yet
 * and runs VisitCompletionService::capture() on each one.
 *
 * Safe to run multiple times — uses INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * Also fixes the resolveMaterialCost issue where expenses.visit_id or
 * expenses.total_amount may not exist on production — handles gracefully.
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

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
// Disable output buffering so progress streams to browser
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(1);

$db = getDB();

echo "=== Backfill visit_margin_snapshots ===\n\n";
echo "PHP " . PHP_VERSION . " | APP_ROOT: " . (defined('APP_ROOT') ? APP_ROOT : 'NOT SET') . "\n\n";

// Step 0: Check if visit_margin_snapshots table exists
try {
    $db->query("SELECT 1 FROM visit_margin_snapshots LIMIT 1");
} catch (Throwable $e) {
    echo "[ERROR] visit_margin_snapshots table does not exist.\n";
    echo "Run migration 901_labor_cost_snapshots.sql first.\n";
    exit;
}

// Step 1: Find all completed visits without a snapshot
echo "Querying completed visits...\n";
try {
    $stmt = $db->query("
        SELECT
            jv.id AS visit_id,
            jv.assigned_crew_id,
            jv.plan_id,
            jv.scheduled_date,
            jv.actual_duration_minutes,
            jp.service_type,
            jp.estimated_duration_minutes,
            jp.price_per_visit,
            jp.property_id,
            jp.contact_id
        FROM job_visits jv
        JOIN job_plans jp ON jp.id = jv.plan_id
        LEFT JOIN visit_margin_snapshots vms ON vms.visit_id = jv.id
        WHERE jv.status = 'completed'
          AND vms.id IS NULL
        ORDER BY jv.scheduled_date ASC
    ");
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "[ERROR] Query failed: " . $e->getMessage() . "\n";

    // Fallback: try simpler query without the LEFT JOIN
    echo "\nTrying simpler query...\n";
    try {
        $stmt = $db->query("
            SELECT
                jv.id AS visit_id,
                jv.assigned_crew_id,
                jv.plan_id,
                jv.scheduled_date,
                jv.actual_duration_minutes
            FROM job_visits jv
            WHERE jv.status = 'completed'
            ORDER BY jv.scheduled_date ASC
        ");
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Found " . count($visits) . " completed visits (will re-process all).\n";
    } catch (Throwable $e2) {
        echo "[FATAL] Even simple query failed: " . $e2->getMessage() . "\n";
        exit;
    }
}

$total = count($visits);
echo "Found {$total} completed visits without snapshots.\n\n";

if ($total === 0) {
    // Also show current snapshot stats
    $existing = (int)$db->query("SELECT COUNT(*) FROM visit_margin_snapshots")->fetchColumn();
    $completedTotal = (int)$db->query("SELECT COUNT(*) FROM job_visits WHERE status = 'completed'")->fetchColumn();
    echo "Existing snapshots: {$existing} / {$completedTotal} completed visits\n";
    echo "Coverage: " . ($completedTotal > 0 ? round(($existing / $completedTotal) * 100, 1) : 0) . "%\n";
    echo "\nNothing to backfill.\n";
    exit;
}

// Step 2: Load the VisitCompletionService
$servicePath = APP_ROOT . '/Modules/Jobs/Services/VisitCompletionService.php';
if (!is_file($servicePath)) {
    echo "[ERROR] VisitCompletionService.php not found at: {$servicePath}\n";
    exit;
}
require_once $servicePath;

// Step 3: Process each visit
$success = 0;
$failed  = 0;
$skipped = 0;

foreach ($visits as $i => $v) {
    $visitId = (int)$v['visit_id'];
    $crewId  = (int)($v['assigned_crew_id'] ?? 1);
    if ($crewId <= 0) $crewId = 1;

    try {
        VisitCompletionService::capture($visitId, $crewId);
        $success++;

        // Progress every 10 visits
        if (($i + 1) % 10 === 0 || $i === $total - 1) {
            $pct = round((($i + 1) / $total) * 100, 0);
            echo "[{$pct}%] Processed " . ($i + 1) . " / {$total} — visit #{$visitId} ({$v['scheduled_date']})\n";
            if (function_exists('flush')) { flush(); }
        }
    } catch (Throwable $e) {
        $failed++;
        echo "[FAIL] Visit #{$visitId}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Results ===\n";
echo "Processed: {$success} snapshots created\n";
echo "Failed:    {$failed}\n";

// Step 4: Summary stats
echo "\n=== Snapshot Coverage ===\n";
$totalSnapshots   = (int)$db->query("SELECT COUNT(*) FROM visit_margin_snapshots")->fetchColumn();
$completedVisits  = (int)$db->query("SELECT COUNT(*) FROM job_visits WHERE status = 'completed'")->fetchColumn();
$coverage = $completedVisits > 0 ? round(($totalSnapshots / $completedVisits) * 100, 1) : 0;
echo "Snapshots: {$totalSnapshots} / {$completedVisits} completed visits ({$coverage}%)\n";

// Margin summary
echo "\n=== Margin Summary ===\n";
$stats = $db->query("
    SELECT
        COUNT(*)          AS visits,
        AVG(margin_pct)   AS avg_margin,
        MIN(margin_pct)   AS min_margin,
        MAX(margin_pct)   AS max_margin,
        SUM(quoted_amount) AS total_revenue,
        SUM(labor_cost)   AS total_labor,
        SUM(drive_cost)   AS total_drive,
        SUM(gross_margin) AS total_margin,
        COUNT(CASE WHEN margin_pct < 20 THEN 1 END) AS low_margin_count,
        COUNT(CASE WHEN gross_margin < 0 THEN 1 END) AS negative_count
    FROM visit_margin_snapshots
")->fetch(PDO::FETCH_ASSOC);

echo "  Total visits tracked:   " . number_format((int)$stats['visits']) . "\n";
echo "  Avg margin:             " . number_format((float)$stats['avg_margin'], 1) . "%\n";
echo "  Min margin:             " . number_format((float)$stats['min_margin'], 1) . "%\n";
echo "  Max margin:             " . number_format((float)$stats['max_margin'], 1) . "%\n";
echo "  Total revenue:          $" . number_format((float)$stats['total_revenue'], 2) . "\n";
echo "  Total labor cost:       $" . number_format((float)$stats['total_labor'], 2) . "\n";
echo "  Total drive cost:       $" . number_format((float)$stats['total_drive'], 2) . "\n";
echo "  Total gross margin:     $" . number_format((float)$stats['total_margin'], 2) . "\n";
echo "  Low margin (<20%):      " . number_format((int)$stats['low_margin_count']) . "\n";
echo "  Negative margin:        " . number_format((int)$stats['negative_count']) . "\n";

// By service type
echo "\n=== By Service Type ===\n";
$bySvc = $db->query("
    SELECT
        COALESCE(service_type, 'unknown') AS svc,
        COUNT(*) AS cnt,
        AVG(margin_pct) AS avg_m,
        SUM(quoted_amount) AS rev
    FROM visit_margin_snapshots
    GROUP BY service_type
    ORDER BY SUM(quoted_amount) DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($bySvc as $s) {
    echo "  " . str_pad($s['svc'], 25) . " — "
        . $s['cnt'] . " visits, "
        . number_format((float)$s['avg_m'], 1) . "% avg margin, "
        . "$" . number_format((float)$s['rev'], 2) . " revenue\n";
}

echo "\nDone. Go to /crm/profitability_appstack.php to see the results.\n";
