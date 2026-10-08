-- Migration 1240: QuickBooks Online API — connection, account map, sync ledger, sync log
-- Date: 2026-10-07
-- Purpose: QuickBooks = the accountant's books. The CRM stays the operational system and
--   approved records sync ONE WAY into QBO. Phase 1 (this migration) is read-only discovery:
--   an OAuth 2.0 connection (tokens encrypted at rest with SOCIAL_ENCRYPTION_KEY), a snapshot
--   of what is in the company file, and the chart-of-accounts map Tim confirms. Phase 2 (push)
--   stays behind ops_settings 'qbo_push_enabled' = 0 and writes through qbo_sync / qbo_sync_log.
--   Design + sources: docs/crm/quickbooks.md.
-- Tables:
--     1. qbo_connections  one row per (environment, realmId); tokens *_enc are
--                         base64(IV + AES-256-CBC) exactly like social_accounts.
--     2. qbo_account_map  chart_of_accounts.id -> QBO Account.Id, with the suggestion score and
--                         who confirmed it. Nothing is pushed to an unconfirmed account.
--     3. qbo_sync         one row per CRM record that has (or will have) a twin in QBO:
--                         qbo_id + SyncToken for updates, content hash for "changed since",
--                         requestid for Intuit-side idempotency, status + error.
--     4. qbo_sync_log     every request that wrote (or dry-ran) — the audit trail.
--     5. ops_settings     qbo_push_enabled = 0 (feature flag, OFF), qbo_dry_run = 1.
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

-- ── 1. Connection ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS qbo_connections (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  environment             VARCHAR(12)  NOT NULL DEFAULT 'sandbox' COMMENT 'sandbox | production',
  realm_id                VARCHAR(32)  NOT NULL COMMENT 'QBO company id from the OAuth callback',
  status                  VARCHAR(16)  NOT NULL DEFAULT 'active' COMMENT 'active | error | disconnected',
  access_token_enc        TEXT         NULL,
  refresh_token_enc       TEXT         NULL,
  access_expires_at       DATETIME     NULL COMMENT 'access tokens live 3600 s',
  refresh_expires_at      DATETIME     NULL COMMENT 'rolling 100 days from the last refresh',
  refresh_hard_expires_at DATETIME     NULL COMMENT '5-year hard limit (x_refresh_token_hard_expires_in)',
  scopes                  VARCHAR(255) NULL,
  company_name            VARCHAR(255) NULL,
  country                 VARCHAR(8)   NULL,
  home_currency           VARCHAR(8)   NULL,
  fiscal_year_start       VARCHAR(16)  NULL COMMENT 'CompanyInfo.FiscalYearStartMonth',
  book_close_date         DATE         NULL COMMENT 'Preferences.AccountingInfoPrefs.BookCloseDate — never write on or before it',
  connected_by            INT          NULL,
  connected_at            DATETIME     NULL,
  last_refresh_at         DATETIME     NULL,
  last_used_at            DATETIME     NULL,
  last_error              VARCHAR(500) NULL,
  disconnected_at         DATETIME     NULL,
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_env_realm (environment, realm_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 2. Chart-of-accounts map ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS qbo_account_map (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  connection_id  INT          NOT NULL,
  crm_account_id INT          NOT NULL COMMENT 'chart_of_accounts.id',
  qbo_account_id VARCHAR(32)  NULL COMMENT 'Account.Id; NULL = Tim said "no twin / do not push"',
  qbo_name       VARCHAR(255) NULL,
  qbo_acct_num   VARCHAR(32)  NULL,
  qbo_type       VARCHAR(64)  NULL COMMENT 'Account.AccountType',
  qbo_sub_type   VARCHAR(64)  NULL COMMENT 'Account.AccountSubType',
  confidence     TINYINT      NOT NULL DEFAULT 0 COMMENT '0-100 suggestion score at confirm time',
  matched_on     VARCHAR(20)  NULL COMMENT 'number | name | type | manual',
  confirmed_by   INT          NULL,
  confirmed_at   DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conn_crm (connection_id, crm_account_id),
  KEY idx_conn_qbo (connection_id, qbo_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 3. Sync ledger ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS qbo_sync (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  connection_id  INT          NOT NULL,
  crm_type       VARCHAR(30)  NOT NULL COMMENT 'expense | invoice | payment_allocation | stripe_payment | bank_line | journal_entry | contact | company | vendor | product',
  crm_id         INT          NOT NULL,
  qbo_type       VARCHAR(30)  NOT NULL COMMENT 'Purchase | Invoice | Payment | Deposit | Transfer | JournalEntry | Customer | Vendor | Item | Attachable',
  qbo_id         VARCHAR(32)  NULL,
  sync_token     VARCHAR(16)  NULL COMMENT 'QBO SyncToken at last write; updates must send it',
  content_hash   CHAR(40)     NULL COMMENT 'sha1 of the payload last sent — unchanged = skip',
  request_id     VARCHAR(50)  NULL COMMENT 'Intuit requestid (max 50 chars) — same id = same result, never a duplicate',
  status         VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending | dry_run | synced | error | skipped | blocked',
  last_synced_at DATETIME     NULL,
  error          VARCHAR(1000) NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conn_crm (connection_id, crm_type, crm_id),
  KEY idx_conn_qbo (connection_id, qbo_type, qbo_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 4. Sync log ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS qbo_sync_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  connection_id INT          NOT NULL,
  sync_id       INT          NULL COMMENT 'qbo_sync.id when the request belongs to one record',
  direction     VARCHAR(8)   NOT NULL DEFAULT 'push' COMMENT 'push | pull | dry_run',
  qbo_type      VARCHAR(30)  NULL,
  operation     VARCHAR(16)  NOT NULL COMMENT 'create | update | query | read | upload | refresh | revoke',
  http_status   SMALLINT     NULL,
  request_id    VARCHAR(50)  NULL,
  intuit_tid    VARCHAR(64)  NULL COMMENT 'response header intuit_tid — quote it to Intuit support',
  summary       VARCHAR(255) NULL,
  payload_hash  CHAR(40)     NULL,
  error         VARCHAR(1000) NULL,
  created_by    INT          NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_conn_created (connection_id, created_at),
  KEY idx_sync (sync_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 5. Feature flag (OFF) + dry-run default (ON) ─────────────────────────────
INSERT INTO ops_settings (setting_key, setting_value, description)
SELECT 'qbo_push_enabled', '0', 'QuickBooks API push (phase 2). 0 = read-only discovery only; receipts keep going by email.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ops_settings WHERE setting_key = 'qbo_push_enabled');

INSERT INTO ops_settings (setting_key, setting_value, description)
SELECT 'qbo_dry_run', '1', 'When push is enabled: 1 = build and show payloads, write nothing to QuickBooks.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ops_settings WHERE setting_key = 'qbo_dry_run');
