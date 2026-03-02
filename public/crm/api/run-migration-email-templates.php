<?php
/**
 * Migration: Create email_templates table and seed default templates.
 *
 * Run once at: /crm/api/run-migration-email-templates.php
 * Admin-only. Safe to run multiple times (idempotent).
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();

// Admin-only: require role admin or manager
$allowedRoles = ['admin', 'manager'];
if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
    http_response_code(403);
    die('Forbidden — admin or manager role required.');
}

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();

// ── 1. Create table (drop first if columns are wrong) ───────────────────────
// Check if table exists and has the expected column
$hasTable = $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates'"
)->fetchColumn();

if ($hasTable) {
    $hasCol = $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'email_templates'
           AND COLUMN_NAME  = 'template_key'"
    )->fetchColumn();

    if (!$hasCol) {
        // Table exists but has wrong schema — drop and recreate
        $db->exec("DROP TABLE email_templates");
        echo "⚠ Dropped existing email_templates (wrong schema) — recreating\n";
        $hasTable = false;
    }
}

if (!$hasTable) {
    $db->exec("
        CREATE TABLE email_templates (
            id           INT          NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(50)  NOT NULL,
            name         VARCHAR(100) NOT NULL,
            subject      VARCHAR(255) NOT NULL,
            body_text    TEXT         NOT NULL,
            is_active    TINYINT(1)   NOT NULL DEFAULT 1,
            updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by   INT          DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   uq_template_key (template_key),
            CONSTRAINT   fk_et_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "✓ Table email_templates created\n";
} else {
    echo "✓ Table email_templates already exists with correct schema\n";
}


// ── 2. Default templates ─────────────────────────────────────────────────────
$defaults = [
    [
        'key'     => 'quote_sent',
        'name'    => 'Quote Sent',
        'subject' => 'Your quote {{quote_number}} from Mowology is ready',
        'body'    => "Hi {{customer_first_name}},

Thank you for reaching out to us! We've put together a custom quote based on your property and service needs.

Quote {{quote_number}} for {{quote_amount}} is valid until {{quote_valid_until}}.

Please click the button below to review the details, ask any questions, or accept your quote online — it only takes a moment.

We look forward to working with you!

{{company_name}}
{{company_phone}}",
    ],
    [
        'key'     => 'invoice_sent',
        'name'    => 'Invoice Sent',
        'subject' => 'Invoice {{invoice_number}} from Mowology — {{amount_due}} due',
        'body'    => "Hi {{customer_first_name}},

Thank you for choosing Mowology! Your invoice for recent services is now ready.

Invoice {{invoice_number}} for {{amount_due}} is due on {{due_date}}.

You can view your invoice and pay securely online using the button below. We accept all major credit cards.

If you have any questions about this invoice, please don't hesitate to reach out.

Thank you for your business!

{{company_name}}
{{company_phone}}",
    ],
    [
        'key'     => 'receipt_sent',
        'name'    => 'Payment Receipt',
        'subject' => 'Payment received — Thank you, {{customer_first_name}}!',
        'body'    => "Hi {{customer_first_name}},

Great news — we've received your payment of {{amount_paid}} for invoice {{invoice_number}}.

Your receipt is attached to this email for your records.

Thank you so much for your business. We appreciate your trust in Mowology and look forward to continuing to serve you.

{{company_name}}
{{company_phone}}",
    ],
    [
        'key'     => 'job_complete',
        'name'    => 'Service Complete',
        'subject' => 'Your {{service_type}} service is complete — {{job_date}}',
        'body'    => "Hi {{customer_first_name}},

Your {{service_type}} service at {{property_address}} has been completed.

Click the button below to view your service report, including photos and notes from our crew.

As always, if you have any feedback or questions about today's service, please don't hesitate to reach out.

Thank you for choosing Mowology!

{{company_name}}
{{company_phone}}",
    ],
];

$stmt = $db->prepare("
    INSERT INTO email_templates (template_key, name, subject, body_text)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name    = IF(name = '', VALUES(name), name),
        subject = IF(subject = '', VALUES(subject), subject),
        body_text = IF(body_text = '', VALUES(body_text), body_text)
");
// Note: ON DUPLICATE KEY UPDATE only overwrites if field is empty — preserves user edits

foreach ($defaults as $tpl) {
    $stmt->execute([$tpl['key'], $tpl['name'], $tpl['subject'], $tpl['body']]);
    echo "✓ Template '{$tpl['key']}' inserted/verified\n";
}

// ── 3. Verify ────────────────────────────────────────────────────────────────
$count = $db->query("SELECT COUNT(*) FROM email_templates")->fetchColumn();
echo "\n✅ Migration complete. {$count} templates in email_templates.\n";
