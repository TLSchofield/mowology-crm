-- Migration 1280: inbound customer mail is routed to the right department head
-- Date: 2026-10-08
-- Purpose: a billing email from a property manager (Vancouver Management Ltd's "EFT Direct
--   deposit form") showed up under SAM on the Team tab: any inbound email from a contact with
--   an open quote made Sam's card say "they replied, you haven't" (SalesDeskService::touches
--   read every inbound row). Each inbound sales_messages row now carries the head it belongs
--   to, decided by InboundTopicClassifier (rules first: invoice / payment / EFT / direct
--   deposit ... -> penny; scheduling / site issues -> otto; quotes / new work -> sam;
--   reviews / social -> mia; everything else -> yui) and by rules Tim teaches with "Move to...".
--   NULL head = read before this migration: the code classifies it on the fly.
-- MySQL 5.7 compatible; every step is guarded, so re-running is safe.

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_messages' AND COLUMN_NAME = 'head');
SET @sql = IF(@c = 0,
    'ALTER TABLE sales_messages ADD COLUMN head VARCHAR(10) NULL COMMENT ''Inbound only: penny | sam | otto | mia | yui''',
    'SELECT ''sales_messages.head already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_messages' AND COLUMN_NAME = 'topic');
SET @sql = IF(@c = 0,
    'ALTER TABLE sales_messages ADD COLUMN topic VARCHAR(12) NULL COMMENT ''billing | sales | schedule | marketing | general''',
    'SELECT ''sales_messages.topic already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_messages' AND COLUMN_NAME = 'head_source');
SET @sql = IF(@c = 0,
    'ALTER TABLE sales_messages ADD COLUMN head_source VARCHAR(10) NULL COMMENT ''rule | learned | moved | reroute (moved/reroute are never re-stamped)''',
    'SELECT ''sales_messages.head_source already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_messages' AND COLUMN_NAME = 'head_reason');
SET @sql = IF(@c = 0,
    'ALTER TABLE sales_messages ADD COLUMN head_reason VARCHAR(160) NULL COMMENT ''The words or sender hint that decided the head''',
    'SELECT ''sales_messages.head_reason already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_messages' AND COLUMN_NAME = 'handled_at');
SET @sql = IF(@c = 0,
    'ALTER TABLE sales_messages ADD COLUMN handled_at DATETIME NULL COMMENT ''Tim marked the routed message done''',
    'SELECT ''sales_messages.handled_at already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @i = (SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_messages' AND INDEX_NAME = 'idx_sm_head');
SET @sql = IF(@i = 0,
    'ALTER TABLE sales_messages ADD INDEX idx_sm_head (head, sent_at)',
    'SELECT ''idx_sm_head already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
