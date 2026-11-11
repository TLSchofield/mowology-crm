# One supplier record, website prices, and Otto's supplier choice (plan, 2026-10-11)

Tim 2026-10-11: SW Garden Supply has prices on its website — read them with code; Pitt Meadows
(Meadows Landscape Supply) is only worth it for jobs near it, and the location should decide;
"Yes" to this plan, and review other data that should be centralized (separate report).

## What production shows today (prod-run preview, 2026-10-11)

- Two unlinked supplier lists: `vendors` (49, Penny's — from receipts; 5 have a website, copied
  only when printed on a receipt) and `supplier_accounts` (20, Otto's directory — domains for 18,
  `vendor_id` NULL for all 20). Lawnboy and Southlands, where crews actually buy, are not in the
  directory; no directory supplier carries a receipt.
- Locations twice: `vendor_locations` (17, from receipt GPS) and `supplier_accounts.lat/lng` (14).
- Prices: `vendor_products` (99 rows, 4 vendors: unit, price_per_unit, alt/bag price) is a current
  catalogue with no date or source; `vendor_price_index` (82) is monthly spend per category, not
  item prices. No price history anywhere. The Meadows 2026 contractor list (PDF, office@) and
  SW Garden's website prices are stored nowhere.
- Yard / depot location empty (`closer_depot_lat/lng` = ''), so no drive cost can start from the yard.
- No job within 20 km of Meadows in the last 12 months or upcoming (95 Vancouver properties, 1 Burnaby).

## Unknowns and how each is settled

| # | Unknown | Settled by |
|---|---|---|
| 1 | Where crews start and load (the yard) | Tim's answer; coordinates entered by hand until a server geocoding key exists |
| 2 | Real road distance and time from straight-line distance | calibrate from Tim's own `ops_trip_runs` (km and minutes actually driven between points) — no Google call |
| 3 | Which supplier sites expose prices to code | one fetch per site: Wix warmup data / schema.org Product / Shopify `products.json` / WooCommerce Store API; record "readable: yes/no" per supplier |
| 4 | Units across sources (yard, half yard, pail, bag, can) | a unit table with yard equivalents; anything unknown stays unconverted and is shown as such |
| 5 | Website price = contractor price? | marked `retail` unless the source is a contractor list or a receipt; Tim's contractor price wins |
| 6 | Allowed to read | robots.txt checked on every run; identify ourselves; one request every 2 s; weekly |
| 7 | Lawnboy / Southlands prices | apply (3) to lawnboylandscape.com and ssdepot.ca |

## Design

1. **One supplier = one `vendors` row.** `supplier_accounts` stays as Otto's relationship/outreach
   facet (asked, applied, list kind, last heard) linked 1:1 by `vendor_id`; website lives on
   `vendors.website`; locations live in `vendor_locations` (directory points move there,
   `source = 'directory'`). Matching: domain ↔ website/email domain, then name; ambiguous pairs go
   to Tim. Lawnboy and Southlands join the directory.
2. **Price history** — new table: vendor, item name, size/unit, price, `kind` (retail / contractor /
   paid), `source` (website / price_list / receipt / quote), source reference (URL, email message key,
   receipt id), `seen_at`. `vendor_products` keeps the current price; history keeps every sighting.
   Tenant rule (agreed 2026-10-10): website prices are public = shareable; emailed contractor lists
   and receipts are private.
3. **Website price reader** — `SupplierPriceReader` with small readers per shop type (Wix first,
   then schema.org / Shopify / WooCommerce), weekly cron (records its runs, so Ada watches it; zero
   prices from a site that had prices = Ada amber "site changed"). Meadows' PDF and future emailed
   lists go in through the same history table (PDF text → rows, Tim confirms the first import).
4. **Otto's supplier choice** — for a job (location, material, quantity): for each supplier with a
   price, material cost + detour cost (extra km × `truck_cost_per_km` + extra minutes × crew rate ×
   (1 + burden) × crew in truck, from `TripCostService`). Suggest the cheapest landed cost; show the
   sum ("Meadows saves $27 on mulch but the detour costs $180 — buy at Lawnboy"). Only suggests;
   never orders.

## Steps and verification
1. Link the lists (preview on prod, Tim confirms ambiguous matches) → migration for the history table.
2. Reader for SW Garden → preview rows on prod → weekly cron → Ada sees it.
3. Import Meadows' PDF (Tim confirms) → check (3)/(7) for Lawnboy, Southlands, the rest.
4. Yard location + road calibration → supplier choice on Otto's card for upcoming jobs, tested on
   real jobs (unit tests on the cost sum; preview against next week's schedule).
