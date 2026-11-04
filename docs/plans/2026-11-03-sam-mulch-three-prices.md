# Sam's mulch price: the floor, what you have been paid, and what the market charges (2026-11-03)

Status: **PLAN, awaiting Tim's approval.** Tim, 2026-11-03: "Yes, plan it: Sam shows the floor, what I have actually charged and had accepted, and a market rate I can set, with the reason for each", then "we can find out supplier prices like mygardenbag", "how can the system research these kind of things", "why doesn't Sam know that then".

## What exists (verified this session)
| Piece | State |
|---|---|
| Sam's price `MulchPricingService` | cost-plus only: material $40 + haul $18 + labour $28 = $86, divided by (1 - 35%) = $132. Its own warning lists what is assumed: spreading minutes, people in the truck, haul detail |
| Market research `MarketPriceService` (migration 1375, built 2026-10-19 on Tim's word "my garden bag publish their prices") | reads My Garden Bag's public Shopify product feed, at most weekly, keeps changes. **Live:** source active, last read 2026-11-02 22:00, no error, 66 price rows, nothing changed since Oct 19 |
| What it holds for mulch (delivered in a reusable bag, not spread, free delivery over $100) | composted bark mulch 1 yd bag **$208**, 1/2 yd $178 (= $356/yd), 1/4 yd $158 (= $632/yd); red-brown bark $208; playground woodchips $210 |
| Your quotes | $175/yd accepted twice (Oct 6: 4 yd = $700; Mar 24: 1 line at $175); $250 for aged mulch with manure after leaf drop, accepted; cedar $65, accepted. No lost mulch line seen |
| **Why Sam does not know** | nothing reads the market table: the only other user is the receipt catalogue (to tell hardscape from garden material). It was built as a data layer on Oct 19 and never connected to Sam's price. Not a bug in either, a missing wire |

## What this says (to show, not to decide for Tim)
$175 installed is **below** My Garden Bag's price for the same material delivered and not spread ($208 a yard for a 1-yard bag). A customer who buys 4 yd from them pays $832 and spreads it himself; the quote for the same 4 yd installed is $700. On Sam's own cost ($86) $175 earns $89 a yard, about 51%, $178 a two-yard trip. Whether the market will take $208 or more installed is not proven: two accepted quotes at $175 is evidence the price is not too high, not that it is the most you could ask.

## The pieces (pure class + tests; no migration unless Tim wants his own market rate stored in the table; otherwise ops_settings)
1. `MulchPriceBand` (pure, `app/Modules/Sales/Services/`): takes the floor (price, cost, target margin), the accepted history, the market rows and Tim's own market rate, returns three lines, each with its reason and a plain-words reading. Rules, one test each:
   - **Floor**: "$132: your cost $86 at your 35% target margin. Below this you earn less than you aim for."
   - **Paid**: the accepted per-yard prices of the same family: latest, highest, how many accepted and how many lost; margin and profit per yard and per trip at that price. Fewer than 3 accepted = "not enough to call it your ceiling". A flat-job line (no yard unit) is left out and said so.
   - **Market**: the lowest and the most comparable current row of the family at 1 yard or more, from active sources, with source, what it is ("delivered, not spread"), the date seen, and "unchanged since <first seen>". Older than 14 days = "last read <date>, may be out of date". Not whole-yard bag sizes (1/2, 1/4 yd) as the headline: shown as per-yard maths only on request. No row = "Sam has no market price for this yet", never $0.
   - **Tim's own rate** (setting `mulch_market_per_yard`, plus a note and the date): optional, for what other landscapers charge installed. Unset = not shown.
   - **Reading**: where the accepted price sits against the other two ("above your floor by $43; $33 below My Garden Bag's delivered-only price"). Never a recommended number, never written to a quote.
2. `MulchPricingService::quotedBefore()` replaced by a targeted read: accepted and lost quotes with a mulch / soil / compost line, any age (today it scans only the newest 800 lines of any kind), per-yard unit read from the product's own unit.
3. `MulchPricingService::hint()` and `answer()` carry the band; the API `mulch-pricing.php?mode=hint` returns it; Sam's "Price it" box and Ask Sam show the three lines. The box's script (`sam-mulch-hint.js`) is not in this repo, so it is pulled from production first and changed there.
4. Market read stays where it is (weekly, shared regional layer, never a tenant's private prices). Adding another source is one row in the sources table (Shopify stores read today); a non-Shopify site needs its own small reader. Tim to name any other supplier or landscaper whose prices are published.

## Unknowns and how each is settled
1. Which unit the accepted lines are in (the $175 lines have no unit; quantity 4 on Oct 6 reads as yards): read the product row for "Black Composted Bark Mulch" on production (read-only) before any code.
2. Whether My Garden Bag's price includes GST (Shopify does not say): Sam says "GST not stated" and compares before-tax to before-tax only if known.
3. What calls the weekly market refresh (last read Nov 2, but no caller in this repo and no cron_runs row): find it (live-only file or another cron) and say so; it must keep running.
4. Region: the sources carry a region (bc-lower-mainland); the tenant's region comes from business settings. One source today, so no filter is needed yet, but the lookup must not hardcode Mowology.
5. Where Tim sets his own rate: an input beside the existing mulch settings if one exists, else on the Price it box.

## Steps and proof
1. Read-only checks for unknowns 1 and 3 (`prod-run.sh`, rolled back).
2. `MulchPriceBand` + tests (floor, paid with fewer than 3, flat job excluded, market stale, market missing, unit mismatch, bag sizes, reading text), registered in `tests/bootstrap.php`.
3. Wire into `MulchPricingService`, the API, Sam's box and Ask Sam; pull the live JS first; suite green; Codex review.
4. Dry run on live (rolled back): the three lines for V6K: $132 / $175 accepted / $208 market.
5. Deploy dependency-first, OPcache, check Sam's box on the dashboard as Tim (Chrome), record: build record, Sam skill trap, vault, backlog.

## Not built
Automatic pricing or writing to any quote; scraping sites that do not publish a feed; competitor installed prices unless Tim types them in; changing the target margin.

## Build record (2026-11-03)
- Pulled first (production was ahead): the live `MulchPricingService` (supplier price-list material), the mulch API and the live-only `sam-mulch-hint.js` (commit "pull live ... into the branch").
- Built: `MulchPriceBand` (pure; 24 tests), `MulchPricingService::bandQuotes / yardProducts / marketRows / band`, `hint()` and `answer()` carry the band; the band block is drawn by `sam-mulch-hint.js` for BOTH Sam's card and the quote builder. New optional setting `mulch_market_per_yard` (0 = not set; never an "assumed" input and never feeds the price). Suite 3637 green.
- **Unknowns settled:** the product is sold by the Cubic Yard (unit_types), a quote line named after it with a blank unit is per yard; the weekly market refresh is called from `generate_visits.php` (live only, not in this repo); the market rows are one source, one region.
- **Dry run on live (rolled back) caught a real flaw before the screen shipped:** "bark mulch" resolved to Cedar Mulch (first product with a cost), so the band compared $65 cedar with a $133 floor and dropped the $175 lines as flat jobs. Now, with no product named, the band follows the product sold most (most accepted quotes, then newest). Live result for V6K: floor $133 (cost $86.31), paid $175 on 2 accepted quotes (latest Oct 6, QUO-2026-0073, 51% margin, $177 a 2-yard trip, "too few to call a ceiling"), market $208 (My Garden Bag, since Oct 19, last read Nov 2, GST not stated), reading "$42 above your floor, $33 below My Garden Bag's delivered, unspread price".
- **Codex** (3 reviews, 13 findings): fixed — a flat job under the product's name (guard: far above the catalogue price, and the quote number is shown so Tim can check), expired quotes were counted as lost, two lines on one quote counted twice, market match took any shared word (now the most shared words, none = no price), freshness was another source's, a stale market price was used as evidence of room, an unknown cost showed as $0. Not fixable from the data: a flat job priced under 2.5 times the catalogue price cannot be told from a yard price (no quantity field separates them).
- **Live:** Sam's box on the dashboard (as Tim) shows the three lines with their reasons and the reading; the quote builder uses the same script.
- **Not done / for Tim:** the market rate other landscapers charge installed is not typed anywhere yet (no settings screen exists for these keys: tell me a figure and I set it); the mulch settings still have no screen. The weekly market read depends on a cron file that is ahead of the repo (Ada should watch it). `git push` stays refused.
