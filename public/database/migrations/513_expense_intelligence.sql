-- ============================================================================
-- Migration 513: Expense intelligence tables
-- ============================================================================
-- Purpose: Two tables that power the smarter analytics layer:
--
--   1. expense_monthly_baselines — Aggregated monthly averages per category.
--      Populated by /crm/cron/expense_baselines.php (monthly).
--      Used by anomaly detector rule HIGH_AMOUNT (flags >2× baseline).
--
--   2. expense_line_item_prices — Individual price observations from receipts.
--      Populated on every expense save with line items.
--      Enables cross-receipt price trending and vendor price comparison.
--
-- MySQL 5.7 compatible — run outside of a transaction (DDL auto-commits)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `expense_monthly_baselines` (
    `id` int NOT NULL AUTO_INCREMENT,
    `accounting_category` varchar(50) NOT NULL,
    `month_num` tinyint UNSIGNED NOT NULL COMMENT '1-12 (January = 1)',
    `avg_total` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Average expense total for this category+month',
    `avg_count` int UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Average number of expenses',
    `stddev_total` decimal(10,2) NULL COMMENT 'Standard deviation of totals',
    `sample_years` tinyint UNSIGNED DEFAULT 0 COMMENT 'How many years of data are included',
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_cat_month` (`accounting_category`, `month_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Monthly spending baselines per category — aggregated by cron for anomaly detection';


CREATE TABLE IF NOT EXISTS `expense_line_item_prices` (
    `id` int NOT NULL AUTO_INCREMENT,
    `vendor_id` int NOT NULL,
    `product_name` varchar(255) NOT NULL COMMENT 'Normalized item name from receipt',
    `unit_price` decimal(10,2) NOT NULL,
    `quantity` decimal(10,3) DEFAULT 1.000,
    `expense_date` date NOT NULL,
    `expense_id` int NOT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_elip_vendor_product` (`vendor_id`, `product_name`),
    KEY `idx_elip_date` (`expense_date`),
    KEY `idx_elip_expense` (`expense_id`),
    CONSTRAINT `fk_elip_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_elip_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Individual price observations from receipt line items — for trending and vendor comparison';
