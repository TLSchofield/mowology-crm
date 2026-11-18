-- 1384 — Onboarding as modules (owner, 2026-10-20: "each section is a module that can be moved up or down
-- the step progress — QuickBooks being one, company name another"). Order and skips live in ops_settings
-- (onboarding_order / onboarding_skipped, json_encode'd lists); each module reads its own done-state from
-- real data. OnboardingService.

-- How the owner wants to be addressed ("Tim"), used wherever Ittai speaks to them.
ALTER TABLE users ADD COLUMN preferred_name VARCHAR(60) NULL AFTER last_name;

-- QuickBooks onboarding: what was read from the client's QuickBooks (customers, items, vendors, terms,
-- company, tax agency), one row each, so the preview reads it once and the local rehearsal can load the
-- same rows from a file. Data is the QuickBooks JSON (TEXT, no MySQL JSON functions).
CREATE TABLE IF NOT EXISTS qbo_onboarding_snapshot (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  kind VARCHAR(16) NOT NULL COMMENT 'customer | item | vendor | term | company | agency | prefs',
  qbo_id VARCHAR(32) NOT NULL,
  data MEDIUMTEXT NOT NULL,
  pulled_at DATETIME NOT NULL,
  UNIQUE KEY uq_qos (site_id, kind, qbo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each QuickBooks onboarding part the owner accepted ("Looks right"), with what it did.
CREATE TABLE IF NOT EXISTS qbo_onboarding_parts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  part VARCHAR(16) NOT NULL COMMENT 'business | clients | services | suppliers | accounts',
  summary VARCHAR(500) NULL,
  applied_by INT NULL,
  applied_at DATETIME NOT NULL,
  UNIQUE KEY uq_qop (site_id, part)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
