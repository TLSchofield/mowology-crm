<?php
/**
 * /app/Services/Receipts/ReceiptService.php
 * Receipt Email Forwarding Service
 *
 * Sends receipt images as email attachments to an accounting inbox (QuickBooks).
 * Uses the existing sendEmail() from MessagingService.php (PHPMailer SMTP + fallback).
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/ReceiptService.php';
 *   $result = sendReceiptToAccounting([
 *       'expense_id' => 42,
 *       'media_id'   => 18,
 *       'vendor'     => 'Home Depot',
 *       'total'      => '145.67',
 *       'date'       => '2026-02-13',
 *       'job_id'     => 5,
 *       'client_name'=> 'Smith Residence',
 *   ]);
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

require_once APP_ROOT . '/Services/Messaging/MessagingService.php';

/**
 * Send a receipt image to the configured accounting email address.
 *
 * @param array $opts {
 *   @type int|null    $expense_id   Expense record ID (for logging)
 *   @type int|null    $media_id     media_assets.id for the receipt image
 *   @type string|null $file_path    Direct file path (alternative to media_id)
 *   @type string|null $vendor       Vendor name (from OCR or manual)
 *   @type string|null $total        Dollar amount (e.g., "145.67")
 *   @type string|null $date         Expense date (YYYY-MM-DD)
 *   @type int|null    $job_id       Linked job ID
 *   @type string|null $client_name  Client/property name for reference
 *   @type string|null $description  Optional description
 * }
 * @param int|null $userIdOverride  Explicit user id for the email log's created_by column.
 *   Defaults to getCurrentUser() when null, which requires an active PHP session — pass
 *   this explicitly for JWT-authenticated callers (no session), e.g. app/Modules/Expenses/Api/receipt-actions.php.
 * @return array ['success' => bool, 'message' => string, 'log_id' => int|null]
 */
function sendReceiptToAccounting(array $opts, ?int $userIdOverride = null): array
{
    $db = getDB();
    if ($userIdOverride !== null) {
        $userId = $userIdOverride;
    } else {
        $user = getCurrentUser();
        $userId = $user['id'] ?? 0;
    }

    // 1. Check if forwarding is enabled
    $config = getReceiptForwardingConfig($db);
    if (!$config['enabled']) {
        return ['success' => false, 'message' => 'Receipt forwarding is disabled', 'log_id' => null];
    }

    $toEmail = $config['accounting_email'];
    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Accounting email address not configured', 'log_id' => null];
    }

    // 2. Resolve the receipt file path
    $filePath = $opts['file_path'] ?? null;
    $mediaId = $opts['media_id'] ?? null;

    if (!$filePath && $mediaId) {
        $filePath = resolveMediaFilePath((int)$mediaId, $db);
    }

    if (!$filePath || !file_exists($filePath)) {
        return ['success' => false, 'message' => 'Receipt file not found: ' . ($filePath ?? 'no path'), 'log_id' => null];
    }

    // 3. Build subject line
    $vendor = $opts['vendor'] ?? 'Unknown';
    $total  = $opts['total'] ?? 'Unknown';
    $date   = $opts['date'] ?? date('Y-m-d');
    $jobId  = $opts['job_id'] ?? null;

    $subject = sprintf(
        'Receipt - %s - $%s - %s - Job #%s',
        $vendor,
        $total,
        $date,
        $jobId ? (string)$jobId : 'n/a'
    );

    // 4. Build email body
    $body = buildReceiptEmailBody($opts, $config);

    // 5. Create log entry (queued status)
    $logId = createEmailLog($db, [
        'expense_id' => $opts['expense_id'] ?? null,
        'media_id'   => $mediaId,
        'to_email'   => $toEmail,
        'subject'    => $subject,
        'status'     => 'queued',
        'created_by' => $userId,
    ]);

    // 6. Send the email with attachment
    $fromName = $config['from_name'] ?: 'Mowology Receipts';
    $result = sendEmail($toEmail, $subject, $body, $filePath, $fromName);

    // 7. Update log entry
    if ($result['success']) {
        updateEmailLog($db, $logId, 'sent');

        // Mark expense as forwarded
        if (!empty($opts['expense_id'])) {
            $stmt = $db->prepare("
                UPDATE expenses
                SET forwarded_to_accounting = 1, forwarded_at = NOW(), status = 'forwarded'
                WHERE id = ?
            ");
            $stmt->execute([$opts['expense_id']]);
        }

        return [
            'success' => true,
            'message' => 'Receipt sent to ' . $toEmail,
            'log_id'  => $logId,
        ];
    } else {
        updateEmailLog($db, $logId, 'failed', $result['error'] ?? 'Unknown error');

        return [
            'success' => false,
            'message' => 'Failed to send receipt: ' . ($result['error'] ?? 'Unknown error'),
            'log_id'  => $logId,
        ];
    }
}

/**
 * Get receipt forwarding configuration from ops_settings.
 */
function getReceiptForwardingConfig(PDO $db): array
{
    $defaults = [
        'enabled'          => false,
        'accounting_email' => '',
        'from_name'        => 'Mowology Receipts',
        'auto_send'        => false,
    ];

    try {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key LIKE 'receipt_%'");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'enabled'          => !empty($rows['receipt_forwarding_enabled']) && $rows['receipt_forwarding_enabled'] !== 'false' && $rows['receipt_forwarding_enabled'] !== '0',
            'accounting_email' => $rows['receipt_accounting_email'] ?? '',
            'from_name'        => $rows['receipt_from_name'] ?? 'Mowology Receipts',
            'auto_send'        => !empty($rows['receipt_auto_send']) && $rows['receipt_auto_send'] !== 'false' && $rows['receipt_auto_send'] !== '0',
        ];
    } catch (Throwable $e) {
        error_log('getReceiptForwardingConfig error: ' . $e->getMessage());
        return $defaults;
    }
}

/**
 * Resolve a media_assets file_path to an absolute filesystem path.
 */
function resolveMediaFilePath(int $mediaId, PDO $db): ?string
{
    $stmt = $db->prepare("SELECT file_path FROM media_assets WHERE id = ?");
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['file_path'])) {
        return null;
    }

    $relativePath = $row['file_path'];

    // file_path is stored as relative from web root (e.g., /uploads/2026/02/abc123.jpg)
    // Resolve to absolute using PUBLIC_ROOT
    $absPath = PUBLIC_ROOT . '/' . ltrim($relativePath, '/');
    if (file_exists($absPath)) {
        return $absPath;
    }

    // Also try MEDIA_ROOT
    $mediaPath = MEDIA_ROOT . '/' . ltrim($relativePath, '/');
    if (file_exists($mediaPath)) {
        return $mediaPath;
    }

    // Try the path as-is (might already be absolute)
    if (file_exists($relativePath)) {
        return $relativePath;
    }

    error_log("resolveMediaFilePath: could not find file for media_id={$mediaId}, path={$relativePath}");
    return null;
}

/**
 * Build the HTML email body for a receipt forwarding email.
 */
function buildReceiptEmailBody(array $opts, array $config): string
{
    $vendor     = htmlspecialchars($opts['vendor'] ?? 'Unknown');
    $total      = htmlspecialchars($opts['total'] ?? 'Unknown');
    $gstAmount  = htmlspecialchars($opts['gst_amount'] ?? '');
    $subtotal   = htmlspecialchars($opts['subtotal'] ?? '');
    $date       = htmlspecialchars($opts['date'] ?? date('Y-m-d'));
    $jobId      = $opts['job_id'] ?? null;
    $clientName = htmlspecialchars($opts['client_name'] ?? '');
    $desc       = htmlspecialchars($opts['description'] ?? '');
    $category   = htmlspecialchars($opts['accounting_category'] ?? '');
    $expenseId  = $opts['expense_id'] ?? null;

    $crmLink = '';
    if ($expenseId) {
        $crmLink = '<p><a href="https://mowology.ca/crm/expenses_appstack.php?id=' . (int)$expenseId . '">View in CRM</a></p>';
    }

    $jobRef = $jobId ? "Job #" . (int)$jobId : 'n/a';
    $clientRef = $clientName ? " ({$clientName})" : '';

    return <<<HTML
<html>
<body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6;">
    <h2 style="color: #2D8659; margin-bottom: 5px;">Receipt — {$vendor}</h2>

    <table style="border-collapse: collapse; margin: 15px 0;">
        <tr><td style="padding: 4px 12px 4px 0; font-weight: bold;">Vendor:</td><td>{$vendor}</td></tr>
        <tr><td style="padding: 4px 12px 4px 0; font-weight: bold;">Date:</td><td>{$date}</td></tr>
        <tr><td style="padding: 4px 12px 4px 0; font-weight: bold;">Subtotal:</td><td>\${$subtotal}</td></tr>
        <tr><td style="padding: 4px 12px 4px 0; font-weight: bold;">GST (5%):</td><td>\${$gstAmount}</td></tr>
        <tr><td style="padding: 4px 12px 4px 0; font-weight: bold;">Total:</td><td><strong>\${$total}</strong></td></tr>
        <tr><td style="padding: 4px 12px 4px 0; font-weight: bold;">Category:</td><td>{$category}</td></tr>
        <tr><td style="padding: 4px 12px 4px 0; font-weight: bold;">Job:</td><td>{$jobRef}{$clientRef}</td></tr>
    </table>

    {$desc}
    {$crmLink}

    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="font-size: 12px; color: #999;">
        Sent from Mowology CRM &bull; Receipt image attached
    </p>
</body>
</html>
HTML;
}

/**
 * Create a receipt_email_log entry.
 */
function createEmailLog(PDO $db, array $data): int
{
    $stmt = $db->prepare("
        INSERT INTO receipt_email_log
            (expense_id, media_id, to_email, subject, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['expense_id'],
        $data['media_id'],
        $data['to_email'],
        $data['subject'],
        $data['status'],
        $data['created_by'],
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Update a receipt_email_log entry status.
 */
function updateEmailLog(PDO $db, int $logId, string $status, ?string $error = null): void
{
    $stmt = $db->prepare("
        UPDATE receipt_email_log
        SET status = ?, error_message = ?, sent_at = CASE WHEN ? = 'sent' THEN NOW() ELSE sent_at END
        WHERE id = ?
    ");
    $stmt->execute([$status, $error, $status, $logId]);
}
