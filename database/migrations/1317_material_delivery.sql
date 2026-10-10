-- Migration 1317: Otto orders bulk material from Lawnboy instead of trailer runs (owner, 2026-10-10).
-- From the books: Lawnboy delivered 6 yd (rock + crusher) in one drop for $73 (Apr 2 2026); the
-- client was charged $150 per delivery; the trailer carries 2 yd a trip; Lawnboy wants 2-3 days.
-- See MaterialDeliveryService. Change these here or in Settings without touching code.
INSERT INTO ops_settings (setting_key, setting_value, description) VALUES
  ('material_delivery_vendor', 'Lawnboy', 'Bulk material: who delivers it'),
  ('material_delivery_cost', '73', 'Bulk material: what one delivery costs us ($, before GST)'),
  ('material_delivery_charge', '150', 'Bulk material: what we charge the client per delivery ($, before GST)'),
  ('material_delivery_notice_days', '3', 'Bulk material: days of notice the supplier needs'),
  ('trailer_capacity_yards', '2', 'Bulk material: yards the trailer carries per trip')
ON DUPLICATE KEY UPDATE description = VALUES(description);
