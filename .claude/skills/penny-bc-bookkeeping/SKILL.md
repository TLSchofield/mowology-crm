---
name: penny-bc-bookkeeping
description: Use BEFORE touching anything money-related in the Mowology CRM / Ittai — Penny (the AI bookkeeper), receipts and OCR, expenses, GST/PST/ITCs, invoices and payments, bank or credit-card statements, deposits, Stripe payouts, e-Transfers, Yardi/SparcPay remittances, the ledger/journal, cash-flow forecast, payroll remittances, ROEs, T4s, WorkSafeBC, year-end, or any question about Tim's books. Also when Tim asks "how much…", "why is income…", "is this paid…", or reports a wrong flag on a receipt. Holds British Columbia bookkeeping rules, how this ledger works (and where it already double-counted), how Penny is allowed to operate, the single source of truth for each job, and the traps already hit.
metadata:
  version: 1.0.0
  created: 2026-10-11
  owner: Tim Schofield (Mowology) — tax facts to be confirmed by Steve Lim CPA (Sit Lim CPA)
---

# Penny — BC bookkeeping for Mowology (and Ittai)

Penny is the CRM's bookkeeper head. This skill is what a careful human bookkeeper would know
before touching Tim's books: the **province's rules**, **how this ledger works**, **how Penny is
allowed to act**, and **where each job lives in code**. Read the reference that matches the task:

| Task | Read |
|---|---|
| GST/PST/ITCs, receipts, payroll deadlines, ROE, T4, WorkSafeBC | `references/bc-rules.md` |
| Income, deposits, payments, Stripe, Jobber era, journal vs P&L, tables | `references/ledger.md` |
| What Penny may do, learn, flag, chase; approvals | `references/operating-rules.md` |
| Traps already hit (read before ANY write to the books) | `references/traps.md` |
| iPhone vs Android vs web — keeping Penny the same everywhere | `references/surfaces.md` |

## Before and after every change (Obsidian + Graphify)

**Before** writing or changing anything money-related:
1. Read the Obsidian notes for the area —
   `~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/`
   `Accounting-Ledger.md` first, then `grep -n -i "<area>" Known-Failure-Patterns.md`
   (receipts, deposit, stripe, gst, payroll, jobber…) and the Decision-Log entry if one exists.
2. Graph what you are about to touch: `python3 ~/.claude/tools/graphify.py touches <function>`
   (who calls it — every screen/app a change reaches) and `… file <path>` (what it uses).
   If the function is called from several places, change it once there; if the same rule is
   written in several places, say so and fix all of them (the GST rule was in two).
3. On production data: `scripts/prod-run.sh` preview first, then `--commit`.

**After** the change:
1. A mistake found or a rule learned → `references/traps.md` (this skill) and
   Known-Failure-Patterns.md (Obsidian) the same day; a "why it's built this way" → Decision-Log.
2. A Penny feature changed on one surface → update `references/surfaces.md` (and the other
   surfaces, or record the gap there).
3. Names, paths or rules in this skill changed → update the skill in the same commit
   (`tests/Unit/Docs/PennySkillTest.php` fails otherwise).
4. `/obsidian` (bare) at the end of the session writes the rest back.

## The five rules that are never broken

1. **Never claim a fact about the books you did not check this session.** Cite the query or
   file. "Unverified" is an acceptable answer; a confident wrong one is not (Tim, 2026-10-11).
   Before saying something is missing, broken or double-counted, search the code (`graphify
   touches <symbol>`) and this skill — it is usually already handled (Stripe payouts are).
2. **Income is counted once — on the invoice.** A bank deposit that pays an invoice is a
   cash-clearing *transfer*, never a second income line. Jobber-era deposits (before
   2026-02-25) are the only record of that income and stay income. See `references/ledger.md`.
3. **Penny never moves money and never enters banking details.** She proposes; Tim approves.
   Mobile receipt capture is capture-only (approval happens on desktop or in Penny's queue).
4. **Every write to the live books is previewed first.** Use `scripts/prod-run.sh` (one
   transaction, rolled back unless `--commit`). Read every function a write path calls before
   using it — `InvoiceReconciliationService::detach()` un-pays invoices (see traps).
5. **Penny learns only from confirmed facts.** Owner decisions and certain matches teach;
   imports, guesses and single sightings do not (thresholds in `references/operating-rules.md`).

## One place for each job (improve it there, and every screen and app gets it)

| Job | The one place | Used by |
|---|---|---|
| Receipt risk flags | `detectAnomalies()` in `app/Services/Receipts/AnomalyDetector.php` | save, desktop, Penny desk, inbox, mobile |
| Penny's plain-English reasons | `RiskExplainer::say()` in `app/Modules/Expenses/Services/RiskExplainer.php` | iPhone + web |
| Penny's receipt checks before approval | `ReceiptBookkeeperService::check()` | penny_prepare cron, desk |
| Reading a receipt | `parseReceiptText()` in `app/Services/Receipts/ReceiptParser.php` | 10 callers |
| Every expense change (create, edit, approve, lines, flags) | `ExpenseGate::apply()` in `app/Modules/Expenses/Services/ExpenseGate.php` | all writers |
| Re-reading a saved receipt photo | `ExpenseRescanService::rescan()` | web + iPhone |
| Recording a customer payment | `InvoiceReconciliationService::applyAllocation()` | bank, e-Transfer, Yardi, invoice screens |
| Tying a deposit to payments already recorded | `InvoiceReconciliationService::linkAllocationsToDeposit()` | Penny's bank card, backfill |
| Stripe payout → invoices + fee | `StripePayoutService` (Stripe is read-only) | import, income clean-up |
| GST return (GST34) | `GstReportService` | tax report, reports, accounting |
| Bank-line category rules | `BankRuleLearning` | transactions, Penny's bank desk |
| Month-end proof per statement | `StatementCloseService` (+ `StatementCoverageService` for gaps) | Penny |
| Cash-flow forecast | `CashFlowForecastService` | cash-flow page, Penny's brief |
| Orphan deposits → what they paid | `DepositBackfillService` | one-off backfill, re-runnable |
| Journal posting | `LedgerSyncService`, `LedgerAccountMap`, `JobberLedgerService` | nightly sync |

**Not yet central (known):** the 5% GST rate is written in about eight places (receipt parser,
product matcher, Jobber back-calculation, two on-screen calculators, the risk checks). Planned:
one BC tax-rules module every caller asks. Until then, a GST rule change means finding every copy
(`grep -rn "0\.05" app public/crm`).

## Keeping this skill true

- `tests/Unit/Docs/PennySkillTest.php` fails the test suite when a file, class or function this
  skill names no longer exists, or when a dated rule in `references/bc-rules.md` is past its
  review date. Fix the skill in the same commit as the code.
- When a mistake is found in Tim's books or in Penny's behaviour, add it to `references/traps.md`
  the same day (what happened, the signature, the rule that prevents it).
- Tax facts carry a source and a `verified`/`review by` date. Rates and deadlines are re-checked
  every January (and whenever CRA/BC announce a change) — with Steve Lim for anything marked
  *confirm with accountant*.
