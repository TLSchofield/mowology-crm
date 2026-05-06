<?php
/**
 * Migration: Quote Follow-Up Tracking
 *
 * Adds follow-up tracking columns to quotes, seeds the quote_followup
 * email template, and configures the automation rules for a 3-day and
 * 7-day follow-up sequence.
 *
 * Run once: https://mowology.ca/crm/api/run-migration-quote-followup.php
 */
declare(strict_types=1);

// Bootstrap
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!in_array(getCurrentUser()['role'] ?? '', ['admin', 'owner'])) {
session_write_close();
    http_response_code(403); die('Admin only');
}

$db = getDB();
$steps = [];

// ── Step 1: quotes.follow_up_sent_at ──────────────────────────────────────
try {
    $db->exec("ALTER TABLE quotes ADD COLUMN follow_up_sent_at DATETIME NULL DEFAULT NULL");
    $steps[] = ['ok', 'quotes.follow_up_sent_at added'];
} catch (PDOException $e) {
    $steps[] = (str_contains($e->getMessage(), 'Duplicate column') || str_contains($e->getMessage(), 'already exists'))
        ? ['skip', 'quotes.follow_up_sent_at already exists']
        : ['fail', 'quotes.follow_up_sent_at: ' . $e->getMessage()];
}

// ── Step 2: quotes.follow_up_count ────────────────────────────────────────
try {
    $db->exec("ALTER TABLE quotes ADD COLUMN follow_up_count TINYINT UNSIGNED NOT NULL DEFAULT 0");
    $steps[] = ['ok', 'quotes.follow_up_count added'];
} catch (PDOException $e) {
    $steps[] = (str_contains($e->getMessage(), 'Duplicate column') || str_contains($e->getMessage(), 'already exists'))
        ? ['skip', 'quotes.follow_up_count already exists']
        : ['fail', 'quotes.follow_up_count: ' . $e->getMessage()];
}

// ── Step 3: Index on sent_at for trigger queries ───────────────────────────
try {
    $db->exec("CREATE INDEX idx_quotes_sent_at ON quotes (status, sent_at)");
    $steps[] = ['ok', 'Index idx_quotes_sent_at created'];
} catch (PDOException $e) {
    $steps[] = (str_contains($e->getMessage(), 'Duplicate key') || str_contains($e->getMessage(), 'already exists'))
        ? ['skip', 'Index idx_quotes_sent_at already exists']
        : ['fail', 'Index: ' . $e->getMessage()];
}

// ── Step 4: Seed quote_followup email template ────────────────────────────
// email_templates uses template_key + body_text (loadEmailTemplate reads these columns)
$followupTemplateBody = "Hi {{customer_first_name}},\n\nJust following up on quote {{quote_number}} for {{quote_amount}} we sent over. We want to make sure it reached you — sometimes these land in junk mail!\n\nYour quote is valid until {{quote_valid_until}}. Click the button below to review and accept it — it only takes a moment.\n\nIf you have any questions or want to adjust anything, don't hesitate to reach out. We'd love to earn your business!\n\n{{company_name}}\n{{company_phone}}";

try {
    $check = $db->prepare("SELECT id FROM email_templates WHERE template_key = 'quote_followup' LIMIT 1");
    $check->execute();
    if ($check->fetchColumn()) {
        $steps[] = ['skip', 'email_templates: quote_followup already exists'];
    } else {
        $db->prepare("
            INSERT INTO email_templates (template_key, name, subject, body_text, is_active, created_at)
            VALUES ('quote_followup', 'Quote Follow-Up', 'Following up on your Mowology quote ({{quote_number}})', ?, 1, NOW())
        ")->execute([$followupTemplateBody]);
        $steps[] = ['ok', 'email_templates: quote_followup seeded'];
    }
} catch (PDOException $e) {
    $steps[] = ['fail', 'email_templates seeding: ' . $e->getMessage()];
}

// ── Step 5: Fix existing Quote Follow-Up rule to use proper send_email action ─
// The original rule used send_campaign with template_category, which requires
// a campaign to exist. Replace with send_email for reliability.
$actions3day = json_encode([
    [
        'type'   => 'send_email',
        'config' => [
            'subject'     => 'Still interested in your Mowology quote?',
            'body'        => '<p>Hi {{first_name}},</p><p>Just a friendly follow-up on quote <strong>{{quote_number}}</strong> for {{quote_amount}}. It\'s valid until {{quote_valid_until}}. Click below to review and accept — or just reply to this email with any questions.</p><p>— The Mowology Team<br><strong>(778) 846-9273</strong></p>',
            'use_template' => 'quote_followup',
        ],
    ],
    [
        'type'   => 'notify_admin',
        'config' => ['message' => '3-day quote follow-up sent to {{first_name}} {{last_name}} ({{quote_number}})'],
    ],
]);

try {
    // Update or insert the 3-day rule
    $existingRule = $db->prepare("SELECT id FROM automation_rules WHERE name LIKE 'Quote Follow-Up%' LIMIT 1");
    $existingRule->execute();
    $ruleId = $existingRule->fetchColumn();

    if ($ruleId) {
        $db->prepare("
            UPDATE automation_rules SET
                name          = 'Quote Follow-Up — 3 Days (No Response)',
                description   = 'Automatically follows up on quotes that were sent 3+ days ago with no acceptance. Works for both viewed and unviewed quotes.',
                trigger_type  = 'quote_followup',
                trigger_config = '{\"days_since_sent\": 3}',
                conditions    = '[]',
                actions       = ?,
                cooldown_hours = 168,
                is_enabled    = 1
            WHERE id = ?
        ")->execute([$actions3day, $ruleId]);
        $steps[] = ['ok', "Existing Quote Follow-Up rule (#{$ruleId}) updated to quote_followup trigger"];
    } else {
        $db->prepare("
            INSERT INTO automation_rules
                (name, description, is_enabled, trigger_type, trigger_config, conditions, actions, cooldown_hours, created_at)
            VALUES
                ('Quote Follow-Up — 3 Days (No Response)',
                 'Automatically follows up on quotes sent 3+ days ago with no acceptance.',
                 1, 'quote_followup', '{\"days_since_sent\": 3}', '[]', ?, 168, NOW())
        ")->execute([$actions3day]);
        $steps[] = ['ok', 'Quote Follow-Up 3-day rule seeded'];
    }
} catch (PDOException $e) {
    $steps[] = ['fail', 'Quote Follow-Up 3-day rule: ' . $e->getMessage()];
}

// ── Step 6: Seed 7-day follow-up rule ─────────────────────────────────────
$actions7day = json_encode([
    [
        'type'   => 'send_email',
        'config' => [
            'subject'     => 'Last chance — your Mowology quote expires soon',
            'body'        => '<p>Hi {{first_name}},</p><p>Your quote <strong>{{quote_number}}</strong> for {{quote_amount}} is expiring on {{quote_valid_until}}. We don\'t want you to miss out!</p><p>Click below to accept, or call us at <strong>(778) 846-9273</strong> if you have questions. We\'re happy to adjust anything to fit your needs.</p><p>— The Mowology Team</p>',
            'use_template' => 'quote_followup',
        ],
    ],
    [
        'type'   => 'notify_admin',
        'config' => ['message' => '7-day URGENT quote follow-up sent to {{first_name}} {{last_name}} ({{quote_number}})'],
    ],
]);

try {
    $check7 = $db->prepare("SELECT id FROM automation_rules WHERE name LIKE '%7 Day%' AND trigger_type = 'quote_followup' LIMIT 1");
    $check7->execute();
    if ($check7->fetchColumn()) {
        $steps[] = ['skip', '7-day Quote Follow-Up rule already exists'];
    } else {
        $db->prepare("
            INSERT INTO automation_rules
                (name, description, is_enabled, trigger_type, trigger_config, conditions, actions, cooldown_hours, created_at)
            VALUES
                ('Quote Follow-Up — 7 Days (Last Chance)',
                 'Sends an urgency follow-up for quotes that have been outstanding for 7+ days.',
                 1, 'quote_followup', '{\"days_since_sent\": 7}', '[]', ?, 168, NOW())
        ")->execute([$actions7day]);
        $steps[] = ['ok', '7-day Quote Follow-Up rule seeded'];
    }
} catch (PDOException $e) {
    $steps[] = ['fail', '7-day rule seeding: ' . $e->getMessage()];
}

// ── Output ─────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head><title>Migration: Quote Follow-Up</title>
<style>
  body{font-family:monospace;padding:24px;background:#f4f6f8;color:#222}
  h2{margin-bottom:16px}
  .step{padding:8px 12px;margin:4px 0;border-radius:4px;font-size:14px}
  .ok{background:#d4edda;color:#155724}
  .skip{background:#fff3cd;color:#856404}
  .fail{background:#f8d7da;color:#721c24}
  .done{margin-top:20px;font-size:16px;font-weight:bold;color:#155724}
</style>
</head>
<body>
<h2>Migration: Quote Follow-Up Tracking</h2>
<?php foreach ($steps as [$type, $msg]): ?>
<div class="step <?php echo $type; ?>">
  <?php echo $type === 'ok' ? '✅' : ($type === 'skip' ? '⏭️' : '❌'); ?>
  <?php echo htmlspecialchars($msg); ?>
</div>
<?php endforeach; ?>
<p class="done">Migration complete — <?php echo count(array_filter($steps, fn($s) => $s[0] === 'ok')); ?> applied, <?php echo count(array_filter($steps, fn($s) => $s[0] === 'skip')); ?> skipped, <?php echo count(array_filter($steps, fn($s) => $s[0] === 'fail')); ?> failed.</p>
<p><strong>Next steps:</strong></p>
<ul>
  <li>Add cron: <code>*/10 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/automation_runner.php >> /tmp/automation_runner.log 2>&1</code></li>
  <li><a href="/crm/quotes_appstack.php">View Quotes</a> — check "Needs Follow-Up" tab</li>
</ul>
</body>
</html>
