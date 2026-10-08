-- 1226 — vendor_messages.kind: 'message' (vendor correspondence) | 'payment' (a payment
-- platform's "You received a payment" / "You've got money" notice, kept until a PayPal /
-- Stripe / Square parser feeds Penny's payment matching). IcloudInboxRouter stores rows
-- without the column until this runs (VendorMessageService::hasKind), so deploy order is free.
-- Idempotent; MySQL 5.7 safe.

SET @t = (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_messages');
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_messages' AND COLUMN_NAME = 'kind');
SET @sql = IF(@t = 1 AND @c = 0,
    'ALTER TABLE vendor_messages ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT ''message'' COMMENT ''message | payment'' AFTER direction',
    'SELECT ''vendor_messages.kind already there (or table missing — run 1223)'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
