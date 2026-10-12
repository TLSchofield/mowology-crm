<?php
/**
 * Cron: Campaign Email Sender
 *
 * Processes pending campaign_sends for campaigns in 'sending' status.
 * Renders template, sends email, updates tracking, logs activity.
 *
 * Run every 15 minutes (cron: 0,15,30,45 * * * *). Never write a slash-star cron
 * pattern in this docblock: the old comment closed it early and made the file a parse error.
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
// Under cron (CLI) nothing else defines getDB()/Database — the web shim gets them from auth.php.
require_once APP_ROOT . '/Core/config.php';

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
require_once APP_ROOT . '/Services/Messaging/TemplateRenderer.php';
require_once APP_ROOT . '/Modules/Marketing/Services/TrackingLinks.php';
require_once APP_ROOT . '/Modules/Consent/Services/CaslRules.php';

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

    // ── One run at a time (Fable review 2026-10-12) ──────────────────
    // The cron and the web "send now" POST could overlap and mail the same rows twice. A MySQL
    // named lock covers both (the cron and PHP-FPM do not share a temp dir); it is released when
    // this connection closes.
    if ((int)$db->query("SELECT GET_LOCK('mowology_campaign_sender', 0)")->fetchColumn() !== 1) {
        $msg = 'Another campaign_sender run is in progress.';
        if ($isCli) { echo "$msg\n"; exit(0); }
        echo json_encode(['success' => true, 'message' => $msg, 'sent' => 0]);
        exit;
    }
    // A row claimed by a run that died mid-send is not sent again blindly: it fails visibly.
    $db->exec("UPDATE campaign_sends SET status = 'failed', error_message = 'interrupted while sending — check before resending'
               WHERE status = 'sending' AND sent_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");

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

    // Migration 1195: a send can wait for the recipient's best time (Mia's send-time learning).
    // Only rows whose scheduled_at has come are sent; rows without one go as before.
    $hasSchedule = true;
    try {
        $db->query("SELECT scheduled_at FROM campaign_sends LIMIT 0");
    } catch (\Exception $e) {
        $hasSchedule = false;
    }
    $nowLocal = date('Y-m-d H:i:s'); // PHP's clock, the same one scheduled_at was written with

    // The consent ledger is the one consent source (canSendMarketing() asks it when it knows the
    // contact): bring it up to date once per run so a withdrawal or new opt-in counts today.
    if ($campaigns) {
        try {
            require_once APP_ROOT . '/Modules/Consent/Services/ConsentLedgerService.php';
            $ledger = new ConsentLedgerService($db);
            if ($ledger->ready()) $ledger->refresh();
        } catch (Throwable $e) {
            error_log('campaign_sender: consent ledger refresh failed: ' . $e->getMessage());
        }
    }

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
                   c.receive_marketing, c.consent_email_express_at, c.consent_email_implied_at,
                   p.address, p.city,
                   prod.name AS product_name, prod.base_price AS product_price,
                   prod.description AS product_description
            FROM campaign_sends cs
            LEFT JOIN contacts c ON c.id = cs.contact_id
            LEFT JOIN properties p ON p.id = (
                SELECT MIN(id) FROM properties WHERE site_contact_id = c.id
            )
            LEFT JOIN products prod ON prod.id = ?
            WHERE cs.campaign_id = ?
              AND cs.status = 'pending'
              " . ($hasSchedule ? "AND (cs.scheduled_at IS NULL OR cs.scheduled_at <= ?)" : "") . "
            ORDER BY " . ($hasSchedule ? "COALESCE(cs.scheduled_at, cs.created_at) ASC, " : "") . "cs.id ASC
            LIMIT $remaining
        ");
        $sends->execute($hasSchedule ? [$campaign['product_id'], $campaign['id'], $nowLocal] : [$campaign['product_id'], $campaign['id']]);
        $pendingSends = $sends->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pendingSends) && $hasSchedule) {
            // Nothing due yet, but people are still waiting for their time: not finished.
            $waiting = $db->prepare("SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = ? AND status = 'pending'");
            $waiting->execute([$campaign['id']]);
            if ((int)$waiting->fetchColumn() > 0) continue;
        }

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

            // CASL: re-verify consent at send time (recipient list may be stale)
            $contactForConsent = [
                'id'                         => (int)($send['contact_id'] ?? 0),
                'email'                      => $email,
                'receive_marketing'          => $send['receive_marketing'] ?? 0,
                'consent_email_express_at'   => $send['consent_email_express_at'] ?? null,
                'consent_email_implied_at'   => $send['consent_email_implied_at'] ?? null,
            ];
            if (!canSendMarketing($contactForConsent, 'email')) {
                $db->prepare("UPDATE campaign_sends SET status = 'skipped', error_message = 'no_consent' WHERE id = ?")
                   ->execute([$send['id']]);
                $totalFailed++;
                continue;
            }

            // Claim the row before sending (like social_publisher.php): only one run may send it.
            $claim = $db->prepare("UPDATE campaign_sends SET status = 'sending', sent_at = NOW() WHERE id = ? AND status = 'pending'");
            $claim->execute([$send['id']]);
            if ($claim->rowCount() !== 1) continue;

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

            // Add open tracking pixel (signed — TrackingLinks, Fable review 2026-10-12)
            $fullEmail = str_replace(
                '</body>',
                '<img src="' . htmlspecialchars(TrackingLinks::openUrl((int)$send['id']), ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="display:none;" /></body>',
                $fullEmail
            );

            // Rewrite links for click tracking — signed, and never the unsubscribe link (that is not a click)
            $sendId = (int)$send['id'];
            $fullEmail = preg_replace_callback(
                '/href="(https?:\/\/(?!mowology\.ca\/crm\/api\/)[^"]+)"/',
                static function (array $m) use ($sendId): string {
                    $target = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                    if (CaslRules::isUnsubscribeLink($target)) return $m[0];
                    return 'href="' . htmlspecialchars(TrackingLinks::clickUrl($sendId, $target), ENT_QUOTES, 'UTF-8') . '"';
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
