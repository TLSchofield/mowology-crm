-- Migration 1315: the client can untick quote lines before signing (owner, 2026-10-10).
-- Council approved Monica's quote without the sprinkler line by email; now the client unticks
-- it on the quote page and signs for exactly what's ticked. See QuoteLineChoiceService.
-- MySQL 5.7: plain ALTERs, no JSON, no generated columns.
ALTER TABLE quote_line_items
  ADD COLUMN client_declined TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'The client unticked this line on the quote page — out of the total, never scheduled',
  ADD COLUMN client_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'The office locked this line: the client cannot untick it';

ALTER TABLE quotes
  ADD COLUMN allow_line_decline TINYINT(1) NULL DEFAULT NULL COMMENT '1 = client may untick lines, 0 = not, NULL = ops setting quote_line_decline_default';

INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('quote_line_decline_default', '1', 'Quotes: clients may untick lines before signing (never contracts). Per-quote override on the quote page.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
