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

## Unknowns — settled 2026-10-15 (sources checked this session)
- **Pl@ntNet terms** (my.plantnet.org/terms_of_use): free up to 500 identifications/day, paid for commercial use beyond that;
  attribution sentence + logo required; 20 simultaneous requests; photos not stored (only date/time/image URL).
  Silent on reselling inside a product for other businesses → **Ittai tenants need their own key or a written agreement**; Mowology alone is fine.
  API: `POST https://my-api.plantnet.org/v2/identify/all?api-key=…`, multipart `images` (≤5, JPEG/PNG) + `organs`
  (leaf|flower|fruit|bark|auto); results[] with species.scientificNameWithoutAuthor, commonNames, score 0–1.
- **Key**: Tim adds `define('PLANTNET_API_KEY', …)` to production secrets.php himself.
- **Bed outlines** (production query): `property_measurements.polygon_coords` = JSON `[{lat, lng}, …]`; only 2 garden beds +
  1 hedge drawn (19 properties have outlines, mostly lawns). → Bed matching works where beds exist; otherwise the crew picks
  from the property's list or the plant stays on the property with its pin. Drawing beds is the real limiter.
- **Photo GPS** (production, last 60 days): 134/326 photos have GPS; browser fixes average ~169 m (too rough for a bed),
  EXIF fixes have none recorded. → Use EXIF from the photo first, then the phone's fix; match a bed only when accuracy ≤ 15 m
  (or the point is inside exactly one bed); otherwise ask.

## Build order (each step tested before the next)
1. `PlantIdService` (app/Modules/Plants/Services): Pl@ntNet call (fake transport in tests), top 3 matches, attribution;
   daily count kept well under 500. Migration: `plant_records` (tenant-neutral: site_id, property_id, measurement_id NULL,
   media_id, lat/lng/accuracy, scientific_name, common_name, score, confirmed_by/at, status).
2. `BedLocator`: point-in-polygon + nearest bed within 5 m; tests on the real Garden Bed South polygons' shape.
3. CRM first: "What is this?" on a property photo → matches → crew/Tim confirms plant + bed → plant list on the property page.
4. Care: shared `plant_care` table (species → task + month window, Claude-drafted, owner-approved before use); the property's
   timing stays private. Care tasks show on the property; Otto adds them to the site plan, Sam offers a quote line — both as
   suggestions Tim approves.
5. Phones: iOS camera + Android crew camera call the same endpoint (JWT), after the CRM path is proven.
6. Corrections → head_lessons (twice = rule).
