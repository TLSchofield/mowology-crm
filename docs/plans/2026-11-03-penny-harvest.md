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
