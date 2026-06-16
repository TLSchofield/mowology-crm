<?php
/**
 * Migration — add companies.pref_attach_pdf flag.
 *
 * The CRM (clients_appstack.php), EmailHelper and MessagingService all read/write
 * companies.pref_attach_pdf (whether to attach the PDF to outgoing quote/invoice
 * emails). It is defined in the schema (migration 010_pdf_generation.sql) but is
 * missing on production, so creating a client with a linked company throws
 * SQLSTATE[42S22] "Unknown column 'pref_attach_pdf' in 'field list'".
 *
 * Idempotent. Run once via /crm/api/run-migration-pref-attach-pdf.php
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

// MySQL has no ADD COLUMN IF NOT EXISTS — check information_schema first.
$exists = $db->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'pref_attach_pdf'
")->fetchColumn();

if ((int)$exists > 0) {
    echo "pref_attach_pdf column already exists on companies — nothing to do.\n";
    echo "[done]\n";
    exit;
}

$db->exec("
    ALTER TABLE companies
        ADD COLUMN pref_attach_pdf TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Attach the generated PDF to outgoing quote/invoice emails for this account'
");

echo "Added companies.pref_attach_pdf (TINYINT(1) NOT NULL DEFAULT 1).\n";
echo "[done]\n";
