-- Migration 1132: e-Transfers learn who pays for whom
-- Date: 2026-10-05
-- Purpose: the e-Transfer matcher only paired a sender with a client when the names
--   matched, so "STRATA PLAN BCS-2106" paying Alexandra Bee's invoice had to be worked
--   out by hand every time. Each time the owner records a transfer, the sender → payer
--   pairs are remembered here, and the matcher (Penny's card and the Invoices panel)
--   suggests that payer's invoices next time.
-- MySQL 5.7 compatible. Code is guarded: nothing is learned until this runs.

CREATE TABLE IF NOT EXISTS etransfer_sender_payers (
  sender_key VARCHAR(190) NOT NULL,
  payer_key VARCHAR(190) NOT NULL,
  sender_name VARCHAR(255) NULL,
  payer_name VARCHAR(255) NULL,
  times INT NOT NULL DEFAULT 1,
  last_seen DATETIME NULL,
  PRIMARY KEY (sender_key, payer_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Who pays for whom, learned from recorded e-Transfers';
