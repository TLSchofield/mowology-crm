-- Migration 1360: every account section a bank statement prints, quiet ones included (2026-10-13).
-- See BankImportService::statementSections() / recordSections() and StatementCoverageService.
--
-- A Vancity monthly PDF prints chequing ••6801, Reserve funds ••6819 and GST Reserves ••6827 together.
-- An account with no transactions that month left no bank_import_rows, so the statements check showed
-- it "Not imported" every month (Reserve funds has had no line since Nov 2025). One row here per
-- section of each committed PDF import: the period, opening / closing balance and how many lines it
-- printed (0 = a quiet month, still "in"). bank_account_id = the chart account it maps to (by the last
-- four digits via bank_statement_accounts.bank_name_match; the statement's own account = the import's).
-- Rows of a rolled-back session are ignored (joined on bank_import_sessions.status = 'imported').
-- Backfill: re-import the monthly PDFs (lines already in are skipped; a statement with nothing new can
-- be recorded on its own).
--
-- MySQL 5.7: plain DDL, no JSON / generated columns; safe to re-run.

CREATE TABLE IF NOT EXISTS bank_statement_sections (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  session_id       INT           NOT NULL COMMENT 'bank_import_sessions.id',
  account_suffix   VARCHAR(12)   NULL     COMMENT 'last four digits of the account number printed on the statement',
  bank_account_id  INT           NULL     COMMENT 'chart_of_accounts.id it maps to (NULL = not known)',
  period_from      DATE          NOT NULL,
  period_to        DATE          NOT NULL,
  opening_balance  DECIMAL(12,2) NULL,
  closing_balance  DECIMAL(12,2) NULL,
  line_count       INT           NOT NULL DEFAULT 0 COMMENT 'transactions the statement printed in this section',
  created_at       DATETIME      NOT NULL,
  KEY idx_bss_session (session_id),
  KEY idx_bss_account (bank_account_id, period_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
