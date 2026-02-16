-- ============================================================================
-- Migration 505: Vendor Products — Known product catalog per vendor
-- ============================================================================
-- Purpose: Store known products for each vendor so the receipt parser can
--          fuzzy-match garbled OCR text against real product names.
--          Especially useful for handwritten receipts (e.g., Lawnboy carbon copies).
--
-- MySQL 5.7 compatible — no JSON functions, no window functions
-- Run outside of a transaction (DDL auto-commits in MySQL)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `vendor_products` (
    `id` int NOT NULL AUTO_INCREMENT,
    `vendor_id` int NOT NULL,
    `name` varchar(255) NOT NULL COMMENT 'Product name as displayed (e.g., Road Base, River Rock 1")',
    `category` varchar(100) NULL COMMENT 'Product grouping (e.g., Sand & Road Base, Rock & Gravel, Mulch)',
    `unit` varchar(50) NULL COMMENT 'Typical unit of measure (yard, half_yard, bag, roll, each)',
    `price_per_unit` decimal(10,2) NULL COMMENT 'Known price per unit (nullable — may vary)',
    `alt_unit` varchar(50) NULL COMMENT 'Alternate unit (e.g., half_yard if primary is yard)',
    `alt_price` decimal(10,2) NULL COMMENT 'Price for alternate unit',
    `bag_price` decimal(10,2) NULL COMMENT 'Price per bag (if sold by bag too)',
    `ocr_aliases` text NULL COMMENT 'Comma-separated OCR variants seen before (e.g., ROAD BAR, ROAD BACE)',
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vp_vendor` (`vendor_id`),
    KEY `idx_vp_active` (`vendor_id`, `is_active`),
    CONSTRAINT `fk_vp_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Known vendor products for OCR fuzzy matching and price validation';


-- ============================================================================
-- Seed: Lawnboy Enterprises Ltd products (vendor_id looked up dynamically)
-- ============================================================================
-- Prices from lawnboylandscape.com as of Feb 2026

SET @lawnboy_id = (SELECT id FROM vendors WHERE name = 'LAWNBOY' LIMIT 1);

-- Turf
INSERT INTO vendor_products (vendor_id, name, category, unit, price_per_unit, alt_unit, alt_price, bag_price, ocr_aliases)
VALUES (@lawnboy_id, 'Sod Turf Roll', 'Turf', 'roll', 7.30, NULL, NULL, NULL, 'SOD, TURF, SOD TURF');

-- Mulch & Manure
INSERT INTO vendor_products (vendor_id, name, category, unit, price_per_unit, alt_unit, alt_price, bag_price, ocr_aliases)
VALUES
(@lawnboy_id, 'Fresh Bark Mulch', 'Mulch & Manure', 'yard', 41.00, 'half_yard', 22.00, 5.00, 'FRESH BARK, BARK MULCH, FRESH MULCH'),
(@lawnboy_id, 'Composted Bark Mulch', 'Mulch & Manure', 'yard', 41.00, 'half_yard', 22.00, 5.00, 'COMPOSTED BARK, COMP BARK, COMPOST MULCH'),
(@lawnboy_id, 'Playground Wood Chip', 'Mulch & Manure', 'yard', 75.00, 'half_yard', 150.00, NULL, 'PLAYGROUND CHIP, WOOD CHIP, PLAY CHIP'),
(@lawnboy_id, 'Mushroom Manure', 'Mulch & Manure', 'yard', 41.00, 'half_yard', 22.00, 5.00, 'MUSHROOM, HORSE MANURE, MANURE');

-- Soil Blends
INSERT INTO vendor_products (vendor_id, name, category, unit, price_per_unit, alt_unit, alt_price, bag_price, ocr_aliases)
VALUES
(@lawnboy_id, 'Turf Blend', 'Soil Blends', 'yard', 41.00, 'half_yard', 22.00, 5.00, 'TURF BLEND, TURF MIX'),
(@lawnboy_id, 'Garden Mix', 'Soil Blends', 'yard', 41.00, 'half_yard', 22.00, 5.00, 'GARDEN MIX, GARDEN SOIL'),
(@lawnboy_id, 'Soil Amender', 'Soil Blends', 'yard', 41.00, 'half_yard', 22.00, 5.00, 'SOIL AMENDER, AMENDER, SOIL AMMENDER');

-- Sand & Road Base
INSERT INTO vendor_products (vendor_id, name, category, unit, price_per_unit, alt_unit, alt_price, bag_price, ocr_aliases)
VALUES
(@lawnboy_id, 'Lime Store Road Base', 'Sand & Road Base', 'yard', 152.00, 'half_yard', 81.00, 6.00, 'LIME STORE, LIME ROAD BASE, LIME BASE'),
(@lawnboy_id, 'Road Base', 'Sand & Road Base', 'yard', 64.00, 'half_yard', 34.00, 4.00, 'ROAD BASE, ROAD BAR, ROAD BACE, RD BASE'),
(@lawnboy_id, 'Washed Stucco Sand', 'Sand & Road Base', 'yard', 88.00, 'half_yard', 47.00, 4.00, 'STUCCO SAND, WASHED SAND, STUCO SAND'),
(@lawnboy_id, 'River Sand', 'Sand & Road Base', 'yard', 54.00, 'half_yard', 29.00, 4.00, 'RIVER SAND, RVR SAND'),
(@lawnboy_id, 'Sechelt Sand', 'Sand & Road Base', 'yard', 54.00, 'half_yard', 29.00, 4.00, 'SECHELT SAND, SECHELT');

-- Rock & Gravel
INSERT INTO vendor_products (vendor_id, name, category, unit, price_per_unit, alt_unit, alt_price, bag_price, ocr_aliases)
VALUES
(@lawnboy_id, 'Navy Jack', 'Rock & Gravel', 'yard', 73.00, 'half_yard', 40.00, 4.00, 'NAVY JACK, NAVY JAC'),
(@lawnboy_id, 'Crusher Dust', 'Rock & Gravel', 'yard', 75.00, 'half_yard', 40.00, 4.00, 'CRUSHER DUST, CRUSH DUST, CRUSHER'),
(@lawnboy_id, 'Bird Eye Rock', 'Rock & Gravel', 'yard', 80.00, 'half_yard', 44.00, 4.00, 'BIRD EYE, BIRDEYE, BIRD EYE ROCK'),
(@lawnboy_id, 'Crush Rock', 'Rock & Gravel', 'yard', 80.00, 'half_yard', 44.00, 4.00, 'CRUSH ROCK, CRUSHED ROCK, CRUSH'),
(@lawnboy_id, 'River Rock 1"', 'Rock & Gravel', 'yard', 107.00, 'half_yard', 59.00, 4.00, 'RIVER ROCK 1, RIVER ROCK'),
(@lawnboy_id, 'River Rock 2"', 'Rock & Gravel', 'yard', 107.00, 'half_yard', 59.00, 4.00, 'RIVER ROCK 2'),
(@lawnboy_id, 'River Rock 2-6"', 'Rock & Gravel', 'yard', 120.00, 'half_yard', 66.00, 6.00, 'RIVER ROCK 2-6, LARGE RIVER ROCK'),
(@lawnboy_id, 'Recycled Crush Concrete', 'Rock & Gravel', 'yard', 54.00, 'half_yard', 29.00, 4.00, 'RECYCLED CRUSH, CRUSH CONCRETE, RECYCLED CONCRETE');

-- Disposal (accepting materials)
INSERT INTO vendor_products (vendor_id, name, category, unit, price_per_unit, alt_unit, alt_price, bag_price, ocr_aliases)
VALUES
(@lawnboy_id, 'Clean Soil Disposal', 'Disposal', 'yard', 45.00, 'half_yard', 22.50, 2.00, 'CLEAN SOIL, SOIL DISPOSAL'),
(@lawnboy_id, 'Clean Concrete Disposal', 'Disposal', 'yard', 45.00, 'half_yard', 22.50, 2.00, 'CLEAN CONCRETE, CONCRETE DISPOSAL'),
(@lawnboy_id, 'Concrete w/Rebar Disposal', 'Disposal', 'yard', 68.00, 'half_yard', 34.00, NULL, 'CONCRETE REBAR, REBAR DISPOSAL'),
(@lawnboy_id, 'Asphalt/Brick/Tile Disposal', 'Disposal', 'yard', 68.00, 'half_yard', 34.00, 2.50, 'ASPHALT, CLAY BRICK, TILE, ASPHALT DISPOSAL');
