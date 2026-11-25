# Measure → materials → quote line (plan, awaiting approval)

Prompted by Tim's AreaMap screenshots (2026-11-23). Audit by read-only researcher, key claims spot-checked.

## What exists today
- **Web measure tool:** `public/crm/products/area-measurement.php` + `public/crm/js/map-draw/map-draw-tool.js`. Outputs sq ft / sq m / acres / perimeter; saves to `property_measurements` (property only, no quote/plan/visit link). Save is replace-all and renumbers every area (`MeasurementService.php:69-77`).
- **Otto lot borders:** `job_geofences` polygon + `area_sqm` (BC ParcelMap source). Area is shown in m² only and never used by quotes or materials.
- **Yard maths** (mulch/gravel: area × depth → yd³): in the JS of `quote-workflow.php` / `quotes/create.php`. **Production's** `app/Services/QuoteCalculator.php` has the PHP half; **the repo copy does not** (live differs from `origin/feature/language`). Repo `api-products.php:745` calls `QuoteCalculator::yardRuleParams`, which the repo never defines.
- `PlanMaterialsService` sums lawn area and parses "2 bags per 4000 sqft"; its regex rejects "per 1,000 sq ft".
- Quote PDF has no map or area. iPhone has no measuring; Android source is not in this repo. No slope handling anywhere. No fertilizer (N per 1,000 ft²), seed, sod or fence maths.

## Problems in the current UI (fix first, they are the "simple and efficient" work)
1. **Too many steps:** Draw Area → corners → close → name + type → Save This Area → Save to Property. Target: corners → Done → saved.
2. **Cancel/undo:** no vertex undo, no Escape; only "Clear Drawing". Add Undo last corner + Cancel.
3. **Phone:** map is inline `height:500px` with no mobile rule (`area-measurement.php:200`); use full-height map with a floating result bar.
4. **Pricing is one rate across all areas** (a driveway priced as lawn) with rates hardcoded in JS (`:601-609`) and stubbed buttons (Add to quote, screenshot, PDF). Replace with per-area-type materials from the product rules in the database.
5. **Units:** saved list shows sq ft only; show sq ft big, acres small.
6. **Inline styles and `utf8mb4_0900_ai_ci` / `ADD COLUMN IF NOT EXISTS` in migration 002** break project rules; fix when touched.

## Proposed UI (one screen, phone-first)
- Full-height satellite map. Floating bar: **big area number (sq ft)**, small acres + perimeter, buttons **Undo** · **Done**.
- Tap corners; tap Done to close. Name defaults to "Lawn 1"; type is one row of chips (**Lawn · Bed · Hard surface · Hedge/edge**), not typing.
- After Done a bottom sheet shows **what to order** for that type, a single big number each:
  - Bed → mulch/soil: yd³ and bags at a depth stepper (default 3 in).
  - Lawn → fertilizer (bags, from N% and lb N per 1,000 ft²), seed, lime, sod; season total across rounds when the plan has rounds.
  - Hard surface → gravel/sand: tons and truckloads.
  - Line → fence/edging/hedge: length.
- One button per line: **Add to quote**, which creates the line with quantity filled from the area and prices from product rules.
- Auto-save per area to the property; no separate "Save to property" step, no renumbering.

## Steps
0. **Sync drift:** `scripts/prod-pull.sh --into-repo app/Services/QuoteCalculator.php` (and check `api-products.php`) so the repo has what production runs. Nothing deploys.
1. Tests for the yard/area maths (none exist today).
2. Rate rules in the database (coverage, depth, N%, lb N per 1,000 ft²); remove hardcoded JS rates. Accept "per 1,000 sq ft" in `PlanMaterialsService`.
3. New materials service under `app/Modules/Products/Services/` + tests.
4. UI rebuild of `area-measurement.php` (per-area save, Undo/Cancel, chips, sheet, Add to quote).
5. Quote PDF: measured shape image + area on the quote (static map, as Proof-of-Work already does in `MapSnapshotService`).
6. Later: GPS "walk the boundary" on iPhone/Android, slope correction, Otto lot-border area shown in sq ft and offered as the starting shape.

## Unknowns to settle before step 3
- Tim's real coverage/fertilizer rates per product (supplier bag sizes, N%). Source: products table + Tim.
- Slope source in BC (no free elevation API checked yet); keep behind a switch.
- Property lines/flood/soil overlays: US-county data in AreaMap, no BC equivalent found; skipped.

## Not doing
Sealcoat/paving estimates, multi-language, hectares.
