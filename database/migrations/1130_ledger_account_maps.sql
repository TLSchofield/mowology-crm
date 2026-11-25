-- Migration 1130: which account each kind of income and spending posts to
-- Date: 2026-10-05
-- Purpose: the ledger posted every invoice to 4900 Other Services and every receipt to
--   6900 Miscellaneous — the category/service → account links were never there on
--   production. Two small maps fix that; the ledger (LedgerAccountMap) and Penny's bank
--   suggestions both read them, and Penny asks the owner to fill any gap.
--     service_revenue_accounts : job service type (slug or label, as job_plans stores
--                                free text) → revenue account
--     expense_category_accounts: receipt accounting category → expense account
--   Ambiguous service types (maintenance, garden maintenance) are left out on purpose:
--   Penny asks which account they belong to. Tools/equipment → 1500 Equipment & Tools
--   (an asset; the owner's call, 2026-10-05).
--   penny_questions gains `subject` (what a non-receipt question is about) and a
--   nullable expense_id, so she can ask about a service type.
-- MySQL 5.7 compatible. Seeds are INSERT IGNORE — re-running changes nothing.

CREATE TABLE IF NOT EXISTS service_revenue_accounts (
  service_type VARCHAR(100) NOT NULL PRIMARY KEY COMMENT 'lower-case slug or label',
  account_id INT NOT NULL,
  set_by INT NULL COMMENT 'null = seeded',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Job service type -> revenue account (ledger + Penny)';

CREATE TABLE IF NOT EXISTS expense_category_accounts (
  category VARCHAR(100) NOT NULL PRIMARY KEY COMMENT 'lower-case receipt accounting category',
  account_id INT NOT NULL,
  set_by INT NULL COMMENT 'null = seeded',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Receipt accounting category -> expense account (ledger + Penny)';

-- Income: clear service types only.
INSERT IGNORE INTO service_revenue_accounts (service_type, account_id)
SELECT m.st, c.id FROM (
  SELECT 'lawn_care' AS st, '4100' AS code UNION ALL SELECT 'lawn care', '4100'
  UNION ALL SELECT 'hedge_trimming', '4200' UNION ALL SELECT 'hedge trimming', '4200'
  UNION ALL SELECT 'snow_removal', '4300' UNION ALL SELECT 'snow removal', '4300'
  UNION ALL SELECT 'salt_application', '4300' UNION ALL SELECT 'salt application', '4300'
  UNION ALL SELECT 'landscaping', '4400'
  UNION ALL SELECT 'cleanup', '4700' UNION ALL SELECT 'seasonal_cleanup', '4700' UNION ALL SELECT 'seasonal cleanup', '4700'
) m JOIN chart_of_accounts c ON c.code = m.code;

-- Spending: every receipt category except 'Other' (stays on 6900).
INSERT IGNORE INTO expense_category_accounts (category, account_id)
SELECT m.cat, c.id FROM (
  SELECT 'materials' AS cat, '5200' AS code UNION ALL SELECT 'fuel', '6100'
  UNION ALL SELECT 'tools/equipment', '1500' UNION ALL SELECT 'repairs/maintenance', '6200'
  UNION ALL SELECT 'vehicle', '6120' UNION ALL SELECT 'disposal/dump', '5000'
  UNION ALL SELECT 'licenses/permits', '6500' UNION ALL SELECT 'subcontractors', '5400'
  UNION ALL SELECT 'marketing', '6400' UNION ALL SELECT 'office/admin', '6500'
  UNION ALL SELECT 'overhead', '6000' UNION ALL SELECT 'meals', '6850' UNION ALL SELECT 'safety', '6000'
) m JOIN chart_of_accounts c ON c.code = m.code;

-- Penny can ask about a service type, not only a receipt.
ALTER TABLE penny_questions MODIFY expense_id INT NULL;
ALTER TABLE penny_questions ADD COLUMN subject VARCHAR(100) NULL AFTER kind;
