<?php
/**
 * Cron: Campaign Email Sender
 *
 * Processes pending campaign_sends for campaigns in 'sending' status.
 * Renders template, sends email, updates tracking, logs activity.
 *
 * Run every 15 minutes: */15 * * * * php /home/mowology/app/Modules/Marketing/Cron/campaign_sender.php
 * Also callable via web POST: POST /crm/cron/campaign_sender.php
 *
 * Sends up to 20 emails per run (shared hosting SMTP throttle).
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
require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
require_once APP_ROOT . '/Services/Messaging/TemplateRenderer.php';

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

$batchSize = 20; // Max emails per run

try {
    $db = getDB();

    // ── Check if tables exist ────────────────────────────────────────
    try {
        $db->query("SELECT 1 FROM marketing_campaigns LIMIT 0");
    } catch (\Exception $e) {
        $msg = 'marketing_campaigns table does not exist. Run migration 604 first.';
        if ($isCli) { echo "SKIP: $msg\n"; exit(0); }
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    // ── Find campaigns in 'sending' status ───────────────────────────
    $campaigns = $db->query("
        SELECT mc.*, et.subject AS template_subject, et.body_html AS template_body
        FROM marketing_campaigns mc
        LEFT JOIN email_templates et ON et.id = mc.template_id
        WHERE mc.status = 'sending'
        ORDER BY mc.created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($campaigns)) {
        $msg = 'No campaigns in sending status.';
        if ($isCli) { echo "$msg\n"; exit(0); }
        echo json_encode(['success' => true, 'message' => $msg, 'sent' => 0]);
        exit;
    }

    $totalSent = 0;
    $totalFailed = 0;
    $campaignsProcessed = 0;

    foreach ($campaigns as $campaign) {
        if ($totalSent >= $batchSize) break; // Respect throttle

        $subject = $campaign['subject_override'] ?: ($campaign['template_subject'] ?? '');
        $bodyTemplate = $campaign['body_override'] ?: ($campaign['template_body'] ?? '');

        if (empty($subject) || empty($bodyTemplate)) {
            error_log("Campaign {$campaign['id']} has no subject or body — skipping");
            continue;
        }

        // Get pending sends for this campaign
        $remaining = $batchSize - $totalSent;
        $sends = $db->prepare("
            SELECT cs.*, c.first_name, c.last_name, c.email AS contact_email,
                   p.address, p.city,
                   prod.name AS product_name, prod.base_price AS product_price,
                   prod.description AS product_description
            FROM campaign_sends cs
            LEFT JOIN contacts c ON c.id = cs.contact_id
            LEFT JOIN properties p ON p.site_contact_id = c.id
            LEFT JOIN products prod ON prod.id = ?
            WHERE cs.campaign_id = ?
              AND cs.status = 'pending'
            ORDER BY cs.id ASC
            LIMIT $remaining
        ");
        $sends->execute([$campaign['product_id'], $campaign['id']]);
        $pendingSends = $sends->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pendingSends)) {
            // All sends processed — mark campaign as completed
            $db->prepare("
                UPDATE marketing_campaigns
                SET status = 'completed',
                    sent_count = (SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = ? AND status = 'sent'),
                    open_count = (SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = ? AND opened_at IS NOT NULL),
                    click_count = (SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = ? AND clicked_at IS NOT NULL)
                WHERE id = ?
            ")->execute([$campaign['id'], $campaign['id'], $campaign['id'], $campaign['id']]);
            $campaignsProcessed++;
            continue;
        }

        foreach ($pendingSends as $send) {
            $email = $send['contact_email'] ?? $send['email'];
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $db->prepare("UPDATE campaign_sends SET status = 'failed', error_message = 'Invalid email' WHERE id = ?")
                   ->execute([$send['id']]);
                $totalFailed++;
                continue;
            }

            // Build merge data
            $contact = [
                'first_name' => $send['first_name'] ?? '',
                'last_name' => $send['last_name'] ?? '',
                'email' => $email,
            ];
            $property = [
                'address' => $send['address'] ?? '',
                'city' => $send['city'] ?? '',
            ];
            $product = null;
            if (!empty($send['product_name'])) {
                $product = [
                    'name' => $send['product_name'],
                    'base_price' => $send['product_price'] ?? '',
                    'description' => $send['product_description'] ?? '',
                ];
            }

            $unsubUrl = generateUnsubscribeUrl($email, (int)$send['id']);
            $mergeData = buildMergeData($contact, $property, $product, $unsubUrl);

            // Add CTA with campaign tracking
            $mergeData['cta_url'] = 'https://mowology.ca/quote?src=campaign-' . $campaign['id'];

            // Render
            $renderedSubject = renderTemplate($subject, $mergeData);
            $renderedBody = renderTemplate($bodyTemplate, $mergeData);
            $fullEmail = wrapInBrandedEmail($renderedBody, $unsubUrl);

            // Add open tracking pixel
            $fullEmail = str_replace(
                '</body>',
                '<img src="https://mowology.ca/crm/api/track-open.php?sid=' . $send['id'] . '" width="1" height="1" alt="" style="display:none;" /></body>',
                $fullEmail
            );

            // Rewrite external links for click tracking (exclude unsubscribe link)
            $sendId = $send['id'];
            $fullEmail = preg_replace_callback(
                '/href="(https?:\/\/(?!mowology\.ca\/crm\/api\/)[^"]+)"/',
                static function (array $m) use ($sendId): string {
                    $encoded = rtrim(strtr(base64_encode($m[1]), '+/', '-_'), '=');
                    return 'href="https://mowology.ca/crm/api/track-click.php?sid=' . $sendId . '&url=' . $encoded . '"';
                },
                $fullEmail
            ) ?? $fullEmail;

            // Send
            $result = sendEmail($email, $renderedSubject, $fullEmail);

            if ($result['success']) {
                $db->prepare("UPDATE campaign_sends SET status = 'sent', sent_at = NOW() WHERE id = ?")
                   ->execute([$send['id']]);
                $totalSent++;

                // Log to communication_log
                try {
                    $db->prepare("
                        INSERT INTO communication_log (contact_id, type, direction, subject, message, to_email, status, created_by, created_at)
                        VALUES (?, 'email', 'outbound', ?, ?, ?, 'sent', NULL, NOW())
                    ")->execute([
                        $send['contact_id'],
                        $renderedSubject,
                        'Campaign: ' . $campaign['name'],
                        $email,
                    ]);
                } catch (\Exception $e) {} // Non-fatal

                // Log to activity_log
                try {
                    $db->prepare("
                        INSERT INTO activity_log (contact_id, action, details, created_at)
                        VALUES (?, 'campaign_email', ?, NOW())
                    ")->execute([
                        $send['contact_id'],
                        'Campaign "' . $campaign['name'] . '" email sent',
                    ]);
                } catch (\Exception $e) {} // Non-fatal
            } else {
                $errorMsg = $result['error'] ?? 'Unknown send failure';
                $db->prepare("UPDATE campaign_sends SET status = 'failed', error_message = ? WHERE id = ?")
                   ->execute([substr($errorMsg, 0, 500), $send['id']]);
                $totalFailed++;
            }

            // Brief pause between sends (100ms) to avoid SMTP throttling
            usleep(100000);
        }

        // Update campaign sent count
        $db->prepare("
            UPDATE marketing_campaigns
            SET sent_count = (SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = ? AND status = 'sent')
            WHERE id = ?
        ")->execute([$campaign['id'], $campaign['id']]);

        $campaignsProcessed++;
    }

    // ── Result ───────────────────────────────────────────────────────
    $result = [
        'success'             => true,
        'message'             => "Campaign sender: {$totalSent} sent, {$totalFailed} failed, {$campaignsProcessed} campaigns processed",
        'sent'                => $totalSent,
        'failed'              => $totalFailed,
        'campaigns_processed' => $campaignsProcessed,
    ];

    if ($isCli) {
        echo $result['message'] . "\n";
    } else {
        echo json_encode($result);
    }

} catch (\Throwable $e) {
    $error = 'campaign_sender error: ' . $e->getMessage();
    error_log($error);
    if ($isCli) { echo "ERROR: $error\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => $error]);
}
