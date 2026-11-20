-- Migration 1435 (2026-11-04): Otto knows each store.
-- lot_*  : the store's legal lot from ParcelMap BC (VendorLotService), used by SupplierStopService instead of an 80 m
--          circle; a refusal is kept in lot_reason (too_big, not_a_lot, unavailable, no_source:<region>).
-- hours_*: where the opening hours came from (VendorHoursService, Google Places, behind ops_settings vendor_hours_lookup,
--          default off) and when they were last checked.
ALTER TABLE vendor_locations
  ADD COLUMN lot_json MEDIUMTEXT NULL,
  ADD COLUMN lot_source VARCHAR(40) NULL,
  ADD COLUMN lot_reason VARCHAR(40) NULL,
  ADD COLUMN lot_checked_at DATETIME NULL,
  ADD COLUMN hours_source VARCHAR(40) NULL,
  ADD COLUMN hours_checked_at DATETIME NULL;
INSERT INTO ops_settings (setting_key, setting_value, description)
SELECT 'vendor_hours_lookup', '0', 'Look up store opening hours from Google Places (needs GOOGLE_PLACES_KEY): 0 off, 1 on'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ops_settings WHERE setting_key = 'vendor_hours_lookup');
