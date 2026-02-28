-- Migration 517: Quote Accuracy Log (Estimating Feedback Loop)
-- Tracks how accurate each quote's material estimates were vs actual costs.
-- Populated weekly by /public/crm/cron/estimating_feedback.php
-- Used by the Profitability dashboard and future quote auto-suggest.

CREATE TABLE IF NOT EXISTS quote_accuracy_log (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    job_plan_id         INT NOT NULL,
    quote_id            INT NULL,
    quote_number        VARCHAR(20) NULL,

    -- Quoted vs actual totals (materials only)
    quoted_materials    DECIMAL(10,2) NOT NULL DEFAULT 0,
    actual_materials    DECIMAL(10,2) NOT NULL DEFAULT 0,
    material_delta      DECIMAL(10,2) NOT NULL DEFAULT 0,  -- quoted - actual (positive = under budget)
    material_delta_pct  DECIMAL(6,2) NULL,                 -- delta / quoted * 100

    -- Quoted vs actual overall
    quoted_total        DECIMAL(10,2) NOT NULL DEFAULT 0,
    actual_total        DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_delta         DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_delta_pct     DECIMAL(6,2) NULL,

    -- Category breakdowns (actual costs)
    actual_fuel         DECIMAL(10,2) NOT NULL DEFAULT 0,
    actual_disposal     DECIMAL(10,2) NOT NULL DEFAULT 0,
    actual_tools        DECIMAL(10,2) NOT NULL DEFAULT 0,

    -- Metadata
    expense_count       SMALLINT NOT NULL DEFAULT 0,
    snapshot_week       DATE NOT NULL,    -- ISO week start (Monday) this snapshot was taken
    accuracy_rating     TINYINT NULL,     -- 1-5 auto-rated: 5=very accurate, 1=very off
    notes               TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_plan       (job_plan_id),
    INDEX idx_quote      (quote_id),
    INDEX idx_week       (snapshot_week),
    INDEX idx_rating     (accuracy_rating),
    -- One snapshot per plan per week
    UNIQUE KEY uk_plan_week (job_plan_id, snapshot_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Vendor price index: rolling averages per category per vendor per month
-- Updated by the same cron for cross-job price trending.
CREATE TABLE IF NOT EXISTS vendor_price_index (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id           INT NOT NULL,
    accounting_category VARCHAR(50) NOT NULL,
    price_month         DATE NOT NULL,        -- First of the month
    avg_amount          DECIMAL(10,2) NOT NULL DEFAULT 0,
    min_amount          DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_amount          DECIMAL(10,2) NOT NULL DEFAULT 0,
    transaction_count   SMALLINT NOT NULL DEFAULT 0,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_vendor     (vendor_id),
    INDEX idx_category   (accounting_category),
    INDEX idx_month      (price_month),
    UNIQUE KEY uk_vendor_cat_month (vendor_id, accounting_category, price_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
