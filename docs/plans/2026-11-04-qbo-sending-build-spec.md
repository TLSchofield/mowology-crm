# Build spec — QuickBooks sending for every business (G1–G6), 2026-11-04

Plan: `2026-11-04-claudia-quickbooks-sending-plan.md` (approved by Tim 2026-11-04: "Build G1 to G6 now, receipts on
with invoices"). PHP 7.4, MySQL 5.7, no autoloader (require_once + `new`), services hold logic, page/API files thin.
Every new rule gets a unit test (SQLite fixtures as the existing QuickBooks tests do). Do not change what Mowology's
own QuickBooks sending does today except where stated.

## G1 — the sending run for every business

- New CLI cron `app/Modules/QuickBooks/Cron/qbo_sync.php`: the QuickBooks block now inside
  `app/Modules/Expenses/Cron/penny_prepare.php` (connection check → `QboSyncService::runQueue()` → receipts via
  `QboExpenseSyncService::runExpenses()` → `recordCronRun('qbo_sync', …)`), moved into a small service method
  (e.g. `QboSyncService::runAll(QboConnectionService $conn): array` returning the summary/status/notes) so both
  callers share ONE implementation. Bootstrap and lock exactly like `app/Modules/Operations/Cron/otto_place_properties.php`
  (CLI only, `CronLock::acquire('qbo_sync_' . Tenant::key())`, `--tenant=<key>` picks the business via Tenant).
- `penny_prepare.php` keeps doing Mowology's own run (same behaviour, now through the shared method), then fans out:
  `BusinessCronFanout::run(APP_ROOT . '/Modules/QuickBooks/Cron/qbo_sync.php', 300)` and logs
  `BusinessCronFanout::summary()` to Penny's log. Fan-out failures never stop penny_prepare (try/catch).
- Each business records its own `cron_runs` row 'qbo_sync' (same messages as today: not connected / off / counts).
- Safety unchanged: a production QuickBooks company is still refused unless that business has `qbo_live_sends=1`.

## G3 — imported customers are linked, never matched again by name

- In `QboOnboardingService::applyClients()`: for every QuickBooks customer it imports OR finds already in Ittai,
  write `qbo_links` rows (entity_type 'client', the current realm) for each key `QboSyncService::customerOf()` can
  produce for that client's invoices: `contact:<id>`, plus `company:<id>` when a company was made/found for it, plus
  `client:<id>` when a `clients` row exists for that contact. qbo_id = the QuickBooks customer Id, request_id
  'imported', status 'sent', message 'linked at import'.
- Never overwrite an existing link that points at a different qbo_id (keep the first; count it as a conflict in the
  returned message). Re-running the import is safe (no duplicate rows).
- Use the existing link-writing code (`saveLink` semantics) — move it to a shared place if needed, do not copy it.

## G4 — her own QuickBooks items on invoice lines

- In `applyServices()`: record each imported product's QuickBooks item in `qbo_mappings` (kind 'item',
  ittai_key = products.id, qbo_id, qbo_name). Also when the product already existed (matched by name).
- In `QboSyncService` invoice building: if EVERY line of the invoice (`invoice_line_items`) has a product with a
  kind 'item' mapping (for this site), send one QuickBooks line per Ittai line with that ItemRef (Qty/UnitPrice/Amount
  from the line, Description = line title/description, TaxCodeRef as today). Otherwise keep today's per-account
  lines exactly. Totals check after sending stays as it is (a difference is kept as 'mismatch').
- Pure builder for the per-line body, unit tested (rounding: amounts to 2 dp, sum must equal Ittai's subtotal or
  fall back to per-account lines).

## G5 + G6 — switch sending on from the onboarding step (phone and web)

- `OnboardingStepService` QuickBooks step gains an action `send` (body `{"on": true|false}`): on = 
  `QboSyncService::setAuto(true, today in the business's timezone)` AND receipts on (`QboExpenseSyncService`
  receipts switch, same start date); off = both off. Owner/admin only (whatever guards the other QuickBooks actions).
- `OnboardingApiPresenter::quickBooksData()` adds `sending: {on: bool, from: 'Y-m-d'|null, offer: bool}`;
  `offer` is true once connected and read and sending is off. Plus the copy for the app to show, from the server:
  `sending.title` "Send new invoices to QuickBooks?", `sending.body` "From today, make invoices in Ittai, not in
  QuickBooks. Ittai sends each invoice, its payments and your receipts to QuickBooks for you.",
  `sending.on_label` "Yes, from today", `sending.off_label` "Not now". When on: `sending.done_line`
  "Sending to QuickBooks from <d M Y>".
- The web onboarding page (`/crm/onboarding/`, QuickBooks module) shows the same choice using the same action.
- The Android app change (button calling `send`) is done by the Android session afterwards — not in this build.
  Keep the API shape above exactly.

## Tests

- runAll summary/status for: not connected, off, sent/held/failed, stopped.
- Fan-out wiring: penny_prepare calls the fan-out with the qbo_sync script (source-scan test is fine).
- Links written at import for contact/company/client keys; no overwrite of a different qbo_id; idempotent re-run.
- Item mapping written for new and matched products; per-line body built when all lines map; falls back otherwise.
- `send` action on/off sets the three switches; presenter `sending` block states.
- Full suite: `php -d memory_limit=1G vendor/bin/phpunit` must pass.
