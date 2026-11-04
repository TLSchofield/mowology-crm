# Otto on arrival of a lead: arrival border now, best day to visit (2026-11-03)

Status: **PLAN — waiting for Tim's yes.** Tim, 2026-11-03: "Otto should immediately sort out their arrival border, work out
where they would be on a route [best day to visit for a quote/service]." Read as: the moment a lead has an address,
Otto pins it, draws its arrival border, and says which working day the crew could look at it (and later do the first
service) at least extra driving.

## What exists (verified in code and live today)
| Piece | State |
|---|---|
| Arrival border = `job_geofences` row with `zone_type = 'arrival_border'` (auto clock-in zone) | the measure-and-quote screen offers "Draw Arrival Border" only when a pin exists and no border does |
| `OttoAutoPlaceService` (live file; pulls into the branch first) | pins by address and draws the city-lot border from ParcelMap BC, **every 6 h**, a few properties a run. Its candidates are Otto's active properties and properties **on a quote**, so a lead with no quote yet is never a candidate |
| `OttoAutoPlaceService::borderOne($propertyId)` | draws one property's border on demand |
| `DriveMinutesAdded::added()` | the extra truck minutes one more stop adds to each crew-day, cheapest slot between neighbouring stops, depot-aware (Sam's Closer uses it for pricing) |
| `WeekLoadRules` (built this afternoon) | a day's load against crew capacity; "a plan length looks wrong" guard |
| `MissedVisitRules::dayBlock()` + `WET_MM` | Sunday / holiday, crew day off, city-hours bylaw, a day forecast ≥ 5 mm is wet |
| `getCalendarStops()` | the stops of any day, per crew |
| Quote visits | an `appointments` row (`status = 'booked'`, tied to `quote_request_id`); Sam already shows "Quote visit booked Fri Oct 16" |

**Live today (8 open leads):** 5 have a property, 3 have none because they gave no address (Erickbentley978, Daniel
Soderberg, Joe & Heather Strasser). Of the 5: two (#72, #73) have pin and border; **two arrived today (#77 Denis, #78
Mark) are pinned but have no border**; one (#74 James Gray, 6 days old) has neither pin nor border. Across all
properties, 1,506 on file, 122 pinned, 108 bordered.

## The pieces
### A. Arrival border on arrival
- Open leads' properties join Otto's candidates (status `new` / `reviewing`, any age, last 30 days).
- `borderOne()` (pin first when missing) is called the moment a lead's property is created, in its own try, from
  the two intake paths that make properties (`process-quote-request.php`, `EmailLeadService`) and from the 15-minute inbox poll as the
  catch-up. One request to OpenStreetMap / the province per lead, inside the service's pacing. A lead with no address
  gets no property: Otto says so ("no address — ask for it", already Sam's one question).
- Same rules as today: never moves a pin, never overwrites a drawn or GPS border; the reason a placement failed is kept
  (`otto_auto_place_state`) and shown.

### B. Best day to look — `LeadRouteDay` (pure) + service
For a lead with a pin: the next 10 working days, each scored with
`DriveMinutesAdded::added()` against that day's crew stops, **blocked** by `dayBlock()`, **skipped** when the day is
full (`WeekLoadRules` ≥ 80%) or planned wrongly (suspect), **noted** when wet. Two questions, same engine:
1. **Quote visit** (30-minute look, `QUOTE_VISIT_MIN`): wants the day with the least added driving and a gap.
2. **First service** (the plan's estimated length) for a lead that said yes.
Answer: best day and two alternatives, each with its reason in numbers: "Thu Nov 6: Dodge is at 3036 W 14th the same
morning, adds 4 min. Fri Nov 7: adds 22 min." No route that day or no depot: "n/a, no route that day", never a guess.
Otto **suggests only**: the existing appointment booking stays Tim's tap.

### C. Where it shows
- Sam's lead row gains one line: "Best day to look: Thu Nov 6 (adds 4 min)" with a Book button into the existing
  appointment form, prefilled.
- The measure-and-quote screen gains a small "Where this fits on the route" box (best day, alternatives) beside the map.
- Otto's brief carries one line per fresh lead ("3 leads placed, 2 have a day to visit, 1 needs an address").

## Unknowns and how each is settled
- Whether open leads' properties carry stable `site_contact_id` and `latitude` at intake (live: yes for 5 of 5 with a
  property) — settled by the dry run.
- Which table holds crew days to read (`calendar_stops` per `getCalendarStops()`; crews and depot as Sam's Closer reads them) — read
  `CloserService` first, reuse its loader, not a second one.
- Appointment slots (hours a crew can take a 30-minute look): start with "any day with a gap"; the appointment form
  already asks for the time.
- No new migration expected; if the border or day needs a stored result, it goes in `ops_settings` JSON like the other Otto state.

## Steps and proof
1. Pull live `OttoAutoPlaceService` (+ `ParcelLotService`, `CityParcelService`) into the branch with their tests.
2. A: candidates + the two call sites; test: a lead property is a candidate, a no-address lead is not.
3. B: `LeadRouteDay` with one test per rule (blocked day, full day, wet day, no route, cheapest slot, quote visit vs service).
4. Dry run (`prod-run.sh`, rolled back) on today's five leads: expect #77 / #78 / #74 get border or a stated reason;
   a best day for each with minutes; the three no-address leads say "ask for the address".
5. Codex on the new classes and call sites; deploy dependency-first; OPcache; check Sam's row, the workflow screen and
   Charlie's brief in Chrome.
6. Not built: automatic booking, any message to the lead, travel-time from a live map API (straight-line × 1.4 at 40 km/h
   like the Closer), crew preference.
