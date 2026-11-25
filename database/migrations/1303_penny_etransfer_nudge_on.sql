-- Migration 1303: penny_etransfer_nudge_enabled = '1' (dry-run before switching the thank-you emails on, 2026-10-08).
INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('penny_etransfer_nudge_enabled', '1', 'Penny: email e-Transfer payers who needed a security answer, once each, asking them to use info@mowology.ca (1 = on, 0 = off)')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
