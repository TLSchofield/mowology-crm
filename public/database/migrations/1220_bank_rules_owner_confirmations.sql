-- Migration 1220: bank rules learn from the owner only — 2 confirmations, not 50
-- Date: 2026-10-07
-- Purpose: Tim (2026-10-07): "at 50 confirmations this will take 2 years". From now on
--   (BankRuleLearning) only the OWNER's decisions count toward a learned rule: Approve /
--   correct on Penny's bank card, "also put the other N lines on …" (bulk apply), and
--   recategorize on the transactions screen. An import committed with the line untouched
--   no longer counts at all — that is how wrong rules grew (Wave PYRL → 2400 Credit Card
--   Payable, TD loan → 2400) from lines nobody had looked at.
--   A learned rule acts on its own once owner_confirmations >= 2.
--     1. transaction_rules.owner_confirmations — owner decisions behind the rule.
--     2. transaction_rules.last_confirmed_tx_id — the bank line that last counted, so the
--        same line recategorized twice never counts twice.
--     3. Learned rules that are ACTIVE today got there by import counts (learned_count >= 50,
--        migration 1131), never by the owner: switched OFF until the owner confirms them twice.
--        learned_count is NOT used to activate anything. Manual rules are untouched.
--   Nothing is deleted. Undo for (3): UPDATE transaction_rules SET is_active = 1 WHERE id IN (…).
-- MySQL 5.7 compatible (information_schema guards, no JSON, no window functions); safe to re-run.

-- 1. owner_confirmations
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_rules' AND COLUMN_NAME = 'owner_confirmations');
SET @sql = IF(@c = 0,
    'ALTER TABLE transaction_rules ADD COLUMN owner_confirmations INT NOT NULL DEFAULT 0 COMMENT ''owner decisions (card approve/correct, bulk apply, recategorize); a learned rule is active at 2'' AFTER learned_count',
    'SELECT ''transaction_rules.owner_confirmations already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. last_confirmed_tx_id
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_rules' AND COLUMN_NAME = 'last_confirmed_tx_id');
SET @sql = IF(@c = 0,
    'ALTER TABLE transaction_rules ADD COLUMN last_confirmed_tx_id INT NULL COMMENT ''accounting_transactions.id of the last owner decision counted (no double count)'' AFTER owner_confirmations',
    'SELECT ''transaction_rules.last_confirmed_tx_id already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. Learned rules switched on by import counts alone stop until the owner confirms them.
UPDATE transaction_rules
SET is_active = 0
WHERE source = 'learned'
  AND is_active = 1
  AND owner_confirmations < 2;
