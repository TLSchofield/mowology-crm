-- Migration 1297: Who read a special request, and when
-- Date: 2026-10-08
-- Purpose:
--   Every crew member on the visit must dismiss the request screen ("Got it") before they can
--   start the job or take a photo of it. One row per (attached visit, user).
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS special_request_acks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_visit_id INT NOT NULL,
  user_id INT NOT NULL,
  acknowledged_at DATETIME NOT NULL,
  client VARCHAR(10) NULL COMMENT 'web | ios',
  UNIQUE KEY uq_sra_user (request_visit_id, user_id),
  INDEX idx_sra_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Crew acknowledgements of special requests';
