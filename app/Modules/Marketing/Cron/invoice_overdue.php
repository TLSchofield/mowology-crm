<?php
/**
 * Invoice Overdue Cron
 *
 * Marks invoices as 'overdue' when due_date has passed and they remain unpaid.
 * This feeds the automation_runner's invoice_overdue trigger, which then sends
 * reminder emails to contacts.
 *
 * Cron: run once daily, e.g. at 8am
 *   0 8 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/invoice_overdue.php
 *
 * Also accessible via POST to /crm/cron/invoice_overdue.php (admin-only shim).
 */

declare(strict_types=1);

// Bootstrap
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once CRM_INCLUDES . '/functions.php';

$db      = getDB();
$log     = [];
$startMs = (int) round(microtime(true) * 1000);
$fromWeb = (PHP_SAPI !== 'cli');

function ovLog(string $msg): void {
    global $log;
    $ts = date('Y-m-d H:i:s');
    $log[] = "[$ts] $msg";
    if (PHP_SAPI === 'cli') { echo "[$ts] $msg\n"; }
}

ovLog("=== Invoice Overdue Cron started ===");

$cronStatus = 'success';
$cronError  = null;
$rows       = 0;

try {
    // Mark eligible invoices as overdue:
    //  - status is 'sent', 'viewed', or 'partial'
    //  - due_date has passed
    //  - not already paid
    $rows = $db->exec("
        UPDATE invoices
        SET status = 'overdue'
        WHERE status IN ('sent', 'viewed', 'partial')
          AND due_date < CURDATE()
          AND (paid_at IS NULL OR balance_due > 0.01)
    ");
    ovLog("Marked {$rows} invoice(s) as overdue");

} catch (Throwable $e) {
    $cronStatus = 'error';
    $cronError  = $e->getMessage();
    ovLog("ERROR: " . $e->getMessage());
    error_log('[invoice_overdue_cron] ' . $e->getMessage());
}

ovLog("=== Done ===");

$durationMs = (int) round(microtime(true) * 1000) - $startMs;
recordCronRun(
    'invoice_overdue',
    $cronStatus,
    "Marked {$rows} invoice(s) as overdue",
    $durationMs,
    $cronError,
    $fromWeb
);

// If running via web shim, return JSON
if ($fromWeb) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'log' => $log]);
}
