-- 1188: 1685 West 14th Avenue had two property records (Tim, 2026-10-06).
--   #76   VR15-40 — the real one: contract CTR-2026-0004, billing Monica Nicule, MacDonald Realty,
--         property border, schedule. Quotes go to Linda Nimmerrichter (strata representative).
--   #1484 duplicate made with the VR15-40 strata client, billing Linda; only QUO-2026-0073 uses it.
-- Moves the quote onto #76 and archives #1484 (nothing deleted). Guarded by id AND address, so it
-- does nothing if either record is not what it was on 2026-10-06.

UPDATE quotes q
  JOIN properties dup  ON dup.id = 1484 AND dup.address = '1685 West 14th Avenue'
  JOIN properties keep ON keep.id = 76  AND keep.address = '1685 West 14th Avenue'
   SET q.property_id = 76
 WHERE q.id = 99 AND q.property_id = 1484;

UPDATE properties
   SET status = 'archived',
       notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END,
                      'Duplicate of property #76 (VR15-40) — archived 2026-10-06; use #76.')
 WHERE id = 1484 AND address = '1685 West 14th Avenue' AND status <> 'archived';
