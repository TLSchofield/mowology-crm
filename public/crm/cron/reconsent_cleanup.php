<?php
/**
 * Jobber Re-Consent Cleanup Cron
 *
 * Auto-archives non-responders after 30 days:
 * - Queue entries sent 30+ days ago with no confirmation → status = 'expired'
 * - Contacts still pending → receive_marketing = 0
 *
 * Schedule: weekly, Sundays at 6 AM Pacific
 *   0 6 * * 0 /usr/local/bin/php /home/mowology/public_html/crm/cron/reconsent_cleanup.php
 *
 * Also callable via POST from the CRM (admin only).
 *
 * @package Mowology\Marketing
 */

declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    requirePermission('admin');
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();
}

$db = getDB();

$expired  = 0;
$optedOut = 0;
$errors   = [];

try {
    // Find queue entries sent 30+ days ago that are still in 'sent' status
    $stmt = $db->query("
        SELECT jrq.id, jrq.contact_id
        FROM jobber_reconsent_queue jrq
        WHERE jrq.status = 'sent'
          AND jrq.sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $staleEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($staleEntries as $entry) {
        $contactId = (int)$entry['contact_id'];
        $queueId   = (int)$entry['id'];

        try {
            // Check if the contact has confirmed via marketing_optin_tokens
            $tokenStmt = $db->prepare("
                SELECT status FROM marketing_optin_tokens
                WHERE contact_id = ?
                ORDER BY created_at DESC LIMIT 1
            ");
            $tokenStmt->execute([$contactId]);
            $tokenRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);

            if ($tokenRow && $tokenRow['status'] === 'confirmed') {
                // Already confirmed — just update queue status, don't touch contact
                $db->prepare("UPDATE jobber_reconsent_queue SET status='expired' WHERE id=?")->execute([$queueId]);
                continue;
            }

            // Not confirmed — mark as expired and opt out
            $db->prepare("UPDATE jobber_reconsent_queue SET status='expired' WHERE id=?")->execute([$queueId]);
            $expired++;

            // Set receive_marketing = 0 (they didn't respond within 30 days)
            $db->prepare("UPDATE contacts SET receive_marketing = 0 WHERE id = ?")->execute([$contactId]);

            // Update token status to expired
            if ($tokenRow && $tokenRow['status'] === 'pending') {
                $db->prepare("UPDATE marketing_optin_tokens SET status='expired' WHERE contact_id=? AND status='pending'")->execute([$contactId]);
            }

            $optedOut++;

        } catch (Throwable $e) {
            $errors[] = "Queue #{$queueId}: " . $e->getMessage();
            error_log("reconsent_cleanup: Error processing queue #{$queueId} — " . $e->getMessage());
        }
    }

} catch (Throwable $e) {
    $msg = 'reconsent_cleanup fatal: ' . $e->getMessage();
    error_log($msg);
    if ($isCli) { echo $msg . "\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$summary = "Reconsent cleanup: expired=$expired, opted_out=$optedOut, checked=" . count($staleEntries);
error_log($summary);

if ($isCli) {
    echo $summary . "\n";
    if ($errors) echo "Errors:\n" . implode("\n", $errors) . "\n";
} else {
    echo json_encode([
        'success'   => true,
        'expired'   => $expired,
        'opted_out' => $optedOut,
        'checked'   => count($staleEntries),
        'errors'    => $errors,
    ]);
}
