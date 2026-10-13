<?php
/**
 * Jobber Re-Consent Email Sender (Cron)
 *
 * Sends up to 10 opt-in emails per run from approved queue entries.
 * Schedule: once daily at 9 AM Pacific
 *   0 9 * * * /usr/local/bin/php /home/mowology/public_html/crm/cron/reconsent_sender.php
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

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';

$db = getDB();
$limit      = 10;
$sent       = 0;
$failed     = 0;
$errors     = [];
$startMs    = (int) round(microtime(true) * 1000);
$fromWeb    = !$isCli;
$cronStatus = 'success';
$cronError  = null;

// Fetch next batch of queued contacts
$stmt = $db->prepare("
    SELECT jrq.id, jrq.contact_id, jrq.attempts, c.email, c.first_name, c.last_name
    FROM jobber_reconsent_queue jrq
    JOIN contacts c ON jrq.contact_id = c.id
    WHERE jrq.status = 'queued'
      AND c.email IS NOT NULL AND c.email != ''
      AND c.is_active = 1
      AND jrq.attempts < 3
    ORDER BY jrq.created_at ASC
    LIMIT $limit
");
$stmt->execute();
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($contacts)) {
    $msg = 'No queued contacts to send.';
    if ($isCli) { echo $msg . "\n"; exit(0); }
    echo json_encode(['success' => true, 'message' => $msg, 'sent' => 0]);
    exit;
}

foreach ($contacts as $row) {
    $contactId = (int)$row['contact_id'];
    $queueId   = (int)$row['id'];

    try {
        // Check for existing confirmed/unsubscribed token — skip
        $existing = $db->prepare("SELECT id, status FROM marketing_optin_tokens WHERE contact_id=? ORDER BY created_at DESC LIMIT 1");
        $existing->execute([$contactId]);
        $existingToken = $existing->fetch(PDO::FETCH_ASSOC);

        if ($existingToken && in_array($existingToken['status'], ['confirmed', 'unsubscribed'])) {
            $db->prepare("UPDATE jobber_reconsent_queue SET status='skipped', error_message=? WHERE id=?")
               ->execute(['Already ' . $existingToken['status'], $queueId]);
            continue;
        }

        // Generate token
        $rawToken  = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        if ($existingToken && $existingToken['status'] === 'pending') {
            $db->prepare("UPDATE marketing_optin_tokens SET token=?, expires_at=?, resent_count=resent_count+1, last_resent_at=NOW() WHERE id=?")
               ->execute([$rawToken, $expiresAt, $existingToken['id']]);
        } else {
            $db->prepare("INSERT INTO marketing_optin_tokens (contact_id, email, token, status, sent_at, expires_at) VALUES (?,?,?,'pending',NOW(),?)")
               ->execute([$contactId, $row['email'], $rawToken, $expiresAt]);
        }

        // Build email
        $baseUrl    = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
        $confirmUrl = $baseUrl . '/crm/api/optin-confirm.php?token=' . urlencode($rawToken);
        $firstName  = $row['first_name'] ?: 'Valued Customer';

        $subject = 'Please confirm your email preferences — Mowology Landscaping';
        $body = '<h2>Hi ' . htmlspecialchars($firstName) . ',</h2>
<p>We\'ve recently upgraded our marketing system and want to make sure you still want to hear from us.</p>
<p>We send occasional updates about seasonal services, special offers, and landscaping tips tailored to your property.</p>
<p style="margin:24px 0;">
  <a href="' . htmlspecialchars($confirmUrl) . '"
     style="background:#2D8659;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:600;display:inline-block;">
    ✓ Yes, keep me subscribed
  </a>
</p>
<p style="color:#666;font-size:13px;">If you do not wish to receive marketing emails, simply ignore this message. You can always unsubscribe at any time.</p>
<p style="color:#666;font-size:13px;">This link expires in 30 days.</p>';

        $displayName = trim($row['first_name'] . ' ' . ($row['last_name'] ?? ''));
        $emailSent = sendCrmEmail($row['email'], $subject, $body, $displayName);

        if ($emailSent) {
            $db->prepare("UPDATE marketing_optin_tokens SET sent_at=NOW() WHERE token=?")->execute([$rawToken]);
            $db->prepare("UPDATE jobber_reconsent_queue SET status='sent', sent_at=NOW() WHERE id=?")->execute([$queueId]);
            $sent++;
        } else {
            throw new RuntimeException('sendCrmEmail returned false');
        }

    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        $attempts = (int)$row['attempts'] + 1;
        $newStatus = $attempts >= 3 ? 'failed' : 'queued';
        $db->prepare("UPDATE jobber_reconsent_queue SET status=?, attempts=?, error_message=? WHERE id=?")
           ->execute([$newStatus, $attempts, $errorMsg, $queueId]);
        $failed++;
        $errors[] = "Contact #$contactId: $errorMsg";
        error_log("reconsent_sender: Failed contact #$contactId — $errorMsg");
    }

    usleep(200000); // 200ms between sends
}

$summary = "Reconsent sender: sent=$sent, failed=$failed, total=" . count($contacts);
error_log($summary);

$durationMs = (int) round(microtime(true) * 1000) - $startMs;
recordCronRun(
    'reconsent_sender',
    $cronStatus,
    "Sent: {$sent}, Failed: {$failed}, Total: " . count($contacts),
    $durationMs,
    $cronError,
    $fromWeb
);

if ($isCli) {
    echo $summary . "\n";
    if ($errors) echo "Errors:\n" . implode("\n", $errors) . "\n";
} else {
    echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed, 'total' => count($contacts), 'errors' => $errors]);
}
