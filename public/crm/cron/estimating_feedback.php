<?php
/**
 * Cron: Estimating Feedback Loop
 *
 * Runs weekly to compare quoted material costs against actual expenses
 * for every active job plan that has both a linked quote and expenses.
 * Writes results to quote_accuracy_log and vendor_price_index.
 *
 * These tables power:
 *   - Profitability dashboard (accuracy trend over time)
 *   - Future: quote auto-suggest (learned material costs by category/vendor)
 *
 * Run weekly: 0 3 * * 1 php /path/to/estimating_feedback.php
 *   (3 AM every Monday, captures the previous week's jobs)
 *
 * Also callable via web POST by admin:
 *   POST /crm/cron/estimating_feedback.php
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────
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
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/Receipts/MarginTracker.php';

// Auth: require admin for web access, skip for CLI
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
}

$startMs    = (int) round(microtime(true) * 1000);
$fromWeb    = !$isCli;
$cronStatus = 'success';
$cronError  = null;
$insertedAccuracy = 0;
$updatedPrices    = 0;

// ── Main ───────────────────────────────────────────────────────────────
try {
    $db = getDB();

    // Calculate ISO week start (Monday) for this snapshot
    $snapshotWeek = date('Y-m-d', strtotime('last Monday', strtotime('tomorrow')));
    // If today is Monday, use today as the week start
    if (date('N') === '1') {
        $snapshotWeek = date('Y-m-d');
    }

    $log = [];
    $insertedAccuracy = 0;
    $skippedNoQuote   = 0;
    $skippedNoExpense = 0;

    // ── Part 1: Quote Accuracy Log ─────────────────────────────────────
    // Find all active job plans with both a linked quote and at least one expense
    $planStmt = $db->query("
        SELECT DISTINCT jp.id AS plan_id
        FROM job_plans jp
        JOIN quotes q ON jp.quote_id = q.id
        JOIN expenses e ON e.job_id = jp.id
        WHERE jp.status = 'active'
          AND e.status != 'rejected'
          AND q.status NOT IN ('draft')
        LIMIT 200
    ");
    $planIds = $planStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($planIds as $planId) {
        $planId = (int)$planId;

        $margin = getJobMargin($planId, $db);
        if (!$margin) {
            $skippedNoQuote++;
            continue;
        }

        // Auto-rate accuracy (1-5)
        $deltaPct = $margin['material_delta_pct'];
        $rating = null;
        if ($deltaPct !== null) {
            if ($deltaPct >= 10)       $rating = 5;  // Spot on or better
            elseif ($deltaPct >= 0)    $rating = 4;  // Under budget, slight variance
            elseif ($deltaPct >= -10)  $rating = 3;  // Slightly over budget
            elseif ($deltaPct >= -25)  $rating = 2;  // Noticeably over budget
            else                       $rating = 1;  // Significantly over
        }

        // UPSERT: update if this plan-week snapshot already exists
        $upsert = $db->prepare("
            INSERT INTO quote_accuracy_log
                (job_plan_id, quote_id, quote_number,
                 quoted_materials, actual_materials, material_delta, material_delta_pct,
                 quoted_total, actual_total, total_delta, total_delta_pct,
                 actual_fuel, actual_disposal, actual_tools,
                 expense_count, snapshot_week, accuracy_rating)
            VALUES
                (?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                quoted_materials   = VALUES(quoted_materials),
                actual_materials   = VALUES(actual_materials),
                material_delta     = VALUES(material_delta),
                material_delta_pct = VALUES(material_delta_pct),
                quoted_total       = VALUES(quoted_total),
                actual_total       = VALUES(actual_total),
                total_delta        = VALUES(total_delta),
                total_delta_pct    = VALUES(total_delta_pct),
                actual_fuel        = VALUES(actual_fuel),
                actual_disposal    = VALUES(actual_disposal),
                actual_tools       = VALUES(actual_tools),
                expense_count      = VALUES(expense_count),
                accuracy_rating    = VALUES(accuracy_rating)
        ");

        $quoteId = null;
        $quoteNumber = null;
        if ($margin['quote_number']) {
            // Resolve quote_id from quote_number
            $qStmt = $db->prepare("SELECT id FROM quotes WHERE quote_number = ? LIMIT 1");
            $qStmt->execute([$margin['quote_number']]);
            $quoteRow = $qStmt->fetch(PDO::FETCH_ASSOC);
            if ($quoteRow) {
                $quoteId = (int)$quoteRow['id'];
                $quoteNumber = $margin['quote_number'];
            }
        }

        $upsert->execute([
            $planId,
            $quoteId,
            $quoteNumber,
            $margin['quoted_materials'],
            $margin['actual_materials'],
            $margin['material_margin'],
            $margin['material_margin_pct'],
            $margin['quote_total'],
            $margin['actual_total'],
            $margin['total_margin'],
            $margin['total_margin_pct'],
            $margin['actual_fuel'],
            $margin['actual_disposal'],
            $margin['actual_tools'],
            $margin['expense_count'],
            $snapshotWeek,
            $rating,
        ]);
        $insertedAccuracy++;

        $log[] = [
            'plan_id'       => $planId,
            'quote_number'  => $quoteNumber,
            'delta_pct'     => $deltaPct,
            'rating'        => $rating,
        ];
    }

    // ── Part 2: Vendor Price Index ─────────────────────────────────────
    // Aggregate actual expense amounts per vendor + category per month (rolling 12 months)
    $priceMonth = date('Y-m-01');  // Snapshot for current month

    $priceStmt = $db->query("
        SELECT
            e.vendor_id,
            e.accounting_category,
            DATE_FORMAT(e.expense_date, '%Y-%m-01') AS price_month,
            AVG(e.total)   AS avg_amount,
            MIN(e.total)   AS min_amount,
            MAX(e.total)   AS max_amount,
            COUNT(*)        AS transaction_count
        FROM expenses e
        WHERE e.vendor_id IS NOT NULL
          AND e.accounting_category IS NOT NULL
          AND e.accounting_category != ''
          AND e.status != 'rejected'
          AND e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY e.vendor_id, e.accounting_category, DATE_FORMAT(e.expense_date, '%Y-%m-01')
    ");

    $priceRows = $priceStmt->fetchAll(PDO::FETCH_ASSOC);
    $updatedPrices = 0;

    foreach ($priceRows as $row) {
        if (!$row['vendor_id']) continue;

        $db->prepare("
            INSERT INTO vendor_price_index
                (vendor_id, accounting_category, price_month, avg_amount, min_amount, max_amount, transaction_count)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                avg_amount        = VALUES(avg_amount),
                min_amount        = VALUES(min_amount),
                max_amount        = VALUES(max_amount),
                transaction_count = VALUES(transaction_count)
        ")->execute([
            (int)$row['vendor_id'],
            $row['accounting_category'],
            $row['price_month'],
            round((float)$row['avg_amount'], 2),
            round((float)$row['min_amount'], 2),
            round((float)$row['max_amount'], 2),
            (int)$row['transaction_count'],
        ]);
        $updatedPrices++;
    }

    // ── Report ─────────────────────────────────────────────────────────
    $result = [
        'success'           => true,
        'snapshot_week'     => $snapshotWeek,
        'accuracy_records'  => $insertedAccuracy,
        'price_index_rows'  => $updatedPrices,
        'plans_processed'   => count($planIds),
        'skipped_no_quote'  => $skippedNoQuote,
        'details'           => $log,
        'message'           => "Estimating feedback: {$insertedAccuracy} accuracy records, {$updatedPrices} price index rows updated",
    ];

    $durationMs = (int) round(microtime(true) * 1000) - $startMs;
    recordCronRun(
        'estimating_feedback',
        $cronStatus,
        "Accuracy records: {$insertedAccuracy}, Price index rows: {$updatedPrices}",
        $durationMs,
        $cronError,
        $fromWeb
    );

    if ($isCli) {
        echo $result['message'] . "\n";
        foreach ($log as $entry) {
            $delta = $entry['delta_pct'] !== null ? number_format($entry['delta_pct'], 1) . '%' : 'N/A';
            echo "  Plan #{$entry['plan_id']} {$entry['quote_number']}: delta={$delta}, rating={$entry['rating']}\n";
        }
    } else {
        echo json_encode($result);
    }

} catch (\Throwable $e) {
    $error = 'estimating_feedback error: ' . $e->getMessage();
    error_log($error);
    $durationMs = (int) round(microtime(true) * 1000) - $startMs;
    recordCronRun('estimating_feedback', 'error', null, $durationMs, $e->getMessage(), $fromWeb);

    if ($isCli) {
        echo "ERROR: " . $error . "\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $error]);
    }
}
