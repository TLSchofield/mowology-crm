-- ============================================================
-- Migration 981: Accounting System — Phase 2
-- Bank Import staging, Accounting Alerts, Recurring entries
-- ============================================================

-- ── 1. Bank Import Sessions ───────────────────────────────────────────────────
-- Tracks each CSV upload batch. Allows reviewing/rolling back imports.

CREATE TABLE IF NOT EXISTS bank_import_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    filename       VARCHAR(255)  NOT NULL,
    bank_name      VARCHAR(80)   NULL,             -- e.g. 'TD', 'RBC', 'BMO'
    account_name   VARCHAR(120)  NULL,             -- which bank account this CSV represents
    row_count      INT           NOT NULL DEFAULT 0,
    imported_count INT           NOT NULL DEFAULT 0,
    skipped_count  INT           NOT NULL DEFAULT 0,
    duplicate_count INT          NOT NULL DEFAULT 0,
    date_from      DATE          NULL,
    date_to        DATE          NULL,
    status         ENUM('pending','imported','rolled_back') NOT NULL DEFAULT 'pending',
    notes          TEXT          NULL,
    created_by     INT           NULL,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 2. Bank Import Rows ────────────────────────────────────────────────────────
-- Staging table for individual rows from a CSV import.
-- Preserved after import for audit + rollback purposes.

CREATE TABLE IF NOT EXISTS bank_import_rows (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    session_id       INT           NOT NULL,
    transaction_date DATE          NOT NULL,
    description      VARCHAR(500)  NOT NULL,
    raw_amount       DECIMAL(10,2) NOT NULL,     -- positive = credit (income), negative = debit (expense)
    type             ENUM('income','expense') NOT NULL,
    amount           DECIMAL(10,2) NOT NULL,     -- always positive
    account_id       INT           NULL,          -- chart_of_accounts.id (set by rules engine)
    transaction_id   INT           NULL,          -- accounting_transactions.id after import
    is_duplicate     TINYINT(1)    NOT NULL DEFAULT 0,
    duplicate_of_id  INT           NULL,          -- accounting_transactions.id of suspected duplicate
    rule_id          INT           NULL,          -- transaction_rules.id that categorized this
    raw_row          TEXT          NULL,          -- original CSV row (JSON) for audit
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    FOREIGN KEY (session_id) REFERENCES bank_import_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 3. Accounting Alerts ──────────────────────────────────────────────────────
-- Persisted alert log — anomalies and intelligence alerts.

CREATE TABLE IF NOT EXISTS accounting_alerts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    alert_type   VARCHAR(60)  NOT NULL,            -- cost_spike, low_margin, uncategorized, revenue_drop
    severity     ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    title        VARCHAR(200) NOT NULL,
    body         TEXT         NULL,
    reference_type VARCHAR(40) NULL,               -- job, account, vendor, transaction
    reference_id   INT         NULL,
    is_dismissed   TINYINT(1)  NOT NULL DEFAULT 0,
    dismissed_by   INT         NULL,
    dismissed_at   TIMESTAMP   NULL,
    created_at     TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type     (alert_type),
    INDEX idx_severity (severity),
    INDEX idx_active   (is_dismissed, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 4. Add bank_account column to accounting_transactions ─────────────────────
-- Tracks which bank account a transaction came from (useful when multiple accounts)

ALTER TABLE accounting_transactions
    ADD COLUMN IF NOT EXISTS bank_account VARCHAR(100) NULL AFTER assigned_user_id;

ALTER TABLE accounting_transactions
    ADD COLUMN IF NOT EXISTS import_session_id INT NULL AFTER bank_account;
