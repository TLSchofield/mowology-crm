<?php
/**
 * Autopay Charge Cron
 *
 * Safety-net for autopay: catches invoices that were not charged at send time
 * (e.g., the contact enrolled after the invoice was already sent) and processes
 * pending retries for previously failed charges.
 *
 * Logic:
 *   1. Find sent/viewed/partial/overdue invoices on autopay-enabled plans where
 *      the contact has a stripe_payment_method_id and no succeeded/pending attempt exists.
 *   2. Find failed attempts eligible for retry (next_retry_at <= NOW(), attempt < 3).
 *   3. Call AutopayService::attemptCharge() for each.
 *
 * Cron: daily at 9am PT (17:00 UTC)
 *   0 17 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Invoices/Cron/autopay_charge.php
 *
 * Also accessible via POST to /crm/cron/autopay_charge.php (admin-only shim).
 */

declare(strict_types=1);

// Bootstrap (upward search for paths.php — works in both local dev and production)
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/Payments/AutopayService.php';

$db  = getDB();
$log = [];

function apLog(string $msg): void
{
    global $log;
    $ts    = date('Y-m-d H:i:s');
    $log[] = "[$ts] $msg";
    if (PHP_SAPI === 'cli') {
        echo "[$ts] $msg\n";
    }
}

apLog('=== Autopay Charge Cron started ===');

$__startTime = microtime(true);

try {

$autopay   = new AutopayService($db);
$processed = 0;
$skipped   = 0;
$errors    = 0;

// ── Pass 1: New charges — sent invoices not yet attempted ─────────────────────
$newStmt = $db->query("
    SELECT i.id AS invoice_id
    FROM invoices i
    JOIN contacts ct ON i.contact_id = ct.id
    LEFT JOIN job_plans jp ON i.plan_id = jp.id
    WHERE i.status IN ('sent', 'viewed', 'partial', 'overdue')
      AND i.balance_due > 0
      AND ct.stripe_payment_method_id IS NOT NULL
      AND (
            -- plan explicitly on, or plan inherits and contact is enrolled
            jp.autopay_override = 1
            OR (jp.autopay_override IS NULL AND ct.autopay_enabled = 1)
          )
      AND i.id NOT IN (
            SELECT invoice_id FROM autopay_attempts
            WHERE status IN ('pending', 'succeeded')
          )
");

while ($row = $newStmt->fetch(\PDO::FETCH_ASSOC)) {
    $invoiceId = (int) $row['invoice_id'];
    apLog("Pass 1 — charging invoice #{$invoiceId}");
    try {
        $result = $autopay->attemptCharge($invoiceId);
        apLog("  → " . $result['status'] . ': ' . $result['message']);
        $processed++;
    } catch (\Throwable $e) {
        apLog("  → ERROR: " . $e->getMessage());
        error_log('[AutopayCron] Exception for invoice ' . $invoiceId . ': ' . $e->getMessage());
        $errors++;
    }
}

// ── Pass 2: Retries — failed attempts whose retry window has opened ───────────
$retryStmt = $db->prepare("
    SELECT aa.invoice_id
    FROM autopay_attempts aa
    JOIN invoices i ON aa.invoice_id = i.id
    WHERE aa.status = 'failed'
      AND aa.attempt_number < 3
      AND aa.next_retry_at IS NOT NULL
      AND aa.next_retry_at <= NOW()
      AND i.status IN ('sent', 'viewed', 'partial', 'overdue')
      AND i.balance_due > 0
      AND aa.invoice_id NOT IN (
            SELECT invoice_id FROM autopay_attempts
            WHERE status IN ('pending', 'succeeded')
          )
    GROUP BY aa.invoice_id
");
$retryStmt->execute();

while ($row = $retryStmt->fetch(\PDO::FETCH_ASSOC)) {
    $invoiceId = (int) $row['invoice_id'];
    apLog("Pass 2 — retrying invoice #{$invoiceId}");
    try {
        $result = $autopay->attemptCharge($invoiceId);
        apLog("  → " . $result['status'] . ': ' . $result['message']);
        $processed++;
    } catch (\Throwable $e) {
        apLog("  → ERROR: " . $e->getMessage());
        error_log('[AutopayCron] Exception (retry) for invoice ' . $invoiceId . ': ' . $e->getMessage());
        $errors++;
    }
}

apLog("=== Done. Processed: {$processed}, Skipped: {$skipped}, Errors: {$errors} ===");

recordCronRun(
    'autopay_charge',
    $errors > 0 ? 'warning' : 'success',
    "{$processed} processed, {$skipped} skipped, {$errors} errors",
    (int) round((microtime(true) - $__startTime) * 1000),
    null,
    PHP_SAPI !== 'cli'
);

// Return JSON when called via HTTP
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode([
        'processed' => $processed,
        'skipped'   => $skipped,
        'errors'    => $errors,
        'log'       => $log,
    ]);
}

} catch (\Throwable $e) {
    apLog('FATAL: ' . $e->getMessage());
    error_log('[AutopayCron] Fatal error: ' . $e->getMessage());
    recordCronRun(
        'autopay_charge',
        'error',
        'Fatal error',
        (int) round((microtime(true) - $__startTime) * 1000),
        $e->getMessage(),
        PHP_SAPI !== 'cli'
    );
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['processed' => 0, 'skipped' => 0, 'errors' => 1, 'log' => $log, 'fatal' => $e->getMessage()]);
    }
}
