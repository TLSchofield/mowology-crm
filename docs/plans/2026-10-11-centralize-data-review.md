# Data kept in more than one place — review (2026-10-11)

Asked by Tim 2026-10-11 ("review any other data that should be centralized"). Code review by a
subagent; the load-bearing claims re-checked this session (marked ✔ with how). Ranked by risk.

| # | What | Copies today | One home | Checked |
|---|---|---|---|---|
| 1 | **Marketing consent (CASL)** | `consent_ledger` (Mia's gate, `ConsentLedgerService`) vs `contacts.receive_marketing` + consent dates + `marketing_unsubscribes` (campaign sender's gate, `canSendMarketing()` in `app/Services/Messaging/MessagingService.php`) | `consent_ledger` as the only gate | ✔ `canSendMarketing()` never reads the ledger; ✔ prod: 5 contacts (22, 24, 28, 55, 199) say yes on the contact but have a "withdrawn" ledger row — 3 have a later grant, 24 and 199 withdrawn and granted in the same second. Note: an unticked quote-form box is recorded as "withdrawn" |
| 2 | **Client identity + who gets invoices** | `contacts`/`companies` (all live writes) vs `clients`/`client_contacts` (filled once by migration 1049, never written since) | `clients` + `client_contacts`, written by `ContactService` | ✔ no runtime INSERT/UPDATE on `clients`/`client_contacts`; `invoices/create.php` reads `clients.display_name` only as the fallback Bill-to name; recipients preselect from `client_contacts.receives_invoices` (agent, not rechecked) |
| 3 | **GST rate** | `business_settings.gst_rate`, `tax_rates` (6 rows, duplicates known), `products.gst_rate`, JS `0.05` | `tax_rates` via `TaxEngine` | ✔ `0.05` hardcoded in `accounting/transactions.php:594`, `products/area-measurement.php:836` |
| 4 | **Message history** | `sales_messages` 845, `communication_log` 62, `receipt_email_log`, `pow_email_log` (write-only) | one log written inside `sendCrmEmail()`/`sendSms()` | agent; client timeline misses `communication_log` |
| 5 | **Company phone/email/name** | `business_settings`, `cms_sites`, hardcoded in ~89 / ~40 files | `business_settings` (Ittai blocker) | agent counts, not rechecked |
| 6 | **Fixed places (yard, suppliers)** | yard: `ops_places` "Yard" (set), `office_latitude/longitude` (empty), `closer_depot_lat/lng` (empty), address geocode; suppliers: `vendor_locations`, `supplier_accounts.lat/lng`, `ops_places` | `ops_places` | ✔ prod values |
| 7 | **Supplier + item prices** | `vendors` vs `supplier_accounts`; `vendor_products`, `expense_line_item_prices`, `vendor_price_index` (written weekly, read by nothing) | see `2026-10-11-one-supplier-record-and-prices.md` | ✔ prod |
| 8 | **Labour cost per hour** | `users.hourly_rate`, `cost_factors`, `ops_settings.closer_hourly_cost` (deliberate copy, reports drift), `freedom_*` | `users.hourly_rate` + one burden | agent |
| 9 | Tags, crew photos copied to media, truck facts (no vehicles table) | — | — | agent |

Side finding ✔: `app/Modules/Marketing/Api/optin.php` and `app/Services/Messaging/TemplateRenderer.php`
carry a hardcoded fallback unsubscribe secret (CLAUDE.md rule 3). Production defines
`UNSUBSCRIBE_SECRET`, so the fallback is unused there; it matters for every other install (Ittai).
