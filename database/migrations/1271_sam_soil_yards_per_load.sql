-- Migration 1271: trailer carries 1 tonne = 2 yd mulch or 1 yd soil (Tim, 2026-10-08).
INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) VALUES
  ('soil_yards_per_load', '1', 'Sam pricing: yards of soil / top-dress / compost per trailer load (1 tonne)');
UPDATE ops_settings SET setting_value = '2', description = 'Sam pricing: yards of mulch per trailer load (1 tonne)' WHERE setting_key = 'mulch_yards_per_load';
