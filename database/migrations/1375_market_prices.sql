-- 1375: local market prices (owner, 2026-10-19: "local market research is important, my garden bag publish
-- their prices"). What OTHERS charge customers, read from their public pages — the SHARED regional layer
-- (Ittai: public facts every tenant in the region may use; no site_id), unlike a tenant's private trade lists.
-- First source: My Garden Bag (Shopify, products.json — robots.txt allows public product data).
-- Additive only: two new tables + one source row. Safe to re-run.
CREATE TABLE IF NOT EXISTS market_price_sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  region VARCHAR(40) NOT NULL DEFAULT 'bc-lower-mainland',
  name VARCHAR(120) NOT NULL,
  url VARCHAR(255) NOT NULL,
  reader VARCHAR(20) NOT NULL DEFAULT 'shopify' COMMENT 'shopify = /products.json',
  what VARCHAR(160) NULL COMMENT 'What their price includes (e.g. delivered in a bag, not spread)',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_fetched_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_market_source_url (url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS market_prices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_id INT NOT NULL,
  product VARCHAR(160) NOT NULL,
  variant VARCHAR(120) NOT NULL DEFAULT '',
  product_type VARCHAR(60) NULL,
  material VARCHAR(20) NULL COMMENT 'soil | mulch | compost | gravel | sand | rock — for bulk comparisons',
  yards DECIMAL(5,2) NULL COMMENT 'Bag size in cubic yards (1, 0.5, 0.25)',
  price DECIMAL(10,2) NOT NULL,
  seen_on DATE NOT NULL COMMENT 'Day this price was read',
  prev_price DECIMAL(10,2) NULL,
  price_change VARCHAR(8) NULL COMMENT 'new | up | down | same vs the last read',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_market_price (source_id, product, variant, seen_on),
  KEY idx_market_material (material, yards)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO market_price_sources (region, name, url, reader, what) VALUES
  ('bc-lower-mainland', 'My Garden Bag', 'https://mygardenbag.com', 'shopify', 'Delivered in a reusable bag (1, ½, ¼ yd); not spread. Free delivery over $100.');
