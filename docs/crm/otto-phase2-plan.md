# Otto, Dispatcher — phase 2 plan: rule tables + equipment register (no AI)

Status: PLAN, awaiting Tim's approval. Branch `feature/otto-dispatcher-phase2` (from `feature/otto-ops-head`). Migrations 1153–1159.
Otto keeps his name; his card's role line becomes "Dispatcher". Same rules as phase 1: he suggests, Tim clicks; nothing moves a visit or messages a crew on its own.

## 1. What exists today (checked in code)

- **Where a job is:** `properties.city` (VARCHAR, **default 'Vancouver'** — a blank city reads as Vancouver, so the default hides unknowns), `properties.latitude/longitude`, postal code. No neighbourhood field, so the West End needs an area rule.
- **When a job is:** `job_visits.scheduled_date`, `scheduled_time_start/end` (nullable); `job_plans.default_time_start/end`, `estimated_duration_minutes`; `calendar_stops.route_order`.
- **Holidays:** `company_holidays` (`holiday_date`, `is_active`, read by `VisitGenerationService::getActiveHolidays`). These are company days off, not necessarily the statutory holidays a bylaw means.
- **Equipment:** there is **no equipment or asset table.** The only related pieces are:
  - `expenses.asset_tag` (`truck | equipment`, migration 1125, used for Penny's fuel split);
  - `cost_factors.factor_type = 'equipment'` (pricing rates, not items);
  - chart of accounts `1500 Equipment & Tools` (fixed asset), `6200 Equipment Maintenance`, `6120 Vehicle Maintenance`.
  - There are no CCA classes and no depreciation anywhere in the ledger.
- **Tasks:** `tasks` table (`task_type ENUM('general','purchase')` from migration 970, `assigned_to`, `due_date`, `status`); page `tasks_appstack.php`. Maintenance tasks should go here rather than in a new task system.
- **Vehicles:**
  - `vehicle_trip_reports` holds `vehicle_id`, `odometer_start`/`odometer_end` per pre/post trip (`TripReportService`). This gives real km per day per vehicle. The fleet list is `time_clock_settings.fleet_vehicles`.
  - `users.device_type='truck'` plus `users.assigned_truck_user_id` links a driver to a truck.
- **Trackimo:**
  - **one** device (`TRACKIMO_DEVICE_ID`) writes `vehicle_location_pings` (lat/lng/speed/`battery_pct`), polled every minute in business hours.
  - The purge (`purgeOlderThan(90)`) is not scheduled, so history should be complete.
  - It is unknown which vehicle carries the tracker, and whether `battery_pct` is the tracker's own battery (most likely) or the truck's.
- **Job timers:** `job_time_entries` (user, visit, minutes) are the only usage signal for equipment hours. Phase 1 gap fixes make these cleaner.

## 2. Rules: what is sourced, what Tim must confirm

| Municipality / area | Rule | Status |
|---|---|---|
| Vancouver (By-law 6555) | Power equipment (mowers, trimmers, edgers…; chainsaws excluded): Mon–Sat 07:00–22:00; Sun + holidays 10:00–22:00 | Sourced from the City's power-equipment page via search summary. The by-law PDF returned 403 to me, so it is **unverified until Tim/we open bylaws.vancouver.ca/6555c.PDF** |
| Vancouver | Leaf blowers within 50 m of residential premises: weekdays 08:00–18:00, Sat 09:00–17:00, **none Sun/holidays** | Same source, unverified |
| Vancouver — West End | Blowers prohibited. News sources say **gas** blowers specifically. | Confirm gas-only vs all, and the boundary |
| Vancouver / Metro Vancouver | Gas landscaping tool phase-out (2021 council motions, Metro Vancouver small-engine work) | **"confirm current bylaw"**: shown as a note, never enforced |
| Richmond (Bylaw 8856) | "Daytime" 07:00–20:00 Mon–Sat, 10:00–18:00 Sun/holiday. This is a general noise definition, not landscaping-specific. | Unverified |
| Burnaby (Bylaw 7332) | Lawn mowing is covered; hours not found | Empty row, Tim fills |
| Others Tim works in | — | Tim lists them; rows start empty |

Each row stores a status (`verified | unverified | confirm_current_bylaw`), a source URL, and who confirmed it and when. The status shows in the wording: an unverified rule is suggested at a lower priority with the words "rule not yet confirmed".

## 3. Unknowns, settled cheapest first (read-only SELECTs in Tim's Chrome unless noted)

1. `SELECT city, COUNT(*) FROM properties GROUP BY city`. Which cities, how many blank, how many with the 'Vancouver' default.
2. Share of upcoming visits with no `scheduled_time_start`. Without a time, Otto can only check the day (Sunday/holiday blowers).
3. `company_holidays` contents compared with the BC statutory list. Do we need a stat-holiday list too?
4. `SELECT DISTINCT service_type FROM job_plans`. Tim marks which service types use blowers, mowers or trimmers.
5. `time_clock_settings.fleet_vehicles` and `vehicle_trip_reports` by vehicle. Is the Might-E listed, and are odometers filled?
6. Which vehicle carries the Trackimo tracker? Is `battery_pct` the tracker's battery? (Tim, or the Trackimo app.)
7. How crews map to vehicles on a given day (`assigned_truck_user_id`, or Tim picks).
8. Expenses that are equipment purchases (category Tools/Equipment, amount > $500). Used to seed the register and link `expense_id`.
9. Did migration 970 run (does `tasks.task_type` exist)?
10. Bylaw texts: Vancouver 6555 PDF (open in Chrome), Burnaby 7332, Richmond 8856, plus Tim's other municipalities.
11. Might-E specs (~40 km/h, ~90 km standard / ~120 km extended battery) come from Tim. Which battery does ours have?

## 4. Migrations (1153–1159)

- **1153 `municipal_rules`:** municipality, area (NULL = whole city), equipment_class (`power_equipment | leaf_blower | all`), power_source (`any | gas`), day_type (`weekday | saturday | sunday_holiday`), allowed_start/end, banned, near_homes_m, status, source_url, note, confirmed_by/at. Seeded with the table in §2.
- **1153 `municipal_areas`:** name, municipality, `fsa_prefixes` (e.g. V6E,V6G as a first cut for the West End), optional polygon (lat/lng JSON as TEXT). Tim confirms the boundary.
- **1154 `service_equipment`:** service_type → equipment_class (+ `uses_blower`). Tim fills it.
- **1155 `equipment`:** name, class (`mower | trimmer | blower | battery_pack | truck | other`), make/model/serial, power_source, assigned_user_id or vehicle, purchase_date, `expense_id` (cost comes from Penny's receipt; `cost_manual` only when there is no receipt), `cca_class` (as recorded by the accountant, not computed), status, hours/cycles baseline. Battery fields: rated_wh, runtime_new_min. Truck fields: range_km, top_speed_kph, reserve_pct, trip-report `vehicle_id`, trackimo_device_id.
- **1156 `equipment_service_intervals`** (class or item; task such as blade sharpening, oil, air filter, belt; every_hours / every_days) and **`equipment_service_log`** (done_at, hours_at, task_id, expense_id).
- **1157 `equipment_usage_daily`** (equipment_id, date, minutes, km, source) and **`battery_pack_runs`** (pack, date, runtime_min, ran_flat).
- **1158:** `tasks.task_type` gains `maintenance`, plus `tasks.equipment_id`.
- **1159:** spare.

## 5. What Otto does with it now (no route solver)

New suggestion kinds on his card, in the brief and in his learning:

- **`bylaw_hours`.** A scheduled visit (today to +7 days) whose start/end falls outside the allowed window. The window comes from the rule for the property's municipality and area, the day type (holiday = `company_holidays` plus the stat list if needed), and the service type's equipment. Example: "Leaf cleanup at 1234 Main St starts 7:30 am Saturday — Vancouver allows blowers from 9." Actions: move the time (Tim edits it; Otto's existing apply flow) or "Rake/vac that part" (adds a visit note).
- **`sunday_blower`.** Blower work on a Sunday or holiday where none is allowed.
- **`west_end`.** Any visit in the West End area: "No blowers here — plan rake/vacuum time." On Tim's click this adds a crew note to the visit.
- **`truck_range`.** Planned km for the Might-E's stops: yard → stops in `route_order` → yard, straight-line distance × a road factor. The road factor starts at 1.35 and is then learned from odometer km ÷ planned km on past days. Otto flags a day over range × (1 − reserve); the starting reserve is 20%, set by Tim.
  - The real range is learned only if a daily end-of-day battery % is recorded: an optional post-trip field, or a question to Tim.
  - Until then the range stays the spec value, marked unverified.
- **`maintenance_due`.** An item's hours since its last service ≥ the interval. Hours = the crew lead's job-timer minutes on visits whose service type uses that equipment class (an approximation, stated as such). On click, Otto creates a maintenance task.
- **`pack_fading`.** The median runtime of a battery pack's last 3 logged runs is under 70% of its runtime when new. Runs are entered by hand from the register page or answered as an Otto question; no telemetry exists.

**Learning, brain and badges:**
- New brain units: rules confirmed, road factor learned, pack runtimes logged, intervals tuned.
- One badge, "Bylaw keeper": 10 out-of-hours flags acted on.

**Pages:**
- `/crm/ops/municipal-rules.php` (AppStack; view, confirm, edit rows)
- `/crm/ops/equipment.php` (the register, with service log and pack runs)
- Both are gated by jobs.edit. The register's cost column reads the linked expense.

**New files:**
- Services: `DispatchRules` (pure: day type, window check, km estimate, fading check), `MunicipalRuleService`, `EquipmentService`, `TruckRangeService`, plus new kinds in `OpsDeskService`.
- API: `app/Modules/Operations/Api/dispatch.php` (`?mode=`).
- Unit tests for every pure rule (window edges at 07:59/08:00, holidays, a missing time, the reserve cap, the fading threshold).

## 6. Out of scope (later)

- **Route solver.** Needs a VPS: production is shared cPanel (no long-running processes, no compiled solvers such as OR-Tools/VROOM/OSRM, PHP time limits). Options are a small VPS called over HTTPS, or a paid routing API. This is a cost decision for later.
- **Job-time prediction (step 2).** Needs, per completed visit:
  - clean minutes (`job_time_entries` / `actual_duration_minutes`, which phase 1 is cleaning);
  - service type, property size (`property_measurements`), crew size (`actual_crew_count`) and crew member;
  - month/season and the weather snapshot.
  - Roughly 100+ clean visits per service type before it is worth fitting.
- Photo-completeness check in the iOS/Android apps, and live day adaptation.

## 7. Decisions for Tim

1. **Municipalities:** which ones to cover, and who confirms each row (Tim, or us reading the bylaw in his Chrome).
2. **Holidays:** add BC stat holidays alongside `company_holidays`?
3. **West End boundary:** postal-code prefixes first, polygon later?
4. **Might-E:**
   - battery size;
   - reserve % (20% proposed);
   - add an optional end-of-day battery % to the post-trip? This touches the Driver form, and the iOS app would also need it.
   - Which vehicle has the Trackimo tracker?
5. **Maintenance:**
   - Otto suggests tasks (recommended), or creates them automatically?
   - Service intervals per class (blade sharpening every 25 h? oil every 50 h?): Tim's numbers.
6. **Battery packs:** who logs a run (crew, Tim), and is 70% the "fading" line?
7. **CCA:** record the class per item for the accountant only (recommended), and leave CCA calculation to Penny/the accountant?
8. **Green waste (open question):** where does it go (transfer station, compost facility, client bins), and what are the hours and fees? This feeds the route and the end-of-day km later.

Sources: [vancouver.ca power equipment](https://vancouver.ca/home-property-development/power-equipment.aspx), [By-law 6555 PDF](https://bylaws.vancouver.ca/6555c.PDF), [Richmond noise regulations](https://www.richmond.ca/city-hall/bylaws/property/noise.htm), [Richmond BL 8856](https://www.richmond.ca/__shared/assets/BL_8856_07242369236.pdf), [Burnaby noise](https://www.burnaby.ca/node/206), [Vancouver gas equipment motion (Turf & Rec)](https://www.turfandrec.com/vancouver-moves-closer-to-banning-gas-powered-landscaping-equipment/), [Metro Vancouver small gas engines](https://coastreporter.net/bc-news/metro-vancouver-inches-closer-to-phasing-out-small-gas-engines-8721913).
