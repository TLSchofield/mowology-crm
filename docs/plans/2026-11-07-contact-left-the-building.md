# Who is the contact now — one answer every head uses, kept true by payments and mail (plan, 2026-11-07)

**Why (Tim, 2026-11-07, Mia's thank-you to a strata rep who had left):** "[The rep] left the strata, another
person has paid the bills, we should be able to figure this out as it happens with the agents." "There are email
communications and payment records that inform this." "Getting this right is fundamental to automating the
business." Property managers, strata reps and accounts clerks change all the time. Every automated message —
invoice, reminder, thank-you, quote, crew call — is only as good as knowing who the person is *today*.

**Status:** plan approved by Tim 2026-11-07. Step 1 (production lookups) done, findings below. Nothing built.

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
- **Mail in:** a new person at the same firm or strata writing about the same building ("I've taken over from the old contact").
- **Already recorded:** `invoice_contacts.bounced`, a contact marked inactive.

### 4. Proposes, Tim confirms, the answer teaches
- One signal = a question. Two agreeing signals (new payer + bounce) = a confident question with the answer
  pre-filled. Nothing changes the roster without a tap.
- **The question (Yui, shown on the building and on the head that tripped it):** "the old contact may have left <strata>.
  <payer> paid the last invoice and the old contact's mail bounced. Make <payer> the contact?" **Yes** → `ended_on` for the old contact,
  <payer> added to the roster with the old contact's roles (created if new). **No** → <payer> recorded as an accepted second
  payer for that building; not asked again.
- **While the question is open, every head holds what would go to the old contact:** Mia's thank-you shows "Paid by <payer>,
  not the old contact. Has the contact changed?" (Thank <payer> instead · the old contact is still right · Skip); Penny's next reminder and
  Sam's next quote wait for the answer. Already-sent invoices are never re-sent.
- After N confirmations of the same pattern for a managing firm (e.g. VML's accounts clerks), Yui may update that
  firm's roster herself and tell Tim after — the same "autonomy earned per vendor" rule as Penny.

## Step 1 findings — production, read-only, 2026-11-07 (anonymised; details stay on production)
**The strata in Tim's screenshot told us twice, and nothing listened.**
- July 2026, inbound to iCloud: an invoice was "still addressed to" a previous rep "who is no longer at our address".
- Mid-October, inbound to iCloud, with one of the new people in copy: the rep said two named people "will be taking
  over our account" because she had sold her unit.
- Early November: the e-Transfer came from one of those two named people, memo naming the building.
- Two days later: Mia drafted the thank-you to the old rep. Her contact is still active with her old address. She
  also writes from a second address that isn't on her contact. Nothing in `property_contacts` links her to this
  building.
- All three signals existed: the handover email (three weeks early, naming the replacements and copying one), the
  payer, and the earlier "no longer at our address" email. Mail came first and was clearest. The payment confirmed it.

**Payers over the last 12 months (26 e-Transfers recorded):**

| Payer | Count |
|---|---|
| The invoice's own contact | 8 |
| A business (strata, realty, management firm) | 11 |
| Sender name missing (one management firm; the parser didn't read the name) | 4 |
| A different person | 3 |

Of the three different people, one was this real handover. One had a near-spelling of the contact's surname (same
household), and one a different surname on a homeowner's account (likely a partner). **So a different payer alone
is weak: 1 real change in 3.** It should ask only with a second signal, when it repeats, or when a handover email
already named that person.

**Leaving phrases in stored inbound mail:** only the two emails above matched. Stored mail is short snippets, and
bounces and auto-replies aren't stored at all. The mail signal needs the poller to read the whole message and to
keep bounces.

**The roster has drifted since June:** `client_contacts` has 1,512 rows for 1,527 contacts, and **none of the 29
contacts added since 7 June has a roster row**. 303 invoices from the last 12 months have no `client_id`.
`property_contacts` has 3 `manager` rows and 1 `site_supervisor`. Step 2 must start by filling the roster and making
every new contact land on it (the client-account model's phase 2.4). Otherwise the resolver answers from a stale list.

**What changes in the design:**
1. Mail comes first. A handover email, where the old contact names who takes over, is the strongest and earliest
   signal. The question can name the new people and fill in their addresses from the copy line.
2. A different payer counts only with a second signal, or when it matches someone the mail already named.
3. A contact's other addresses belong to the same person, so a reply from any of them counts.
4. Also fix the e-Transfer parser leaving the sender name blank for one management firm.

## Step 3 started — 2026-11-09
`ContactRoster` (client-level: `current($clientId, $purpose, $today)`, `isFormer()`, `firstCurrent()`) is built, and
Mia's thank-you is the first sender switched. Decided while building:
- Keyed by account (`client_id`), not property: the roster is per account and invoices now all carry a client.
- `resolveReminderRecipients()` is unchanged. An invoice's recipient list stays final for invoices and reminders
  (Decision-Log); the roster only decides who Mia thanks. Other senders switch one at a time.
- No `contact_roster_enabled` switch: the lookup changes nothing until a left date exists, and only a person or the
  step 4 question can set one. Production preview: 60 recent payments, same answer every time.
- If the only person on an account has left, Mia drafts nothing until someone new is added.

## Unknowns and how each is settled, cheapest first
1. **What the old contact's case looks like in the data** — her payment row (method, sender, invoice), her contact and roster
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
1. Prod lookups for unknowns 1–5; write the counts and the old contact's case here (anonymised).
2. Migration: dates on `client_contacts` and `property_contacts`.
3. `ContactRoster::current()` + tests; switch Mia's thank-you first (smallest, where the fault showed), then Penny's
   reminders, invoices, quotes, Yui, crew, autopay — one per commit, old-vs-new answer test each.
4. `ContactChangeService` payment signal + Yui's question card (web + iPhone) + the holds on Mia/Penny/Sam.
5. Mail signals in the inbox pollers (bounce, auto-reply, takeover) feeding the same service.
6. phpunit, Codex review per step, deploy behind `ops_settings.contact_roster_enabled`, check on the old contact's building live.
