<?php
/**
 * Migration: Invoice Tracking System
 *
 * Adds columns to `invoices` table for view tracking, email open tracking,
 * and automated reminder tracking. Also ensures `invoice_contacts` table
 * has the required tracking columns.
 *
 * Run once via POST: /crm/api/run-migration-invoice-tracking.php
 */

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
requireLogin();
session_write_close();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

header('Content-Type: application/json');

$db  = getDB();
$log = [];

function mig($msg) {
    global $log;
    $log[] = $msg;
}

// Helper: check if column exists
function colExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // ── invoices table: view tracking ─────────────────────────────────────
    if (!colExists($db, 'invoices', 'last_viewed_at')) {
        $db->exec("ALTER TABLE invoices ADD COLUMN last_viewed_at TIMESTAMP NULL AFTER viewed_at");
        mig("Added invoices.last_viewed_at");
    } else {
        mig("invoices.last_viewed_at already exists");
    }

    if (!colExists($db, 'invoices', 'view_count')) {
        $db->exec("ALTER TABLE invoices ADD COLUMN view_count INT UNSIGNED DEFAULT 0 AFTER last_viewed_at");
        mig("Added invoices.view_count");
    } else {
        mig("invoices.view_count already exists");
    }

    // ── invoices table: email open tracking ───────────────────────────────
    if (!colExists($db, 'invoices', 'email_opened_at')) {
        $db->exec("ALTER TABLE invoices ADD COLUMN email_opened_at TIMESTAMP NULL AFTER view_count");
        mig("Added invoices.email_opened_at");
    } else {
        mig("invoices.email_opened_at already exists");
    }

    // ── invoices table: reminder tracking ─────────────────────────────────
    if (!colExists($db, 'invoices', 'first_reminder_sent_at')) {
        $db->exec("ALTER TABLE invoices ADD COLUMN first_reminder_sent_at TIMESTAMP NULL");
        mig("Added invoices.first_reminder_sent_at");
    } else {
        mig("invoices.first_reminder_sent_at already exists");
    }

    if (!colExists($db, 'invoices', 'last_reminder_sent_at')) {
        $db->exec("ALTER TABLE invoices ADD COLUMN last_reminder_sent_at TIMESTAMP NULL");
        mig("Added invoices.last_reminder_sent_at");
    } else {
        mig("invoices.last_reminder_sent_at already exists");
    }

    if (!colExists($db, 'invoices', 'reminder_count')) {
        $db->exec("ALTER TABLE invoices ADD COLUMN reminder_count INT UNSIGNED DEFAULT 0");
        mig("Added invoices.reminder_count");
    } else {
        mig("invoices.reminder_count already exists");
    }

    // ── invoice_contacts table: ensure tracking columns ───────────────────
    if (!colExists($db, 'invoice_contacts', 'invoice_sent_at')) {
        $db->exec("ALTER TABLE invoice_contacts ADD COLUMN invoice_sent_at TIMESTAMP NULL");
        mig("Added invoice_contacts.invoice_sent_at");
    } else {
        mig("invoice_contacts.invoice_sent_at already exists");
    }

    if (!colExists($db, 'invoice_contacts', 'invoice_opened_at')) {
        $db->exec("ALTER TABLE invoice_contacts ADD COLUMN invoice_opened_at TIMESTAMP NULL");
        mig("Added invoice_contacts.invoice_opened_at");
    } else {
        mig("invoice_contacts.invoice_opened_at already exists");
    }

    if (!colExists($db, 'invoice_contacts', 'email_bounced')) {
        $db->exec("ALTER TABLE invoice_contacts ADD COLUMN email_bounced TINYINT(1) DEFAULT 0");
        mig("Added invoice_contacts.email_bounced");
    } else {
        mig("invoice_contacts.email_bounced already exists");
    }

    if (!colExists($db, 'invoice_contacts', 'bounce_reason')) {
        $db->exec("ALTER TABLE invoice_contacts ADD COLUMN bounce_reason VARCHAR(500) NULL");
        mig("Added invoice_contacts.bounce_reason");
    } else {
        mig("invoice_contacts.bounce_reason already exists");
    }

    mig("=== Migration complete ===");
    echo json_encode(['success' => true, 'log' => $log]);

} catch (Throwable $e) {
    mig("ERROR: " . $e->getMessage());
    error_log('[invoice-tracking-migration] ' . $e->getMessage());
    echo json_encode(['success' => false, 'log' => $log, 'error' => $e->getMessage()]);
}
