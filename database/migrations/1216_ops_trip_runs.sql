-- Migration 1216: trip overhead — named places + costed dump / supplier runs (Otto + Penny)
-- Date: 2026-10-07
-- Purpose: the truck's Trackimo trail (vehicle_location_pings) is split into stops and drives
--   (TripSegmentService). A stop at a named place (dump, supplier, yard, fuel) between two client
--   stops is overhead: drive time, time on site, km. TripCostService prices each one (driver's
--   hourly rate + burden, km × truck_cost_per_km, linked receipts) and keeps it in ops_trip_runs
--   so Otto can show a baseline ("dump run, one man: avg 12 min there, 35 min round trip, $41").
--   Stops of 5+ min that are neither a client property nor a known place are offered on Otto's
--   card to be named once; naming creates the ops_places row.
-- Seeds: Vancouver Transfer Station (from the 2026-10-07 trail stop) and the overnight Yard.
--   truck_cost_per_km is seeded only when it doesn't exist yet (0.70, a CRA-style default).
-- Code is guarded: Otto's trips block and the cron say "needs migration 1216" until these exist.
-- MySQL 5.7 compatible (no JSON, no window functions); safe to re-run.

CREATE TABLE IF NOT EXISTS ops_places (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  kind ENUM('dump','supplier','yard','fuel','other') NOT NULL DEFAULT 'other',
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  radius_m INT NOT NULL DEFAULT 150,
  address VARCHAR(255) NULL,
  vendor_match VARCHAR(255) NULL COMMENT 'words that identify this place on a receipt vendor, separated by |',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ops_places_name (name),
  INDEX idx_ops_places_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Otto: named places the truck goes that are not client properties (dump, supplier, yard, fuel)';

CREATE TABLE IF NOT EXISTS ops_trip_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_date DATE NOT NULL,
  trip_key VARCHAR(40) NOT NULL COMMENT 'one round trip: run_date + time the truck left; legs of a combined run share it',
  place_id INT NOT NULL,
  kind VARCHAR(12) NOT NULL COMMENT 'ops_places.kind at the time of the run',
  user_id INT NULL COMMENT 'the driver',
  crew_count TINYINT NULL COMMENT 'people in the truck, when known',
  one_man TINYINT(1) NULL COMMENT '1 = only the driver was in the truck; NULL = unknown',
  crew_basis VARCHAR(12) NULL COMMENT 'clock | phone | default | manual | none',
  crew_override TINYINT(1) NULL COMMENT 'owner toggle: 1 one man, 0 two man — wins over GPS, kept on re-runs',
  from_property_id INT NULL,
  return_property_id INT NULL,
  left_at DATETIME NULL COMMENT 'truck left the client property (or first ping of the day)',
  arrived_at DATETIME NOT NULL,
  departed_at DATETIME NOT NULL,
  returned_at DATETIME NULL COMMENT 'truck back at a client property or the yard',
  drive_min DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT 'this place''s share of the driving',
  onsite_min DECIMAL(6,1) NOT NULL DEFAULT 0,
  km DECIMAL(7,2) NOT NULL DEFAULT 0 COMMENT 'this place''s share of the km',
  labour_cost DECIMAL(10,2) NULL COMMENT 'NULL when the driver has no hourly rate',
  truck_cost DECIMAL(10,2) NULL,
  receipt_cost DECIMAL(10,2) NULL,
  receipt_ids VARCHAR(255) NULL COMMENT 'expenses.id list, comma separated',
  total DECIMAL(10,2) NULL,
  rate_missing TINYINT(1) NOT NULL DEFAULT 0,
  ping_from DATETIME NULL,
  ping_to DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ops_trip_runs_stop (run_date, place_id, arrived_at),
  INDEX idx_ops_trip_runs_kind (kind, one_man),
  INDEX idx_ops_trip_runs_place (place_id),
  INDEX idx_ops_trip_runs_trip (trip_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Otto/Penny: one row per overhead stop (dump, supplier…) with its share of drive time, km and cost';

INSERT IGNORE INTO ops_places (name, kind, lat, lng, radius_m, address, vendor_match)
VALUES ('Vancouver Transfer Station', 'dump', 49.2073000, -123.1130000, 150, '377 W Kent Ave N, Vancouver',
        'transfer station|city of vancouver|landfill|vancouver landfill');

INSERT IGNORE INTO ops_places (name, kind, lat, lng, radius_m, address, vendor_match)
VALUES ('Yard', 'yard', 49.2543000, -123.1262000, 150, NULL, NULL);

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('truck_cost_per_km', '0.70', 'Otto/Penny: truck cost per km for trip overhead — CRA-style default, edit me');
