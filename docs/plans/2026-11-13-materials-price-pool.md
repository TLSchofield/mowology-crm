# Materials price pool, seeded by Mowology — plan (2026-11-13, not approved, nothing built)

Backlog: *Materials price pool, seeded by Mowology* (`docs/plans/ittai-backlog.md`). Consent: vault Decision-Log,
2026-11-13 — Tim: "I consent for mowology's product purchases [prices] to be shared in the data pool … that gives seed
data to all." It covers **Mowology only**. Every other business stays private until its owner opts in.

## What it is

One shared table of what businesses actually **paid** for an item, by size, supplier, region and month. Mowology's
receipts and supplier invoices seed it. Every Ittai business can read it, so a new business sees real prices on day one
before it has loaded a single supplier list. It is the "later: anonymised materials price index" line under *Supplier
price lists*, started early with Tim's consent.

## 1. Which Mowology purchase lines feed it

Source: `expense_line_items` on Mowology's `expenses`. A line goes in only when **all** of these hold:

| Rule | Why |
|---|---|
| The expense is approved (not pending, not rejected) | Penny's rule 5: only confirmed facts |
| Not personal and not on the shareholder account (`shareholder_account`, personal flags) | A personal buy is not a business price |
| The line is linked to the catalogue by `alias`, `exact`, `confirmed` or `added` (`catalog_match`), never `suggested` or `none` | A guessed product would poison the pool |
| It has a size (catalogue `size_code`, or a unit size once *Consumables* step 2 lands) | "Garden soil" with no size is not comparable |
| A unit price: `unit_price`, or `line_total ÷ quantity` when quantity > 0 | |
| Not an adjustment line (`is_adjustment = 0`), not a refund, not a return | |
| The vendor resolves to a commons vendor (`VendorIdentity` / `kb_vendors`) | The supplier is the shared identity, not Mowology's `vendors.id` |

The unit price is the **net paid price before tax** (after any line discount; `original_unit_price` stays private).
Supplier invoices count when they arrive as expenses with line items (unknown U1 below).

**Not in the first cut:**
- Supplier trade price lists (`supplier_price_items`). They are prices *offered*, not paid, and trade lists are the
  most likely to carry "do not share" terms. Later, per supplier, marked `listed` not `paid`, only with that
  supplier's permission.
- Public retail prices (`market_prices`). Already a shared regional layer. The read side can show them next to the
  pool, labelled "published price", but they are not pool rows.
- Jobber price history and anything Mowology *charges* clients. Selling prices are margins; never shared.

## 2. Shared vs private

| Leaves Mowology (the pool row) | Never leaves |
|---|---|
| Product key: catalogue family + `match_key` (+ `alt_key`) and display name | Which receipt, expense or invoice; the receipt image |
| Size (`size_code`) and unit (each, litre, yard, kg) | Client, job, property, quote |
| Supplier: commons vendor id + `name_key` | Quantity and line total |
| Region code (from the **supplier's** location, never the job's) | Card, account, who bought it, which employee |
| Month (first day of the month, not the day) | The exact date |
| Unit price, before tax, CAD | Discounts, original price, selling price, markup, margin |
| Basis: `paid` | Notes, Penny's lessons, category, GST paid |
| Contributor as a hash only (`VendorIdentity::tenantHash`), never shown | The business's name |

One function builds the row from a whitelist (the same discipline as the commons' privacy whitelist), and a test fails
if the row carries any other key.

Two privacy steps beyond Tim's list:
- **Month, not day.** A supplier's staff could match "Nov 12, 1 × $89" to one sale. A month cannot.
- **Region from the supplier's yard.** The job's location could point at a client.

Honest limit: while Mowology is the only contributor, every price in the pool is in effect Mowology's. Tim's consent
covers that. It is also why other businesses' rows are never shown one by one (section 5).

## 3. Where the pool lives

**Recommendation: the platform database, `Database::platform()` (`app/Core/config.php`).**

- The pool is data about many businesses. It must not sit inside one business's database. If it lived with the
  vendor commons in Mowology's database (`KB_MODE` hub), other businesses' prices would sit in Mowology's backups and
  be readable by Mowology's database users.
- Every business today runs on the same install, each with its own database (`Tenant::businesses()`), so all of them
  can reach the platform database directly. No HTTPS read API is needed for phase 1. A business on another install
  later reads it through an endpoint shaped like `public/api/knowledge/vendor.php` (per-business key, 24 h cache,
  fails soft).
- Same rule as the calculators' saved numbers: if `ITTAI_PLATFORM_DB_*` are not set, `platform()` returns null and
  the pool is simply off. It never falls back to a business database.

**Product keys must be portable.** `TenantProvisioner::REFERENCE_TABLES` copies catalogue categories, families and
size equivalents to a new business, but not `catalog_products`, so product ids differ between businesses. Pool rows
key on `family + match_key + size_code` (and the commons vendor id + `name_key` for the supplier), never on a
business's own `catalog_product_id`.

Tables (platform database, a new script next to `database/platform/001_tools_saved_numbers.sql`; medians worked out
in PHP, no window functions):
- `price_pool_rows`: contributor hash, line hash (HMAC of the source line id with a platform secret, so an edited or
  deleted line can be updated or removed without exposing the id), family, match_key, product name, size_code, unit,
  vendor id, vendor name_key, region, month, unit_price, basis (`paid` | later `listed`), shown_alone (1 only for a
  contributor who consented to single prices: Mowology), created/updated.
- `price_pool_contributors`: contributor hash, consent text version, consented at, by which role, shown_alone, status
  (`on` | `withdrawn`).
- `price_pool_supplier_rules`: vendor id + name_key, rule (`show` | `unnamed` | `exclude`), reason, set by, set at.
- `price_pool_typical`: worked out nightly per product key + size + region over the last 12 months: median, 25th and
  75th percentile, purchases, businesses, suppliers, oldest and newest month.

A Decision-Log entry records that the pool lives on the platform database while the vendor commons still lives in
Mowology's (moving the commons is a separate decision, not this plan).

## 4. Per-supplier opt-out

Each supplier in the pool has one rule in `price_pool_supplier_rules`:
- `show` (default for paid prices): the supplier's name appears with its prices.
- `unnamed`: its prices count toward "typical" but its name is hidden ("a nursery in the Lower Mainland").
- `exclude`: its rows are deleted and never sent again.

Who sets it: Tim (hub owner) for any supplier; a supplier who writes in gets `exclude` the same day. Before
switching on, every supplier Mowology holds a **trade account** with is checked for terms that forbid sharing prices
(U4). Until checked, a trade-account supplier starts as `unnamed`.

## 5. When a price shows, and as what

Two kinds of answer:
1. **"Paid at <supplier>, <month>: $X"** — a single price. Only from a contributor who consented to single prices
   (Mowology today), and only to signed-in Ittai businesses.
2. **"Typical in <region>: $X (range $A–$B, N purchases)"** — the median, rounded (to $0.25 under $20, $1 under
   $200, $5 above), with the middle range, never the lowest or highest single price.

**Minimum group for "typical" (Tim sets the numbers). Recommendation:**
- at least **5 purchases** in the last 12 months, and
- once any non-Mowology business is in the group: at least **3 different businesses**, and no one business more than
  **60%** of the purchases (otherwise the "typical" is one business's price in disguise).

Why not 10 like the calculators: the calculators group whole businesses' numbers; here one product, one size, one
region needs 10 businesses that all bought it in a year, which may never happen. The dominance rule does the privacy
work instead. If Tim prefers 10 businesses, the pool will show almost only Mowology's single prices for a long time.

**Delay:** a purchase enters the pool after its month has closed. This keeps it about history, not live prices (see
U11).

## 6. How other businesses opt in

- Default: off. Reading the pool is open to every Ittai business ("seed data to all"); contributing is a choice.
- Owner only (the admin who can change settings), on the business's Settings page: one switch with plain words —
  what leaves (the left column of section 2) and what never does — plus a second, unticked switch "Show my prices one
  by one, without my name" (default off; without it their rows only count toward "typical").
- The consent is stored with the text version, date and role, in the business's database and in
  `price_pool_contributors`.
- Turning it off deletes that business's rows from the pool by the next nightly run and rebuilds "typical".
- No reward for contributing in phase 1 (no give-to-get). Revisit when there are enough businesses.

## 7. How the read side uses it

**"Best price, in stock"** (gardener page line, `public/ittai.ca/g/index.php`; the CRM page
`public/crm/suppliers/best-price.php`, thin over `SupplierSearchService`):
- The business's own supplier lists stay first: they are the only source that knows stock.
- Under them, from the pool: "Typical paid in your region" and, where allowed, "Paid at <supplier>, <month>".
  Pool prices never say "in stock".
- A business with no lists loaded still gets pool prices: that is the seed value on day one.
- The gardener page line changes only once this is real. Draft for Tim to approve: "Load your suppliers' lists, or
  start from what other businesses paid. Quote a planting and see who has it, at what price."

**Free calculators** (`app/Modules/Tools/`, ittai.ca/tools, public, no sign-in):
- Only the **markup-margin** tool has a cost input. A "Start from a typical cost" picker could fill it from
  `price_pool_typical`. The server still recomputes from the inputs, as now.
- Public pages read **typical prices only**, never single prices, from a small file published nightly. No live
  database query and no free-text search from the public site.
- Only if Tim confirms "all" includes the public (U5). Otherwise calculators stay as they are.

## 8. Unknowns and how each gets settled (cheapest first)

| # | Unknown | Settled by |
|---|---|---|
| U1 | Do supplier invoices arrive as expenses **with line items**, or only totals? | One read-only count on production: expenses by `source`, with and without lines |
| U2 | How many Mowology lines are eligible today (approved, linked, sized, priced, not personal)? The backlog found ~24 price rows against ~700 lines. | The same read-only query. If it is small, *Consumables* steps 2–4 (unit size, matching, a price for every line) come first, and this plan waits |
| U3 | Are receipt `unit_price` values before tax and after discount? | Compare 30 lines from 5 vendors with their receipt images; check `references/traps.md` (penny skill) |
| U4 | Does any trade account's terms forbid sharing prices? | Tim lists Mowology's trade accounts; read the stored account forms (`supplier_accounts`, migration 1320). Tim decides each |
| U5 | Does "all" mean Ittai businesses, or the public calculators too? | Ask Tim (one question) |
| U6 | Group size: 5 purchases / 3 businesses / 60%, or 10 like the calculators? | Tim |
| U7 | Does the platform database exist yet? (Decision-Log: `ITTAI_PLATFORM_DB_*` not set, save is off) | Tim creates it in cPanel (Tim-To-Do). Until then the pool is off |
| U8 | Which region codes? `market_price_sources.region` uses `bc-lower-mainland` | Read the `regions` skill and supplier geo (migration 1321); use one code list for both |
| U9 | Is `match_key` stable enough across businesses to join on? | Run the matcher on 50 Mowology product names in a test and compare keys; `CatalogMatchers` tests |
| U10 | Rounding steps and the 12-month window | Simulate on Mowology's real lines (from U2) before deciding |
| U11 | Canada's Competition Act: sharing prices among competing businesses | One read of the Competition Bureau's competitor collaboration guidelines (information sharing). The design already follows the usual safe shape: past, aggregated, through a third party, purchase prices not selling prices. If the read says otherwise, stop and tell Tim |

Not settled before shipping: none planned. If U9 shows weak keys, the pool ships behind `ops_settings.price_pool`
= `off` | `preview` | `on` and the preview is the measurement.

## 9. Steps and how each is proven

0. **No code.** Tim answers U4–U7. Read-only queries for U1–U3. One read for U11. Result noted here.
1. **Build, nothing shared.** Platform tables; `PricePoolService` (eligibility, whitelist row, line hash);
   `PricePoolTypical` (median, range, group rules, rounding). Codex writes it (`scripts/codex-web-build.sh`) from a
   spec; Claude reviews. Proven by unit tests: the whitelist test; each exclusion rule (personal, `suggested`,
   adjustment, no size); a group one short of the minimum stays hidden; the dominance rule; withdrawal deletes rows.
2. **Preview for Tim.** An admin page showing exactly the rows that would leave Mowology (product, size, supplier,
   region, month, price, nothing else) and the "typical" each would make. Tim reads it and says yes, or marks
   suppliers `unnamed` / `exclude`. Nothing written to the platform database yet. Codex review
   (`scripts/codex-review.sh`): it reads receipts.
3. **Switch on for Mowology.** Nightly publisher writes the rows. Proven: platform row count equals the preview count;
   editing and deleting a test line updates and removes its row.
4. **Read side in the CRM.** Pool lines on Best price. Checked on the live page as Mowology and as a second test
   business with no lists.
5. **Other businesses opt in.** Settings switch, consent record, withdrawal. Tested with a test business.
6. **Calculators** (only if U5 = public). Nightly typical file, picker on markup-margin.
7. **Later:** `listed` prices from trade lists, per supplier, with permission; replies from *Quote and availability
   requests* feed the pool the same way.

Each step is its own deploy, with the usual status line.
