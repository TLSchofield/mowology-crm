# Shared knowledge directory — plan (2026-10-23, awaiting Tim's approval; nothing built)

Tim, 2026-10-23: "Penny shares what she learns across tenants"; "a vendor directory with scraped knowledge and confirmed knowledge";
vendors need geocode + boundary; Otto can draw boundaries (his city-plan / ParcelMap BC function); products can be shared; and "yes all"
to the other things Penny has learned at Mowology. Builds on the vendor knowledge commons (`docs/architecture/vendor-knowledge-commons.md`,
migration 1330, `app/Modules/Knowledge/`). Standing rule: everything is tenant-neutral (Mowology is tenant #1 and the hub, not the template).

## 1. What gets shared, and the one rule for each

Provenance on EVERY shared fact (new column `provenance`): `scraped` (public page or public record: source + fetched date) →
`confirmed_1` (one business) → `confirmed_n` (two or more distinct businesses) → `curated` (hub owner). `blocked` never returns.
A `scraped` fact is shown, never acted on, until a business confirms it.

| Kind | Examples | Shared when |
|---|---|---|
| Vendor identity | name key, GST/BN, phone, domain, kind | already live: one business |
| Vendor locations | point, address, geo quality | scraped (geocode of a public address) or one confirmed stop; confirmed_n at two businesses |
| Boundaries | lot outline, drawn/accepted shape, radius | public record (ParcelMap BC) = scraped; accepted by an owner = confirmed_1; two owners = confirmed_n |
| Products | name, brand, size, unit, pack, SKU, barcode, neutral category | paper facts: one business |
| Public prices | shelf/regular price, pump price, with date + source | observed price (printed regular price, never the discounted one); shown with age |
| Supplier standard price lists | an imported list/catalogue price sheet (e.g. the Inline Nurseries xlsx): product, size, unit, list price, effective date | one business imports it (Tim, 2026-10-23: "standard imported price lists from suppliers is also fine"); labelled `supplier list`, dated, with the supplier; shown as "list price", never as what anyone paid |
| Bank wording | "SQ <merchant>", "WAVEPYRL", "POINT OF SALE (<merchant> <store#>)" → payee kind | confirmed by two businesses; any line holding a person's name is never shared |
| Notice / report layouts | Interac autodeposit and claim emails, Interac alert texts, Stripe payout lines, Wave wages report | one business (layout only, never a name or amount) |
| Regional tax rules | BC PST/GST treatment, meals at 50%, remittance due dates | curated by the hub, with a region tag (BC first) |
| Anomaly signals | duplicate receipt, total vs tax mismatch, invoice number out of sequence | confirmed_n |
| Benchmarks | overhead share, fuel cost per labour hour | opt-in per business, at least 5 businesses per figure, only a range |

Never shared, whatever the setting: customers and what they owe, payer links (who pays for whom), amounts paid, discounts and trade prices,
a business's own negotiated, contract or customer-specific pricing (a list is shared only if it is the supplier's standard list; the import step asks "standard list or ours only?" and defaults to ours only when unsure), jobs, cards, notes, payroll and pay, shareholder accounts, the
owner's personal draws, crew GPS tracks, visit times. Every new shared field is added to the whitelist on purpose; nothing is shared by default.
Every contribution can be withdrawn, and a business that leaves takes its contributions with it.

## 2. What is built (read-only)

- A **Vendor directory** page (owner): search canonical vendors; per vendor: identity, locations on a map with boundary, products, public prices, with
  provenance badges and counts ("confirmed by 3 businesses"; "from the vendor's website, Oct 20"); buttons Confirm / Not right (they feed the same
  promotion rules); "Add to my vendors" links the directory vendor to the business's own record.
- **Boundaries by Otto:** default = the city lot outline for the vendor's address (reuse Otto's ParcelMap BC function: `OttoAutoPlaceService`,
  border_source `parcel`, strata de-duplicated, paced and retried). After 3+ receipt-matched stops he proposes a better shape from where the
  dwell points really sat (40-250 m, only good fixes: accuracy limit as in SupplierStopService). Owner: Accept / Adjust (draggable corners,
  reusing the area-measurement map tools) / Not right, or draws one by hand. Precedence: owner-drawn > owner-accepted > Otto's proposal (shown,
  not used) > 80 m circle. Stored as plain text polygon (MySQL 5.7), point-in-polygon in PHP. A mall store keeps a tight shape, not the whole lot.
- **Fuel price alert** (queued earlier) becomes a view over shared pump prices and station locations.
- Region is a setting: "which lot map" is per region (BC = ParcelMap BC; others added later).

## 3. Stages (each proved before the next; every switch off or shadow first)

0. Verify the commons is live (read-only checks), then with Tim run migration 1330, `kb-backfill`, OPcache. Settle: does the second tenant exist (no: test with a fake tenant on a second schema).
1. Provenance column + directory page over what already exists (confirmed vendor facts). Tests: promotion, whitelist, withdrawal.
2. Locations + boundaries (lot outline, Otto's proposal, owner drawing, precedence, share rules). Local test with fake stops; map checked at 390/820/1440.
3. Products + public prices (receipt regular-price lines; fuel first) + supplier standard price lists (reuse the existing supplier-list xlsx import; add the "standard list or ours only?" choice; a newer list replaces an older one, history kept).
4. Patterns: bank wording (person names stripped by a test that fails the build if one leaks), notice and report layouts, anomaly signals, regional tax rules.
5. Scraping, behind its own switch, only for the sources Tim names (see decisions).
6. Benchmarks last, opt-in, size-gated.

Per stage: unit tests (privacy whitelist test: no forbidden field can be sent), local site, Codex review (it moves data between businesses), deploy with the switch off,
check live. A leak test runs in the suite: a synthetic tenant's private data must never appear in any hub response.

## 4. Unknowns and how each is settled (one source each)

1. Is the commons live? Read-only status check on production (kb tables, commons size on Penny's card).
2. Can Otto's lot function be reused for vendors? Read `OttoAutoPlaceService` and its tests; vendors have `vendor_locations` (point, geo_exact, geo_source).
3. Is a second business connectable now? `kb_tenants` + key; test with a second schema, no real second business needed.
4. Which public pages may be scraped (terms of use, robots)? Tim names the sources; I read each site's terms and keep to facts (address, phone, hours, product names, shelf prices).
5. How are bank lines stripped of people? A tested normaliser keeps only merchant-like tokens and drops anything matching a known person or e-Transfer pattern.
6. Region model: read the shared `regions` skill and the existing region settings before adding "which lot map / which tax rules".

## 5. Decisions needed from Tim

- Approve the sharing table above (anything to remove, or added).
- Which public sources may be scraped, and which vendors first.
- Benchmarks: yes or no, and the minimum group size (5 proposed).
- Stage 0: when you can run migration 1330 and the backfill.

Risks: leaking a person or an amount through a pattern (mitigated by whitelist + leak test + Codex); stale scraped facts (shown with age, never acted on); the province's lot service stalling (paced, retried, falls back to the circle); a wrong shared boundary (needs two owners; withdrawable).
