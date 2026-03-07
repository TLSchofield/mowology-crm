<?php
declare(strict_types=1);
header('Content-Type: application/json');

// Bootstrap
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

function tryAlter(PDO $db, string $sql, string $label, array &$results): void
{
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'added'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate column') || $e->getCode() === '42S21') {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $msg];
        }
    }
}

function tryCreate(PDO $db, string $sql, string $label, array &$results): void
{
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'created'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || $e->getCode() === '42S01') {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $msg];
        }
    }
}

function trySeed(PDO $db, string $sql, string $label, array &$results): void
{
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'seeded'];
    } catch (PDOException $e) {
        $results[] = ['step' => $label, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

// ── 1. quotes.contact_id ─────────────────────────────────────────────────────
tryAlter($db, "ALTER TABLE quotes ADD COLUMN contact_id INT NULL DEFAULT NULL AFTER company_id", 'quotes.contact_id', $results);
tryAlter($db, "ALTER TABLE quotes ADD INDEX idx_contact (contact_id)", 'quotes idx_contact', $results);

// Backfill contact_id from property → site_contact_id
try {
    $rows = $db->exec("
        UPDATE quotes q
        JOIN properties p ON p.id = q.property_id
        SET q.contact_id = p.site_contact_id
        WHERE q.contact_id IS NULL AND p.site_contact_id IS NOT NULL
    ");
    $results[] = ['step' => 'quotes.contact_id backfill', 'status' => 'seeded', 'rows' => $rows];
} catch (PDOException $e) {
    $results[] = ['step' => 'quotes.contact_id backfill', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 2. quotes.last_viewed_at ──────────────────────────────────────────────────
tryAlter($db, "ALTER TABLE quotes ADD COLUMN last_viewed_at DATETIME NULL DEFAULT NULL AFTER viewed_at", 'quotes.last_viewed_at', $results);

// Backfill last_viewed_at from existing viewed_at
try {
    $db->exec("UPDATE quotes SET last_viewed_at = viewed_at WHERE last_viewed_at IS NULL AND viewed_at IS NOT NULL");
    $results[] = ['step' => 'quotes.last_viewed_at backfill', 'status' => 'seeded'];
} catch (PDOException $e) {
    $results[] = ['step' => 'quotes.last_viewed_at backfill', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 3. invoices.contact_id (if missing) ──────────────────────────────────────
tryAlter($db, "ALTER TABLE invoices ADD COLUMN contact_id INT NULL DEFAULT NULL AFTER company_id", 'invoices.contact_id', $results);
tryAlter($db, "ALTER TABLE invoices ADD INDEX idx_contact (contact_id)", 'invoices idx_contact', $results);

// Backfill invoices.contact_id from company → primary_contact_id, fallback property → site_contact_id
try {
    $rows = $db->exec("
        UPDATE invoices i
        LEFT JOIN companies co ON co.id = i.company_id
        LEFT JOIN properties p ON p.id = i.property_id
        SET i.contact_id = COALESCE(co.primary_contact_id, p.site_contact_id)
        WHERE i.contact_id IS NULL
    ");
    $results[] = ['step' => 'invoices.contact_id backfill', 'status' => 'seeded', 'rows' => $rows];
} catch (PDOException $e) {
    $results[] = ['step' => 'invoices.contact_id backfill', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 4. invoices.overdue_notified_at ──────────────────────────────────────────
tryAlter($db, "ALTER TABLE invoices ADD COLUMN overdue_notified_at DATETIME NULL DEFAULT NULL AFTER paid_at", 'invoices.overdue_notified_at', $results);

// ── 5. messages table (in-app crew/office messaging) ─────────────────────────
tryCreate($db, "
    CREATE TABLE messages (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        from_user_id INT NOT NULL,
        to_user_id   INT NOT NULL,
        subject      VARCHAR(200) NOT NULL DEFAULT '',
        body         TEXT NOT NULL,
        is_read      TINYINT(1) NOT NULL DEFAULT 0,
        read_at      DATETIME NULL,
        context_type ENUM('none','job_plan','visit','contact') NOT NULL DEFAULT 'none',
        context_id   INT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_to   (to_user_id, is_read),
        INDEX idx_from (from_user_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'messages table', $results);

// ── 6. Invoice overdue email template ─────────────────────────────────────────
trySeed($db, "
    INSERT IGNORE INTO email_templates (name, category, subject, body_html, body_text, is_active, created_by)
    VALUES (
        'Invoice Overdue Reminder',
        'invoice_reminder',
        'Reminder: Your invoice is past due — {{invoice_number}}',
        '<div style=\"font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)\">'
        '<div style=\"background:#2D8659;padding:28px 32px\">'
        '<h1 style=\"margin:0;color:#fff;font-size:22px;font-weight:700\">Payment Reminder</h1>'
        '<p style=\"margin:6px 0 0;color:rgba(255,255,255,.85);font-size:14px\">Mowology Landscaping &amp; Lawn Care</p>'
        '</div>'
        '<div style=\"padding:32px\">'
        '<p style=\"margin:0 0 16px;font-size:16px;color:#333\">Hi {{first_name}},</p>'
        '<p style=\"margin:0 0 16px;color:#555\">We hope you&apos;re enjoying your lawn! We wanted to send a friendly reminder that invoice <strong>{{invoice_number}}</strong> for <strong>{{amount}}</strong> was due on <strong>{{due_date}}</strong> and remains outstanding.</p>'
        '<p style=\"margin:0 0 24px;color:#555\">If you have already sent payment, please disregard this notice — it may have crossed in transit.</p>'
        '<div style=\"background:#E8F3F0;border-radius:8px;padding:20px 24px;margin:0 0 24px\">'
        '<p style=\"margin:0;font-size:13px;color:#666\">Invoice</p>'
        '<p style=\"margin:4px 0 0;font-size:22px;font-weight:700;color:#1A5F4A\">{{invoice_number}}</p>'
        '<p style=\"margin:6px 0 0;color:#333\">Balance due: <strong>{{amount}}</strong></p>'
        '</div>'
        '<p style=\"margin:0 0 24px;color:#555\">To pay by e-Transfer, send to <strong>pay@mowology.ca</strong> and include your invoice number as the message. We also accept cash and cheque.</p>'
        '<p style=\"margin:0 0 8px;color:#555\">If you have any questions, please call or text us at <strong>(778) 846-9273</strong>.</p>'
        '<p style=\"margin:0;color:#555\">Thank you for your business!</p>'
        '<div style=\"margin:32px 0 0;padding:24px 0 0;border-top:1px solid #eee\">'
        '<p style=\"margin:0;font-size:12px;color:#999\">Mowology Landscaping | 778-846-9273 | mowology.ca</p>'
        '</div>'
        '</div>'
        '</div>',
        'Hi {{first_name}}, this is a friendly reminder that invoice {{invoice_number}} for {{amount}} was due on {{due_date}} and remains outstanding. To pay, e-Transfer to pay@mowology.ca with your invoice number, or call (778) 846-9273. Thank you!',
        1,
        NULL
    )
", 'email_templates.invoice_reminder', $results);

// ── 7. Invoice overdue automation rule (enabled) ──────────────────────────────
trySeed($db, "
    INSERT IGNORE INTO automation_rules
        (name, description, is_enabled, trigger_type, trigger_config, conditions, actions, cooldown_hours)
    VALUES (
        'Invoice Overdue Reminder (7 days)',
        'Send a polite payment reminder when an invoice is 7+ days past due.',
        1,
        'invoice_overdue',
        '{\"overdue_days\":7}',
        '[{\"field\":\"receive_marketing\",\"operator\":\"eq\",\"value\":\"1\"}]',
        '[{\"type\":\"send_email\",\"config\":{\"subject\":\"Reminder: Your invoice is past due\",\"body\":\"Hi {{first_name}},\\n\\nThis is a friendly reminder that you have an outstanding invoice with Mowology Landscaping.\\n\\nTo pay, please e-Transfer to pay@mowology.ca and include your invoice number as the message.\\n\\nQuestions? Call or text (778) 846-9273.\\n\\nThank you!\"}},{\"type\":\"notify_admin\",\"config\":{\"message\":\"Invoice overdue reminder sent to {{first_name}} {{last_name}}\"}}]',
        168
    )
", 'automation_rules.invoice_overdue', $results);

// ── 8. Enable Quote Follow-Up automation rule ─────────────────────────────────
try {
    $rows = $db->exec("UPDATE automation_rules SET is_enabled = 1 WHERE name LIKE 'Quote Follow-Up%' AND is_enabled = 0");
    $results[] = ['step' => 'automation_rules.quote_followup enable', 'status' => $rows ? 'enabled' : 'already enabled'];
} catch (PDOException $e) {
    $results[] = ['step' => 'automation_rules.quote_followup enable', 'status' => 'error', 'msg' => $e->getMessage()];
}

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
