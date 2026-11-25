-- Migration 1217: Otto asks Penny — receipts and bank lines name the truck's stops
-- Date: 2026-10-07
-- Purpose: Otto used to ask Tim to name every unnamed truck stop. Now StopEvidenceService asks
--   Penny first: a receipt whose PRINTED time (Time In/Out on a scale ticket, the till time)
--   falls inside a stop, a receipt photographed at the stop, a vendor store location within
--   150 m, or the vendor's past receipts that keep lining up with the same truck stop. Strong
--   evidence creates the ops_places row itself (source = 'penny', vendor_id, evidence); a card
--   charge on the bank feed is only a guess, offered on Otto's card as Yes / No, and a "No" is
--   remembered in ops_place_rejections so it is never asked again for that spot.
--   ops_trip_runs keeps the evidence line ("scale ticket #411 9:49–10:01") and whether onsite_min
--   came from the ticket or from GPS.
-- Code is guarded: without these columns places are still created (no source / vendor_id) and
--   runs are stored without the evidence line; "No" answers need ops_place_rejections.
-- MySQL 5.7 compatible (information_schema guards, no JSON, no window functions); safe to re-run.

-- 1. ops_places.source — NULL = named by hand, 'penny' = from receipt / bank evidence
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ops_places' AND COLUMN_NAME = 'source');
SET @sql = IF(@c = 0,
    'ALTER TABLE ops_places ADD COLUMN source VARCHAR(12) NULL COMMENT ''NULL = named by hand; penny = from receipts / bank lines; confirmed = Penny guessed, owner said yes'' AFTER vendor_match',
    'SELECT ''ops_places.source already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. ops_places.vendor_id — the vendors row this place is
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ops_places' AND COLUMN_NAME = 'vendor_id');
SET @sql = IF(@c = 0,
    'ALTER TABLE ops_places ADD COLUMN vendor_id INT NULL COMMENT ''vendors.id when Penny knows the vendor'' AFTER source',
    'SELECT ''ops_places.vendor_id already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. ops_places.evidence — why Penny named it
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ops_places' AND COLUMN_NAME = 'evidence');
SET @sql = IF(@c = 0,
    'ALTER TABLE ops_places ADD COLUMN evidence VARCHAR(255) NULL COMMENT ''e.g. receipt #411 printed 09:49; 3 past receipts'' AFTER vendor_id',
    'SELECT ''ops_places.evidence already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ops_places' AND INDEX_NAME = 'idx_ops_places_vendor');
SET @sql = IF(@c = 0,
    'CREATE INDEX idx_ops_places_vendor ON ops_places (vendor_id)',
    'SELECT ''idx_ops_places_vendor already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. ops_trip_runs.evidence + onsite_basis
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ops_trip_runs' AND COLUMN_NAME = 'evidence');
SET @sql = IF(@c = 0,
    'ALTER TABLE ops_trip_runs ADD COLUMN evidence VARCHAR(255) NULL COMMENT ''evidence for this stop from Penny, e.g. scale ticket #411 9:49-10:01'' AFTER receipt_ids',
    'SELECT ''ops_trip_runs.evidence already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ops_trip_runs' AND COLUMN_NAME = 'onsite_basis');
SET @sql = IF(@c = 0,
    'ALTER TABLE ops_trip_runs ADD COLUMN onsite_basis VARCHAR(8) NULL COMMENT ''gps | ticket — where onsite_min came from'' AFTER evidence',
    'SELECT ''ops_trip_runs.onsite_basis already exists'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 5. Penny's guesses the owner said "No" to — never offered again for that spot
CREATE TABLE IF NOT EXISTS ops_place_rejections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stop_date DATE NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  name VARCHAR(120) NOT NULL COMMENT 'the vendor Penny guessed',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ops_place_rej_geo (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Otto/Penny: weak stop guesses (bank card lines) the owner rejected';
