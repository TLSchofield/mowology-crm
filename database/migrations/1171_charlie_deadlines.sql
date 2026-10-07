-- Migration 1171: Charlie (Foreman) — compliance & renewals calendar
-- Date: 2026-10-06
-- Purpose: the business's own deadlines (tax, payroll, WorkSafeBC, licences, insurance,
--   equipment), each with a repeat rule and a reminder window. Charlie reminds early, puts
--   what's due in the 7 am brief, and prepares read-only checklists (the year-end pack).
--   Charlie files and pays nothing.
--     charlie_deadlines            — one row per obligation (rule grammar: DeadlineRules.php)
--     charlie_deadline_occurrences — one row per due date: open / done / snoozed / skipped
-- Seed: Mowology is a CORPORATION WITH EMPLOYEES (Tim, 2026-10-06), Dec 31 year-end.
--   Every tax and payroll item is "confirm with your accountant". Instalments are OFF until
--   Tim says CRA asks for them. Open items (no date yet) are Tim's to fill in on
--   /crm/foreman_calendar_appstack.php.
-- Code is guarded: nothing shows until this has run. MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS charlie_deadlines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(20) NOT NULL DEFAULT 'other' COMMENT 'tax | payroll | safety | licence | insurance | vehicle | equipment | contract | other',
  rule VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'annual:MM-DD | annual:MM-last | dates:MM-DD,… | monthly:DD | every:Nm | once:YYYY-MM-DD | empty = not set up',
  anchor_date DATE NULL COMMENT 'every:Nm counts from here',
  lead_days SMALLINT NOT NULL DEFAULT 14 COMMENT 'reminders start this many days before',
  prepare TEXT NULL COMMENT 'what Charlie gets ready, one line per step',
  pack TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = attach the year-end accountant pack',
  condition_note VARCHAR(255) NULL,
  confirm_note VARCHAR(255) NULL,
  amount_hint DECIMAL(10,2) NULL,
  url VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  source VARCHAR(10) NOT NULL DEFAULT 'seed' COMMENT 'seed | tim',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_charlie_deadline_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS charlie_deadline_occurrences (
  id INT AUTO_INCREMENT PRIMARY KEY,
  deadline_id INT NOT NULL,
  due_date DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open | done | snoozed | skipped',
  snoozed_until DATE NULL,
  done_at DATETIME NULL,
  done_by INT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_charlie_occ (deadline_id, due_date),
  INDEX idx_charlie_occ_status (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO charlie_deadlines (slug, title, category, rule, lead_days, prepare, pack, condition_note, confirm_note, url, active) VALUES
('year_end_pack', 'Year-end package to your accountant', 'tax', 'annual:01-31', 21,
  'Close every month of last year (Accounting → periods)\nApprove the last receipts (Penny)\nCategorise the last bank lines\nSend the accountant the income statement, GST report and bank statements', 1,
  NULL, 'Confirm with your accountant what they want and by when.', '/crm/accounting/income-statement.php', 1),
('corp_tax_balance', 'Pay the corporate income tax balance', 'tax', 'annual:02-last', 30,
  'Ask your accountant for the balance owing\nPay CRA from the business account', 0,
  NULL, 'Confirm with your accountant: 2 months after year-end, or 3 months (Mar 31) if the company qualifies as a small CCPC.', NULL, 1),
('corp_t2', 'File the corporate tax return (T2)', 'tax', 'annual:06-30', 45,
  'Your accountant prepares and files the T2\nCheck the year-end package went over in January', 0,
  NULL, 'Confirm with your accountant: due 6 months after year-end.', NULL, 1),
('corp_instalments', 'Corporate tax instalments', 'tax', 'monthly:last', 10,
  'Pay the instalment amount CRA set', 0,
  'Only if CRA asks the company for instalments — off until you say so', 'Confirm with your accountant: monthly, or quarterly for a qualifying small CCPC.', NULL, 0),
('gst_annual', 'GST return and payment (annual filer)', 'tax', 'annual:03-31', 30,
  'GST collected and paid for the year (Tax report)\nFile the return and pay from the business account', 0,
  NULL, 'Confirm your GST reporting period with your accountant (annual returns are due 3 months after year-end).', '/crm/tax-report_appstack.php', 1),
('gst_quarterly', 'GST return and payment (quarterly filer)', 'tax', 'dates:01-31,04-30,07-31,10-31', 21,
  'GST collected and paid for the quarter (Tax report)\nFile the return and pay', 0,
  'Only if the company files GST quarterly — turn this on and the annual one off', 'Confirm your GST reporting period with your accountant.', '/crm/tax-report_appstack.php', 0),
('payroll_remit', 'Payroll remittance to CRA (CPP, EI, income tax)', 'payroll', 'monthly:15', 7,
  'Total last month''s source deductions plus the employer share\nRemit to CRA', 0,
  NULL, 'Confirm your remitter type with your accountant (regular remitters: by the 15th of the next month).', NULL, 1),
('t4_slips', 'T4 slips and T4 Summary', 'payroll', 'annual:02-last', 30,
  'Year totals per employee\nFile the T4 Summary with CRA\nGive each employee their T4', 0,
  NULL, 'Confirm with your accountant: due the last day of February.', NULL, 1),
('wcb_premiums', 'WorkSafeBC payroll report and premiums', 'safety', 'dates:01-31,04-30,07-31,10-31', 14,
  'Payroll for the period\nReport and pay in WorkSafeBC Online Services', 0,
  NULL, 'Confirm with WorkSafeBC or your accountant whether you report quarterly or annually.', NULL, 1),
('wcb_directors', 'WorkSafeBC coverage review for directors', 'safety', 'annual:01-15', 21,
  'Directors are not covered automatically — check Personal Optional Protection is in place\nCoverage only runs while premiums are paid', 0,
  NULL, 'Confirm with WorkSafeBC.', NULL, 1),
('van_licence', 'City of Vancouver business licence renewal', 'licence', 'annual:12-31', 45,
  'Renewal notices arrive in November\nRenew online before Dec 31 — late costs the greater of $45 or 10%', 0,
  NULL, NULL, NULL, 1),
('other_licences', 'Licences in other municipalities (or the inter-municipal licence)', 'licence', '', 30,
  'List each municipality you work in and its renewal date', 0, NULL, NULL, NULL, 1),
('liability_insurance', 'Commercial general liability insurance renewal', 'insurance', '', 30,
  'Renew with your broker\nSend the new certificate to strata clients who ask for it', 0, NULL, NULL, NULL, 1),
('truck_insurance', 'Might-E Truck insurance renewal', 'vehicle', '', 21,
  'Renew the truck''s insurance', 0, NULL, NULL, NULL, 1),
('equipment_service', 'Equipment service', 'equipment', '', 14,
  'Set a repeat for each machine that needs regular service', 0, NULL, NULL, NULL, 1),
('contract_template_review', 'Contract template review', 'contract', '', 30,
  'Read the service contract template and update terms and prices', 0, NULL, NULL, NULL, 1);
