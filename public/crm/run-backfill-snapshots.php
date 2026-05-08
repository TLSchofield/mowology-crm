<?php
/**
 * Direct SQL backfill for visit_margin_snapshots.
 * Doesn't rely on VisitCompletionService — queries actual columns.
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Bootstrap — NO echo before session
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Admin only.');
}

header('Content-Type: text/plain; charset=utf-8');
while (ob_get_level()) ob_end_flush();

echo "=== Direct SQL Backfill: visit_margin_snapshots ===\n";
echo "User: " . ($user['full_name'] ?? 'admin') . "\n\n";

$db = getDB();

// Step 1: Check what columns actually exist on production
echo "--- Column Audit ---\n";

function hasColumn(PDO $db, string $table, string $col): bool {
    try {
        $stmt = $db->query("SELECT {$col} FROM {$table} LIMIT 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

$jpCols = ['contact_id', 'service_type', 'property_id', 'price_per_visit',
           'estimated_duration_minutes', 'default_crew_id', 'total_visits_planned', 'quote_id'];
echo "job_plans columns:\n";
foreach ($jpCols as $c) {
    $has = hasColumn($db, 'job_plans', $c);
    echo "  {$c}: " . ($has ? 'YES' : 'MISSING') . "\n";
}

$jvCols = ['plan_id', 'assigned_crew_id', 'actual_duration_minutes', 'scheduled_date',
           'drive_time_minutes', 'labor_rate_snapshot', 'labor_cost_snapshot', 'drive_cost_snapshot',
           'actual_amount', 'status', 'completed_at'];
echo "\njob_visits columns:\n";
foreach ($jvCols as $c) {
    $has = hasColumn($db, 'job_visits', $c);
    echo "  {$c}: " . ($has ? 'YES' : 'MISSING') . "\n";
}

// Check visit_margin_snapshots
echo "\nvisit_margin_snapshots: ";
try {
    $db->query("SELECT 1 FROM visit_margin_snapshots LIMIT 1");
    echo "EXISTS\n";
} catch (Throwable $e) {
    echo "MISSING — " . $e->getMessage() . "\n";
    echo "\nRun migration 901 first.\n";
    exit;
}

// Check users.hourly_rate
echo "users.hourly_rate: " . (hasColumn($db, 'users', 'hourly_rate') ? 'YES' : 'MISSING') . "\n";

// Check drive cost setting
$driveCostPerMin = 0.75;
try {
    $r = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'drive_cost_per_minute'")->fetchColumn();
    if ($r) $driveCostPerMin = (float)$r;
    echo "drive_cost_per_minute: \${$driveCostPerMin}\n";
} catch (Throwable $e) {
    echo "ops_settings: not available (using default \$0.75/min)\n";
}

// Step 2: Get completed visits
echo "\n--- Loading Completed Visits ---\n";
$hasDriveTime = hasColumn($db, 'job_visits', 'drive_time_minutes');
$hasActualAmount = hasColumn($db, 'job_visits', 'actual_amount');
$hasServiceType = hasColumn($db, 'job_plans', 'service_type');
$hasContactId = hasColumn($db, 'job_plans', 'contact_id');
$hasPropertyId = hasColumn($db, 'job_plans', 'property_id');
$hasPricePerVisit = hasColumn($db, 'job_plans', 'price_per_visit');
$hasEstDuration = hasColumn($db, 'job_plans', 'estimated_duration_minutes');

$sql = "
    SELECT
        jv.id AS visit_id,
        jv.plan_id,
        jv.assigned_crew_id,
        jv.actual_duration_minutes,
        jv.scheduled_date,
        " . ($hasDriveTime ? "jv.drive_time_minutes," : "NULL AS drive_time_minutes,") . "
        " . ($hasActualAmount ? "jv.actual_amount," : "NULL AS actual_amount,") . "
        " . ($hasContactId ? "jp.contact_id," : "NULL AS contact_id,") . "
        " . ($hasPropertyId ? "jp.property_id," : "NULL AS property_id,") . "
        " . ($hasServiceType ? "jp.service_type," : "NULL AS service_type,") . "
        " . ($hasPricePerVisit ? "jp.price_per_visit," : "NULL AS price_per_visit,") . "
        " . ($hasEstDuration ? "jp.estimated_duration_minutes," : "NULL AS estimated_duration_minutes,") . "
        COALESCE(u.hourly_rate, 25) AS hourly_rate
    FROM job_visits jv
    JOIN job_plans jp ON jp.id = jv.plan_id
    LEFT JOIN users u ON u.id = COALESCE(jv.assigned_crew_id, jp.default_crew_id)
    WHERE jv.status = 'completed'
    ORDER BY jv.scheduled_date ASC
";

try {
    $stmt = $db->query($sql);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "[ERROR] Visit query failed: " . $e->getMessage() . "\n";
    echo "SQL: {$sql}\n";
    exit;
}

$total = count($visits);
echo "Found {$total} completed visits.\n\n";

if ($total === 0) {
    echo "Nothing to backfill.\n";
    exit;
}

// Step 3: Process each visit
echo "--- Processing ---\n";
$ok = 0;
$fail = 0;

$upsertSql = "
    INSERT INTO visit_margin_snapshots
        (visit_id, plan_id, contact_id, property_id, visit_date,
         service_type, quoted_amount, labor_cost, material_cost,
         drive_cost, gross_margin, margin_pct)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        quoted_amount  = VALUES(quoted_amount),
        labor_cost     = VALUES(labor_cost),
        material_cost  = VALUES(material_cost),
        drive_cost     = VALUES(drive_cost),
        gross_margin   = VALUES(gross_margin),
        margin_pct     = VALUES(margin_pct),
        computed_at    = NOW()
";
$upsertStmt = $db->prepare($upsertSql);

foreach ($visits as $v) {
    $visitId = (int)$v['visit_id'];
    $planId  = $v['plan_id'] ? (int)$v['plan_id'] : null;

    try {
        // Revenue: use actual_amount, or price_per_visit, or try invoice
        $quotedAmount = null;
        if (!empty($v['actual_amount'])) {
            $quotedAmount = (float)$v['actual_amount'];
        } elseif (!empty($v['price_per_visit'])) {
            $quotedAmount = (float)$v['price_per_visit'];
        }
        // Fallback: check invoices
        if ($quotedAmount === null && $planId) {
            try {
                $invStmt = $db->prepare("SELECT SUM(total) FROM invoices WHERE plan_id = ? AND status NOT IN ('cancelled', 'draft')");
                $invStmt->execute([$planId]);
                $invTotal = $invStmt->fetchColumn();
                if ($invTotal) {
                    // Divide by total completed visits for this plan
                    $vcStmt = $db->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ? AND status = 'completed'");
                    $vcStmt->execute([$planId]);
                    $vcCount = max(1, (int)$vcStmt->fetchColumn());
                    $quotedAmount = round((float)$invTotal / $vcCount, 2);
                }
            } catch (Throwable $e) { /* skip */ }
        }

        // Labor cost
        $actualMinutes = (int)($v['actual_duration_minutes'] ?? 0);
        if ($actualMinutes <= 0) {
            // Try time entries
            try {
                $teStmt = $db->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM job_time_entries WHERE visit_id = ? AND status IN ('completed', 'edited')");
                $teStmt->execute([$visitId]);
                $actualMinutes = (int)$teStmt->fetchColumn();
            } catch (Throwable $e) { /* skip */ }
        }
        if ($actualMinutes <= 0) {
            $actualMinutes = (int)($v['estimated_duration_minutes'] ?? 0);
        }
        $hourlyRate = (float)($v['hourly_rate'] ?? 25);
        $laborCost = ($actualMinutes > 0 && $hourlyRate > 0)
            ? round($hourlyRate * ($actualMinutes / 60), 2)
            : null;

        // Drive cost
        $driveMinutes = (int)($v['drive_time_minutes'] ?? 0);
        $driveCost = ($driveMinutes > 0) ? round($driveCostPerMin * $driveMinutes, 2) : null;

        // Material cost (try expenses table)
        $materialCost = null;
        if ($hasPropertyId && $v['property_id']) {
            try {
                $matStmt = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM expenses WHERE property_id = ? AND accounting_category = 'Materials' AND status NOT IN ('rejected')");
                $matStmt->execute([$v['property_id']]);
                $mc = (float)$matStmt->fetchColumn();
                if ($mc > 0) $materialCost = $mc;
            } catch (Throwable $e) { /* skip */ }
        }

        // Margin
        $grossMargin = null;
        $marginPct = null;
        if ($quotedAmount !== null) {
            $totalCost = ($laborCost ?? 0) + ($materialCost ?? 0) + ($driveCost ?? 0);
            $grossMargin = round($quotedAmount - $totalCost, 2);
            $marginPct = $quotedAmount > 0 ? round($grossMargin / $quotedAmount * 100, 2) : null;
        }

        // Insert
        $upsertStmt->execute([
            $visitId,
            $planId,
            $v['contact_id'] ?? null,
            $v['property_id'] ?? null,
            $v['scheduled_date'],
            $v['service_type'] ?? null,
            $quotedAmount,
            $laborCost,
            $materialCost,
            $driveCost,
            $grossMargin,
            $marginPct,
        ]);

        $ok++;
        $mStr = $marginPct !== null ? number_format($marginPct, 1) . '%' : 'n/a';
        $rStr = $quotedAmount !== null ? '$' . number_format($quotedAmount, 2) : 'n/a';
        $lStr = $laborCost !== null ? '$' . number_format($laborCost, 2) : 'n/a';
        echo "[OK] #{$visitId} ({$v['scheduled_date']}) — rev:{$rStr} labor:{$lStr} margin:{$mStr}\n";

    } catch (Throwable $e) {
        $fail++;
        echo "[FAIL] #{$visitId}: " . $e->getMessage() . "\n";
    }
    flush();
}

echo "\n=== Results ===\n";
echo "Success: {$ok}\n";
echo "Failed: {$fail}\n";

// Summary
$snap = $db->query("
    SELECT COUNT(*) AS cnt, AVG(margin_pct) AS avg_m,
           SUM(quoted_amount) AS rev, SUM(gross_margin) AS margin
    FROM visit_margin_snapshots
")->fetch(PDO::FETCH_ASSOC);
echo "\nTotal snapshots: {$snap['cnt']}\n";
echo "Avg margin: " . round((float)($snap['avg_m'] ?? 0), 1) . "%\n";
echo "Total revenue: $" . number_format((float)($snap['rev'] ?? 0), 2) . "\n";
echo "Total margin: $" . number_format((float)($snap['margin'] ?? 0), 2) . "\n";

echo "\nDone. Check /crm/profitability_appstack.php\n";
