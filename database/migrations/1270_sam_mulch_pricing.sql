-- Migration 1270: Sam's mulch pricing — the assumptions behind the installed price per yard
-- Date: 2026-10-07
-- Purpose: Tim — "Sam should be better informed about mulch pricing from the postcode we were at."
--   MulchPricingService prices mulch / soil / compost per yard installed from Penny's receipt lines
--   (material), Otto's real supply runs or a straight-line estimate (haul), and spreading labour,
--   at the Closer's hourly cost and the target margin (overhead_settings.profit_margin).
--   These are the inputs no data measures yet. Every one is shown as "assumed" on the dry run
--   (/crm/api/mulch-pricing.php?mode=dryrun) until Tim changes it. Seeded only when missing.
-- No schema change. MySQL 5.7 compatible; safe to re-run.

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) VALUES
  ('mulch_yards_per_load',      '2',  'Sam mulch pricing: yards the truck carries per supplier trip — default, edit me'),
  ('mulch_spread_min_per_yard', '45', 'Sam mulch pricing: person-minutes to barrow and spread one yard — default, edit me'),
  ('mulch_load_min',            '15', 'Sam mulch pricing: minutes loading at the supplier when Otto has no measured stop — default'),
  ('mulch_haul_crew',           '1',  'Sam mulch pricing: people in the truck on the pickup — default'),
  ('mulch_receipts_n',          '6',  'Sam mulch pricing: newest receipt lines in the material median'),
  ('mulch_min_charge',          '0',  'Sam mulch pricing: minimum charge; 0 = one yard carrying a whole load''s haul');
