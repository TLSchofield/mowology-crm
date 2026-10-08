# QuickBooks Online — the accountant's books

**Decision (Tim, 2026-10-07):** QuickBooks Online (QBO) is the accountant's set of books. The CRM stays the
operational system — Penny prepares receipts, bank lines, invoices and payments; Tim/Penny approve — and
approved, cleaned records sync **one way** into QBO. The accountant gets a normal QBO *Accountant* user and
works in QBO; what they change there (adjusting entries) is **read back into a CRM report only**, never written
into the CRM's own journal.

Tim has a QBO account but is not sure what the company file holds, so **phase 1 is read-only discovery**:
connect, read, map accounts. **Phase 2 (push) is designed and coded behind a flag that is OFF**
(`ops_settings.qbo_push_enabled = 0`, migration 1240) and is not to be switched on until the discovery report
has been read, the chart of accounts has been mapped, and the accountant has agreed where FY2026 starts.

Render of the settings page (stub data): `docs/crm/renders/quickbooks.jpg`.

---

## 1. What Intuit's documentation says (verified 2026-10-07) — and what is assumed

Everything below marked **[verified]** was read on developer.intuit.com on 2026-10-07 (the docs site is a
client-rendered app; pages were read in the browser, the OpenID discovery documents with `curl`).
**[assumed]** = standard practice or a third-party source, not confirmed on Intuit's site; treat as a thing
to check in the sandbox before it matters.

### OAuth 2.0 [verified]
Source: *Set up OAuth 2.0* (`/docs/develop/authentication-and-authorization/oauth-2.0`) and
*Authorization FAQ* (`…/faq`); endpoints from `https://developer.api.intuit.com/.well-known/openid_configuration`
and `…/openid_sandbox_configuration` (both name the same three endpoints).

| Item | Fact |
|---|---|
| Authorization endpoint | `https://appcenter.intuit.com/connect/oauth2` with `client_id`, `scope`, `redirect_uri`, `response_type=code`, `state` — all required; `state` is the CSRF check and the server "won't accept requests without" it |
| Scope | `com.intuit.quickbooks.accounting` (`openid`/`profile`/`email` exist for SSO; not needed) |
| Callback | `?code=…&state=…&realmId=…` — `realmId` is the company id used in every API URL; `code` max 512 chars |
| Token endpoint | `POST https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer`, form-encoded, `Authorization: Basic base64(client_id:client_secret)`, `grant_type=authorization_code&code&redirect_uri` — "Only send one request to exchange the authorization code. Multiple requests may invalidate tokens." |
| Access token | `expires_in: 3600` (60 min); send as `Authorization: Bearer …`; a 401 means it expired |
| Refresh token | `x_refresh_token_expires_in: 8640000` = **100 days, rolling** (extended on each use); if unused for 100 days the user must reauthorize. Intuit **rotates the value** ("every 24 hours, or the next time you refresh after 24 hours") — *always store the latest `refresh_token` from the most recent response*. Refresh one at a time; a second attempt with the same token returns `invalid_grant` and may revoke it |
| Hard limit | **5 years** per refresh token (`x_refresh_token_hard_expires_in`, returned when the request carries the header `x-include-refresh-token-hard-expires-in: true`) |
| Refresh call | same token endpoint, `grant_type=refresh_token&refresh_token=…` |
| Revoke | `POST https://developer.api.intuit.com/v2/oauth2/tokens/revoke`, Basic auth, JSON `{"token": refresh_token}`; 200 = revoked |
| Redirect URIs | must be `https`, no IP addresses, no query parameters (pass state instead); registered separately for Development (sandbox) and Production keys; the value "must match … including casing, http scheme, and trailing '/'" |
| Keys | two sets per app — Development (sandbox) and Production — each under its own *Keys & OAuth* tab |

### REST API basics [verified]
Sources: *Basic schema and data formats* (`/docs/learn/rest-api-features`), *API call limits and throttles*
(`/docs/learn/limits-and-throttles`), *Basic ID and field definitions* (`/docs/learn/learn-basic-field-definitions`),
*Minor versions* (`/docs/learn/explore-the-quickbooks-online-api/minor-versions`).

| Item | Fact |
|---|---|
| Base URLs | sandbox `https://sandbox-quickbooks.api.intuit.com`, production `https://quickbooks.api.intuit.com`; `/v3/company/{realmId}/{entity}`; query `GET …/query?query=<select>`; delete is `POST …/{entity}?operation=delete` (we never call it) |
| Headers | `Accept: application/json` (default is XML), `Content-Type: application/json` on POST; response header `intuit_tid` — log it, it is what Intuit support asks for |
| minorversion | "Support for minor versions 1 through 74 … was discontinued beginning August 1, 2025"; anything < 75 is ignored and answered as 75; **75** is the current and only one (adds `DefaultTimeZone` on CompanyInfo). We send `minorversion=75` explicitly |
| Throttling | **500 requests/min per realm**, **10 requests/s per realm and app**; batch: ≤ 30 payloads recommended, 40 batch req/min per realm+app; HTTP **429** → wait 60 s; any request > 120 s times out; query returns **max 1000** entities per page (use `STARTPOSITION`/`MAXRESULTS`); sandbox additionally 40 emails/day |
| Idempotency | `?requestid=<id>` on writes: "If our service receives another request with the same request ID … it can recognize and send the same response for the original request. This prevents duplication." Unique per realm, **max 50 chars** (36 for batch). Our id = `expense-<id>-<12 hex of the content hash>` |
| Encoding | non-US files are UTF-8 |
| Timestamps | `YYYY-MM-DDTHH:MM:SS±HH:MM`; `TxnDate` is a plain date |

### Production keys for a private, single-company app [verified via search + Intuit blog summaries; portal pages not readable]
- The *app assessment questionnaire* is **required for every app that touches production data, "even if it is a
  private app for your own use"**; approval unlocks the production Client ID/secret. It covers how the app
  operates, data handling, API usage, authorization, error handling, legal compliance and security
  (~30 minutes to fill in). Sources: Intuit Developer blog posts (blogs.intuit.com p=33093 / p=33153, now on
  medium.com/intuitdev — the medium page would not render here), apideck's summary of Intuit's compliance
  page, dbsync's step-by-step for a private app.
- Before the questionnaire the app's *Production* settings need: host domain, launch URL, disconnect URL,
  **EULA URL** and **privacy-policy URL**, and at least one app category. For a private app these can be
  pages on mowology.ca (see §7).
- **[assumed]** Review takes days, not weeks, and no App Store listing is needed. Verify in the portal.
- The sandbox needs none of this: Development keys work immediately against a sandbox company.
- **[assumed]** A **Canadian** sandbox company can be created from the developer dashboard ("Add a sandbox
  company" lets you choose the country). The default sandbox is US — a US sandbox has no GST/PST codes, so a
  Canadian one is needed to test tax codes. Verify in the portal.

### Canadian sales tax [verified]
Source: *Automated sales tax (non-US locales)* (`/docs/workflows/calculate-sales-tax/automated-sales-tax-for-non-us-locales`)
plus the Purchase / Invoice / JournalEntry / Deposit entity references.
- Tax is specified **per line** with `TaxCodeRef` (on `SalesItemLineDetail` for sales, on
  `AccountBasedExpenseLineDetail` for purchases). "When creating or updating purchase transactions, QuickBooks
  Online automatically calculates tax and returns the amount in the TxnTaxDetail.TotalTax attribute" — we send
  **net amounts + the tax code**, not tax amounts.
- `GlobalTaxCalculation` (`TaxExcluded` | `TaxInclusive` | `NotApplicable`) is "required for non-US
  companies" on Purchase, Invoice, Deposit and JournalEntry. We always send `TaxExcluded`.
- A `TaxCode` is one or more `TaxRate`s (`SalesTaxRateList` / `PurchaseTaxRateList`); Canadian files come
  pre-populated (GST, GST/PST BC, Exempt, Zero-rated, Out of scope). **Sales tax can only be switched on from
  the QBO Taxes screen, not through the API** — `Preferences.TaxPrefs.UsingSalesTax` tells us.
- Overriding the computed tax is possible (`TxnTaxDetail` with every `TaxRateRef` of the code) but some
  rates are read-only and refuse it with a business validation error — we do not override.
- **Meals 50 % ITC [assumed]:** Canadian QBO handles the half-claimable GST on meals through a dedicated tax
  code in some files ("GST/HST on meals") and through a year-end adjustment in others. The planner posts the
  full GST with the mapped meals account, tags the line `[meals — 50% ITC]` and warns. Confirm with the
  accountant which way this file does it.

### Entities we use [verified field names, from the API reference pages]
| CRM record | QBO entity | Key fields |
|---|---|---|
| approved expense (+ `expense_line_allocations`) | **Purchase** | `PaymentType` Cash/Check/CreditCard (required); `AccountRef` = the bank/credit-card account paid from (required); `EntityRef {type: Vendor}`; `Line[] AccountBasedExpenseLineDetail {AccountRef, TaxCodeRef, BillableStatus}`; `TxnDate`; `DocNumber` ≤ 21 chars (duplicates throw for Cash/CreditCard); `PrivateNote` ≤ 4000; `TotalAmt` is computed by QBO; `SyncToken` required on update |
| receipt image | **Attachable** via `POST /v3/company/{realm}/upload` (multipart: `file_metadata_01` JSON + `file_content_01` file; request ≤ 100 MB; `AttachableRef[{EntityRef {type: Purchase, value: id}}]`, `Category: Receipt`) | returns `AttachableResponse[0].Attachable.Id`; `TempDownloadUri` to fetch it back |
| invoice (+ `invoice_items`) | **Invoice** | `CustomerRef` required; `Line[] SalesItemLineDetail {ItemRef, Qty, UnitPrice, TaxCodeRef, ServiceDate}`; `DocNumber` — "If Preferences:CustomTxnNumber is false then do not send a value as it can lead to unwanted duplicates"; taxable invoices ≤ 750 lines; `DueDate`, `PrivateNote` |
| invoice payment / Stripe payment | **Payment** | `CustomerRef`, `TotalAmt` required; `Line[{Amount, LinkedTxn[{TxnId, TxnType: Invoice}]}]`; `DepositToAccountRef` (Bank/Other Current Asset) — "If you do not specify this account, payment is applied to the Undeposited Funds account"; lines update ALL-or-NONE |
| bank deposit grouping several payments | **Deposit** | `DepositToAccountRef` required; `Line[{Amount, LinkedTxn[{TxnId, TxnType: Payment, TxnLineId: 0}]}]` for Undeposited-Funds payments; `DepositLineDetail {AccountRef, Entity}` for direct deposits |
| bank-only lines (loan payments → 2610, Wave payroll → 5100, interest) | **JournalEntry** (or **Purchase** with the liability account on the line) | `Line[] JournalEntryLineDetail {PostingType Debit/Credit, AccountRef, Entity {Type, EntityRef}}`; debits must equal credits; `DocNumber` duplicates throw when `WarnDuplicateJournalNumber` is on |
| transfers between own accounts (chequing ↔ savings, card payment) | **Transfer** | `FromAccountRef`, `ToAccountRef`, `Amount`, `TxnDate` **[assumed field names — entity page not read]** |
| chart of accounts | **Account** (read) | `Id`, `Name`, `AcctNum` (no colons; present when *Use account numbers* is on), `Classification` Asset/Liability/Equity/Revenue/Expense, `AccountType`, `AccountSubType`, `CurrentBalance`, `Active`, `TaxCodeRef` (global locales) |
| company facts | **CompanyInfo** `GET companyinfo/{realmId}` | `CompanyName`, `LegalName`, `Country`, `FiscalYearStartMonth`, `CompanyStartDate`, `NameValue[]` (`OfferingSku`, `SubscriptionStatus`, `IndustryType`) |
| settings | **Preferences** `GET preferences` | `AccountingInfoPrefs.BookCloseDate` / `FirstMonthOfFiscalYear` / `UseAccountNumbers` / `ClassTrackingPerTxn`, `TaxPrefs.UsingSalesTax`, `CurrencyPrefs.HomeCurrency` / `MultiCurrencyEnabled`, `SalesFormsPrefs.CustomTxnNumbers`, `ReportPrefs.ReportBasis`, `VendorAndPurchasesPrefs` |
| customers / vendors / items | **Customer**, **Vendor**, **Item** | `DisplayName` (unique) **[verified for Customer/Vendor by sample payloads; create-side rules assumed]**; `Item.Type = Service`, `IncomeAccountRef` |

Not needed for phase 1 or 2: **Webhooks / ChangeDataCapture** (QBO → CRM). The "accountant adjustments"
report (§3) is a scheduled *read* of `JournalEntry` by `TxnDate`/`MetaData.LastUpdatedTime` — CDC would only
save requests; it needs a public endpoint and a verifier token, so it is deferred.

---

## 2. Design

### Direction and safety rules
1. **One way, CRM → QBO.** Only records Tim/Penny approved (`expenses.status = approved`,
   invoices sent/paid, payments recorded). Drafts, rejected, cancelled never go.
2. **Idempotent twice over.** `qbo_sync` holds `(crm_type, crm_id) → qbo_id, sync_token, content_hash,
   request_id, status`. Unchanged content = skip; changed = **update with the stored SyncToken**; every write
   carries a `requestid` derived from the content hash so a retry after a dropped connection can only replay
   the same result (Intuit's guarantee), never create a twin.
3. **Never delete in QBO.** `QboApiClient` has no delete method and the test
   `test_the_client_and_the_push_service_can_never_delete_in_quickbooks` greps for `operation=delete`.
   Corrections are updates (SyncToken) or reversing entries.
4. **Never write into a closed period.** `Preferences.AccountingInfoPrefs.BookCloseDate` is read at discovery
   and stored on the connection; `QboPushPlanner::dateBlock()` refuses anything dated on/before it.
5. **Never write before FY2026.** `QboPushPlanner::PUSH_FROM = 2026-01-01`. FY2025 is filed and locked in the
   CRM (migration 1237); the accountant enters the FY2026 opening balances in QBO themselves. The CRM's own
   opening entries (3900) are not pushed.
6. **Dry run first.** `qbo_dry_run = 1` by default: with the push flag on, `pushExpense()` builds the payload,
   records it as `dry_run` in `qbo_sync` and stops. The settings page's *Dry run* button works with the flag
   OFF and shows every payload and every reason a record is held back.
7. **Tokens encrypted at rest** with `SocialEncryption` (AES-256-CBC, `SOCIAL_ENCRYPTION_KEY`). The two failure
   modes stay distinct: "stored but will not decrypt" is reported as a key mismatch, never as "not connected"
   (Known-Failure-Patterns 2026-09-25 — the FB/IG outage).
8. **Every request logged** (`qbo_sync_log`: operation, HTTP status, `requestid`, `intuit_tid`, error).

### Mapping
| CRM | QBO | How |
|---|---|---|
| `chart_of_accounts` | `Account` | `qbo_account_map`, suggested by `QboAccountMapService::suggest()` — number match 100, exact name (same classification) 90, similar name 60–85, different classification ≤ 40 and never pre-picked. **Tim confirms every row**; a confirmed NULL = "no twin, never push". |
| `expenses.accounting_category` → chart account | — | existing `chart_of_accounts.expense_category_alias` (comma list) gives the CRM account; the map gives the QBO one |
| `expenses.payment_method` | `Purchase.PaymentType` + `AccountRef` | card/visa/master → CreditCard; cheque/check → Check; everything else (debit, e-transfer, cash) → Cash. The account paid from comes from the *paid from* choices (`ops_settings qbo_paid_from` JSON: bank / credit_card / cheque) |
| `vendors` / `expenses.vendor_name_raw` | `Vendor` | `qbo_sync(vendor)`; else query `Vendor WHERE DisplayName = …`; else create on first push |
| `companies` / `contacts` (invoice payer) | `Customer` | **phase 2b** — same pattern as Vendor on `DisplayName`; the 3-way payer split (company_id / client_id / contact_id, see memory) decides the name; PM-managed buildings → the firm |
| `products` | `Item` | **phase 2b** — one default *service Item* chosen on the settings page for every line; per-product Items later if the accountant wants revenue split |
| `invoices` (+ `invoice_items`) | `Invoice` | `invoiceFromCrm()`; `tax_rate` 0 → exempt code, ≤ 6 % → GST, > 6 % → GST/PST; `DocNumber` only when the file has `CustomTxnNumbers`, otherwise CRM number in `PrivateNote` |
| `invoice_payment_allocations`, `stripe_payments` | `Payment` (+ `Deposit`) | `paymentFromAllocation()`; one Payment per allocation linked to its Invoice, `DepositToAccountRef` = the bank account when the deposit is known (bank line matched), else Undeposited Funds and a `Deposit` groups the payout (Stripe payout = several Payments + a fee line) |
| `expense_line_allocations` | Purchase lines | one `AccountBasedExpenseLineDetail` per split line; `is_stock` lines → the stock/inventory account; `pst_taxable` or `pst_amount > 0` → GST/PST code; `gst_amount = 0` → exempt code; meals flagged |
| bank-only lines: loan payment → 2610, interest → 6810, Wave payroll → 5100, card payment → 2400, chequing ↔ savings | `JournalEntry` / `Transfer` | **phase 2c** — from the journal entries the CRM already posts (`journal_entries.source_type = bank_import`), one JE per CRM entry with the mapped accounts; own-account moves as `Transfer` |
| accountant's adjusting entries in QBO | CRM report | **phase 3** — nightly `SELECT * FROM JournalEntry WHERE MetaData.LastUpdatedTime > …` into a read-only "Accountant adjustments" page; the CRM journal is never written from it |
| receipt image (`media_assets`) | `Attachable` | uploaded right after the Purchase, linked to it; `qbo_sync(expense_receipt)` so it is never uploaded twice |

### Tables (migration 1240, both folders, MySQL 5.7, idempotent)
- `qbo_connections` — one per (environment, realm): encrypted tokens, three expiries (access 1 h, refresh 100 d
  rolling, 5-y hard), company facts from discovery incl. `book_close_date`, status active/error/disconnected,
  `last_error`.
- `qbo_account_map` — `crm_account_id → qbo_account_id` (+ name/number/type at confirm time, confidence,
  `matched_on`, who/when confirmed).
- `qbo_sync` — the idempotency ledger (above).
- `qbo_sync_log` — the audit trail.
- `ops_settings`: `qbo_push_enabled = 0`, `qbo_dry_run = 1`; the page saves `qbo_tax_gst`, `qbo_tax_gst_pst`,
  `qbo_tax_exempt`, `qbo_item_service`, `qbo_paid_from`; discovery caches `qbo_discovery_report` / `qbo_discovery_at`.

### Code (all in `app/Modules/Accounting/Services/QuickBooks/`, no namespace, `require_once` + `new`)
| File | Role |
|---|---|
| `QboConfig.php` | secrets.php constants → client id/secret, env, redirect URI, base URLs, Basic header; `isConfigured()` / `missing()` |
| `QboTransport.php` | the one network seam (`QboTransport` interface + `QboCurlTransport`; multipart for uploads). Tests use `QboFakeTransport` |
| `QboOAuthService.php` | authorize URL, code exchange, refresh, revoke, `parseTokenResponse()` |
| `QboConnectionService.php` | the stored connection: `connect()`, `accessToken()` (auto-refresh 5 min ahead, stores the rotated refresh token), `markError()`, `disconnect()`, `describe()` for the card |
| `QboApiClient.php` | `/v3` calls: headers, minorversion 75, `requestid`, 401 → refresh + retry once, 429 → `QboThrottledException`, Fault → `QboApiException` with `intuit_tid`; `query()`, `queryAll()` (pages of 1000), `count()`, `post()`, `upload()`. **No delete.** |
| `QboDiscoveryService.php` | `gather()` (~60 GETs) + pure `summarise()` → the report + warnings in Tim's words; cached in `ops_settings` |
| `QboAccountMapService.php` | pure `suggest()` + the map table |
| `QboPushPlanner.php` | pure CRM → payload mappers (`purchaseFromExpense`, `invoiceFromCrm`, `paymentFromAllocation`), `dateBlock()`, `hash()`, `requestId()` |
| `QboPushService.php` | flags, `preview()` (dry run, writes nothing), `pushExpense()` (Purchase + Attachable, through `ExpenseGate` 'forward' like the email route), vendor twin, `qbo_sync` / `qbo_sync_log` |
| `app/Services/QuickBooks/QBService.php` | unchanged API for its callers (`expenses.php` qb_status / batch_forward / qb_retry, receipt-actions, receipt-send, settings): `_qbCurrentMethod($db)` is `'api'` only when `qbo_push_enabled = 1`, `qbo_dry_run = 0` and a connection is active; otherwise email as before. `qbGetStatus()` now also reports `api_connected`, `api_company_name`, `api_environment`, `api_push_enabled`, `api_dry_run` |
| `public/crm/accounting/quickbooks.php` + `public/crm/js/quickbooks.js` | Settings → QuickBooks page (linked from Settings → Receipt Forwarding) |
| `public/crm/api/quickbooks.php` | `?mode=status / discovery / accounts / preview`, `POST refresh_discovery / map_confirm / map_clear / map_accept_all / choices / disconnect` (admin, CSRF, `mode` never `action`) |
| `public/crm/api/quickbooks/oauth-start.php`, `oauth-callback.php` | the OAuth round trip (`$_SESSION['qbo_oauth_state']`) |
| `public/crm/css/mowology-brand.css` | `.mw-qbo-*` appended at the end |
| `tests/Unit/Accounting/QuickBooks/*` | 51 tests, 220 assertions, all against `QboFakeTransport` |

### Decision-Log entry (draft for the vault)
> **2026-10-07 — QuickBooks Online is the accountant's books; the CRM pushes one way, from FY2026, never into
> a closed period, never deleting.** Tim keeps the CRM as the operational system (Penny prepares, Tim approves);
> QBO holds what the accountant files. Phase 1 is read-only (OAuth + discovery + account map Tim confirms).
> Phase 2 (push) is built behind `qbo_push_enabled = 0` with `qbo_dry_run = 1`, idempotent through `qbo_sync`
> (qbo_id + SyncToken + content hash + Intuit `requestid`), refuses dates before 2026-01-01 (FY2025 filed and
> locked, 1237) and on/before `Preferences.BookCloseDate`. Receipts keep going by email until the flag is live;
> `QBService` switches route per call. The accountant's adjustments are read back into a report, never into the
> CRM journal — the CRM's double-counting history (bank deposits vs invoices, Jobber era) is exactly why no
> second writer touches the CRM books. Tokens use `SocialEncryption` so a key rotation has one owner and its
> two failure modes stay distinguishable. Why not Bills: the CRM pays at purchase; Purchase matches reality.
> Why not webhooks/CDC: nothing in QBO needs to change the CRM in real time.

---

## 3. Phases

**Phase 1 — now.** Connect; read the file; map the chart; save the tax-code / paid-from / item choices; look at
the dry run. Migration 1240. Flag OFF; receipts by email unchanged.

**Phase 2 — after Tim reads the report and the accountant agrees:**
- 2a expenses: flip `qbo_dry_run` to 0 and `qbo_push_enabled` to 1 *in the sandbox first*, push a handful,
  read them in the sandbox UI, then production. `QBService::forward` then creates Purchase + Attachable instead
  of emailing (`expenses.php` → `batch_forward` is the same button).
- 2b customers, invoices, payments, deposits (planner mappers exist; the DB side — Customer twin, invoice
  push, allocation push, Stripe payout → Deposit — is next).
- 2c bank-only journal entries and transfers.
- A cron (registered in `/crm/database_appstack.php`'s `$crons`, cPanel line owed) that pushes approved
  records nightly; until then the button on the expenses page.

**Phase 3 — accountant read-back:** nightly read of JournalEntry changes into a CRM report page.

---

## 4. Risks and recommendations

- **The file may already contain 2025 (and some 2026) entries from the accountant or a bank feed.** The
  discovery report counts transactions per year and warns; if there is 2026 activity, whoever enters it must
  stop before the push or everything exists twice. Decide with the accountant *who owns 2026 in QBO* before
  the flag goes on.
- **FY2026 opening balances are the accountant's** (filed FY2025 closing → QBO opening). The CRM's 3900
  entries and Jobber-era deposits never go. If the accountant wants the CRM's trial balance at 2025-12-31 as a
  cross-check, `/crm/accounting/trial-balance.php` is the thing to show them.
- **Tax codes**: GST on purchases in QBO Canada uses a purchase-side rate ("GST (ITC)"); the page lets Tim pick
  the exact codes from the file instead of the code guessing by name. Meals 50 % ITC: ask the accountant
  (dedicated code vs year-end adjustment).
- **Account numbers off in the file** → mapping by name only; the report says so. Turning them on in QBO
  (Settings → Advanced → Chart of accounts) makes the map exact and future-proof.
- **100-day refresh rule**: a connection unused for 100 days lapses. Discovery or a nightly push keeps it
  alive; the card shows days left and the cron (when added) should refresh weekly even with nothing to push.
- **Production keys need the questionnaire + EULA/privacy URLs** (§1). Plan a day for it; the sandbox needs
  nothing.
- **Sandbox is US by default** — create a Canadian sandbox company to see GST/PST.
- **Two writers to the expense row** — the API route marks `forwarded` through `ExpenseGate` exactly like the
  email route, so the gate's lock and learning behave the same (`ExpenseGateCallersTest` enforces it).
- **Accountant access**: a QBO *Accountant user* (QBO → Manage users → Accounting firms) is free and gives them
  their own login and tools (reclassify, close books, year-end). Ask them to **set the closing date** with a
  password after FY2025 is final — the sync honours it automatically. Do **not** give them a CRM login for
  bookkeeping; the CRM report of their adjustments (phase 3) is the bridge.
- **Prod ≠ repo drift**: `QBService.php` and `settings.php` are shared files — `cmp` against production
  before deploying (memory: always 3-way merge shared files); `mowology-brand.css` is append-only here.

---

## 5. Files to deploy (feature/quickbooks-api, base `63c7766d` feature/bank-balance-check)

New: `app/Modules/Accounting/Services/QuickBooks/*.php` (9 files), `public/crm/accounting/quickbooks.php`,
`public/crm/js/quickbooks.js`, `public/crm/api/quickbooks.php`, `public/crm/api/quickbooks/oauth-start.php`,
`public/crm/api/quickbooks/oauth-callback.php`, `database/migrations/1240_quickbooks_api.sql` +
`public/database/migrations/1240_quickbooks_api.sql`, `docs/crm/quickbooks.md`, `docs/crm/renders/quickbooks.jpg`,
`tests/Unit/Accounting/QuickBooks/*`.
Changed (shared — diff against live first): `app/Services/QuickBooks/QBService.php`, `public/crm/settings.php`
(one link in the Receipt Forwarding tab), `public/crm/css/mowology-brand.css` (appended), `tests/bootstrap.php`.
Then: run migration 1240 from `/crm/database_appstack.php`, OPcache reset (`/crm/api/opcache-reset.php`),
add the secrets (§7), open `/crm/accounting/quickbooks.php`.

---

## 6. Verification done here
- `vendor/bin/phpunit`: 2225 tests, 7594 assertions, 0 failures, 0 errors (19 pre-existing deprecations, 39 skipped).
- `php -l` on every PHP file touched; `node --check public/crm/js/quickbooks.js`.
- Page rendered with stub auth/PDO and canned API answers → `docs/crm/renders/quickbooks.jpg` (connected,
  production badge, discovery report with per-year counts and tax codes, account map with confirmed/strong rows,
  push choices and the dry run).
- No real Intuit endpoint was called; nothing deployed; no migration run.

---

## 7. Tim — setting up the Intuit app, step by step

You need about 20 minutes for the sandbox part. Production keys come later (they need the questionnaire).

1. **Developer account.** Go to <https://developer.intuit.com> and sign in with the **same Intuit login you use
   for QuickBooks** (so the sandbox and the real company sit under one account). If it asks you to create a
   *workspace*, do it (My Hub → Workspaces → name it "Mowology").
2. **Create the app.** Dashboard → *Create an app* → **QuickBooks Online and Payments** → choose only the
   **Accounting** scope (`com.intuit.quickbooks.accounting`). Name it "Mowology CRM".
3. **Development (sandbox) keys.** In the app, open **Development → Keys & OAuth**. Copy the **Client ID** and
   **Client Secret**. Under *Redirect URIs* add exactly:
   `https://mowology.ca/crm/api/quickbooks/oauth-callback.php`
   (https, no trailing slash, same case). Save.
4. **A Canadian sandbox company.** Dashboard → **Sandbox** → *Add a sandbox company* → country **Canada**.
   (The default sandbox is a US file and has no GST/PST.) Note its name.
5. **Secrets.** Add to `public/app_config/secrets.php` (I cannot edit that file):
   ```php
   define('QBO_CLIENT_ID',     'paste the Client ID');
   define('QBO_CLIENT_SECRET', 'paste the Client Secret');
   define('QBO_ENV',           'sandbox');      // 'production' later
   define('QBO_REDIRECT_URI',  'https://mowology.ca/crm/api/quickbooks/oauth-callback.php');
   ```
   `SOCIAL_ENCRYPTION_KEY` is already there (the social accounts use it); the QuickBooks tokens use the same key.
6. **Deploy + migrate.** Deploy the files in §5, run migration **1240** on the Database page, hit the OPcache
   reset.
7. **Connect.** CRM → Settings → *Receipt Forwarding* → **QuickBooks Online connection** → *Connect to
   QuickBooks*. Sign in, pick the **sandbox** company, allow. You land back on the page and it reads the file.
8. **Read, then map.** Read the warnings at the top of *What is in the company file*. Then on the chart of
   accounts table press *Accept all strong matches* (number matches), fix the rest by hand, and pick the tax
   codes / paid-from accounts / service item in the *Push* card and *Save choices*. Press *Dry run* to see what
   the first expenses would become. Nothing is written.
9. **Production keys (when you are ready to point at the real company).** In the app open **Production →
   Keys & OAuth**. Intuit wants, before it hands them over: host domain `mowology.ca`; launch URL
   `https://mowology.ca/crm/accounting/quickbooks.php`; disconnect URL
   `https://mowology.ca/crm/accounting/quickbooks.php?disconnected=1`; the same redirect URI as step 3;
   an **End-user licence agreement URL** and a **Privacy policy URL** (two short pages on mowology.ca —
   `/privacy` exists; a `/crm-terms` page saying it is a private tool for Mowology Landscaping is enough — tell
   me and I will add it); an app category (Accounting / Expense management); and the **App assessment
   questionnaire** (Production settings → App assessment questionnaire, ~30 minutes: how the app works, where
   it is hosted (cPanel, Canada), what data it reads/writes, that tokens are encrypted at rest, that it uses
   the official OAuth endpoints and `intuit_tid` logging). It applies to private apps too. Submit; Intuit
   unlocks the production keys after review.
10. **Switch to production.** Replace the two keys in `secrets.php` with the Production ones, set
    `QBO_ENV` to `'production'`, press *Connect to QuickBooks* again and pick **the real company**. Read the
    file. **Do not** change `qbo_push_enabled` until we have been through the report together and the
    accountant has set the closing date.
11. **Accountant.** In QuickBooks (the real company): Settings (gear) → *Manage users* → *Accounting firms* →
    invite your accountant by email. Ask them to set the **closing date** to 2025-12-31 once FY2025 is final.
