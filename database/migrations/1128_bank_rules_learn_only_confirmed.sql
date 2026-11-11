-- Migration 1128: bank rules — keep only what was really learned
-- Date: 2026-10-05
-- Purpose: until now every imported bank line with no rule became an ACTIVE 'learned'
--   rule, even lines left on the default Miscellaneous / Other Services account that
--   nobody had looked at (119 of 221 learned rules pointed at Miscellaneous). From now on
--   (BankRuleLearning) default accounts never teach and a learned rule switches on only
--   after 2 confirmations. This brings the existing rules in line:
--     - learned rules pointing at a default account (4900, 6900) are switched OFF;
--     - learned rules confirmed only once are switched OFF until confirmed again.
--   Nothing is deleted; manual rules are untouched. Undo: set is_active = 1 again.
-- MySQL 5.7 compatible.

UPDATE transaction_rules r
JOIN chart_of_accounts coa ON coa.id = r.account_id
SET r.is_active = 0
WHERE r.source = 'learned'
  AND r.is_active = 1
  AND (coa.code IN ('4900', '6900') OR r.learned_count < 2);
