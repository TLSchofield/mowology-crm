# G10–G11: QuickBooks recurring invoices — no double billing, and they come across as contracts (build spec, 2026-11-05)

Tim: "Continue" (G10/G11 from `2026-11-04-claudia-quickbooks-sending-plan.md`). MySQL 5.7, no new tables, no migration.

## U6 settled (2026-11-05)

Source: Intuit's PHP SDK data classes (`IPPRecurringTransaction`, `IPPRecurringInfo`, `IPPRecurringScheduleInfo`);
Intuit's own API reference page is rendered by JavaScript and could not be read.
- A `RecurringTransaction` wraps one transaction (Invoice, Bill, …). The wrapped transaction carries `RecurringInfo`:
  `Name`, `RecurType` (Automated / Reminded / UnScheduled), `Active` (bool), `ScheduleInfo`.
- `ScheduleInfo`: `IntervalType` (Daily / Weekly / Monthly / Yearly), `NumInterval`, `DayOfMonth`, `DayOfWeek`,
  `WeekOfMonth`, `MonthOfYear`, `RemindDays`, `DaysBefore`, `MaxOccurrences`, `StartDate`, `EndDate`,
  `NextDate` (read-only), `PreviousDate` (read-only).
- **Not confirmed:** the exact JSON of a query reply. Expected `QueryResponse.RecurringTransaction = [{"Invoice": {…}}]`.
  The code accepts both that wrapper and a bare transaction that has `RecurringInfo`; the rehearsal2 walk confirms it
  (make one recurring invoice in the sandbox, read it).
- Ittai never writes recurring transactions (no pausing from Ittai). The owner pauses them in QuickBooks.

## Facts read (2026-11-05)

- `QboClient::all($entity)` reads `QueryResponse[$entity]` page by page → `all('RecurringTransaction')` returns the
  wrappers. `QboOnboardingService::pull()` keeps only rows with a top-level `Id`, so wrappers would be dropped.
- Contracts: `createContract()` (app/Services/CrmFunctions.php) is the one place a contract is made. It uses `getDB()`
  and needs property_id, contact_id and start_date. Contract billing (`app/Modules/Contracts/Cron/contract_billing.php`)
  bills only `status='active'`, `billing_cycle='monthly'` contracts **with an active job plan**, on the 1st, at
  `billing_amount` + GST (`TaxEngine::gstRate`). It re-checks each contract inside a locked transaction (the `$why`
  chain, step 3b'), and a contract it skips goes into `$skipped` with its reason.
- So an imported contract with no plan bills nothing. The double bill starts when the owner adds the visits (plan) while
  QuickBooks still has the recurring invoice switched on. Ittai emails the client its contract invoice, so a guard in
  QuickBooks sending would be too late: **the guard belongs in contract billing.**
- QuickBooks customer for a contract: `qbo_links` type 'client', keys `property:<property_id>` (G12 buildings),
  `contact:<contact_id>`, `company:<id>`, `client:<id>` (`QboOnboardingService::customerKeys`).
- Phone QuickBooks step: `OnboardingStepService::apply` key 'quickbooks', `do=send` turns sending on;
  `OnboardingQuickBooksModule::PARTS` lists the five import parts; `QboOnboardingService::partPreviews()` one line each.

## New: `app/Modules/QuickBooks/Services/QboRecurringService.php`

`__construct(PDO $db, ?QboConnectionService $conn, int $siteId = 1)`

- `static normalise(array $row): ?array` (pure). Unwraps `['Invoice' => …]`, or takes a bare row with
  `RecurringInfo`. Non-invoice types → null. Returns `id` (the transaction Id), `name` (RecurringInfo.Name),
  `active` (Active === true), `recur_type`, `customer_id` (CustomerRef.value), `customer_name` (CustomerRef.name),
  `total` (TotalAmt), `subtotal` (sum of `SalesItemLineDetail` line Amounts, else TotalAmt − TxnTaxDetail.TotalTax),
  `interval` (IntervalType), `every` (NumInterval, default 1), `day_of_month`, `start_date`, `end_date`, `next_date`,
  `lines` (descriptions, joined, ≤ 200 chars).
- `static bills(array $n): bool` (pure): active AND recur_type is not UnScheduled (case-insensitive). These are the ones that
  make or remind invoices.
- `static cycleOf(array $n): array` (pure): Monthly every 1 → `['monthly', null]`; Yearly every 1 → `['annual', 'Billed once
  a year in QuickBooks']`; anything else → `['custom', 'QuickBooks bills every <N> <interval>']`.
- `read(): array{ok:bool, rows:list, stale:bool, error?:string}`. Live `all('RecurringTransaction')`, normalised, cached
  for the instance. When the live read fails, fall back to the snapshot rows of kind 'recurring' (stale = true). When
  there is neither → ok false with the error.
- `customerIdsFor(int $contactId, int $propertyId, ?int $companyId): list<string>`: QuickBooks customer ids linked to
  this contract (keys above; `client:` via `clients.legacy_contact_id = contactId`). Each lookup in try/catch.
- `holdReason(array $contract): ?string` with keys contact_id, property_id, company_id (optional), billing_amount:
  - no customer ids linked → null (a client QuickBooks does not know cannot be billed there);
  - read() failed → `QuickBooks recurring invoices could not be read (<error>) — held so the client is not billed twice.`;
  - any row with `bills()` true whose customer_id is in the ids → `QuickBooks still bills this client with the
    recurring invoice "<name>" ($<total> <every/interval words>) — pause it in QuickBooks, then this contract bills.`
    (Any amount: a price change must not slip through as a double bill.)
  - else null.
- `pausePrompt(): ?string` for the switch: when any `bills()` row exists → `QuickBooks still makes <N> recurring
  invoice(s): <name — $total>, … Pause each one an Ittai contract replaces, or the client gets two invoices. Ittai
  holds a contract's invoice while its QuickBooks one is on.` (at most 5 names, then "and N more"); else null.

## G10 — contract billing holds a contract whose client QuickBooks still bills

In `contract_billing.php`:
1. Before the contract loop: when this is NOT the default business (`class_exists('Tenant') && !Tenant::isDefault()`;
   Mowology's own billing is unchanged by standing rule), the QboConnectionService tables are ready and a company is
   connected → `$recurring = new QboRecurringService($db, $conn, 1)` and call `$recurring->read()` once (the HTTP read
   happens before any lock). If building the service throws (no working QuickBooks link), `$recurring = null`, nothing
   is held, and the run summary says "QuickBooks recurring check unavailable: <class>". A failed READ is different:
   the service exists and `holdReason` holds linked clients (below).
2. In the step 3b' `$why` chain, after the in-force check: `$recurring ? $recurring->holdReason([...]) : null`. A hold
   rolls back like the other reasons and goes into `$skipped` with the reason, so `cron_runs` and Ada show it.
3. Test businesses ('test' => true) get the same hold (they make drafts otherwise).

## G10 — the owner is told when switching sending on

- `OnboardingStepService` 'quickbooks' `do=send` with on=true: after `setAuto`, when `pausePrompt()` returns text,
  the reply message is `Sending to QuickBooks from today. ` + that text. The phone shows the message already.
- Web `public/crm/accounting/quickbooks.php`: where the automatic-sending switch is shown, show the same text when
  it is not null (escaped with `h()`). No inline `<style>`.

## G11 — the "Contracts" part (her recurring invoices become Ittai contracts)

1. `pull()`: also read `RecurringTransaction` (via `QboRecurringService::normalise`) into the snapshot as kind
   'recurring', qbo_id = the transaction Id. A failed recurring read must NOT fail the pull: the message adds
   " Recurring invoices could not be read." Message adds ", N recurring invoices" when any.
2. Preview `contracts()`: one row per `bills()` row, and per UnScheduled/inactive row with `why` "Off in QuickBooks —
   not offered". Each billable row: name, customer name, amount (subtotal), cycle words, next date, and the Ittai match:
   - property: `linkedProperty(customer_id)` (G12 building) else the contact's own property (the contact linked to that
     customer via qbo_links key `contact:<id>`; first property with `site_contact_id` = contact, lowest id);
   - no contact or no property → `why` "Import the client first" / "The client has no property — add one first",
     not offered;
   - already imported (a qbo_links row type 'recurring' with this qbo_id and an entity_id) → `why` "Already a contract".
   Result: `rows`, `new` (offered count), `done`.
3. `apply('contracts')`, with optional `$only` list of recurring ids (default: all offered). For each offered row:
   `createContract()` through an injectable creator (constructor/setter callable, default
   `fn(array $d, int $u) => createContract($d, $u)`, requiring CrmFunctions.php when the function is missing) with:
   property_id, contact_id, title = name, billing_cycle from `cycleOf`, billing_amount = subtotal,
   invoice_timing 'upfront', start_date = next_date ?: today (business day as elsewhere), end_date = end_date ?: null,
   notes = `Imported from QuickBooks recurring invoice "<name>". QuickBooks keeps billing until you pause it there;
   this contract bills once it has visits and the QuickBooks one is paused.` + cycle note when any.
   Then save the link (`QboLinkService::save`, type 'recurring', entity_id 'contract:<id>', qbo_id = recurring id,
   message 'recurring invoice imported as a contract'). No job plan is made (the owner adds the visits).
   Idempotent: a recurring id already linked is skipped. Each row in try/catch; failures counted and named.
   Message: "N contracts made from QuickBooks recurring invoices" + skipped/failed parts. `markPart('contracts', …)`.
4. `apply()` switch gets 'contracts'; `computePreviews()` gets a 'contracts' line ("N recurring invoices → contracts",
   or "No recurring invoices"). `OnboardingQuickBooksModule::PARTS` gets 'contracts' (six parts).
5. Writes follow the existing rule (`canWrite()`): same as other parts.

## Tests

`tests/Unit/QuickBooks/QboRecurringServiceTest.php` (pure + sqlite):
- normalise: wrapper and bare forms; a Bill wrapper → null; subtotal from lines and from TotalAmt − tax.
- bills(): Automated/Reminded active true; UnScheduled false; inactive false.
- cycleOf: monthly/1, yearly/1, weekly/2.
- holdReason: no link → null; linked contact with an active recurring for its customer → reason with name; paused →
  null; property link (building) matches; read failure with a link → "could not be read"; read failure, no snapshot,
  no link → null.
- pausePrompt: none → null; seven → five names + "and 2 more".
QboOnboardingTest additions:
- pull keeps recurring rows and survives a failing recurring read (fake client).
- contracts(): offered row with contact + property; building via sub-customer link; no contact → not offered;
  already linked → "Already a contract".
- apply('contracts') with a fake creator: data passed (cycle, amount = subtotal, start = next date, notes); link saved;
  re-run makes none; `$only` limits.
OnboardingStepService: send on with a recurring row → message includes the pause text (fake factory).
contract_billing: the `$why` chain is inline; cover the decision via `holdReason` tests (no cron harness exists).
Full suite: `php -d memory_limit=1G vendor/bin/phpunit`.

## Out of scope / known

- Pausing a QuickBooks recurring invoice from Ittai (write) — owner does it in QuickBooks.
- iPhone/Android rendering of a sixth part: the server adds it; whether the apps show it untouched is checked on the
  walk (backlog if not).
- GST: contract billing always adds `TaxEngine::gstRate()`; a business not registered for GST (G8) needs that rate at 0
  in Settings — check Claudia's answer before her contracts bill.
- QuickBooks bills on its own day of the month; Ittai bills on the 1st. Said in the contract note.
