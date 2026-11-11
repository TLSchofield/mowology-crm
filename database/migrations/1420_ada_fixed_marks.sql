-- Migration 1420: Ada "Mark fixed" marks
-- Purpose: Tim marks a problem on the System Health page as fixed. Ada then judges that job only on
-- runs AFTER fixed_at, shows "waiting for its next run", and records the outcome (cleared / came
-- back) so the page can show what she rechecked. One row per mark; the open one has outcome NULL.
--
-- (The column is system_key: SYSTEM is a reserved word in MySQL 8.)
-- Safe to re-run: CREATE TABLE IF NOT EXISTS. MySQL 5.7-compatible, no JSON, no generated columns.

CREATE TABLE IF NOT EXISTS ada_fixed_marks (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    system_key       VARCHAR(190) NOT NULL,
    problem          VARCHAR(40)  NOT NULL DEFAULT '',
    evidence_at_fix  VARCHAR(500) NULL,
    fixed_at         DATETIME NOT NULL,
    fixed_by         INT NULL,
    outcome          ENUM('cleared','came_back') NULL,
    outcome_at       DATETIME NULL,
    outcome_evidence VARCHAR(500) NULL,
    INDEX idx_ada_marks_system (system_key, outcome),
    INDEX idx_ada_marks_outcome (outcome_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
