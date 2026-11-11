-- Migration 1113: Bank statement verification table
--
-- Records the TRUE per-(date, amount) transaction count from a real bank
-- statement, as confirmed by an admin uploading/pasting the actual export.
-- findDuplicateGroups() uses this as ground truth (when present) instead of
-- the "keep 1, flag the rest" heuristic, and removeDuplicateRow() uses it to
-- allow safely removing excess income/expense duplicates (not just rows
-- already reclassified to type='transfer') — a verified real-statement count
-- is stronger evidence than the type heuristic.
--
-- Real case that motivated this: March 2026 had 238 excess rows (some
-- transactions imported 3x), confirmed by comparing every DB row against the
-- real Vancity export line-by-line — including same-day-same-amount
-- transactions from a single payer (Dorset Realty Group paying multiple
-- properties) that a naive "any duplicate date+amount" grouping would have
-- wrongly deleted down to 1.

CREATE TABLE IF NOT EXISTS bank_statement_verifications (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_date  DATE NOT NULL,
    amount            DECIMAL(10,2) NOT NULL,
    real_count        INT UNSIGNED NOT NULL,
    verified_by       INT NULL,
    verified_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_date_amount (transaction_date, amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
