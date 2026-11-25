-- Migration 1356: Accountant's Ledger — review fixes (lock history, late-entry flag, fiscal year end, CRM URL)
-- Date: 2026-10-13
--
-- 1. accounting_period_locks — every lock and unlock of a month (locked_at, unlocked_at, who, reason).
--    accounting_periods keeps only the CURRENT state, and a re-lock overwrites closed_at, so a month
--    unlocked and locked again lost the record of its first lock (review finding 2). The exception
--    report classifies against the FIRST lock; step 3 (reopen with a reason) writes unlock rows here.
--    Backfilled from accounting_periods (one row per locked month, its closed_at / closed_by / notes).
-- 2. journal_entries.late_for — the original date of an automatic posting that LedgerService moved
--    out of a locked month into the current one. Set at post time; backfilled once from the memo
--    ("Late entry for YYYY-MM-DD — …"). The exception report reads the flag, not memo LIKE.
-- 3. business_settings.fiscal_year_end (MM-DD, default 12-31) — the Ledger derives fiscal years from
--    it instead of assuming the calendar year.
-- 4. business_settings.crm_url — the CRM's own address, for links the CRM hands out (the accountant's
--    set-password link). Seeded https://mowology.ca where the company website is mowology.ca.
--
-- MySQL 5.7 compatible (no JSON, no generated columns, no ADD COLUMN IF NOT EXISTS); safe to re-run.

-- 1. Lock history
CREATE TABLE IF NOT EXISTS accounting_period_locks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  year          SMALLINT     NOT NULL,
  month         TINYINT      NOT NULL,
  locked_at     DATETIME     NOT NULL,
  locked_by     INT          NULL,
  lock_reason   VARCHAR(255) NULL,
  unlocked_at   DATETIME     NULL,
  unlocked_by   INT          NULL,
  unlock_reason VARCHAR(255) NULL,
  created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_period (year, month),
  KEY idx_locked_at (locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Every lock / unlock of an accounting month (migration 1356); accounting_periods holds the current state';

INSERT INTO accounting_period_locks (year, month, locked_at, locked_by, lock_reason)
  SELECT p.year, p.month, COALESCE(p.closed_at, p.created_at, NOW()), p.closed_by, LEFT(COALESCE(p.notes, 'Locked (backfilled from accounting_periods)'), 255)
  FROM accounting_periods p
  WHERE p.status = 'locked'
    AND NOT EXISTS (SELECT 1 FROM accounting_period_locks l WHERE l.year = p.year AND l.month = p.month);

-- 2. Late-entry flag
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entries' AND COLUMN_NAME = 'late_for');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE journal_entries
     ADD COLUMN late_for DATE NULL COMMENT ''original date of an automatic posting moved out of a locked month (LedgerService)'' AFTER entry_kind,
     ADD KEY idx_late_for (late_for)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE journal_entries
   SET late_for = STR_TO_DATE(SUBSTRING(memo, 16, 10), '%Y-%m-%d')
 WHERE late_for IS NULL AND memo LIKE 'Late entry for ____-__-__%';

-- 3. Fiscal year end
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'fiscal_year_end');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE business_settings ADD COLUMN fiscal_year_end CHAR(5) NOT NULL DEFAULT ''12-31'' COMMENT ''MM-DD the fiscal year ends (Ledger)''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. The CRM's own address
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'crm_url');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE business_settings ADD COLUMN crm_url VARCHAR(255) NULL COMMENT ''https address of this CRM, for links it hands out''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE business_settings SET crm_url = 'https://mowology.ca'
 WHERE (crm_url IS NULL OR crm_url = '') AND company_website IN ('mowology.ca', 'www.mowology.ca', 'https://mowology.ca', 'https://www.mowology.ca');
