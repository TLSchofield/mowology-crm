# Charlie, Foreman — phase 2 plan

**Status: plan only. Tim approves before any code is written.**

- Branch: `feature/charlie-foreman-phase2`, from `origin/feature/charlie-chief-of-staff` @ `63693b50`.
- Migrations: 1171–1173 used, 1174–1179 spare.
- Charlie keeps his name. His title becomes **Foreman** (chief of staff): a copy change in `charlie-card.php` and `CharlieBriefService::HEADS`.

This phase has two pieces:

- **A — Compliance and renewals calendar.** No AI. It is useful the day it ships.
- **B — Unified decision inbox and conflict rules.** It is built on the heads that exist today, and phased for the ones that don't exist yet.

---

## 0. What exists. Checked before inventing tables.

| Looked for | Found | Use |
|---|---|---|
| Reminders / tasks / deadlines tables | None. `purchase_tasks` (1029) is crew supply runs. `compliance_events` (603) is GPS events. Neither fits. | New tables (1171) |
| `business_settings` (020) | `gst_registration`, `pst_registration`, `business_license` (number only). No year-end, legal structure, insurance or WCB fields. | Read the licence number for the licence item. Year-end and structure become Charlie settings in `ops_settings`. |
| Penny's period locks | `accounting_periods` (980): `status` is open, closed or locked, checked by `LedgerService:429` | The accountant pack shows which months of the year are closed |
| Year numbers | `ReportingService`, `TaxEngine`, `OwnerFreedomService` | Linked from the accountant pack. Read-only. |
| Client contract renewals | `contract_renewal` cron already auto-renews client contracts | **Not duplicated.** Charlie's calendar is about the *business's own* obligations. |
| Vehicles / equipment | Only `vehicle_trip_reports` and `vehicle_location_pings`. There is no asset register and no odometer. | Equipment service is date-based in A. Usage-based service waits for an asset register. |
| Head action endpoints | Penny `bookkeeper.php` (decide, answer); Sam `sales-head.php` (send, park, answer, lead_dismiss); Otto `otto.php` (decide, answer, plus `otto_suggestions` 1150) | The inbox routes to these. It owns no logic of its own. |
| Message history per client | `sales_messages` (Sam 1140), `communication_log` (campaigns and opt-in only), `campaign_sends`. `sendEmail()` logs nothing centrally. | "One message per client per week" can only be enforced on proposals that pass through the inbox, plus this history on a best-effort basis (see B3) |
| Payment failures | `autopay_attempts` (1036) | Urgent list |
| Same-day weather | Otto's weather suggestions (`otto_suggestions.kind = 'weather'`) | Urgent list |
| Margin floor | No setting. Margin snapshots exist per visit; `products.min_price` (119). | Its rule is phased |
| Protected time | Only per-plan `blackout_dates` (200). There is no owner calendar. | Its rule is phased |
| Ad spend | Nothing | Its rule is phased |
| Push to Tim | `ApnsService` / `FcmService` / `PushDispatcher` exist. Whether APNs is provisioned is unknown (memory: inert until .p8). | Urgent interrupts go by push if configured, otherwise email |

### Two facts that may change the calendar. Tim and his accountant decide.

1. **The business may be incorporated.**
   - Sam's copy rules (`app/Services/Copy/mowology-copy-rules.md`) name the business **"Mowology Lawns & Landscapes Ltd."**, which suggests a corporation.
   - The sole-proprietor seed list (T1, Apr 30 / Jun 15, personal instalments) would then be wrong.
   - A corporation has a T2 return, a corporate tax balance date, a different GST return date and director WorkSafeBC coverage instead.
2. **Nigel is crew.**
   - If he is an employee, payroll deadlines likely apply: source-deduction remittances, T4s, WorkSafeBC employer registration and premiums.
   - These are probably the most frequent deadlines of all, and Tim's doc doesn't list them.

Both go in as **"confirm with accountant"** items, switched off until Tim says which applies.

---

## A — Compliance and renewals calendar

### Model (migration 1171)

**`charlie_deadlines`** — one row per obligation.

| Column | Meaning |
|---|---|
| `slug` | UNIQUE |
| `title` | |
| `category` | tax, licence, insurance, safety, vehicle, equipment, contract or other |
| `rule` | TEXT. One of:<br>`annual:MM-DD`<br>`dates:03-15,06-15,09-15,12-15`<br>`every:6m` from `anchor_date`<br>`once:YYYY-MM-DD` |
| `lead_days` | When reminders start |
| `prepare` | TEXT. What Charlie gets ready, as a plain checklist. |
| `condition_note` | e.g. "only if CRA sent instalment reminders" |
| `active` | 1/0 |
| `needs_setup` | Open items Tim fills in |
| `amount_hint` | Feeds Charlie's money weight |
| `url` | |
| `confirm_note` | |
| `source` | `seed` or `tim` |
| timestamps | |

**`charlie_deadline_occurrences`** — one row per due date.

- Columns: `deadline_id`, `due_date`, `status` (open, done, snoozed or skipped), `snoozed_until`, `done_at`, `done_by`, `note`.
- UNIQUE on (`deadline_id`, `due_date`).
- History is kept, so "licence renewed 2026-11-20" is a record.

**Pure `DeadlineRules`** (unit tested):

- `nextDue(rule, from)`
- `remindFrom(due, lead)`
- `priority(today, due, lead)`:
  - overdue or 3 days or less → 1
  - inside the lead window → 2
  - next 60 days → 3, shown on the calendar page only

Includes Feb 29 handling and a year rollover.

### Seed (all "confirm with accountant"; sole-proprietor set active only if Tim confirms that structure)

| Item | Rule | Lead | Notes |
|---|---|---|---|
| Income tax instalments | `dates:03-15,06-15,09-15,12-15` | 14 days | `active = 0` until Tim answers "Does CRA ask you for instalments?" |
| City of Vancouver business licence | `annual:12-31` | from mid-Nov (about 45 days) | Late = greater of $45 or 10%. Shows `business_settings.business_license`. |
| Pay income-tax balance and annual GST | `annual:04-30` | from Mar 1 | Prepares the **accountant pack** (below) |
| File T1 and GST return | `annual:06-15` | 30 days | "Interest has run from May 1 on anything unpaid" |
| WorkSafeBC Personal Optional Protection review | `annual:01-15` | 21 days | Sole proprietors aren't covered automatically; coverage lasts only while premiums are paid |
| *Open — Tim fills in (`needs_setup`):* | | | |
| Licences in other municipalities, or the inter-municipal licence | | | |
| Commercial general liability renewal | | | Plus a "send certificate" checklist for strata clients |
| Might-E Truck insurance renewal | | | |
| Equipment service intervals | `every:Nm` | | |
| Contract template review | | | |
| *Off until confirmed:* | | | |
| Incorporated set (T2, corporate balance, GST return) | | | |
| Employer set (remittances, T4s, WCB employer) | | | |

### What "preparing" means. Read-only links; Charlie files nothing.

**Accountant pack** (from Mar 1):

- Year P&L (`ReportingService`)
- GST collected and paid (`TaxEngine`)
- Months still open in `accounting_periods`
- Unapproved receipts (Penny)
- Unreconciled bank lines

Each is one line with a link and a count. The bad numbers come first: "4 months not closed, 23 receipts unapproved."

### Into the brief

- A new source `'charlie'` in `CharlieBriefService`.
- Key: `charlie:deadline:<slug>:<due_date>`, which is stable per occurrence.
- Kind: `charlie:deadline_<category>`.
- `since` = remind-from date, so age weighting grows as the date nears.
- `value` = `amount_hint`.

### Buttons

- **Done**: the next occurrence is generated.
- **Snooze**: 1–7 days, never past the due date.
- **Not this year**: skipped, with a note.

Each fires from the Charlie card, and from a new AppStack page **`/crm/foreman_calendar_appstack.php`**. The page has the list, add/edit (the date rule as plain inputs, no raw rule strings) and the history.

### Files

- `app/Modules/ChiefOfStaff/Services/{DeadlineRules,DeadlineService,AccountantPackService}.php`
- API modes on `charlie.php`: `deadlines`, `deadline_done`, `deadline_snooze`, `deadline_save`
- The page; CSS added to the Charlie section

---

## B — Unified decision inbox and conflict rules

### B0. Contract addition (optional, backward compatible)

Heads may add `proposals(string $name): array`. Heads without it still appear, view-only, from `brief()` (link out).

Each proposal carries:

- `key` (stable) and `head`
- `kind` and `text`
- `value` ($ at stake) and `due` (date)
- `contact_id` / `client_id` / `property_id`: **required for any proposal that messages a person** (rules B3)
- `channel`: message, schedule, price, money or other
- `batch_key` plus `batch_label`, e.g. `penny:category_ok`, "approve 6 receipt categories"
- `actions`: [{`label`, `endpoint`, `body`, `style`}]

The endpoint must be on an allowlist:

- `/crm/api/bookkeeper.php`
- `/crm/api/sales-head.php`
- `/crm/api/otto.php`
- Mia's endpoint once it exists

The inbox renders nothing else, so a URL in the data can't make it post anywhere.

### B1. Inbox — a view and a router (phase B1, now)

- **`CharlieInboxService`**
  - Collects proposals the same way `collect()` collects briefs.
  - **Deduplicates**: by key, and by (`contact_id`, `channel` = message) across heads.
  - **Ranks**: `CharlieRankService` plus a deadline factor (due in ≤1 day ×2, ≤3 days ×1.5).
  - **Batches**: proposals sharing a `batch_key` (2 or more) become one row.
- **One tap on a batch.**
  - The browser calls each item's head endpoint in turn, with CSRF, so the head's own permission and validation apply.
  - It stops at the first failure and shows "4 done, 1 needs you".
- **Logging.** After each call the card posts `?mode=inbox_done` to Charlie (migration 1173 `charlie_inbox_actions`) so ranking learns.
- **Placement.** A fold on Charlie's card plus a full page section. It never auto-applies anything.

### B2. Conflict rules (migration 1172)

**Tables:**

- `charlie_rules`
  - `slug`; Tim's sentence (`text`); `enabled`
  - `params` TEXT, e.g. `{"late_days":14}`
  - `escalate_when`, `never_escalate`, `phase`, `needs` (which head/data it waits on)
- `charlie_rulings`
  - `rule_slug`, `proposal_key`, `other_key`, `contact_id`
  - `verdict`: held, blocked, allowed or escalated
  - `reason`
  - `overridden_at` / `by`

**Pure `ConflictRules::apply(proposals, facts)` returns verdicts.**

- Held or blocked proposals sit in a visible "Held by a rule" fold.
- Each shows the rule sentence and an **Override** button. The override is logged and the proposal is released.
- **A rule overridden twice in 60 days** → Charlie asks: "You've overridden '<rule>' twice. Change it?" He offers the one parameter that would have allowed both overrides, e.g. 14 → 21 days, or "except strata".
- Rules are editable on the calendar page's "Rules" tab: enable, parameters, text. Every ruling is listed there too.

| Rule | Phase | Data it needs |
|---|---|---|
| **Collections vs upsell** — no sales message to a client with an invoice more than 14 days late; collections first; never escalates | **B1** | Invoices (exists), plus `contact_id` on Sam's and Mia's message proposals. Use `InvoiceRouting`'s client identity, never `invoices.contact_id` (vault rule). |
| **One message per client per week** — highest priority wins; escalate for a legal notice or contract copy | **B1** | Proposals in the inbox, plus history from `sales_messages`, `communication_log` and `campaign_sends`. Best effort: other sends aren't logged centrally. Logging inside `sendEmail()` is a separate decision (it touches shared messaging). |
| **New job vs protected time** — protected time wins, offer the next slot; escalate if an existing contract is at risk | **B3** | An owner protected-time calendar (none today) and Otto proposing schedule changes |
| **Discount below margin floor** — block and propose the next tier; escalate for a dense street with near-zero drive cost | **B3** | A margin-floor setting, Sam proposing priced quotes with a cost basis, and route density from Otto |
| **Route full** — pause paid ads and neighbour offers for that area, keep reviews and retention; escalate for a high-value commercial lead | **B4** | Otto's capacity per area, Mia's neighbour-offer proposals, and an ads head (none) |

### Quiet by default

Interrupts go only to Tim's urgent list. Everything else waits for the card and the 7 am brief.

- **Payment failure over $X**: `autopay_attempts` failed, amount ≥ setting. **B1.**
- **Same-day weather cancellation**: an Otto weather suggestion for today. **B1** once Otto is deployed.
- **Client complaint**: there is no source yet. Sam's inbox classifies *who* wrote, not *what*. **B3**, when Sam or Mia flag complaints.

The channel is push if APNs is configured, otherwise one email. Each interrupt is sent once, deduplicated by key.

### Guardrails, enforced in code

- Charlie holds no money, filing, signing or messaging actions of his own.
- The inbox only forwards Tim's click to an allowlisted head endpoint, which already needs Tim's permission.
- Batches need a tap. Nothing is applied from a cron.
- **Bad news first.** The card's lead line and the brief open with:
  - failed heads
  - held conflicts
  - overdue deadlines
  - payment failures

  …before "the one thing". `CharlieVoice` gets a test for this order.

---

## Unknowns, cheapest first

1. Sole proprietor or Ltd.; employees or not. Tim and his accountant answer. This decides which seed sets are active.
2. Does CRA ask for instalments? Tim answers.
3. Is APNs provisioned on prod? Read `ApnsService::isConfigured()` through Tim's Chrome.
4. Mia's endpoint name and whether she ships `proposals()`. Read her branch when it lands.
5. Can Penny's per-receipt decisions batch (same `batch_key`)? Penny's files are off-limits, so her proposals come from a read-only adapter linking to her carousel. Real batching needs Penny to expose `proposals()` later.
6. Prod drift on `charlie.php` and the card after the lead's deploy. `cmp` against prod before phase 2 ships.

## Tests

**Unit:**

- `DeadlineRules`: rule parsing, next due across a year end, Feb 29, priority bands, snooze capped at the due date.
- Accountant-pack wording (bad first).
- `ConflictRules`: each B1 rule (hold, allow, escalate, never-escalate), override counting, the proposed parameter change.
- Inbox: dedupe, batch grouping, deadline ranking, endpoint allowlist rejection.
- `CharlieVoice`: bad-news-first order.

**Database flow:** the SQLite harness from phase 1, extended for deadlines, rulings and overrides.

**Render:** the card, the calendar page and the inbox with stub data.

**Gate:** full phpunit stays green.

## Build order

1. A: tables, seed, rules, service, brief source, card buttons, page.
2. B1: contract addition, inbox view and router, batching, the two B1 rules, rules tab, urgent list (payment failure).
3. B3 and B4 when their data exists.

## Decisions for Tim

1. Business structure and employees, which decides the seed sets.
2. Instalments: yes or no.
3. Urgent channel (push or email) and the payment-failure threshold ($).
4. Calendar page: a new page `/crm/foreman_calendar_appstack.php`, or a tab in Settings.
5. Rule defaults: 14 days late; one message per client per week; "overridden twice in 60 days".
6. Whether to add central logging inside `sendEmail()`. It makes the weekly-message rule complete, but it changes shared messaging code.

## Built — 2026-10-06 (A + B1)

**Tim's answers, applied:**
- Mowology is a corporation with employees, so the corporate set is seeded and there are no T1 items:
  - T2 return (Jun 30)
  - Corporate tax balance (end of February; Mar 31 if it qualifies as a small CCPC)
  - GST, annual (Mar 31); the quarterly version is off
  - Payroll remittances (the 15th of each month)
  - T4s (last day of February)
  - WorkSafeBC payroll report and premiums (quarterly)
  - WorkSafeBC director coverage review (Jan 15)
  - Year-end package to the accountant (Jan 31)
  - City of Vancouver licence (Dec 31; reminders start 45 days before)
- Corporate instalments are seeded **off**.
- Every tax and payroll item carries "confirm with your accountant".
- Tim's open items are seeded with no date: other licences, liability insurance (with the strata certificate), truck insurance, equipment service, contract template review.
- Urgent alerts go by email only. The payment threshold is $500 (`ops_settings charlie_urgent_payment_min`), editable on the page.
- Rule defaults: 14 days late; one message per client per 7 days; 2 overrides in 60 days triggers a proposed rewrite.
- No central logging inside `sendEmail()`. That stays a later decision.

**Added while building (needs Tim's eye):**
- **A reply to a customer who wrote in is never held by the weekly rule.** It isn't outreach, but it does count as that week's message. Collections-first still applies to it.

**Migrations:**
- 1171: deadlines and their occurrences, plus the seed
- 1172: rules and rulings, plus the 5 seeded rules (3 of them off, for later)
- 1173: inbox log, alerts and the threshold setting

**Cron:**
- `charlie_morning_brief` now checks urgent alerts on every run, syncs the heads hourly (in the first 15 minutes of the hour), and sends the 7 am brief as before.
- Recommended crontab: `2,17,32,47 * * * *`. Hourly still works; alerts just wait up to an hour.

**Still phased:**
- B3: protected time; margin floor; client complaints on the urgent list
- B4: route full / ads
- Penny batching: needs Penny to publish `proposals()`; until then her rows are view-only
