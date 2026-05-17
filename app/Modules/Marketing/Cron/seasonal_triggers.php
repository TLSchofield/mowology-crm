<?php
/**
 * Cron: Seasonal Marketing Triggers
 *
 * Runs on the 1st of each month. For each product with a trigger_month
 * matching the current month, creates a queued campaign targeting contacts
 * who have NOT purchased that product.
 *
 * Campaigns are queued (not auto-sent) for admin review — hybrid approach.
 *
 * Run monthly: 0 8 1 * * php /home/mowology/app/Modules/Marketing/Cron/seasonal_triggers.php
 * Also callable via web POST: POST /crm/cron/seasonal_triggers.php
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

    // ── Check required tables exist ──────────────────────────────────
    $hasCampaigns = false;
    try {
        $db->query("SELECT 1 FROM marketing_campaigns LIMIT 0");
        $hasCampaigns = true;
    } catch (\Exception $e) {}

    $hasTriggerMonth = false;
    try {
        $check = $db->query("SHOW COLUMNS FROM products LIKE 'trigger_month'");
        $hasTriggerMonth = ($check->rowCount() > 0);
    } catch (\Exception $e) {}

    if (!$hasCampaigns) {
        $msg = 'marketing_campaigns table does not exist. Run migration 604 first.';
        if ($isCli) { echo "SKIP: $msg\n"; exit(0); }
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    if (!$hasTriggerMonth) {
        $msg = 'products.trigger_month column does not exist. Run migration 600 first.';
        if ($isCli) { echo "SKIP: $msg\n"; exit(0); }
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    $currentMonth = (int)date('n'); // 1-12
    $monthName = date('F'); // January, February, etc.

    // ── Find products with trigger_month = current month ─────────────
    $products = $db->prepare("
        SELECT id, name, description, base_price
        FROM products
        WHERE trigger_month = ?
          AND is_active = 1
    ");
    $products->execute([$currentMonth]);
    $triggerProducts = $products->fetchAll(PDO::FETCH_ASSOC);

    if (empty($triggerProducts)) {
        $msg = "No products have trigger_month = {$currentMonth} ({$monthName}).";
        if ($isCli) { echo "$msg\n"; exit(0); }
        echo json_encode(['success' => true, 'message' => $msg, 'campaigns_created' => 0]);
        exit;
    }

    // ── Find the seasonal upsell template ────────────────────────────
    $template = $db->query("
        SELECT id FROM email_templates
        WHERE category = 'seasonal_upsell' AND is_active = 1
        ORDER BY id ASC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    $templateId = $template ? (int)$template['id'] : null;

    $campaignsCreated = 0;
    $totalRecipients = 0;

    foreach ($triggerProducts as $product) {
        // Check if a campaign already exists this month for this product
        $existing = $db->prepare("
            SELECT id FROM marketing_campaigns
            WHERE product_id = ?
              AND trigger_type = 'seasonal'
              AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ");
        $existing->execute([$product['id']]);
        if ($existing->fetch()) {
            if ($isCli) echo "  Skip: Campaign for \"{$product['name']}\" already exists this month.\n";
            continue;
        }

        // Count potential recipients (contacts who haven't purchased this product)
        $recipientCount = $db->prepare("
            SELECT COUNT(DISTINCT c.id)
            FROM contacts c
            WHERE c.is_active = 1
              AND c.receive_marketing = 1
              AND c.email IS NOT NULL
              AND c.email != ''
              AND c.email NOT IN (SELECT email COLLATE utf8mb4_0900_ai_ci FROM marketing_unsubscribes)
              AND c.id NOT IN (
                  SELECT contact_id FROM contact_product_history WHERE product_id = ?
              )
        ");
        $recipientCount->execute([$product['id']]);
        $count = (int)$recipientCount->fetchColumn();

        if ($count === 0) {
            if ($isCli) echo "  Skip: No eligible recipients for \"{$product['name']}\".\n";
            continue;
        }

        // Create a queued campaign
        $campaignName = "{$monthName} — {$product['name']} Upsell";
        $db->prepare("
            INSERT INTO marketing_campaigns
                (name, template_id, product_id, segment_type, trigger_type, auto_send, status,
                 recipient_count, created_by, created_at)
            VALUES (?, ?, ?, 'never_purchased_product', 'seasonal', 0, 'queued', ?, NULL, NOW())
        ")->execute([
            $campaignName,
            $templateId,
            $product['id'],
            $count,
        ]);

        $campaignId = (int)$db->lastInsertId();

        // Populate campaign_sends
        $recipients = $db->prepare("
            SELECT DISTINCT c.id, c.email
            FROM contacts c
            WHERE c.is_active = 1
              AND c.receive_marketing = 1
              AND c.email IS NOT NULL
              AND c.email != ''
              AND c.email NOT IN (SELECT email COLLATE utf8mb4_0900_ai_ci FROM marketing_unsubscribes)
              AND c.id NOT IN (
                  SELECT contact_id FROM contact_product_history WHERE product_id = ?
              )
        ");
        $recipients->execute([$product['id']]);

        $insertSend = $db->prepare("
            INSERT IGNORE INTO campaign_sends (campaign_id, contact_id, email, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");

        while ($r = $recipients->fetch(PDO::FETCH_ASSOC)) {
            $insertSend->execute([$campaignId, $r['id'], $r['email']]);
        }

        $campaignsCreated++;
        $totalRecipients += $count;

        if ($isCli) echo "  Created: \"{$campaignName}\" → {$count} recipients (queued for review)\n";

        // Log to activity_log
        try {
            $db->prepare("
                INSERT INTO activity_log (action, details, created_at)
                VALUES ('seasonal_campaign', ?, NOW())
            ")->execute([
                "Auto-created seasonal campaign \"{$campaignName}\" with {$count} recipients",
            ]);
        } catch (\Exception $e) {} // Non-fatal
    }

    // ── Result ───────────────────────────────────────────────────────
    $result = [
        'success'            => true,
        'message'            => "Seasonal triggers: {$campaignsCreated} campaigns created, {$totalRecipients} total recipients",
        'campaigns_created'  => $campaignsCreated,
        'total_recipients'   => $totalRecipients,
    ];

    if ($isCli) {
        echo "\n" . $result['message'] . "\n";
    } else {
        echo json_encode($result);
    }

} catch (\Throwable $e) {
    $error = 'seasonal_triggers error: ' . $e->getMessage();
    error_log($error);
    if ($isCli) { echo "ERROR: $error\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => $error]);
}
