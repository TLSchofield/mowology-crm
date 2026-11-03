# Otto harvest — what the operations and small-business plugins add to the Operations desk (2026-11-03)

Status: **PLAN — waiting for Tim's yes.** Survey of `operations/skills/{capacity-plan, vendor-review,
change-request, process-optimization}`, small-business `inventory-planner`, `restock`, `call-list`,
`payroll-prep` (punch rules) — Sonnet, numbers only. Diffed against `OttoRules`, `OpsDeskService`,
`DispatchRules`, `VisitDurationRules`, `MissedVisitRules`, `UnscheduledWorkRules`, `TrainingRules`,
`EquipmentService`, `SupplierPriceListService`, `MarketPriceService`, `PayrollRunChecks` (Penny's, reusable).

## What the survey found
Only three skills carry numbers: **inventory-planner** (velocities, cover, buffer, reorder, dead stock,
the dated seasonal watch), **payroll-prep** (punch anomalies: >12 h shift, >16 h day, 7 days straight,
overlap, zero-length, missing person, OT >125% of own trailing average) and **capacity-plan** (one
table: target 80%, "100% means no room for surprises"). vendor-review has headings and a 3-way verdict,
no scale. change-request, process-optimization and call-list hold nothing Otto can run on.

## What the live data says (read-only, `prod-run.sh`, 2026-11-03 15:00)
| Source | Today |
|---|---|
| Time clock, last 6 weeks | 3 people clock. Tim (user 1): **84.5 h on 8 shifts** (wk 43), **79 h on 7** (wk 42), 78.8 h on 7 (wk 41); one **duplicate pair** (#412/#413, both 01:33 on Oct 11, 12 h each); one **zero-length** shift (#409, 54 s); one 12 h 04 min shift (#402); 3 shifts open now (normal, mid-day) |
| Week ahead (planned minutes × crew size) | Nov 3: 1,452 · Nov 4: 2,249 · **Nov 5: 15,392** · Nov 6: 1,457 · Nov 7: 941 · Nov 9: 2,894. Five active crew logins → 2,400 crew-minutes a day. Nov 5 is 6× capacity: a plan length is wrong, not the crew |
| Last week | 108 visits scheduled, 22 completed, 37,439 planned crew-minutes |
| Snow season | 43 active contracts; **no salt / ice-melt / de-icer line on any receipt, ever**; season starts Nov 1 (`SnowContractService::SEASON_START`) |
| Repeat purchases (365 d, ≥4 days) | coffee cups (34 days), bottle deposits, cup fees, diesel, "green waste" — nothing a restock model should order; no line item is linked to a catalogue product |
| Vendors, 90 d | a repair shop $1,528, Chevron $869 (48 receipts), Lawnboy $715 (9), landfill $408 (12), Esso/Shell fuel |

## The pieces (each a pure class + test; all surface through `OpsDeskService::brief()`, which Charlie's
## brief, the team card and the iPhone already read — no new page, no new suggestion kind, no migration)

### A. Clock week review — *payroll-prep punch rules, asked never fixed*
`ClockWeekRules::review(entries by person, trailing weeks, now)` over `time_clock_entries` for the last
completed Monday–Sunday week, one brief item per person with up to 3 flags (`PayrollRunChecks::PUNCH_CAP`):
- shift > 12 h (`PayrollRunChecks::SHIFT_HOURS`), day > 16 h, 7+ days straight (reuse `PayrollRunChecks::punchFlags()`);
- **overlapping or duplicate** shifts (two rows of one person that share time — never merged, Otto asks);
- **zero-length** shift (< 5 min: a double tap);
- still-open shift from a past day ("hours unknown" — Otto already proposes the clock-out; this names it);
- week hours > **125%** of the person's own trailing 4-week average (`PayrollRunChecks::OT_FACTOR`), with both numbers;
- zero hours for someone who clocked in 3 of the last 4 weeks.
Wording: who, when, what with both numbers; dollars only when a rate is known (`users.hourly_rate` if present,
else "cost unknown"). Link: `/crm/timeclock/timesheet-detail.php`. Runs Monday to Wednesday for last week, then
drops. Today it would say: Tim 84.5 h vs 4-week average ~62 h; duplicate pair Oct 11; zero-length Oct 8.

### B. Week load — *capacity-plan: 80% target, 100% is no room*
`WeekLoadRules::days(planned crew-minutes per day, active crew, hours/day 8)` for the next 7 days:
- > 100% **over-booked**, 80–100% **full — no room for rain**, < 40% **light**; else nothing said;
- **sanity first:** a day above 300% of capacity, or one plan at ≥ 8 h × crew, is reported as "a plan length
  looks wrong (plan #N, M min)" — never as a crew problem (Nov 5 today);
- one brief item for the week naming the worst days; priority 1 when tomorrow is over-booked.
Crew count = active, non-tablet users with role user/manager (5 today) — shown in the line so Tim can
correct it (`ops_settings otto_crew_count` overrides).

### C. Seasonal stock watch — *inventory-planner's dated watch list; the owner names the season*
`SeasonalStockRules::items(watch list, purchases, today)`: the list lives in ops_settings
`otto_seasonal_stock` (JSON: item, receipt pattern, season start MM-DD, lead days, needed-when clause);
seeded once with **ice melt / salt**, season Nov 1, lead 14 days, needed when any active contract is a
snow/salt route. Rule: decide-by = season start − lead; if no receipt line matches the pattern in the last
120 days, a brief item from decide-by: "Order ice melt by Oct 18 — nothing bought on a receipt since …";
after season start: "Snow season started Nov 1 — no salt on any receipt (43 routes). Bought elsewhere?
Tell Penny the receipt." Never undated. Honest limit stated: Otto sees only receipts.
**Not built:** velocity / cover / reorder quantities — there is no stock count and no on-order record; the
repeat items on receipts are coffee and deposits. Written down as "n/a until stock is counted".

### D. Supplier review — *vendor-review's three headings and Proceed / Negotiate / Pass, every 90 days*
`SupplierReviewRules::build(vendor spend 90 d by category, price-list ages, directory alternatives)`: one
brief item per quarter (key `otto:supplier-review:2026Q4`, dismissable, re-asked after 90 days or when Tim
dismisses): top vendors by spend with share; **concentration** = one vendor over 60% of a category's spend
while the supplier directory lists another in that category → "Negotiate"; a supplier's newest price list
older than 90 days → "stale list"; fuel and dump fees shown but never "Negotiate" (no alternative). Verdict
words only as Otto's suggestion.

## Not built, and why
change-request state machine, process-optimization waste tags, call-list blocks — no numbers, and the
schedule already has its own states. Pay checks (rate moves, missing person on the run) are Penny's
`PayrollRunChecks` and live. Generic restock — see C.

## Dry run before anything ships
A: run on the last 6 weeks — expect Tim's 84.5 h week, the Oct 11 pair, the Oct 8 double tap; no flag on
Nigel/Dodge. B: expect Nov 5 reported as a plan length, not an over-booking; check which plan. C: expect
the salt item today, "season started". D: expect fuel/dump/repair at the top with no Negotiate.

## Verification
`tests/Unit/Operations/OttoHarvestRulesTest.php`; Codex on `app/Modules/Operations/Services` (new files +
`OpsDeskService`); `cmp` showed `OpsDeskService` and `OttoRules` equal to live, so they can be edited;
`otto-card.php`, `otto-card.js`, `otto.php`, `OttoActionService`, `SupplierPriceListService` are **ahead on
production** and are not touched. Deploy per file, OPcache, Charlie's card and the team card checked in Chrome.
