-- ============================================================================
-- Migration 510: Expense budget tracking table
-- ============================================================================
-- Purpose: Stores monthly budget targets per accounting category.
--          The BudgetService compares actual spending against these targets
--          and fires warnings at configurable thresholds (default 80%/100%).
--
--          Admin sets budgets via the Settings or Expenses UI.
--          budget_month is the first of the month (e.g., 2026-03-01).
--
-- MySQL 5.7 compatible — run outside of a transaction (DDL auto-commits)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `expense_budgets` (
    `id` int NOT NULL AUTO_INCREMENT,
    `accounting_category` varchar(50) NOT NULL COMMENT 'Must match expenses.accounting_category values',
    `budget_month` date NOT NULL COMMENT 'First of month, e.g. 2026-03-01',
    `budget_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
    `warning_threshold` tinyint UNSIGNED DEFAULT 80 COMMENT 'Show warning at this % spent',
    `critical_threshold` tinyint UNSIGNED DEFAULT 100 COMMENT 'Show critical alert at this % spent',
    `notes` text NULL,
    `created_by` int NOT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_cat_month` (`accounting_category`, `budget_month`),
    CONSTRAINT `fk_eb_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Monthly budget targets per expense category with alert thresholds';
