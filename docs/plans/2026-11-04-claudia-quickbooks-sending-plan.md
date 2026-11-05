# Claudia keeps QuickBooks: Ittai sends her invoices, payments and receipts (plan, 2026-11-04)

Tim: "plan QuickBooks invoice write-back (step 2) before Claudia's day", then "once she is interested in switching
over can we have the ledger operating in shadow?". Council 2026-11-04: she keeps QuickBooks at the start.

## What already exists (read 2026-11-04, not assumed)

- **Sending is built.** `QboSyncService` sends an Ittai invoice + its customer + its payments (allocations and
  Stripe) to QuickBooks; `QboExpenseSyncService` sends approved receipts as Purchases (bank-feed "1 match found"
  proven 2026-10-20). Fixed request ids (no duplicates on retry), `qbo_links` remembers each thing, total compared
  after sending (mismatch kept and said). Proven in the Canadian sandbox 2026-10-20 (INV-2026-0461/0457 to the cent).
- **Switches** (ops_settings, per business): `qbo_auto_send` + `qbo_auto_from` (invoices/payments from a date),
  `qbo_auto_receipts`, `qbo_live_sends` (a production company is refused until it is 1).
- **The 15-minute run** is inside `penny_prepare.php` (cron key `qbo_sync`) — and it runs for **Mowology only**.
  `BusinessCronFanout` (on feature/language, used for Otto) is not applied to it.
- **Ittai's own ledger** (`LedgerSyncService`) posts invoices and payments whether or not QuickBooks is connected,
  so a shadow ledger already half-exists, unlabelled.

## Gaps before Claudia's day

| # | Gap | Fix |
|---|---|---|
| G1 | The sending run never runs for her business | Fan `penny_prepare` (or just its QuickBooks part, as `qbo_sync.php`) out per business with `BusinessCronFanout`; cron_runs per business so Ada sees a stop |
| G2 | Live company refused (sandbox only) | Intuit Production keys (Tim, below) + `qbo_live_sends=1` on her business only |
| G3 | Her imported QuickBooks customers are matched again by name when sending → a renamed or company-vs-person client becomes a duplicate customer in her QuickBooks | The onboarding import writes `qbo_links` (client key → her QuickBooks customer id) for every client it imports or matches |
| G4 | Lines go to new "Lawn Care Services (Ittai)" items, not her own items | A line whose service came from her QuickBooks (`qbo_mappings` kind item) is sent with her own item; others fall back to the per-account item |
| G5 | Nobody switches sending on from the phone | Onboarding QuickBooks step, after Accept: "Send new invoices to QuickBooks from today?" Yes = `qbo_auto_send=1`, `qbo_auto_from=today`, receipts too. Phone-only owner rule |
| G6 | Double entry if she also types invoices into QuickBooks | Plain rule shown once at that switch: "From today, make invoices in Ittai, not QuickBooks." |
| G7 | Payments: does a Payment sent by API match her bank-feed deposit (as Purchases did)? | Test in the sandbox: payment to Undeposited Funds vs straight to the bank account, then "For review" on a feed line |
| G8 | GST: she may not be registered | Already handled by the exempt-code mapping; check her answer and map before the switch |

## Status 2026-11-04 evening

G1–G6 LIVE (deployed on Tim's go; Codex-built and reviewed; 4,116 tests). rehearsal2 sandbox customers moved to
Vancouver public-place addresses; import accepted by Tim and checked field by field
(`scripts/qbo-import-check.snippet.php`).

## More eventualities (Tim 2026-11-04: Claudia has contracts; "the whole point of using my company for testing was
## to ensure we cover as many eventualities as possible")

| # | Gap | Fix |
|---|---|---|
| G9 | Contract billing (`contract_billing` cron) runs for Mowology only — a business's contracts would never bill | Fan it out per business with `BusinessCronFanout`, as qbo_sync; cron_runs per business; check on rehearsal2 with one test contract |
| G10 | Double invoices: QuickBooks recurring invoices AND Ittai contract invoices once sending is on | Before `send` can be switched on, the QuickBooks step lists her QuickBooks recurring invoices and asks her to pause each one an Ittai contract replaces; sending holds an invoice whose customer has an active QuickBooks recurring invoice for the same amount (says why) |
| G11 | Her contracts don't come across: the import reads no recurring transactions | Read QuickBooks RecurringTransaction (U6) into the snapshot; a "Contracts" part offers one Ittai contract per recurring invoice (client, property, amount, schedule), owner confirms each |
| G12 | Management companies (VML / Dorset style): sub-customers become properties but lose their billing, and invoices for a building go to the parent customer | Link each imported property to its QuickBooks sub-customer (qbo_links 'property'); send that property's invoices to the sub-customer (QuickBooks' "bill with parent" then does the right thing); a sub-customer billed on its own → its bill-to name/address/email become the property's billing contact (existing PM routing, migration 1063); top-level customers that look like one company's buildings ("VML – …") → ask to group, never guess |
| G13 | A person client's billing (mailing) address is dropped (only companies store billing) | Store it where the client/account model keeps a payer's address (check `clients` columns first); field check must show 0 misses |
| G14 | A second client at the same street address gets no property (the first one's is reused) | Import creates the property for the second client unless it is the same QuickBooks customer; a shared address is said in the preview ("2 clients at 350 West Georgia St") |

Unknowns: U6 QuickBooks RecurringTransaction API (entity, query support, minor version, what it returns for schedule
and amount) — one Intuit docs read before G10/G11. U7 where the clients spine keeps a payer's mailing address — one
schema read before G13. U8 `contract_billing` per-business safety (it sends invoices: a test business must not email
real people) — read the cron and its guards before G9.

Order: G9 (blocks any contract in any business) → G12/G13/G14 (import correctness, one change to the import) →
G10/G11 (recurring transactions, after U6). Codex builds each, Claude reviews, Codex review (money), field check and
a rehearsal2 walk before deploy.

## Shadow ledger (when she says she might switch)

Ittai's ledger already posts her invoices and payments. Shadow mode makes it a deliberate second set of books
that she never files from, and checks it against QuickBooks every month:

- **S1 Label.** ops_settings `books_mode` = `quickbooks` | `shadow` | `ittai`. In `shadow`, every books screen
  and export says "Practice books — QuickBooks is your real set"; nothing is sent to CRA or the accountant from it.
- **S2 Fill the gaps.** QuickBooks sees bank lines Ittai does not (spending she never snapped). In shadow she
  uploads the monthly statement (the existing statement import) so Penny's books are complete — this is the
  real-life test of the monthly upload she would live with after switching.
- **S3 Compare monthly.** Read her QuickBooks Profit and Loss, Balance Sheet and GST summary for the month
  (Intuit Reports API, read-only) and line them up against Ittai's, account by account through `qbo_mappings`.
  Differences go to Penny's questions, never silently "fixed".
- **S4 Switch rule.** Three months in a row matching within a set tolerance, then her accountant reviews one month
  side by side and agrees. Then `books_mode=ittai`: opening balances from QuickBooks at a month end (Jobber-exit
  lessons: income counted once), sending to QuickBooks stops, she cancels QuickBooks.

Shadow is built after G1–G8 and only when she shows interest; nothing in it is needed for her first day.

## Leaving QuickBooks elegantly (Tim 2026-11-04: "when she decides to cancel QuickBooks can we do that elegantly?")

Because Ittai's ledger posts every invoice, payment and receipt from day one (the same ones it sends), on the day
she cancels Ittai already holds everything since her start date. One "Leave QuickBooks" step does the rest:

1. **Pick the month end** to switch at (the last month QuickBooks was complete).
2. **Bring in what happened before Ittai:** read her QuickBooks trial balance at her Ittai start date → one opening
   balance entry in Ittai (bank, receivables, payables, GST owing, equity). Invoices already in Ittai are never
   posted again (Jobber-exit lesson: income counted once).
3. **Check the gap:** for the months Ittai and QuickBooks both ran, compare each account (the shadow compare, S3).
   Differences (bank-only spending she never snapped, owner draws) become Penny's questions before switching.
4. **Keep her records:** download her QuickBooks reports (P&L, balance sheet, GST, general ledger, per year) and
   attachments into Ittai's records before she cancels — CRA wants six years.
5. **Stop sending, disconnect,** `books_mode=ittai`; her accountant gets Ittai's accountant login.

Done well, cancelling QuickBooks is a 10-minute step, not a migration. Building 2–3 needs the Reports API (U4).

## Unknowns and how each is settled

| Unknown | Settled by |
|---|---|
| U1 Production keys: what Intuit asks (questionnaire, privacy policy/EULA URLs, app review time) | One read of Intuit's "get production keys" page before Tim starts |
| U2 Production redirect URIs: one per business host, or one fixed callback host | Same Intuit page; backlog already favours one fixed host (fewer URIs to add per business) |
| U3 Payment bank-feed match (G7) | Sandbox test, before her day |
| U4 Reports API gives P&L / Balance Sheet / GST by month under the accounting scope | One Intuit docs read before S3 |
| U5 Fan-out timing: 15-minute job × N businesses within the cron window | `BusinessCronFanout` timeout per business; measure on rehearsal2 |

## Steps and proof

1. G1 fan-out + G3 links + G4 own items + G5/G6 switch (server). Tests; Codex review (money); local run against
   the sandbox on a second local business.
2. G7 sandbox payment-match test.
3. Walk on rehearsal2 (sandbox company): connect → Accept → switch on → issue an invoice in the app → see it,
   its customer (her existing one) and its payment in the sandbox QuickBooks, totals equal.
4. Tim: Intuit Production keys; then the same walk on a throwaway business against Tim's own live QuickBooks
   (memory: Tim offered it; one test invoice, voided after).
5. Claudia's day: connect her live company, `qbo_live_sends=1` on her business only.
6. Later, on her interest: S1–S4.

## Tim's calls

1. Start the Intuit Production keys application now (it gates step 4 and her day).
2. Receipts to QuickBooks on by default with invoices (recommended: yes, otherwise her bank feed in QuickBooks has
   spending Ittai knows about but QuickBooks does not).
