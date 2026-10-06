# Sam, the Closer: rate card and pricing function (plan, step 1)

Status: **PLAN, waiting for Tim's approval.** No feature code has been written.
Branch: `feature/sam-closer-pricing` (from `feature/sam-sales-head`). Never deployed, never
pushed to `feature/language`.

Sam keeps his name. His title changes from sales head to **Closer**. Step 1 is the rate card
and one tested PHP pricing function. The arithmetic lives in PHP. A model may only choose
the wording of the tiers.

```
visit price = max(minimum,
                  ((site minutes + drive minutes added) × target hourly rate
                   + materials + disposal) / (1 − target margin))
```

---

## 1. What already exists (do not duplicate it)

The CRM already has **four** half-connected rate stores. The Closer reads from them. It does
not add a fifth.

| What | Where | Notes |
|---|---|---|
| Labour, equipment and material rates | `cost_factors` table. Edited in `public/crm/products/cost-factors.php`, API at `app/Modules/Products/Api/api-cost-factors.php`. Seed values are at :235 (Owner $45/$52 burdened, Foreman $35, Laborer $25, mowers $8–15/h, truck $12/h) | Rate per hour or per unit. Live values unknown. |
| Overhead and margin | `overhead_settings` (key/value). Defaults are at `api-cost-factors.php:180-187` and `PlanProfitabilityService.php:42-60`: `profit_margin` 35, `overhead_percent` 20, `overhead_apply_mode` (0 = %, 1 = $/billable h), `estimated_billable_hours` 160. `overhead_items` holds monthly overhead lines | **`profit_margin` is the existing target margin.** The Closer reuses it. |
| Per-service price rules | `product_pricing_rules` + `measurement_groups` (migration 301): `flat / per_sqft / per_linear_ft / min_plus_*`, `minimum_price`, `included_units`. Engine: `app/Services/QuoteCalculator.php:24` `calculateLineItemFromRule()`, `:131` `autoPopulateQuoteFromMeasurements()`, `:322` `calculateBundleLineItems()`. Line items already snapshot `price_per_unit`, `minimum_applied`, `pricing_snapshot` | This prices by **area**, not by minutes. The Closer is a cost-plus **check** that sits beside it. It does not replace it (see §4). |
| Product minimums | `products.min_price` (migration 119), `products.base_price`, `products.service_type` (1034) | These are the per-service minimum visit prices. |
| Tiers | `product_bundles.tier` enum `good/better/best/custom` + `product_bundle_items` (301). They show as tier badges in `public/crm/quotes/create.php:1599`. The products-manager "GGOB" form at `products-manager.php:495-540` uses **hard-coded** checkbox ids 1–8 | **Basic/Standard/Full care map onto good/better/best.** No new tier table is needed. |
| Service packages | `service_packages` (025): `default_duration_minutes`, `margin_target_percent` 35, `base_price` | This is a fifth margin number. Flag it, don't use it. |
| Owner rate | `ops_settings.freedom_owner_rate` (`OwnerFreedomService.php:84-93`). Falls back to the owner's `users.hourly_rate` | This is what Tim *pays himself*. It is not a charge-out rate. |
| Crew pay | `users.hourly_rate` (falls back to 25 in `PlanProfitabilityService.php:128,201`) | |
| Drive cost | `ops_settings.drive_cost_per_minute` = 0.75 (migration 901). Used by `VisitCompletionService.php:78-84` | |
| Visit P&L | `visit_margin_snapshots` (901): quoted, labour, material, drive, margin_pct per visit, `service_type`. Written by `VisitCompletionService::capture()`. Read by Owner Freedom, profitability, reports | |
| Timer | `job_time_entries` (`visit_id`, `duration_minutes`, `status`, `time_type ENUM('job','purchase','drive')` from 970). `job_visits.actual_duration_minutes`, `drive_time_minutes`, `labor_rate_snapshot`. `job_plans.estimated_duration_minutes` is a rolling average (`VisitCompletionService.php:~205`) | |
| Measurements | `property_measurements` (002/301: `area_sqft`, `perimeter_ft`, `linear_ft`, `measurement_type`, `measurement_group_key`, polygon). Rolled up into `properties.total_lawn_sqft`, `total_hard_surface_sqft`, `total_hedge_linear_ft`, `total_other_sqft`. Legacy `lawn_size_sqft`. Precedence is at `CalendarStops.php:332-342`. Service: `app/Services/MeasurementService.php` | **There is no edging-length field and no obstacle field.** |
| Quote accuracy | `quote_accuracy_log` (517). Weekly quoted vs actual per plan, **materials and disposal only** | |
| Routing | `calendar_stops` (property, crew, `stop_date`, `route_order`). `properties.latitude/longitude`. Haversine at `app/Modules/Jobs/Api/optimize-route.php:326` `haversineM()`. JS estimate at `public/crm/js/route-engine.js:494-516`: **×1.4 detour, 40 km/h**. The Might-E is already assumed | |
| Contracts | `contracts.renewal_date`, `auto_renew`, `renewal_increase_pct` (`ContractService.php:114`). Renewal cron `app/Modules/Contracts/Cron/contract_renewal.php` (30-day notice window at :69). Signed wording is snapshotted by `ContractTermsService` (migration 1119) | **Production is ahead of the repo on `contract_renewal.php`** (vault Known-Failure-Patterns, 57 prod-only lines). Do not touch it. |

### Traps found in the timer data (these decide how much history is usable)

1. **Drive and purchase time is counted as site time.** `VisitCompletionService` step 12
   (`:173-183`) and the rolling average (`:205-215`) sum *all* `job_time_entries` and never
   filter `time_type = 'job'`. Calibration must filter it.
2. **The margin snapshot is taken before the actual minutes are written.** Step 4 (`:68-71`)
   reads `actual_duration_minutes` before step 12 fills it, so it falls back to
   `estimated_duration_minutes`. Treat `visit_margin_snapshots.labor_cost` as possibly
   estimated. Recompute minutes from `job_time_entries` instead.
3. **Crew-minutes are not wall-minutes.** Two people on a site for 30 minutes give 60
   entry-minutes. The rate card must say per **person-hour**.
4. **Cluster sessions apportion time.** `ClusterService::apportionTime()` splits one session
   across neighbouring properties. Those rows are allocations, not measurements. Down-weight
   them or exclude them.
5. **History is thin.** The Owner Freedom audit (2026-09-22) found **34 of 214 visits timed**.
   Per-service counts have to come from production (§2). The plan assumes "tens per service,
   not hundreds".

---

## 2. Unknowns and how to settle each (cheapest first)

Production is read only through Tim's logged-in Chrome: the `/crm/database_appstack.php`
schema authority and its read-only query box, or the CRM debug panel. Every query below is
SELECT-only and MySQL 5.7-safe.

| # | Unknown | How to settle it |
|---|---|---|
| U1 | Live `cost_factors`, `overhead_settings`, `overhead_items` values | `SELECT * FROM cost_factors WHERE active=1`; `SELECT * FROM overhead_settings`; `SELECT category,item_name,amount,frequency FROM overhead_items WHERE is_active=1` |
| U2 | Whether 301's tables and columns exist on prod (pricing rules, bundles, `total_hedge_linear_ft`, `quote_line_items.bundle_id`) | Schema snapshot. Check that migration 301 has run. |
| U3 | Timed-visit history per service, this season and last | `SELECT jp.service_type, YEAR(jv.scheduled_date) y, COUNT(DISTINCT jv.id) visits, COUNT(DISTINCT jte.visit_id) timed FROM job_visits jv JOIN job_plans jp ON jp.id=jv.plan_id LEFT JOIN job_time_entries jte ON jte.visit_id=jv.id AND jte.status IN ('completed','edited') AND jte.time_type='job' WHERE jv.status='completed' GROUP BY 1,2` |
| U4 | How many timed visits have a measured lawn | Same query joined to `properties`, counting `COALESCE(NULLIF(total_lawn_sqft,0), lawn_size_sqft) > 0` |
| U5 | Whether `time_type='drive'` entries are actually recorded | `SELECT time_type, COUNT(*), SUM(duration_minutes) FROM job_time_entries GROUP BY 1` |
| U6 | Properties missing lat/lng | `SELECT COUNT(*) FROM properties WHERE latitude IS NULL OR latitude=0` |
| U7 | How dense the route days are (stops per crew per day) | `SELECT stop_date, crew_id, COUNT(*) FROM calendar_stops WHERE stop_date >= '2026-05-01' GROUP BY 1,2` |
| U8 | Last season's accepted quotes that have a plan and timed visits (the backtest set) | `quotes` accepted → `job_plans.quote_id` (or `quote_id` on the plan; check the column) → visits. Count them. |
| U9 | Which products and bundles exist, with `tier` and `min_price` | `SELECT id,name,service_type,base_price,min_price FROM products WHERE is_active=1`; `SELECT * FROM product_bundles` |
| U10 | What the signed contract says about price-change notice | `SELECT id,name,service_type,body FROM` the terms-template table (1119). Read the price, renewal and notice clauses. Tim confirms. |
| U11 | Truck speed on real streets | Compare `time_type='drive'` minutes (U5) against the haversine×1.4 km between consecutive stops for the same day. If they agree within about 20%, keep 40 km/h. Otherwise fit the factor. |
| U12 | Margin of current clients (for fall price review) | `SELECT contact_id, service_type, COUNT(*), AVG(margin_pct) FROM visit_margin_snapshots WHERE visit_date >= '2026-04-01' GROUP BY 1,2`. Caveat: trap 2 above. |

If U3/U4 show fewer than about 15 timed and measured visits for a service, the site-minutes
model for that service ships as **"rate-card minutes only (not yet calibrated)"**. Tim sets
the minutes per 1,000 sq ft by hand, and the weekly recalibration switches on when the count
passes the threshold. This is the switch the global rules ask for.

---

## 3. Files to create

| File | Purpose |
|---|---|
| `app/Modules/Sales/Services/CloserRateCard.php` | Reads the rate card **read-only**. Sources: `overhead_settings.profit_margin` (target margin), new `ops_settings` keys `closer_hourly_cost`, `closer_margin_floor_pct`, `closer_min_visit`, `closer_truck_kmh` (40), `closer_detour_factor` (1.4), plus `products.min_price` per service. If `closer_hourly_cost` is blank, derives a suggestion from `cost_factors` (burdened labour + mower + trimmer + blower + truck per hour) + overhead per billable hour, and labels it as a suggestion. **There is no write method.** Tim edits on the Cost Factors page. |
| `app/Modules/Sales/Services/CloserPricing.php` | Pure static functions. `price(array $in, array $card): array` (the formula), `tiers(array $lot, array $card, array $tierDefs): array`, `marginOf(float $price, float $cost): float`, `belowFloor(...)`. No DB. |
| `app/Modules/Sales/Services/SiteMinutesModel.php` | `predict(array $lot, string $service, array $coeffs): array{minutes, basis, n}`. Linear per service: `a + b·lawn_sqft/1000 + c·edge_ft/100 + d·obstacles`. `fit(array $rows): array`, a closed-form least squares on at most 4 terms, falling back to median minutes per 1,000 sq ft when n is small. `loadTraining(PDO)` uses the corrected timer query (job entries only, cluster-apportioned rows excluded). |
| `app/Modules/Sales/Services/DriveMinutesAdded.php` | `added(float $lat, float $lng, array $routeDays, array $card): array{minutes, day, crew_id, neighbours}`. For each existing route day (calendar_stops + property coords, next 4 weeks, same weekday cadence), takes the cheapest insertion: `d(a,x)+d(x,b)−d(a,b)` over consecutive stops, ×detour ÷ speed. Returns the minimum. `haversineKm()` is a local copy of the `optimize-route.php` math, because that one is a page-file function. |
| `app/Modules/Sales/Cron/closer_recalibrate.php` | Weekly. Refits `SiteMinutesModel` per service and stores the coefficients and n in the new table. Reports drift (actual margin vs `profit_margin`, actual vs predicted minutes) to Sam's questions. **It never writes a rate.** |
| `database/migrations/1141_closer_rate_card.sql` | `closer_site_models` (service_type, a, b, c, d, n, r2, fitted_at). `INSERT IGNORE` the `closer_*` keys into `ops_settings` with NULL values. Adds `properties.edge_linear_ft DECIMAL(10,2) NULL` and `properties.obstacle_count SMALLINT NULL`. A `SHOW COLUMNS` guard, no `IF NOT EXISTS` on indexes. |
| `tests/Unit/Sales/CloserPricingTest.php`, `SiteMinutesModelTest.php`, `DriveMinutesAddedTest.php`, `CloserRateCardTest.php` | Registered in `tests/bootstrap.php` next to the other Sales `require_once` lines. |
| `docs/crm/sales-head.md` | Add a Closer section. |

Not touched: `api-products.php` (production is behind the repo; memory 2026-10-04),
`contract_renewal.php` (production is ahead), `QuoteCalculator.php` (read only), anything in
Expenses, `bookkeeper-card.js`.

Measurement additions for edges and obstacles reuse the existing map draw tool. A
`polyline` shape with a new `measurement_type` value `edge` is a later UI step. Until then,
`edge_linear_ft` and `obstacle_count` are typed in by hand.

---

## 4. The pricing function

`CloserPricing::price($in, $card)`

| Input | Source |
|---|---|
| `site_minutes` | `SiteMinutesModel::predict()` from the lot. Basis is `calibrated(n)` or `rate_card`. |
| lot: `lawn_sqft` | `COALESCE(NULLIF(total_lawn_sqft,0), lawn_size_sqft)`, the same precedence as `CalendarStops.php:332`. Satellite or drawn, so it's a draft. |
| lot: `edge_ft`, `obstacles` | New columns (hand-entered for now). `total_hedge_linear_ft` is used for Full care hedge work. |
| `drive_minutes_added` | `DriveMinutesAdded::added()` from `properties.latitude/longitude` + `calendar_stops`. Cheapest insertion into the nearest route day. A client with no route day nearby falls back to the round trip from the depot (an `ops_settings` depot lat/lng; add it if it's missing). |
| `hourly_rate` | `closer_hourly_cost`. This is the **fully burdened cost per person-hour, not the charge-out rate.** If it already included profit, dividing by (1 − margin) would charge the margin twice. |
| `crew_size` | 1 by default; the product or package can override it. |
| `materials`, `disposal` | Sum of the tier's product `base_cost` per visit + a `disposal` cost factor (per yard or per visit). `quote_accuracy_log.actual_disposal` is the check. |
| `target_margin` | `overhead_settings.profit_margin` / 100. |
| `minimum` | `max(closer_min_visit, products.min_price for the tier's lead product)` |

Output: `{price, cost, margin_pct, minimum_applied, parts:{site, drive, materials, disposal},
basis:{site:'calibrated n=23'|'rate_card', drive:'added to Tue route, 3 neighbours'},
below_floor: bool, draft: true}`. Price rounds up to the next dollar. Every quote built from it
carries the line *"Price confirmed after the first visit"* and `pricing_snapshot` JSON
(existing column).

**Tiers.** `tiers()` calls `price()` three times with different service lists:
- **Basic** (good): mow, trim, blow.
- **Standard** (better): Basic plus edging plus seasonal cleanup visits, amortised per visit.
- **Full care** (best): Standard plus aeration, overseeding, hedge and bed work.

Tier contents come from `product_bundles` and `product_bundle_items` rows with `tier`
good/better/best. Tim builds three bundles once, and the hard-coded GGOB checkboxes are
retired later. The model only writes the one-line description of each tier.

**Relation to `QuoteCalculator`:** the area rules stay the default way line items get priced.
The Closer shows its cost-plus price beside the rule price, and flags when the rule price
falls below the margin floor. Tim decides which one to send. Merging the two is a later step,
after the backtest says which is closer to reality.

**First-visit reconcile (step 2, after approval):** when a visit is completed on a plan that
came from a Closer quote, compare the actual job minutes and photos against the prediction.
If the gap is more than 20%, Sam asks Tim whether to re-price. He never re-prices on his own.

**Fall price review (step 3):** from September, list clients below `closer_margin_floor_pct`
over the season (recomputed from timer data, not the snapshot), each with a plain-words
reason ("visits took 52 min against 35 quoted"). Draft the renewal through the existing Sam
follow-up flow. Stagger the notices across weeks. The notice date has to satisfy the notice
clause from the signed terms snapshot (U10), so a client whose contract renews before that
date is held back.

---

## 5. Tests and verification

- **Unit (pure).**
  - Formula against hand-worked cases: the minimum clamps, margin 0, margin 0.99 is
    refused, zero drive.
  - `margin_pct` round-trips: `(price − cost)/price == target`.
  - The tier order: price(Basic) < price(Standard) < price(Full care).
  - Cheapest insertion: next door to three stops gives about 0. A lone outlier gives a
    round trip. An empty day falls back to the depot.
  - Least-squares fit recovers known coefficients from synthetic data. Small n falls back
    to the median.
  - The rate card has no write path: reflection asserts no public setter.
- **Backtest (local, no production writes).** Export last season's accepted quotes that have
  completed, timed visits (U8) as CSV through Chrome. A script in the scratchpad runs
  `price()` per quote using the *actual* lots and route days, and reports:
  - (a) predicted vs actual job minutes (MAE, bias) per service;
  - (b) the Closer price vs the price that was accepted;
  - (c) the realised margin at the accepted price.
  The acceptance bar is set with Tim. Suggested: minutes MAE ≤ 25%, no systematic bias over
  10%.
- **`vendor/bin/phpunit`** all green before the commit.
- **Render check.** A stub render of the Closer's quote panel. The UI is a later step.

---

## 6. Decisions for Tim

1. **Target hourly cost** (`closer_hourly_cost`): the fully burdened cost per person-hour
   (wage + burden + mower/trimmer/blower + Might-E + overhead share). Sam shows a suggestion
   built from `cost_factors`. Tim sets the number.
2. **Target margin.** Keep `overhead_settings.profit_margin` (default 35%) as the target,
   or set a new one? And retire `service_packages.margin_target_percent`?
3. **Margin floor** (`closer_margin_floor_pct`), the line for the fall review. Suggested:
   target minus 10 points.
4. **Minimum visit price** (`closer_min_visit`), plus per-service `products.min_price`.
5. **Tier contents.** Which products go in Basic, Standard and Full care. How seasonal
   cleanups and aeration are spread per visit (per visit, or listed separately as seasonal
   visits).
6. **Depot location** for clients with no nearby route day.
7. **Calibration threshold** (suggested: 15 timed and measured visits per service), and
   whether to exclude cluster-apportioned visits.
8. **Approve the migration**: two new property columns and one table.

---

## Noted for later (not built in step 1)

- **Fit score per lead:** drive minutes added, lot complexity, service mix, payment history
  (invoices `amount_paid`/days late). Feeds `SalesDeskService::rankLeads()`.
- **Instant quote from an address:** buy measurement (e.g. LawnVex, priced per lookup) or
  build it (Google Solar/Maps polygons + the existing `MapDrawTool`). Decide after the
  backtest shows how much lot-size error costs.
- **BC future-performance contracts** (Business Practices and Consumer Protection Act; needs
  a BC lawyer before the template changes):
  - Covers consumer contracts of C$50 or more that are not fully supplied and paid at
    signing.
  - Prescribed contents apply.
  - A copy goes to the customer within 15 days.
  - Direct sales have a 10-day cooling-off period and need a copy at signing. The
    invited-seller exemption applies.
  - The customer can cancel if the service is not supplied within 30 days of the date
    promised.
  - The terms snapshot in `ContractTermsService` is where the wording lives.
- **DNCL:** check the National Do Not Call List before any cold call or text to a lead who
  is not an existing customer.
- **Win/loss reasons:** add a reason to the "lost" answer in `SamQuestionService` (price,
  timing, went elsewhere, no reply) so the Closer learns which tier and price lose.
