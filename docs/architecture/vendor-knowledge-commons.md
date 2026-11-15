# Vendor knowledge commons

*Built 2026-10-11. Migration 1330. Code: `app/Modules/Knowledge/Services/`. Hub API: `public/api/knowledge/vendor.php`.*

## Why

Penny (the receipt bookkeeper) learns a vendor from the owner's confirmations: how its receipt reads, what its
SKUs are, how it is usually booked. Until now that learning was keyed to this install's `vendors.id`, so a
second tenant would start from nothing and pay the API for every Chevron receipt again. The commons makes the
**universal** part of that learning shared across tenants, while everything about a tenant's business stays
private. Tenants come from different trades, so nothing in the commons speaks landscaping.

## Two layers

| Shared (commons) | Tenant-private (unchanged) |
|---|---|
| Canonical identity: name key, GST/BN number, phone digits, web domain, country/region, kind | The tenant's `vendors` row, aliases, notes, locations, supplier relationship (`supplier_accounts`) |
| Receipt layout: tax labels/positions, date format, suffix/barcode patterns | Parse lessons with the tenant's text, accuracy counters |
| SKU dictionary: `sku_raw` → item name | `vendor_product_skus` → the tenant's own products |
| Category default **as a neutral taxonomy code**; tax treatment (GST-exempt) | Which local category, which job, which card, amounts, frequency, firm rules |

Privacy whitelist (what ever leaves a tenant): identity fields, layout facts, SKU → name, a neutral category
code, `gst_exempt`. Never amounts, jobs, cards, notes, frequency, or the tenant's name — the hub sees a hash
(`VendorIdentity::tenantHash`) or an API key.

## Identity

`VendorIdentity` (pure): `nameKey()` strips legal suffixes, store numbers and punctuation ("THE HOME DEPOT
#7128" → `home depot`); `phoneDigits()` keeps the last ten; `domainOf()` from a URL or e-mail; `gstNumber()`
reads `123456789 RT0001` and labelled 9-digit BNs. Resolution order: GST number → phone → domain → name key →
alias. `kb_vendor_aliases` collects other spellings.

## Taxonomy

`CategoryTaxonomy::CODES` — sixteen trade-neutral codes (`fuel`, `materials`, `parts_repairs`, `disposal`, …).
Each install bridges code ↔ its own category names through `kb_category_map` (seeded for Mowology's 14).
The commons stores codes only. A plumber maps `materials` to "Materials & fittings", a landscaper to "Materials".

## Promotion

`LocalVendorKnowledgeStore::promotedStatus()` — one place:

- `receipt_layout`, `sku`, `kind` → **shared** as soon as one tenant confirms (they describe the vendor's paper).
- `category_default`, `tax_treatment` → **shared** when **two distinct tenants** confirm, or the hub owner curates.
- `blocked` never comes back. Repeat confirmations by one tenant raise `confirmations`, not `tenants`.

A vendor is `candidate` → `shared` at two tenants; `curated` / `blocked` are owner decisions
(`curateVendor()`). Remote tenants only ever receive `shared` facts; the hub sees its own candidates.

## Where it plugs in

1. `ReceiptSmartMatch::matchVendorFromOcr` → `kbResolveVendorFromOcr()` first: GST/phone/domain → commons
   vendor → linked local vendor (confidence 95). Unlinked commons vendors fall through to local matching and
   auto-creation.
2. `ReceiptLearning::learnFromConfirmedExpense` → `VendorKnowledgeService::publishLesson()` after local
   learning (fails soft, never blocks).
3. `ReceiptBookkeeperService::buildContext` → `shared_hints` in the prompt (usual category bridged to ours,
   tax treatment, kind). `check()` is unchanged: firm owner rules still win.
4. `ReceiptBookkeeperService::suggest` → `codeSuggestionFirst()`: for a **learned** vendor
   (`CodeSuggestionGate`: ≥ 5 suggestions in a row accepted unchanged, a category default local or shared,
   captured amounts that add up, no fuel without a learned asset tag, no contradicting firm rule) code builds the
   suggestion in the model's schema, runs it through `check()`, and stores it with `model = 'code'`,
   `made_by = 'code'`, zero tokens.

## Modes

- `ops_settings.penny_code_suggestions`: `off` | `shadow` (default — stored as `source = 'shadow'`, never
  shown; Penny's card reports agreement with the model) | `on` (replaces the API call).
- Mode is per business (2026-11-14), because every business shares one secrets.php:
  an explicit `KB_MODE` (`hub` | `client` | `off`) wins; otherwise the default business (`Tenant::isDefault()`) is
  always `hub` (never calls itself over HTTP); any other business is `client` when `KB_HUB_URL` and
  `KB_HUB_SECRET` are both defined, else `hub` (own tables, as before). Client: 24 h `kb_cache`, 2 s timeout, fail-soft.
- Per-business key is derived, not stored: `VendorKnowledgeService::clientKey(Tenant::key(), KB_HUB_SECRET)` =
  base64(HMAC-SHA256), unpadded. A single shared key would make every business the same `kb_tenants` row and the
  two-tenant promotion rule could never fire. An explicit `KB_API_KEY` still wins.
- Test businesses (`Tenant::isTest()`) read the commons but never contribute (`publishLesson` returns false).
- Only layout/SKU facts are shared at one tenant and nothing reads them yet; category/tax facts reach other
  businesses only once two tenants confirm or the hub owner curates the vendor (`curateVendor`).

## Connecting a new tenant

1. secrets.php (Tim only): `define('KB_HUB_URL', 'https://mowology.ca/api/knowledge/vendor.php');` and
   `define('KB_HUB_SECRET', '<openssl rand -base64 32>');`. The URL must be the default business's host: the endpoint
   reads whichever database the host belongs to.
2. Register businesses on the hub: `scripts/prod-run.sh scripts/kb-register-tenants.php` (preview), then `--commit`.
   Idempotent; prints no keys or hashes. Re-run when a business is added.
3. The tenant needs migration 1330 tables `kb_cache` and `kb_category_map` (cache is optional in code).
4. Run `/crm/api/kb-backfill.php` once if it already has vendors.

## Operating notes

- Backfill for this install: `/crm/api/kb-backfill.php` (admin, idempotent) after migration 1330.
- Penny's card: "Handled by code alone" now counts `model = 'code'`; the commons size and shadow agreement
  appear under it.
- Tests: `tests/Unit/Knowledge/` (identity, taxonomy, promotion, gate).
- `supplier_accounts.sells` (migration 1320) still carries landscaping words; it is tenant-private, but should
  move to the neutral taxonomy when next touched.
