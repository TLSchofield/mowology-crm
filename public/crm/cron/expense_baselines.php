<?php
/**
 * Cron: Expense Monthly Baselines
 *
 * Aggregates monthly expense averages per accounting category into
 * expense_monthly_baselines. Used by the anomaly detector (HIGH_AMOUNT rule)
 * and seasonal profiling.
 *
 * Run monthly: 0 2 1 * * php /path/to/expense_baselines.php
 * Also callable via web POST: POST /crm/cron/expense_baselines.php
 *
 * Looks at the last 2 years of expense data to build averages.
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
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
}

try {
    $db = getDB();

    // Aggregate expense data per category per month (last 2 years)
    $stmt = $db->query("
        SELECT
            accounting_category,
            MONTH(expense_date) AS month_num,
            AVG(total) AS avg_total,
            COUNT(*) AS total_count,
            COUNT(DISTINCT YEAR(expense_date)) AS year_count,
            ROUND(STDDEV(total), 2) AS stddev_total
        FROM expenses
        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
          AND accounting_category IS NOT NULL
          AND accounting_category != ''
          AND status != 'rejected'
        GROUP BY accounting_category, MONTH(expense_date)
        ORDER BY accounting_category, month_num
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;

    foreach ($rows as $row) {
        $cat = $row['accounting_category'];
        $monthNum = (int)$row['month_num'];
        $avgTotal = round((float)$row['avg_total'], 2);
        $totalCount = (int)$row['total_count'];
        $yearCount = (int)$row['year_count'];
        $stddev = $row['stddev_total'] !== null ? (float)$row['stddev_total'] : null;

        // Average count per month (across years)
        $avgCount = $yearCount > 0 ? (int)round($totalCount / $yearCount) : $totalCount;

        // UPSERT: use REPLACE since we have a UNIQUE KEY on (accounting_category, month_num)
        $stmt2 = $db->prepare("
            REPLACE INTO expense_monthly_baselines
                (accounting_category, month_num, avg_total, avg_count, stddev_total, sample_years, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt2->execute([$cat, $monthNum, $avgTotal, $avgCount, $stddev, $yearCount]);
        $updated++;
    }

    $result = [
        'success'  => true,
        'message'  => "Updated {$updated} baseline records from " . count($rows) . " category-month combinations",
        'updated'  => $updated,
    ];

    recordCronRun(
        'expense_baselines',
        'success',
        $result['message'],
        null,
        null,
        !$isCli
    );

    if ($isCli) {
        echo $result['message'] . "\n";
    } else {
        echo json_encode($result);
    }

} catch (\Throwable $e) {
    $error = 'expense_baselines error: ' . $e->getMessage();
    error_log($error);
    recordCronRun('expense_baselines', 'error', 'Fatal error', null, $e->getMessage(), !$isCli);

    if ($isCli) {
        echo "ERROR: " . $error . "\n";
        exit(1);
    } else {
        echo json_encode(['success' => false, 'error' => $error]);
    }
}
