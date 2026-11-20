-- Migration 1359: Otto knows when the crew went to the yard (plan docs/plans/2026-10-13-otto-supplier-stops.md).
-- See SupplierStopService / SupplierJobSuggestService (app/Modules/Operations/Services/).
--   1. vendor_locations gets where its point came from (hand / google / nominatim / learned), whether the
--      point is a building (exact) and why a row has no point — geocoded ONCE by the nightly trip cron,
--      never at page load.
--   2. supplier_stops: a crew phone or a truck at least 3 minutes within 80 m of a supplier location,
--      rebuilt per day (ids kept when the same stop is found again), with the receipts that belong to it.
--      receipt_status 'none' = the owner said nothing was bought (Otto's missing-receipt item closed).
--   3. supplier_stop_job_suggestions: Otto's job pick for a material receipt with no job (shadow mode:
--      shown on the receipt editor, one tap confirms, nothing auto-assigns). Every outcome is kept so
--      his hit rate can be scored before any rule is switched on (N >= 10 at >= 95%).
-- Private to the tenant (stops, crews, jobs). MySQL 5.7: plain DDL; run once.

ALTER TABLE vendor_locations
  ADD COLUMN geo_source VARCHAR(20) NULL COMMENT 'hand | google | nominatim | learned — where lat/lng came from',
  ADD COLUMN geo_exact TINYINT(1) NULL COMMENT '1 = a building / house number, 0 = only the street or area',
  ADD COLUMN geocoded_at DATETIME NULL COMMENT 'when the geocoder was asked (set even when it found nothing)',
  ADD COLUMN geo_note VARCHAR(255) NULL COMMENT 'why there is no point (no address, not found)';

UPDATE vendor_locations SET geo_source = CASE WHEN source = 'learned' THEN 'learned' ELSE 'hand' END
WHERE lat IS NOT NULL AND lng IS NOT NULL AND geo_source IS NULL;

CREATE TABLE supplier_stops (
  id INT NOT NULL AUTO_INCREMENT,
  stop_date DATE NOT NULL,
  location_key VARCHAR(30) NOT NULL COMMENT 'vl:<vendor_locations.id> or op:<ops_places.id>',
  vendor_id INT NULL,
  place_name VARCHAR(160) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'supplier' COMMENT 'supplier | fuel',
  arrived_at DATETIME NOT NULL,
  left_at DATETIME NOT NULL,
  minutes SMALLINT NOT NULL DEFAULT 0,
  nearest_m SMALLINT NOT NULL DEFAULT 0,
  points INT NOT NULL DEFAULT 0,
  seen_by VARCHAR(10) NOT NULL COMMENT 'phone | truck | both',
  user_ids VARCHAR(100) NULL COMMENT 'crew phones (and truck tablet logins) that saw the stop',
  people VARCHAR(160) NULL COMMENT 'first names for the evidence line',
  device_ids VARCHAR(100) NULL COMMENT 'Trackimo devices that saw it',
  expense_ids VARCHAR(255) NULL COMMENT 'receipts matched to this stop (comma list)',
  receipt_basis VARCHAR(20) NULL COMMENT 'printed | photo | date',
  receipt_status VARCHAR(12) NULL COMMENT 'NULL = open; none = owner says nothing was bought',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ss_date (stop_date),
  KEY idx_ss_vendor (vendor_id, stop_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE supplier_stop_job_suggestions (
  id INT NOT NULL AUTO_INCREMENT,
  expense_id INT NOT NULL,
  stop_id INT NULL,
  stop_line VARCHAR(255) NULL COMMENT 'the stop as shown: "LAWNBOY Oct 7 11:27–11:34 · Nigel, truck"',
  suggested_plan_id INT NULL,
  reason VARCHAR(500) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open | confirmed | changed | rejected | moot',
  decided_plan_id INT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sjs_expense (expense_id),
  KEY idx_sjs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
