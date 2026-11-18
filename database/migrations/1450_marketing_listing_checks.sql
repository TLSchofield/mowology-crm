-- Migration 1450: Mia's record with each listing (the knowledge triangle on the Listings flow line).
-- Date: 2026-11-10
-- One row per decision the owner makes on the strip: confirmed (still the same / matches), changed (it differs),
-- fixed (corrected on the site), claimed. MiaListingJobs::records() counts the newest 10 per listing.
-- MySQL 5.7 compatible; safe to run twice.

CREATE TABLE IF NOT EXISTS marketing_listing_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  listing_id INT NOT NULL,
  result VARCHAR(12) NOT NULL COMMENT 'confirmed | changed | fixed | claimed',
  user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_listing_checks_listing (listing_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: owner decisions on listings (her record per site)';
