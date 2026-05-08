-- Extend transaction_rules to track auto-learned patterns.
-- 'manual' = user-created via UI.  'learned' = written by BankImportService
-- after the user confirms a categorization with no existing rule.

ALTER TABLE transaction_rules
    ADD COLUMN source        ENUM('manual','learned') NOT NULL DEFAULT 'manual'      AFTER updated_at,
    ADD COLUMN learned_count INT                      NOT NULL DEFAULT 0             AFTER source,
    ADD COLUMN last_learned_at DATETIME               NULL                           AFTER learned_count;

CREATE INDEX idx_rules_source ON transaction_rules (source);
