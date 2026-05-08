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
$limit = 10;
$sent = 0;
$failed = 0;
$errors = [];

// Fetch next batch of queued contacts
$stmt = $db->prepare("
    SELECT jrq.id, jrq.contact_id, jrq.attempts, c.email, c.first_name, c.last_name
    FROM jobber_reconsent_queue jrq
    JOIN contacts c ON jrq.contact_id = c.id
    WHERE jrq.status = 'queued'
      AND c.email IS NOT NULL AND c.email != ''
      AND c.is_active = 1
      AND jrq.attempts < 3
    ORDER BY jrq.sort_priority ASC, jrq.created_at ASC
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
        $confirmUrl = $baseUrl . '/optin-confirm.php?token=' . urlencode($rawToken);
        $firstName  = $row['first_name'] ?: 'Valued Customer';

        // Determine if current client for email variant
        $isCurrent = false;
        try {
            $curStmt = $db->prepare("
                SELECT 1 FROM properties p
                JOIN job_plans jp ON jp.property_id = p.id
                JOIN job_visits jv ON jv.plan_id = jp.id
                WHERE p.site_contact_id = ? AND jv.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                LIMIT 1
            ");
            $curStmt->execute([$contactId]);
            $isCurrent = (bool)$curStmt->fetchColumn();
        } catch (Throwable $e) { /* not critical */ }

        if ($isCurrent) {
            $subject = 'Stay in the loop — Mowology Landscaping';
            $body = '<h2>Hi ' . htmlspecialchars($firstName) . ',</h2>
<p>As a valued Mowology client, we want to make sure you\'re getting the most out of our services.</p>
<p>We occasionally send updates about seasonal care tips, scheduling reminders, and exclusive offers for existing clients.</p>
<p>To keep receiving these updates, just confirm below:</p>
<p style="margin:24px 0;">
  <a href="' . htmlspecialchars($confirmUrl) . '"
     style="background:#2D8659;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:600;display:inline-block;">
    Yes, keep me subscribed
  </a>
</p>
<p style="color:#666;font-size:13px;">If you prefer not to receive these updates, simply ignore this email. Your service schedule is not affected either way.</p>
<p style="color:#666;font-size:13px;">This link expires in 30 days.</p>';
        } else {
            $subject = 'We\'d love to hear from you — Mowology Landscaping';
            $body = '<h2>Hi ' . htmlspecialchars($firstName) . ',</h2>
<p>It\'s been a while since we last worked together, and we wanted to reach out.</p>
<p>Spring is here and our crews are gearing up for the season. Whether you need lawn care, garden maintenance, hedge trimming, or a fresh landscape design, we\'d love to help again.</p>
<p>If you\'d like to stay on our list for seasonal offers and updates, just confirm below:</p>
<p style="margin:24px 0;">
  <a href="' . htmlspecialchars($confirmUrl) . '"
     style="background:#2D8659;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:600;display:inline-block;">
    Yes, keep me subscribed
  </a>
</p>
<p style="color:#666;font-size:13px;">If you\'re no longer interested, simply ignore this email and you won\'t hear from us again.</p>
<p style="color:#666;font-size:13px;">This link expires in 30 days.</p>';
        }

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

if ($isCli) {
    echo $summary . "\n";
    if ($errors) echo "Errors:\n" . implode("\n", $errors) . "\n";
} else {
    echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed, 'total' => count($contacts), 'errors' => $errors]);
}
