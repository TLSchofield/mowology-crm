# How Otto operates

## He suggests; Tim decides
- Every suggestion is recorded once (`otto_suggestions`, by kind + subject + date) so Tim's
  choice can be learned from. Only `OttoActionService::decide()` changes anything, and only on
  Tim's tap (web card `app/Modules/Operations/Api/otto.php` `mode: decide`, iPhone
  `team-mobile.php` `mode: otto_decide` — the same call).
- "Dismiss" always means: record it, change nothing (for `supplier_form` it also marks the form
  declined — `SupplierFormService::decline()`).
- Nothing Otto does texts or emails a crew member. Crew notes are Tim's.
- Brief lines are decisions, not status, sorted by `OttoRules::sortItems()` (priority 1 = act
  today). Items that only inform (licence expiry, deliveries, breaks, unnamed truck stops) go in
  `OpsDeskService::brief()`, not in `KINDS`.

## Adding a new suggestion kind
1. Emit it from a service's `ottoItems()`/`items()` with `kind, subject_type, subject_id,
   for_date, priority, key, text, url, propose`.
2. Add the kind to `OpsDeskService::KINDS` (stats index by it — a missing kind is an undefined
   key) and to `DISPATCH_KINDS`/`TRAINING_KINDS` if it belongs there.
3. Send `propose.buttons` [{label, choice, main}] — web and iPhone render them with no app
   release (Decision-Log 2026-10-10).
4. Handle each `choice` in `OttoActionService::decide()`; unknown kinds return "Unknown
   suggestion.".

## What he applies, when Tim taps
| Kind | Tap does |
|---|---|
| weather | move (the guard's own rescheduler, slot from `OttoActionService::findSlot()`) or keep; rain % taught to the service's lesson |
| clock_out | sets clock-out to Tim's time (guess from `OttoRules::suggestClockOut()`: last real timer end or last on-site fix, never the auto clock-out, capped 14 h) |
| job_timer / no_time | sets minutes; no_time teaches the plan's duration |
| silent | real / fine — recorded only |
| bylaw, west_end, truck_range, maintenance, pack_fading | note, task, ack, retire (register) |
| training_* / safety_refresher | task for Tim |
| supplier_form | send (`SupplierFormService::send()`) |
| supplier_chase | ack, or mark the account open (`SupplierDirectoryService::setStatus()`) |

## He learns only from Tim's decisions
| Lesson | Taught by | Threshold |
|---|---|---|
| Weather lean per service type | move/keep with rain % (`otto_lessons`) | 3 like-this decisions (within 15 rain points), 75% agreeing (`OttoRules`) |
| Visit duration per plan | no_time minutes Tim saves | kept/edited within 5 min counts as right |
| Quiet-phone wait | silent "fine" answers (`OttoQuestionService::answer()`) | asks after a pattern |
| Places (dump, supplier, fuel) | receipts seen at the same truck stop | 2+ days (`StopEvidenceService::learnPlaces()`); Tim's yes/no on `TripCostService::confirmStop()` |

## Supplier account forms (owner, 2026-10-10)
- Yui's reader (`SupplierInboxService::poll()`, office@ + iCloud, read-only) hands every PDF from a
  directory supplier to `SupplierFormService::capture()`.
- Fillable PDF → filled from `CompanyProfileService::profile()` via `PdfFormFiller::fill()`; Otto
  shows answers, blanks, recipients. "Send it" replies to the supplier (their other recipients
  copied), with the business licence when the form asks for it; then the supplier is `applied`.
- Never filled (`CompanyProfileService` owner-only list): bank/transit/cheque, credit and trade
  references, guarantees, signatures/initials/dates of signing, SIN, birth date, driver's
  licence, passport. A form asking for any of them → "Yours to fill", no Send button.
- No form fields (print-and-sign) → status `unfillable`, "needs you", lists what it asks for.
- No answer 7 days after applying → `supplier_chase`.
- Supplier outreach and trade-list subscriptions use **office@mowology.ca** (Tim, 2026-10-10).
- Plan: `docs/plans/2026-10-10-supplier-account-forms.md`. Migration 1320/1321.

## Supplier directory
- `supplier_accounts` (asked → applied → open / declined / none), linked to `ops_places` (kind
  `supplier`) for Otto's trip labels and to `vendors` for Penny.
- Only a building-level point becomes a place (`geo_exact`): a street-level point would mislabel
  truck stops within 150 m. Hand-set points count as exact.
- Geocoding: Google needs a server key; the CRM's Maps key is browser-only (referer-restricted),
  so it falls back to `SupplierDirectoryService::nominatim()` (OpenStreetMap, ≤1 request/s,
  identifies as office@).
- Tenant split (Ittai): directory, documents and forms are private; the form field dictionary is
  shared knowledge.

## Supplier price lists + shared catalogue (owner, 2026-10-12; migration 1331)
- Yui's reader (`SupplierInboxService::poll()`) sees mail from a supplier whose domain has a row in
  `supplier_list_formats`; the .xlsx/.csv links in it (format's `link_hosts`, https only, 5 MB, no
  redirects; booking forms skipped) go to `SupplierPriceListService::importFromEmail()`. Dedupe:
  message + link, and (site, supplier, kind, list date). A failure is logged, never stops the poll.
- Formats are DATA: header anchor ("ProductID"), column synonyms ("Item"/"Plant", "Price"/"List
  Price", "@month" = the specials column named after the month), date/title/rules cells, kinds,
  valid-until patterns. One generic parser: `SupplierListParser::parse()`. New supplier = a new
  format row, not code. "Price" on Inline's list is Tim's TRADE price (confirmed 2026-10-12).
- Every line keeps the supplier's own name, code, size and category as given; the catalogue
  link sits beside it (`catalog_product_id`, `size_code`, `match_how`, `match_confidence`).
- Matching (`CatalogService::resolve()`): confirmed alias → exact key → second-tier key (plants:
  same genus+species+cultivar, series written differently or left out; goods: UPC, part number,
  brand+name) → fuzzy = SUGGESTED into the review queue only, never linked automatically.
  Decisions (`CatalogService::decide()`: confirm / merge / separate) become shared aliases.
- Plants never auto-link across a different series, a different growing form (STD standard vs
  shrub), sister cultivars ('Little Lime' / 'Little Lime Punch') or colours ('… Red' / '… Orange')
  — all seen on Inline's real list (`PlantNameNormalizer::similarity()`).
- Ittai split: SHARED (no site_id, no prices) = formats, families, category tree + mappings,
  catalogue products, attribute defs/values, size equivalences, aliases. PRIVATE (site_id) =
  `tenant_catalog_families`, `supplier_price_lists`, `supplier_price_items`, `catalog_match_queue`.
  Trade prices never leave the company that received them.
- Page: `public/crm/suppliers/price-lists.php` (products.view; review decisions need products.edit).

## Bulk material delivery (owner, 2026-10-10; `MaterialDeliveryService::DEFAULTS`)
- Lawnboy delivers: cost $73, charge the client $150, order 3 days ahead.
- The trailer carries 2 yd. `MaterialDeliveryService::decide()` picks trailer runs vs delivery from
  yards, trailer capacity and the learned trip cost.
- Otto lists placed one-off jobs in the next 21 days whose material should be delivered, with the
  order-by date. Values live in `ops_settings`; the defaults apply when unset.

## Company documents
- One current document per type (business licence, insurance, WorkSafeBC clearance) in
  STORAGE_ROOT/company-docs. Yui files the city's licence email herself (`licence_sender`);
  number/expiry read by `CompanyDocumentService::parseLicence()`. Reminder 30 days before expiry.
- City of Vancouver licence renews every Dec 31.
