-- Common names and reference photos for catalogue plants (2026-10-20, Tim: "how do we get common names and photos?").
-- SHARED (no site_id): public botanical facts from iNaturalist, one row per species (or genus) key.
-- MySQL 5.7 safe. One ADD COLUMN per statement so the runner can skip any that already exist.
CREATE TABLE IF NOT EXISTS catalog_taxa (
  species_key VARCHAR(120) NOT NULL PRIMARY KEY COMMENT 'lower case: genus species, or genus alone',
  taxon_rank VARCHAR(16) NULL,
  inat_taxon_id INT NULL,
  common_name VARCHAR(160) NULL,
  photo_file VARCHAR(80) NULL COMMENT 'file in uploads/taxa, copied from iNaturalist',
  photo_credit VARCHAR(255) NULL,
  photo_license VARCHAR(20) NULL,
  status VARCHAR(8) NOT NULL DEFAULT 'none' COMMENT 'found, none or error',
  looked_up_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE catalog_products ADD COLUMN species_key VARCHAR(120) NULL COMMENT 'catalog_taxa.species_key, empty = not a plant name';
CREATE INDEX idx_catalog_product_species ON catalog_products (species_key);
