# Plant ID on site → the bed it grows in → its care on the schedule and quotes (planned 2026-10-15)

Tim: "PlantNet yes, after heads. I also want the snap to contain GPS, and attach it to the bed location if possible.
It would then inform a work schedule and/or quotation. E.g. lavender pruned tight in September."
Replaces PictureThis ($90+/yr). Build after the heads' outcome loops (docs/plans/2026-10-15-heads-learn-only-what-repeats.md).

## What exists to build on (checked 2026-10-15)
- Beds with map outlines: `property_measurements` (name, type garden / hedge / lawn …, `polygon_coords` GeoJSON) —
  drawn with the area-measurement tool. A GPS point can be matched to the bed it falls in (point-in-polygon).
- The iPhone camera records where each photo was taken (CameraController passes CLLocation); the Android crew camera
  is a web page (location available from the browser); photos land in media_assets (gps_lat / gps_lng columns).
- Claude is already wired (Penny); Pl@ntNet API is free up to 500 identifications a day (my.plantnet.org/pricing).

## The feature
1. **Snap → "What is this?"** on the crew camera (iPhone, Android) and in the CRM: the photo keeps its GPS; Pl@ntNet
   names the plant (top matches + confidence); Claude, given those matches and the photo, says its condition and the care
   it needs (e.g. lavender: prune tight after flowering, late Aug–Sept; never into old wood).
2. **The bed it's in**: the GPS point is matched to the property's measured bed (point-in-polygon, nearest bed within a
   few metres when GPS is rough); the crew confirm or pick the bed. No measured bed → kept on the property with its pin.
3. **A plant list per bed** (new table, tenant-neutral: plant, bed, property, photo, confirmed by): the property's
   planting record, visible on the property page and the client portal.
4. **Care calendar**: each plant's care tasks (shared knowledge: species → task + month window, learned once for every
   tenant; the owner's own timing per property stays private) — "Lavender, front bed: prune tight, September".
5. **Into the schedule and quotes**: a recurring job-plan task on the property's site plan for the right month (Otto),
   and a quote line Sam can offer when the property has no plan for it ("Prune lavender — September, $X").
6. **Learning**: the owner's corrections to the plant name or the care timing are lessons (head_lessons, the shared
   rule: twice = rule).

## Unknowns to settle first
- Pl@ntNet API terms for a commercial CRM inside 500/day (terms say free up to 500/day; confirm commercial use is allowed).
- Polygon format actually stored in production (GeoJSON order lng/lat?) — read a few rows.
- Phone GPS accuracy in a courtyard vs bed size — test with real photos; fall back to "pick the bed".
- Where care knowledge comes from (Claude + a reviewed table) and who signs it off.
