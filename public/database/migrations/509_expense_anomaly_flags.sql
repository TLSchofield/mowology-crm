-- ============================================================================
-- Migration 509: Add anomaly detection flags to expenses
-- ============================================================================
-- Purpose: Store machine-calculated fraud/anomaly signals for each expense.
--          The AnomalyDetector service runs 10 rules on create/update and
--          stores triggered rule codes + a composite risk score (0-100).
--
--          Codes are comma-separated strings (MySQL 5.7 compatible, no JSON).
--          Example: "ROUND_NUMBER,GST_MISMATCH"
--
-- MySQL 5.7 compatible — run outside of a transaction (DDL auto-commits)
-- ============================================================================

ALTER TABLE `expenses` ADD COLUMN `anomaly_flags` varchar(500) NULL
    COMMENT 'Comma-separated anomaly codes from AnomalyDetector'
    AFTER `match_confidence`;

ALTER TABLE `expenses` ADD COLUMN `anomaly_score` tinyint UNSIGNED DEFAULT 0
    COMMENT 'Composite risk score 0-100 (max of triggered rule scores)'
    AFTER `anomaly_flags`;

CREATE INDEX `idx_exp_anomaly` ON `expenses` (`anomaly_score`);
