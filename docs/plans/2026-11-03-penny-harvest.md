# Plan — Penny harvest (pieces 3, 7, 10 of the small-business map)

Date 2026-11-03 · Source rules: Anthropic small-business plugin (`invoice-chase/reference/tone-matching.md:1-60`,
`v2_sources.md:30-45`, `ap-processor/reference/intake_and_extraction.md:50-62`, `coding_rules.md:19-56`,
`cash-flow-snapshot/SKILL.md:45-115` + `reference/gotchas.md:45-65`). Harvest map: `docs/plans/2026-11-03-small-business-skills-harvest.md`.
Pattern: Sam piece 1 (`docs/plans/2026-11-03-sam-lead-score-and-buckets.md`).

## What exists already (verified this session — this is a diff, not a rebuild)
- **Reminders:** `app/Modules/Marketing/Cron/invoice_reminders.php` (the `public/crm/cron` file is a shim): max 3 reminders,
  3-day cooldown, per-invoice subjects "Invoice X: $ due DATE / is due today / is N days past due", link-only (Tim 2026-10-06),
  SMS without URLs. **`ReminderGate::decide()`** (live since 2026-11-02, mode `on` 2026-11-03) already holds reminders for
  AP inboxes, before the payer's grace (p75 of last 6 days-late + 3), an unlinked deposit equal to the balance, and stale
  statements; holds go to `invoice_reminder_holds` (migration 1424). `PaymentOnTheWay` covers pending e-Transfers.
- **Duplicates:** `ExpenseLookupService::findDuplicates` (same vendor, total ±$0.01, ±3 days), `DuplicateReceiptService`
  (same image / same ticket number), `TwinFinder` + daily `TwinCheckService` (approved twins ≤3 days, bad bank links → Penny
  questions, 2026-10-29). `RecurringBillService::detect` flags a recurring bill that moved **>15%**. `AnomalyDetector`
  flags HIGH_AMOUNT (>2× category monthly average) and **every** expense with no `receipt_media_id`.
- **Forecast:** `CashFlowForecastService` (13 weeks; per-client median lateness over 12 months, ≥2 payments, clamped 0–90;
  recurring bills; biweekly bank spending; opening balance from the bank; "like last year" view). **No confidence band.**

## ⚠️ Branch state (decide before any code)
`ReminderGate.php`, `InvoiceReminderRules.php`, `AutomatedInvoiceInbox.php`, `TwinFinder.php`, `TwinCheckService.php`,
`ReminderGateTest.php` and the live `PennyQuestionService` additions exist only on **`claude/penny-reminder-grace`**
(11 commits ahead of `feature/language`, 4 behind) and on production. `feature/language` cannot run the live reminder cron.
**Proposal:** step 0 merges `origin/claude/penny-reminder-grace` into `feature/language` in the build worktree (tests green),
pushes it, and only then builds. This is the same scar as the Decision-Log entry of 2026-11-02 (Codex removed a live filter
because the branch lacked the file); the fix is to stop the divergence, not to pull single files.

## Piece A — reminder tone by payment history, under ReminderGate
1. **`PayerTone::classify(array $paidInvoices, string $today): array`** — pure static in `app/Modules/Invoices/Services/`.
   Input: the payer's invoices paid in the last 12 months (due_date, paid date = `invoice_payment_allocations.payment_date`,
   else `invoices.paid_at`). On time = paid on or before due. ≥3 invoices and ≥75% on time → `gentle`; late in 25–50%, or
   <3 invoices → `neutral`; late >50% → `firm`. Returns tone, counts, on-time %, and a one-line reason.
   Payer = `ReminderGate::payerKeyFor()` (company or contact), the same key the grace rule uses.
2. **Wording per tone** in the cron (link-only stays; phone number stays; one call to action):
   gentle "Quick reminder: Invoice X for $Y" + one acknowledging sentence; neutral "Following up: Invoice X — $Y past due",
   facts only; firm "Past due notice: Invoice X — $Y (N days overdue)" + one deadline sentence (due date + grace, never a threat).
   SMS keeps its wording. Approaching / due-today reminders are untouched (tone applies to overdue only).
3. **Consolidation:** when more than one overdue invoice for the same payer passes the gate in one run, send one email listing
   each invoice and the combined total, using the payer's tone (plugin rule). Reminder counters update per invoice as today.
4. **"Paid in the last 14 days" → a new gate reason `recent_payment`** inside `ReminderGate::decide()` (after
   `deposit_candidate`): any allocation `payment_date` for the payer within 14 days holds the reminder and records the hold.
   This is a certainty rule, so it belongs in the gate, not the tone class.

## Piece B — bill and receipt controls (small; mostly already built)
Only two plugin rules are missing; both become Penny **questions**, never automatic changes:
1. **`repeat_amount`:** approved expense with the same vendor key and same total as another approved expense **4–90 days**
   apart (inside 3 days `TwinFinder` already asks). Daily, in `TwinCheckService`, one question per pair, dismissable
   ("Different purchases" teaches nothing beyond the pair — some vendors bill identical amounts monthly, so it surfaces only).
2. **Thresholds for Tim (no code until answered):** recurring-bill change flag 15% (ours) vs 10% (plugin); duplicate day span
   3 (ours) vs 5 (plugin); no-receipt flag on every expense (ours) vs only over $25 (plugin). Recommendation: keep 15% and 3
   days (fewer false alarms), add the $25 floor to `checkNoReceipt` so sub-$25 parking and coffee stop scoring 15.
3. **Auto-code after N agreeing bills:** Penny's bank-rule learning promotes after **2** confirmations from different lines
   (`BankRuleLearning`); the plugin says 3. Recommendation: keep 2 — it is Tim's standing "twice = rule" and already in the
   operating rules. No change.

## Piece C — cash-forecast confidence band
1. **`CashFlowForecastService::band(array $lagsByClient, array $inflowByClient): array`** — pure static. Per client:
   lag = days from due date to allocation payment date over 12 months; band% = stddev/mean; **mean ≤ 1 day → 5%**;
   **<3 payments → 30%**; weighted by each client's expected inflow; **capped at 50%** and flagged "too thin to model" when
   capped. Output `low`/`high` per week next to the existing forecast line; `cashflow.php` draws the band, Penny's brief says
   "±N%" once.
2. **Fixed costs:** verify `RecurringBillService::detect` uses "same vendor 3+ consecutive months within 10%"; if it uses
   something looser, align the forecast's fixed-cost list to that rule and show the list on the page ("treating these as
   fixed — right?"). Never assume an opening balance: if the bank balance is missing the page already says so — keep.

## Unknowns and how each is settled (cheapest first)
1. **Does `invoices.payment_date` exist?** The forecast's COALESCE fallback is unverified. → `SHOW COLUMNS` via
   `scripts/prod-run.sh` snippet (read-only) before writing any query.
2. **How the cron groups per invoice today** (for consolidation) → read `invoice_reminders.php:60-160` on the merged branch.
3. **`RecurringBillService::detect` criteria** → read the function (one file).
4. **Whether tones match Tim's sense of each payer** → dry run: `prod-run.sh` snippet lists every payer with an overdue or
   open invoice, their 12-month on-time %, the tone, and whether `recent_payment` would hold them today. Tim reads it
   before anything sends. Expect the stratas/PMs to come out neutral or firm by history; the gate still spaces them.
5. **Band widths on real data** → same dry run prints per-client mean lag, stddev, band%, and the weighted total band.

## Steps and verification
0. Merge `origin/claude/penny-reminder-grace` → build branch; `vendor/bin/phpunit` green; push `feature/language`.
1. `PayerTone` + `tests/Unit/Invoices/PayerToneTest.php` (one test per band, the <3 rule, on-time = due day).
2. `recent_payment` in `ReminderGate::decide()` + test in `ReminderGateTest.php` (sqlite PDO stub already there).
3. Cron: tone wording + consolidation; render the three emails locally with fixture data (no send); DraftSafety-style test
   that the firm template has one call to action and no second deadline.
4. `repeat_amount` in `TwinCheckService` + test; `$25` floor in `AnomalyDetector::checkNoReceipt` + test (after Tim's answer).
5. `band()` + test; `cashflow.php` band line; brief wording.
6. Dry run on production (read-only) → Tim reads tones, holds and bands.
7. Codex review of every code file; fix real findings. Deploy one file at a time, gate and services first, cron last;
   OPcache reset; watch the next reminder run's `invoice_reminder_holds` for `recent_payment`; check cashflow page live.
8. Vault + Penny skill: `references/one-place.md` (PayerTone, band), `traps.md` if anything bites, `surfaces.md` unchanged
   (web-only), bump skill version; `HeadSkillsTest` green.

Out of scope: any tax rule from the plugin (none covers GST/HST/PST/CRA); SMS tone; reminders attaching PDFs (stays link-only).
Roughly 350 lines of PHP + 200 of tests.

---

# Part 2 — the accountancy skills (added 2026-11-03 on Tim's ask)

Surveyed in full: small-business month-end-prep, close-month, ap-processor, pay-the-bills, cash-flow-snapshot,
payroll-prep, plan-payroll, report-builder, report-pack, business-pulse, tax-season-organizer; finance plugin
reconciliation, variance-analysis, financial-statements, journal-entry(-prep), close-management, audit-support, sox-testing.
Everything US-tax (SE tax, 1099, safe harbour, W-9), SOX scoping and multi-entity consolidation is skipped.

## Already in Penny (verified against the code this session — not to be rebuilt)
- Month close with statement proof, books-vs-bank drift to the cent, open receipts, unsorted bank lines, a grace period, never
  locking automatically (`MonthCloseService`, `StatementCloseService`, `BankBalanceCheckService`, `MonthCloseListService`).
- Stripe payout → invoices + fee (`StripePayoutService`), e-Transfer and Yardi EFT pollers, deposit ↔ invoice matching
  (`InvoiceReconciliationService`, `BankInvoiceMatchService`), duplicates and twins (see Part 1), nightly ledger sync with
  named failures, Wave payroll month → journal with per-shareholder sweeps, GST report via `TaxEngine`, the accountant's
  fenced login and journal source documents, cost-spike / revenue-drop / low-margin / unusual-expense alerts (`AlertEngine`).

## What the accountancy skills add (ranked; each is its own small piece)
| # | Piece | The rule, as harvested | Where it lands |
|---|---|---|---|
| D | **Settlement match statuses and tolerances** | net payout vs bank deposit: amount within $0.50 **and** date within 2 days = reconciled; <$0.50 noted not flagged; ≥$0.50 flagged with the delta; five states `reconciled / missing_in_ledger / unmatched_deposit / date_mismatch / unreconciled_in_ledger`; match on payout **arrival** date; refunds match credits, never deposits; holds listed as next-month items (`month-end-prep/SKILL.md:92-106`, `reference/paypal-settlements.md`) | `StripePayoutService` + the e-Transfer matcher report the five states; the close shows counts per state |
| E | **Close gates, carried items, "n/a — source"** | no packet or forecast while flags are open; deferred items go to an Action Items list, never dropped (empty = "No open action items — books are clean"); sending to the accountant is a separate approval; every missing source is named as "n/a — <source>", never estimated or carried forward (`month-end-prep/SKILL.md:181-195`, `close-month/SKILL.md:25-61`, `report-builder` gotchas, `shared/absent-is-not-zero.md`) | `MonthCloseService::blockers()` gains the carried-items list; `MonthCloseListService::email()` names n/a sources; forecast refresh runs only after the month is locked |
| F | **P&L narrative + variance explanation format** | 150–250 words, six parts: headline, revenue, margin, expense lines moved >10% month-over-month, bottom line, watch list; each variance = line, favourable/unfavourable $ and %, driver, outlook (one-time / continues / improving / deteriorating), action; banned: "timing" or "one-time" without saying what, "various small items" on a material line; thresholds scaled for us: 15% or $50K vs prior period, 10% vs budget (`month-end-prep/SKILL.md:197-212`, `variance-analysis/SKILL.md:106-159`) | a new `CloseNarrativeService` (pure text from P&L rows); the close email and Steve's year-end packet get it; facts only, Penny writes, Tim approves |
| G | **Payroll import checks** | before posting a Wave month: OT above 125% of the person's trailing 4-period average (or any OT for someone who has none); hours >10% above schedule; any day >16 h; **any shift >12 h fires even on a first run**; 7 consecutive days; rate change; run gross moved >10% from the prior run; person missing or new; never fill a punch; entry balances with **no suspense plug**; net pay = bank debit; period follows pay date; payroll expense vs ledger difference >$0.50 is an item, totals only, never names in the packet (`payroll-prep/reference/anomaly_rules.md:28-82`, `books_sync.md:31-54`, `month-end-prep/SKILL.md:172-179`) | `WavePayrollImportService::matchMonth()` returns flags in who / when / both numbers / dollar form; the timeclock half (12 h shift, 7 days, OT vs own average) can also run weekly on `time_entries` for Otto |
| H | **Reconciling-item taxonomy and ageing** | every open bank line is one of timing (clears in 1–5 business days, no entry), adjustment required (fee, interest, wrong amount/account, duplicate, missing entry) or investigate (unidentified, disputed, aged, recurring); ageing 0–30 current, 31–60 investigate, 61–90 escalate, 90+ write off or adjust; **any unreconciled difference blocks the close**; three periods of growth = fix the process (`finance/skills/reconciliation/SKILL.md:90-162`) | Penny's bank desk labels each waiting line with its class and bucket; the close list counts them; the 90+ bucket becomes a Penny question |
| I | **Journal-entry pre-post checklist** | balanced; open period; valid accounts; supported maths; clear memo; consistent with prior periods; accruals auto-reverse; within authority; **not a duplicate**; unusual or round amounts explained; out-of-period adjustments always need the top approver (`finance/skills/journal-entry-prep/SKILL.md:131-186`) | `LedgerSyncService` / manual journal screen: a `JournalEntryCheck` run before any posting; the closed-year guard already covers "open period" |
| J | **Receipt/AP coding confidence** | auto-code only when vendor history agrees across ≥3 prior items and nothing on this one contradicts it (our lessons promote at 2 — decision for Tim); no even job split, code to the likeliest job and flag "unsplit"; capitalisation threshold asked once and remembered; card charges coded, never staged as payable; a low-confidence total never enters a payment or match; footing check lines + tax + freight = total else show both; statements find gaps, never create entries (`ap-processor/reference/coding_rules.md:7-48`, `intake_and_extraction.md:39-49`, `matching_and_exceptions.md`) | `ReceiptBookkeeperRules` / `ExpenseSplitService` / `AnomalyDetector` (footing check as a flag); the capitalisation threshold as a Settings value |
| K | **Instalments and the packet header** | annualise YTD ÷ months × 12 only after asking about seasonality (landscaping is seasonal); a missed instalment on its own line, never estimate penalty or interest; packet states the year, the assumptions, and a "not applied" list (`tax-season-organizer/reference/calculation-assumptions.md`) | Penny's instalment card for Steve's 2026 instalments (memory: `reference_accountant_and_tax_instalments.md`) |
| L | **Business-pulse finance bands** | A/R green none >31 d, yellow 31–60, red 60+ or the 61+ bucket >10% of A/R; revenue MoM green ≥0, yellow to −5%, red below; runway ≥6 mo / 3–6 / <3; overall = worst, forced red if a named client + amount + deadline within 7 days; retune after 4 weeks (`business-pulse/reference/thresholds.md:13-71`) | `OwnerFreedomService` / Kaname's brief (overlaps harvest piece 5) |

## Recommended order (after Part 1 A–C ship)
D (payout statuses) → G (payroll checks) → E (close gates + n/a) → H (bank-line classes) → F (narrative) → I → J → K → L.
Each: plan section above is the spec; pure class + tests; prod-run dry run; Codex; deploy; vault + Penny skill one-place rows.
Decisions for Tim before D starts: keep learning at 2 or move to 3 for coding (J); the $50K / 15% variance floor for our size (F).

## Dry run 2026-11-03 (read-only, 13 overdue payers / 271 approved expenses / 427 payment lags) — what it changed
- **Tones:** 5 firm, 5 neutral, 3 gentle; nine payers have more than one overdue invoice and now get ONE email. The 14-day
  hold caught the case the plugin warns about: a gentle payer who paid the day before would otherwise have been chased.
- **Repeat amounts:** the plugin rule alone asked 48 questions, mostly $200 fuel prepays and $84 Lawnboy loads. Two cuts,
  both Claude's judgement (tell Tim): an amount a vendor charges 3+ times in the window is habitual and never asked about
  (`RepeatAmountFinder::HABIT_COUNT`), and nothing under $25 is asked (`MIN_AMOUNT`). Result: 3 questions.
- **Band:** the plugin's stddev ÷ mean scored a client who pays early 26 times and 12 days late 3 times at the 50% cap.
  Replaced by the share of a client's payments more than 7 days off their own median lag (lags clamped at 0). Kept: <3
  payments → 30%, cap 50% + "thin", inflow-weighted overall. Live: ±22.1% overall; biggest payer 10.3%.
- The $25 floor on the missing-receipt flag silences 3 live expenses.

## Part 2 — D built 2026-11-03: Stripe settlement states on the month close
`SettlementStatusService` (pure `classify()` + cached `report($ym)`, read-only Stripe `payouts->all`) gives each payout of a
month one of the plugin's states; `MonthCloseService` adds them to the last 3 open months' blockers ("9 Stripe payouts in the
bank but not booked yet", "N not on any bank line — a statement may be missing", "N Stripe-looking deposits with no payout").
Amounts match to the cent (Stripe pays the exact net; the plugin's $0.50 is for processors that round); date ≤2 days = same
settlement, 3–5 = date_mismatch. **Dry run (Aug–Oct live):** the first pass called 10 payouts "missing" and 15 deposits
orphans — the payouts had been booked at import as single-invoice matches (type transfer, `pi_…` reference), and the
"deposits" were Stripe fee expense rows sharing the description. After the fix: Aug 14/14 reconciled, Sep 14/14, Oct 5
reconciled + 9 waiting on Penny's card. e-Transfers already carry their own states (pending/recorded) and are left alone.

## Part 2 — G built 2026-11-03: payroll-run checks on the Wave import page
`PayrollRunChecks` (pure) reads each loaded run against the runs before it and its clock punches: OT above 125% of the
person's last-4-payday average (or any OT where there was none), wages or the run total moved >10%, implied rate
(wages ÷ weighted hours) moved >2%, zero hours / missing person / new person, any punch >12 h (fires on a first run),
any day >16 h, seven days straight. Flags read who / when / both numbers / impact (rate from `users.hourly_rate`, else
"rate unknown"), grouped by person, unknown hours first; shown under "Before you book" on the import page; nothing
blocks booking. Not done: hours against schedule (no per-person scheduled-hours figure exists), rate history (none
exists; the implied-rate check stands in). Net pay = bank and the balanced journal were already enforced.
**G dry run (nine live runs) and Codex:** raw "wages moved >10% vs prior run" flagged nearly everyone every month (a Wave month
holds 2 or 3 biweekly paydays; landscaping hours swing with the season) and historical months carried dozens of forgotten
clock-outs. Now: wages per payday, and only when the hours do not explain the change; implied rate at 5% against the person's
median over prior runs (stat/vacation pay moves it a little every month); punch flags capped at 3 per person; checks for
preview months only. Result: 4 flags each for the two open months (new/missing people, one rate, the season). Codex: overnight
punches now split across the days they cover, DST-safe streaks, accent-safe name keys, a run dropping to zero still flags.

## Part 2 — E, H, F, I, J, K, L built 2026-11-03 (one batch)
- **E close gates / carried items / n/a:** `MonthCloseListService::items()` now carries `since` (first date each blocker appeared,
  kept per month in ops_settings, keyed by `itemKey()` — account codes kept, amounts and counts not), `na` (every source that
  could not be read: Stripe, receipts, bank lines, the P&L) and the email says "open since Oct 20" after 7 days and "Not checked
  this time: …" rather than looking clean. The plugin's packet/forecast gate has no equivalent here (no packet is produced).
- **H bank-line classes and ageing:** `BankLineAge` — timing / adjustment / investigate and 0–30 / 31–60 / 61–90 / 90+; every
  waiting line on Penny's bank card carries `age` (chip at 61+ days); the close blockers say "N bank lines over 90 days unsorted ($X)".
  Live: Jan–Jul 2026 lines are all stale (11 / 5 / 36 / 15 / 15 / 16 / 15), August has 39 at 61–90 days.
- **F narrative:** `CloseNarrative::write()` from `AccountingService::getProfitLoss()` for the month and the prior month, in
  Charlie's 7 am close email ("The month in plain words"). Balance-sheet rows (GST collected, card payable) are excluded; a sign
  flip or two losses are said in figures, never as "down 308%". October live: a CAD 5,849 loss, five expense lines moved >10%.
- **I journal checklist:** `JournalEntryCheck` — balanced, accounts present, memo, locked month, >60 days back, future date,
  round estimates, accrual reversal, duplicate of a posted entry — as warnings under the payroll page's journal entry.
- **J receipt coding:** `checkFooting` (line items adding up to MORE than the receipt; 7 live receipts, OCR doubled lines)
  and `checkCapitalAsset` (equipment-like category at ≥ $500, the figure the receipt line rule already uses; override once in
  ops_settings `capitalization_threshold`). Auto-code stays at 2 agreeing confirmations (Tim's standard).
- **K tax note:** `TaxInstalmentNote` via `CashFlowForecastService::taxNote()` on the cash-flow page: fiscal YTD net, annualised
  only beside the seasonal caveat, rate only from `ops_settings tax_rate_combined` (none on file → "not estimated"), instalments
  from the cashflow settings; payment of instalments is not tracked, so no "missed" verdict is given.
- **L pulse bands:** `PulseBands` on the Owner Freedom report: A/R red (21 invoices past 30 days, $3,181 past 60, each named),
  revenue +11% green, runway n/a on purpose (the report never reads the bank), overall red.
- Codex: 6 findings, 5 fixed (item keys, every unread source named, journal checks get real posted entries and locked months,
  empty A/R is green, calendar months for the tax note); declined padding a clean month's narrative to 150 words.

## Next for Sam (Tim's question 2026-11-03) — from the map, not yet built
Piece 2 client rhythm (quiet = gap > 2× the client's own median, gone > 3×; win-back 0/+10/+28, no pitch first, stop on reply),
piece 8 quote pricing from 2–3 comparables with the arithmetic shown and a >15% rate-card flag, piece 9 outreach cadences and
the voice profile. Two head-to-head links worth building: Penny's payer habit → Sam's quote terms (slow payers get a deposit
line), Sam's hot pipeline → Penny's forecast. Not yet surveyed: the sibling sales plugin (win-loss review, renewal radar, deal
signals, customer health, call prep, objection handling) — a second survey like today's would say which rules are new.

## Sam finished 2026-11-03 (Tim: "finish Sam", "complete them entirely")
Built and live: **ClientRhythm** (quiet > 2× / gone > 3× the client's own median purchase gap from invoices, off-season gaps
ignored, in-season switch from `ops_settings business_seasonal` + season months; ranked by 12-month spend; a no-pitch "Checking
in" draft; `ClientTier` fit × engagement with a stated cutoff and a motion) on Sam's card and in Charlie's brief; **RenewalRadar**
(120 days, 90 for small contracts; risk up on 30+ days' silence, verdict, −90/−45/−14 timeline) from `contracts`; **quote
hygiene** (no amount / no service); **SamSignals** digest headline (empty = success); **ObjectionReply** hints with a 3–5 sentence
draft on unanswered replies; **ComparablePricing** beside the Closer's price (whole-job comparables, median, >15% rate-card flag);
**Penny → Sam** payer notice above the terms on quote create (tone + p75 lag; "consider a deposit line" when firm or ≥21 days);
**Sam → Penny** pipeline sentence on the cash-flow page (open quotes × win rate, dated by days-to-yes + terms + lateness);
**respond_by** on leads (hot today / qualified 48 h / unclear this week).
Dry run on live data: 70 clients with a rhythm, 21 quiet or gone (the top one worth $6.7K/yr, last seen June); 12 contracts
inside the radar, 5 "needs attention" for no contact on record; objection classifier fired on 2 of 40 replies (conservative by
design); 14 open quotes worth $29K. Not built: hours-against-schedule style signals (no data), win-loss review (needs loss
reasons recorded first — a `lost_reason` on quotes is the prerequisite; added to the backlog).
