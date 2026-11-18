-- Migration 1313: every salt & snow route gets the winter crew automatically (owner, 2026-10-08).
-- Comma-separated user ids, LEAD FIRST: Nigel Casey (6) leads — the stop's assigned person is the
-- one whose phone can record what was done and invoice it — with Dodge Ram (7, the truck) and
-- Tim Schofield (1). Change the list here (or in Settings) without touching code.
INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('snow_route_crew_user_ids', '6,7,1', 'Snow & salt: crew put on every new salt & snow route plan, lead first (user ids, comma-separated)')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description);
