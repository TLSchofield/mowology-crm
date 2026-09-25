<?php
/**
 * Run Migration 1037 — Company-level Stripe payment columns
 *
 * Adds Stripe card storage to the companies table so company invoices
 * can use a separate business card from the contact's personal card.
 * Safe to re-run — duplicate column errors are caught and reported.
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
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$statements = [
    "ALTER TABLE companies ADD COLUMN stripe_customer_id       VARCHAR(100) NULL DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN stripe_payment_method_id VARCHAR(100) NULL DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN stripe_card_brand        VARCHAR(20)  NULL DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN stripe_card_last4        VARCHAR(4)   NULL DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN stripe_card_exp          VARCHAR(7)   NULL DEFAULT NULL",
    "ALTER TABLE companies ADD COLUMN autopay_enabled          TINYINT(1)   NOT NULL DEFAULT 0",
    "ALTER TABLE companies ADD COLUMN autopay_enrolled_at      TIMESTAMP    NULL DEFAULT NULL",
];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false ||
            strpos($msg, '1060') !== false) {
            $results[] = "SKIP[$i] Column already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $msg . ' — SQL: ' . substr(trim($sql), 0, 60);
        }
    }
}

echo "Migration 1037 — Company Stripe Payment Columns\n";
echo str_repeat('=', 60) . "\n";
foreach ($results as $line) {
    echo $line . "\n";
}
echo "\nDone.\n";
