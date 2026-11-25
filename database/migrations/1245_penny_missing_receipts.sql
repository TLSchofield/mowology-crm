-- Migration 1245: Penny's missing-receipt chaser
-- Date: 2026-10-07
-- Purpose: every 2026 card / bank spending line with no receipt becomes a "missing receipt" item
--   with the person who probably made it (card last 4 → person, else the truck / time clock that
--   day, else the owner). Penny nudges that person (push + their Penny card on the crew app) the day
--   after, again 3 days later, then only Tim's weekly summary. A receipt arriving through any path
--   (ExpenseGate hook) or "Snap it" from the card closes the item; "No receipt" records a reason.
--   MissingReceiptService (app/Modules/Expenses/Services) owns all of it; cron penny_chase runs it.
--   Tables:
--     penny_missing_receipts       one row per bank line Penny is (or was) chasing
--     penny_card_holders           card ••last4 → person (Tim fills it on Penny's dashboard card)
--     penny_receipt_exempt_rules   lines that never have a receipt (bank fees, loans, payroll …) —
--                                  Tim edits the list on the same card
-- 2025 is filed and locked: only lines dated 2026-01-01 or later are ever looked at.
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

CREATE TABLE IF NOT EXISTS penny_missing_receipts (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id  INT           NOT NULL COMMENT 'accounting_transactions.id (bank_import, expense)',
  charge_date     DATE          NOT NULL,
  charge_time     CHAR(5)       NULL COMMENT 'HH:MM when the bank line carries one',
  amount          DECIMAL(10,2) NOT NULL,
  description     VARCHAR(255)  NULL COMMENT 'the bank line as the bank wrote it',
  vendor_label    VARCHAR(80)   NULL COMMENT 'what Penny calls the vendor ("Lawn Boy")',
  card_last4      CHAR(4)       NULL,
  user_id         INT           NULL COMMENT 'who probably made the charge (asked first)',
  basis           VARCHAR(12)   NOT NULL DEFAULT 'owner' COMMENT 'card | truck | clock | owner | manual',
  basis_note      VARCHAR(160)  NULL COMMENT 'why Penny thinks it was them',
  status          VARCHAR(12)   NOT NULL DEFAULT 'open' COMMENT 'open | received | no_receipt | exempt',
  expense_id      INT           NULL COMMENT 'received: the receipt that closed it',
  reason          VARCHAR(20)   NULL COMMENT 'no_receipt: lost | not_available',
  reason_note     VARCHAR(255)  NULL,
  resolved_by     INT           NULL,
  resolved_at     DATETIME      NULL,
  nudges          TINYINT       NOT NULL DEFAULT 0 COMMENT 'pushes sent to the person (max 2)',
  last_nudged_at  DATETIME      NULL,
  next_nudge_at   DATETIME      NULL COMMENT 'NULL = no more pushes (weekly summary only)',
  last_push       VARCHAR(60)   NULL COMMENT 'ios | android | no device | android: FCM not configured …',
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pmr_tx (transaction_id),
  INDEX idx_pmr_status_user (status, user_id),
  INDEX idx_pmr_next (status, next_nudge_at),
  INDEX idx_pmr_date (charge_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny: bank / card spending lines with no receipt, and who she is asking (MissingReceiptService)';

CREATE TABLE IF NOT EXISTS penny_card_holders (
  card_last4  CHAR(4)      NOT NULL PRIMARY KEY,
  user_id     INT          NULL COMMENT 'NULL = a shared card: fall back to truck / clock',
  label       VARCHAR(60)  NULL COMMENT 'e.g. "Visa — Nigel"',
  updated_by  INT          NULL,
  updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny: which person carries which card (last 4 digits)';

CREATE TABLE IF NOT EXISTS penny_receipt_exempt_rules (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  match_on    VARCHAR(12)  NOT NULL COMMENT 'account_code | account_name | description',
  pattern     VARCHAR(80)  NOT NULL COMMENT 'account_code: exact code or prefix ending in *; else a case-insensitive substring',
  label       VARCHAR(80)  NOT NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT          NULL,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pere (match_on, pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny: bank lines that never come with a receipt — never chased';

INSERT IGNORE INTO penny_receipt_exempt_rules (match_on, pattern, label) VALUES
  ('account_code', '6800',  'Bank charges & fees'),
  ('account_code', '5100',  'Crew wages (payroll)'),
  ('account_code', '2*',    'Liabilities — loans, card payments, GST / PST / payroll remittances'),
  ('account_code', '3*',    'Owner draws & equity'),
  ('account_code', '6110',  'Vehicle insurance'),
  ('account_code', '6300',  'Business insurance'),
  ('account_name', 'interest',   'Interest'),
  ('account_name', 'loan',       'Loan payments'),
  ('account_name', 'insurance',  'Insurance (pre-authorised)'),
  ('account_name', 'utilit',     'Utilities (pre-authorised)'),
  ('description', 'SERVICE CHARGE',  'Bank service charge'),
  ('description', 'MONTHLY FEE',     'Bank monthly fee'),
  ('description', 'ACCOUNT FEE',     'Bank account fee'),
  ('description', 'NSF',             'NSF fee'),
  ('description', 'OVERDRAFT',       'Overdraft interest / fee'),
  ('description', 'INTEREST',        'Interest'),
  ('description', 'LOAN',            'Loan payment'),
  ('description', 'PAYROLL',         'Payroll'),
  ('description', 'PYRL',            'Payroll (Wave)'),
  ('description', 'REC GEN',         'Receiver General (CRA remittance)'),
  ('description', 'RECEIVER GENERAL','Receiver General (CRA remittance)'),
  ('description', 'CRA ',            'CRA remittance'),
  ('description', 'WORKSAFE',        'WorkSafeBC premium'),
  ('description', 'ICBC',            'ICBC insurance (pre-authorised)'),
  ('description', 'INSURANCE',       'Insurance (pre-authorised)'),
  ('description', 'BC HYDRO',        'BC Hydro (pre-authorised)'),
  ('description', 'FORTISBC',        'FortisBC (pre-authorised)'),
  ('description', 'TELUS',           'Telus (pre-authorised)'),
  ('description', 'ROGERS',          'Rogers (pre-authorised)'),
  ('description', 'E-TRANSFER',      'Interac e-Transfer sent'),
  ('description', 'TRANSFER',        'Transfer between accounts'),
  ('description', 'PAYMENT - THANK YOU', 'Card payment'),
  ('description', 'STRIPE',          'Stripe fees / payouts');

-- Tim can change these without a deploy (MissingReceiptService reads them, defaults in code).
INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) VALUES
  ('penny_chase_quiet_start', '21', 'Penny receipt chaser: no pushes from this hour (24 h, Pacific)'),
  ('penny_chase_quiet_end',   '7',  'Penny receipt chaser: pushes may start again at this hour'),
  ('penny_chase_from',        '2026-01-01', 'Penny receipt chaser: first bank date she looks at (2025 is filed)');
