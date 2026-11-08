# Who is the contact now — one answer every head uses, kept true by payments and mail (plan, 2026-11-07)

**Why (Tim, 2026-11-07, Mia's thank-you to Erin Hope-Goldsmith for $175.02):** "Erin left the strata, another
person has paid the bills, we should be able to figure this out as it happens with the agents." "There are email
communications and payment records that inform this." "Getting this right is fundamental to automating the
business." Property managers, strata reps and accounts clerks change all the time. Every automated message —
invoice, reminder, thank-you, quote, crew call — is only as good as knowing who the person is *today*.

**Status:** plan only, waiting for Tim's OK. Nothing built.

## What the code does today (checked 2026-11-07 on feature/language a3fa164ee)
**Nine places each pick the person their own way, and none can tell a current contact from a former one.**

| Who sends | Picks the person from | Knows "left"? |
|---|---|---|
| Invoices (create, visit, contract billing, move) | `companies` routing + `properties.property_manager_id` (`InvoiceRouting.php`) | only `contacts.is_active`, in `determineInvoiceRecipients` alone |
| Invoice recipients picker | `client_contacts` + `clients.managed_by_client_id` | no |
| Penny's reminders | invoice send snapshot (`invoice_contacts`, drops `bounced`), then PM routing, then `invoices.contact_id` | bounce on that one invoice only |
| Mia's thank-you | reuses Penny's reminder recipient | no |
| Sam's quotes + CC | `properties.quote_contact_id`, `companies.quote_contact_id`, `property_contacts` manager/site | no |
| Yui's desk | `property_contacts` manager, then company primary/billing | no |
| Building manager on file | `property_contacts` manager | no |
| Crew on-site contact | `property_contacts` site_supervisor | no |
| Autopay | `invoices.contact_id` | no |

Two people-with-roles tables exist (`client_contacts`, `property_contacts`), neither has a "left" date, and the
client-account migration that made `client_contacts` the roster is only part-way (phase 2.3 of 4).

**The payer is recorded and never compared:** `etransfer_notifications.sender_name` + the Interac sender address,
Stripe's payer email, the bank memo. **No inbox reader handles bounces, auto-replies or "no longer with".**

## The design
### 1. One roster, with history
- `client_contacts` is the roster (the client-account model already says so). Add `started_on DATE NULL`,
  `ended_on DATE NULL`, `ended_reason VARCHAR(40) NULL` (`left`, `bounced`, `replaced`, `owner_said`). One migration,
  MySQL 5.7 safe. Former contacts are kept, never deleted: history, old invoices and "who did we deal with in 2025"
  stay true.
- `property_contacts` (crew/on-site) gets the same two date columns so the crew never calls someone who left.

### 2. One resolver every head calls
`ContactRoster::current($propertyId, $purpose)` in `app/Modules/Clients/Services/` — purpose is `invoice`,
`reminder`, `thanks`, `quote`, `quote_cc`, `onsite`, `autopay`. It returns today's people for that purpose, skipping
anyone with `ended_on` in the past, and says *why* each was picked. The nine places above switch to it one at a time
(each behind the same switch, each with a test that the old and new answers match for every current contact).
This is the seam the client-account model's phase 2.4 needed anyway.

### 3. Evidence keeps it true (Yui owns it: people and mail)
`ContactChangeService` collects signals per building and per person:
- **Payment:** the payer matches nobody on the roster for that building (strong when the payer's address shares the
  old contact's firm domain, or the payer is already a contact of the managing firm).
- **Mail out:** a bounce (550 user unknown) or an auto-reply to something we sent ("no longer with", "has left",
  "mailbox not monitored", "please contact … instead") — and the replacement address it names, if any.
- **Mail in:** a new person at the same firm or strata writing about the same building ("I've taken over from Erin").
- **Already recorded:** `invoice_contacts.bounced`, a contact marked inactive.

### 4. Proposes, Tim confirms, the answer teaches
- One signal = a question. Two agreeing signals (new payer + bounce) = a confident question with the answer
  pre-filled. Nothing changes the roster without a tap.
- **The question (Yui, shown on the building and on the head that tripped it):** "Erin may have left <strata>.
  <payer> paid the last invoice and Erin's mail bounced. Make <payer> the contact?" **Yes** → `ended_on` for Erin,
  <payer> added to the roster with Erin's roles (created if new). **No** → <payer> recorded as an accepted second
  payer for that building; not asked again.
- **While the question is open, every head holds what would go to Erin:** Mia's thank-you shows "Paid by <payer>,
  not Erin. Has the contact changed?" (Thank <payer> instead · Erin is still right · Skip); Penny's next reminder and
  Sam's next quote wait for the answer. Already-sent invoices are never re-sent.
- After N confirmations of the same pattern for a managing firm (e.g. VML's accounts clerks), Yui may update that
  firm's roster herself and tell Tim after — the same "autonomy earned per vendor" rule as Penny.

## Unknowns and how each is settled, cheapest first
1. **What Erin's case looks like in the data** — her payment row (method, sender, invoice), her contact and roster
   rows, any bounce or reply mail. Read-only prod lookup (`scripts/prod-run.sh`); blocked this session by the
   permission check, needs Tim's permission once.
2. **How often payers legitimately differ** — over the last 12 months, payments whose payer is not on the roster for
   the building. Same lookup. Decides whether a payment alone asks, or only with a second signal.
3. **What the bounces and auto-replies look like** — stored inbound mail on office@ / iCloud. Same lookup; gives the
   real phrase list for the matcher's tests.
4. **How full `client_contacts` is on prod** (phase 1 created 1510 rows in June) and whether new contacts since then
   reach it. Same lookup. If it has drifted, step 2 starts by filling it.
5. **Prod schema differs from repo** for contacts/companies (known) — check columns before the migration.

## Steps and proof
1. Prod lookups for unknowns 1–5; write the counts and Erin's case here (anonymised).
2. Migration: dates on `client_contacts` and `property_contacts`.
3. `ContactRoster::current()` + tests; switch Mia's thank-you first (smallest, where the fault showed), then Penny's
   reminders, invoices, quotes, Yui, crew, autopay — one per commit, old-vs-new answer test each.
4. `ContactChangeService` payment signal + Yui's question card (web + iPhone) + the holds on Mia/Penny/Sam.
5. Mail signals in the inbox pollers (bounce, auto-reply, takeover) feeding the same service.
6. phpunit, Codex review per step, deploy behind `ops_settings.contact_roster_enabled`, check on Erin's building live.
