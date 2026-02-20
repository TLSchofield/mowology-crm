<?php
/**
 * /app/Services/QuickBooks/QBService.php
 * QuickBooks Abstraction Layer
 *
 * Single point of integration for all "send to accounting" operations.
 * Currently implemented via email forwarding (ReceiptService.php).
 * When QB Online API access is added, swap only this file — all callers
 * stay unchanged.
 *
 * Adds on top of raw ReceiptService:
 *   - Batch forwarding (send multiple expenses in one call)
 *   - Retry queue for failed sends (up to 3 attempts)
 *   - QB-specific field mapping (for future API calls)
 *   - Status summary for the Settings page
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/QuickBooks/QBService.php';
 *   $result = qbForwardExpense($expenseId, $db);
 *   $results = qbForwardBatch([1, 2, 3], $db);
 *   $status  = qbGetStatus($db);
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/Core/paths.php';
}

require_once APP_ROOT . '/Services/Receipts/ReceiptService.php';

// ── Constants ──────────────────────────────────────────────────────────────
const QB_MAX_RETRIES    = 3;
const QB_RETRY_DELAY_S  = 300;  // 5 minutes between retry attempts


// ── Public API ─────────────────────────────────────────────────────────────

/**
 * Forward a single expense to QuickBooks (or accounting email).
 *
 * @param int      $expenseId
 * @param PDO|null $db
 * @return array ['success' => bool, 'message' => string, 'log_id' => int|null, 'method' => string]
 */
function qbForwardExpense(int $expenseId, ?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    // Load expense with all needed fields
    $expense = _qbLoadExpense($expenseId, $db);
    if (!$expense) {
        return ['success' => false, 'message' => "Expense #{$expenseId} not found", 'log_id' => null, 'method' => 'none'];
    }

    // Already forwarded?
    if ($expense['forwarded_to_accounting']) {
        return ['success' => true, 'message' => 'Already forwarded', 'log_id' => null, 'method' => 'already_done'];
    }

    // Check for previous failed attempts — retry throttle
    $attempts = _qbGetRetryCount($expenseId, $db);
    if ($attempts >= QB_MAX_RETRIES) {
        return ['success' => false, 'message' => "Max retries ({$attempts}) reached for expense #{$expenseId}", 'log_id' => null, 'method' => 'max_retries'];
    }

    // Map expense fields to QB format
    $opts = _qbMapExpenseToOpts($expense);

    // Execute: currently email, future: QB API
    $result = _qbExecuteForward($opts, $db);

    // Log the attempt for retry tracking
    _qbLogAttempt($expenseId, $result, $db);

    return array_merge($result, ['method' => _qbCurrentMethod()]);
}


/**
 * Forward multiple expenses to QB in one call.
 *
 * @param int[]    $expenseIds
 * @param PDO|null $db
 * @return array ['sent' => int, 'failed' => int, 'results' => array]
 */
function qbForwardBatch(array $expenseIds, ?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    $sent   = 0;
    $failed = 0;
    $results = [];

    foreach ($expenseIds as $id) {
        $id = (int)$id;
        if (!$id) continue;

        $r = qbForwardExpense($id, $db);
        $results[$id] = $r;

        if ($r['success']) {
            $sent++;
        } else {
            $failed++;
        }

        // Brief pause between sends to avoid SMTP rate limiting
        if (count($expenseIds) > 1) {
            usleep(200000);  // 200ms
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'results' => $results];
}


/**
 * Retry all failed sends from the last 24 hours (for a cron job).
 *
 * @param PDO|null $db
 * @return array
 */
function qbRetryFailed(?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    // Find expenses with failed email logs in the last 24h, under retry limit
    $stmt = $db->query("
        SELECT DISTINCT rel.expense_id
        FROM receipt_email_log rel
        WHERE rel.status = 'failed'
          AND rel.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND rel.expense_id IS NOT NULL
          AND (
              SELECT COUNT(*) FROM receipt_email_log
              WHERE expense_id = rel.expense_id AND status = 'failed'
          ) < " . QB_MAX_RETRIES . "
          AND (
              SELECT MAX(created_at) FROM receipt_email_log
              WHERE expense_id = rel.expense_id
          ) <= DATE_SUB(NOW(), INTERVAL " . QB_RETRY_DELAY_S . " SECOND)
    ");

    $failedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$failedIds) {
        return ['retried' => 0, 'message' => 'No eligible failed sends to retry'];
    }

    $result = qbForwardBatch(array_map('intval', $failedIds), $db);
    $result['message'] = "Retried " . count($failedIds) . " failed sends: {$result['sent']} succeeded, {$result['failed']} still failing";

    return $result;
}


/**
 * Get QB forwarding status summary for Settings page.
 *
 * @param PDO|null $db
 * @return array
 */
function qbGetStatus(?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    $config = getReceiptForwardingConfig($db);

    try {
        // Counts from receipt_email_log (last 30 days)
        $stmt = $db->query("
            SELECT
                SUM(status = 'sent')   AS total_sent,
                SUM(status = 'failed') AS total_failed,
                SUM(status = 'queued') AS total_queued,
                MAX(sent_at)           AS last_sent_at
            FROM receipt_email_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);

        // Pending expenses (not yet forwarded, not rejected, low anomaly)
        $pendingStmt = $db->query("
            SELECT COUNT(*) FROM expenses
            WHERE forwarded_to_accounting = 0
              AND status IN ('approved', 'draft')
              AND anomaly_score <= 30
        ");
        $pendingCount = (int)$pendingStmt->fetchColumn();

        // Failed sends with retry potential
        $retryStmt = $db->query("
            SELECT COUNT(DISTINCT expense_id) FROM receipt_email_log
            WHERE status = 'failed'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND expense_id IS NOT NULL
        ");
        $retryCount = (int)$retryStmt->fetchColumn();

    } catch (\Throwable $e) {
        error_log('qbGetStatus error: ' . $e->getMessage());
        $counts = ['total_sent' => 0, 'total_failed' => 0, 'total_queued' => 0, 'last_sent_at' => null];
        $pendingCount = 0;
        $retryCount = 0;
    }

    return [
        'method'            => _qbCurrentMethod(),
        'enabled'           => $config['enabled'],
        'accounting_email'  => $config['accounting_email'],
        'auto_send'         => $config['auto_send'],
        'sent_30d'          => (int)($counts['total_sent'] ?? 0),
        'failed_30d'        => (int)($counts['total_failed'] ?? 0),
        'queued'            => (int)($counts['total_queued'] ?? 0),
        'last_sent_at'      => $counts['last_sent_at'] ?? null,
        'pending_count'     => $pendingCount,
        'retry_eligible'    => $retryCount,
        'api_connected'     => false,   // Will be true when QB OAuth is implemented
        'api_company_name'  => null,    // QB company name once connected
    ];
}


/**
 * Map QB categories to QuickBooks Online expense account names.
 * Used to pre-populate QB fields when the real API is added.
 *
 * @param string $accountingCategory Mowology category (e.g., 'Materials')
 * @return string QB account name
 */
function qbMapCategory(string $accountingCategory): string
{
    $map = [
        'Materials'       => 'Job Materials',
        'Fuel'            => 'Fuel & Mileage',
        'Tools/Equipment' => 'Tools & Equipment',
        'Disposal/Dump'   => 'Disposal & Dumping',
        'Repairs'         => 'Repairs & Maintenance',
        'Vehicle'         => 'Vehicle Expenses',
        'Meals'           => 'Meals & Entertainment',
        'Office'          => 'Office Supplies',
        'Subcontractor'   => 'Subcontractors',
    ];

    return $map[$accountingCategory] ?? 'Job Expenses';
}


// ── Private Helpers ─────────────────────────────────────────────────────────

/** Which integration method is currently active? */
function _qbCurrentMethod(): string
{
    return 'email';  // Change to 'api' when QB OAuth is implemented
}

/** Load all expense fields needed for forwarding. */
function _qbLoadExpense(int $expenseId, PDO $db): ?array
{
    $stmt = $db->prepare("
        SELECT
            e.id, e.expense_date, e.vendor_name_raw, e.description,
            e.amount, e.tax_amount, e.total, e.accounting_category,
            e.receipt_media_id, e.job_id, e.contact_id,
            e.forwarded_to_accounting, e.status, e.anomaly_score,
            v.name AS vendor_name,
            c.first_name, c.last_name
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN contacts c ON c.id = e.contact_id
        WHERE e.id = ?
    ");
    $stmt->execute([$expenseId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Map expense row → opts array for sendReceiptToAccounting(). */
function _qbMapExpenseToOpts(array $expense): array
{
    $vendorName = $expense['vendor_name'] ?: $expense['vendor_name_raw'] ?: 'Unknown';
    $clientName = '';
    if (!empty($expense['first_name']) || !empty($expense['last_name'])) {
        $clientName = trim(($expense['first_name'] ?? '') . ' ' . ($expense['last_name'] ?? ''));
    }

    return [
        'expense_id'          => (int)$expense['id'],
        'media_id'            => $expense['receipt_media_id'] ? (int)$expense['receipt_media_id'] : null,
        'vendor'              => $vendorName,
        'total'               => number_format((float)$expense['total'], 2),
        'subtotal'            => number_format((float)$expense['amount'], 2),
        'gst_amount'          => number_format((float)($expense['tax_amount'] ?? 0), 2),
        'date'                => $expense['expense_date'],
        'job_id'              => $expense['job_id'] ? (int)$expense['job_id'] : null,
        'client_name'         => $clientName,
        'description'         => $expense['description'] ?? '',
        'accounting_category' => $expense['accounting_category'] ?? '',
        'qb_account'          => qbMapCategory($expense['accounting_category'] ?? ''),
    ];
}

/** Execute the forward using the current method (email or future API). */
function _qbExecuteForward(array $opts, PDO $db): array
{
    // Currently: email forwarding via ReceiptService
    return sendReceiptToAccounting($opts);

    // Future QB API implementation:
    // if (_qbCurrentMethod() === 'api') {
    //     return _qbApiCreateExpense($opts, $db);
    // }
}

/** Count previous send attempts (successful + failed) for an expense. */
function _qbGetRetryCount(int $expenseId, PDO $db): int
{
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM receipt_email_log WHERE expense_id = ? AND status = 'failed'");
        $stmt->execute([$expenseId]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

/** Log an attempt for retry tracking (updates existing log row via ReceiptService). */
function _qbLogAttempt(int $expenseId, array $result, PDO $db): void
{
    // The log entry is created by sendReceiptToAccounting → createEmailLog().
    // We just need to record the attempt timestamp for retry throttling.
    // Nothing extra needed — receipt_email_log has a created_at column.
}
