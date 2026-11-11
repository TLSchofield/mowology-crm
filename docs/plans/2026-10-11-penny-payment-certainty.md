# Plan — Penny's payment certainty before any chasing (2026-10-11)

Owner: "Penny can only chase clients that she is 100% sure haven't paid… in line with payment
understanding and linking for a client, how they pay, when they pay. It is also currently dependent
on e-transfer and direct debit comprehension and statement uploads."

Context: Charlie's brief should put **decisions**, not status, on the owner's desk (Chief-of-Staff
rules, agreed 2026-10-11). Penny's lines go first because today's three brief lines were all hers.
This plan is the gate in front of that: Penny must know what is *really* unpaid.

## Where the data stands (production, 2026-10-11)
- Overdue: **39 invoices, $10,207.93** (oldest due 2026-05-31; 1 over 120 days).
- Paid invoices since the Jobber cutover (2026-02-25): **374 ($155,504)**; only **39** carry a
  payment allocation (`invoice_payment_allocations`: 30 e-Transfer, 6 cheque, 2 card, 1 other).
  For the other 335 we don't know how or when they were paid → no client habit to learn from yet.
- Income rows since cutover: 378 booked from invoices (`reference_type='invoice'`, $156,744) and
  **194 bank deposits ($195,031) linked to no invoice** — the known double count
  (`project_income_double_count_jobber_cutover`). Linking deposits ↔ invoices fixes both problems.
- Bank data: accounts last imported between Jun 19 and Oct 5; **two accounts (ids 24, 41 — 2,370
  rows) show a last date of 2026-12-31**, in the future → a date-parsing error on import (likely
  2025-12-31 read as 2026) — must be settled before "freshness" can be trusted.
- Already live: e-Transfer + Yardi remittance pollers, Stripe webhook, deposit↔invoice matching
  (migration 1062, deposits flip to `transfer`), "Already recorded" button, `etransfer_sender_payers`
  (sender → payer), per-client lateness median (`CashFlowForecastService::lateness`).

## What Penny must know before an invoice counts as unpaid
1. **No candidate payment in any channel** — no e-Transfer, Yardi line or Stripe charge for it, and
   no unlinked bank deposit near the amount from that payer since the invoice date.
2. **Fresh evidence** — the bank account that payer uses has statements covering the days since the
   due date. If not: "can't tell yet", never "unpaid".
3. **Past the client's own habit** — a payer profile per client: method(s), usual lag after due
   date, usual payer name on the bank line, who pays (strata via PM / Yardi on the 25th …).

Each overdue invoice gets one status:
- `paid_unlinked` — a payment found; Penny links it (exact) or asks (near).
- `cant_tell` — evidence too old (statement needed).
- `late_for_them` — past their habit, nothing found → may be chased (later step, owner approves).
- `needs_owner` — large, very late, disputed or a sensitive client.

## Unknowns → how each is settled (cheapest first)
1. **Future-dated bank rows (Dec 31, 2026).** Query the rows and their `raw_row`; compare with the
   statement PDF/CSV. Fix the parser + the rows before using dates.
2. **Which account each client pays into** (and which deposits are client money vs transfers/loans).
   From the 39 allocations + `etransfer_sender_payers`; report coverage.
3. **How many of the 194 unlinked deposits match an invoice exactly** (amount + payer name/memo +
   date window) — dry-run the matcher on prod, read-only, before writing anything.
4. **Direct debit / pre-authorized payments** — does any client pay by PAD/EFT that isn't Yardi?
   Look at deposit descriptions grouped by pattern.

## Steps (each proved before the next)
1. Fix bank row dates (unknown 1) — parser test + corrected rows.
2. Backfill links: exact matches linked automatically (allocation + deposit → transfer, through the
   existing 1062 path); near matches listed for the owner in one screen. Report: how many of the 374
   paid invoices now know how/when they were paid.
3. `PaymentProfileService`: per client — methods, median lag, payer names, account. Unit-tested.
4. `PaymentCertaintyService`: status per overdue invoice (rules above). Unit-tested on fixtures.
5. Penny's brief + Charlie: replace "37 overdue invoices" with the certainty line, e.g. "Penny can
   only be sure about 9 of 39 — statements stop Oct 5. Upload October and she can sort the rest."
6. (Later, separate approval) chasing only `late_for_them`, tone per client, owner approves the batch.

## Research — what an extraordinary bookkeeper does (2026-10-11, sources below)
Tags: HAS = Penny does it; PART = partly; NEW = software can do it with the owner's approval;
OWNER = needs the owner / accountant. Corrected against what the CRM actually has.

**Daily** — receipts captured + matched to job/vehicle (HAS); duplicates (HAS); e-Transfer/Yardi
matching (HAS); **GST receipt documentation check (NEW)**: CRA Memo 8-4 tiers — under $30: vendor,
date, total; $30–$149.99: + vendor GST number + tax; $150+: + buyer name, terms, item description —
flag a deficient receipt while a corrected one is easy to get; **Stripe payout coding (NEW)**: gross
sales − refunds − fees so deposits tie to invoices and fees are booked.

**Weekly** — collections ladder 7 / 14 / 30 days, strata via the PM's payables contact with the
PO / work order (NEW — gated by this plan's certainty); bill-pay plan against the 13-week forecast
(PART: forecast exists); unbilled work incl. snow events with no invoice (PART: counted as "House",
not drafted); a three-line Monday note: cash, owed to us, due out this week (NEW).

**Payroll / monthly** — source deductions due the 15th, countdown + exact amount (PART: Charlie's
calendar has the date, not the amount); **ROE on layoff (NEW/OWNER)**: interruption = layoff or 7 days
without work, electronic ROE due within 5 days of the end of that pay period — detect from
timesheets, pre-fill, owner picks the reason code (Nigel is laid off Dec 1 every year); month-end
close of every bank / card / loan / Stripe account to the statement, differences explained (PART:
BankBalanceCheckService); no uncategorised or "ask accountant" items over 30 days (NEW); job and
service-line profit (labour × loaded rate + materials + fuel/equipment), flag under-margin jobs
(NEW); lock the closed month, log later changes (PART: FY2025 locked); fraud/error scan — new or
changed vendors, unknown payees, round transfers, cheque gaps, voids, write-offs, pay-rate or
bank-detail changes, each needing owner sign-off (NEW); one-page month pack (NEW).

**Quarterly** — GST return prepared and tied to the ledger, owner files (NEW); BC PST
self-assessment on goods bought without PST, e.g. out-of-province (NEW); WorkSafeBC quarterly
payroll report (≥ $1,500/yr assessments; assessable payroll aligns with T4 boxes 14 / 71) (NEW);
vendor/customer list clean-up + bad-debt review (NEW); instalments vs actual profit (PART/OWNER).

**Seasonal** — off-season cash bridge and reserves (PART: forecast with Nigel's layoff); snow
contract billing cadence matches the contract before winter (NEW); layoff/recall calendar: ROEs,
final pay, vacation pay (NEW).

**Year-end** — T4s + Summary by the last day of February, $10/day per late slip (NEW); accountant
package: trial balance, reconciliations, fixed-asset additions with invoices, vehicle logbooks,
shareholder loan schedule, payables/receivables lists, accruals (NEW); salary vs dividends, asset
timing (OWNER/accountant); 6-year searchable records (PART: receipt archive).

**Style** — exceptions only, each with a recommendation and one tap (= the Chief-of-Staff rule).

**Highest value next for Mowology** (research's top 5, in order): monthly close with locks (the
2026 income overstatement makes everything else untrustworthy); ROE/layoff workflow (Dec 1 layoff,
5-day deadline); job and service-line profit (strata and snow routes are where profit leaks);
GST receipt-tier check at capture; collections ladder that knows strata/PMs (after this plan).

To confirm with Steve Lim (accountant): GST filing frequency; vacation-pay payout on layoff.

Sources: CRA Memo 8-4 (canada.ca … /8-4/documentary-requirements-claiming-input-tax-credits.html);
PowerPay 2026 payroll calendar; Achkar Law ROE FAQs; BC Gov — contractors paying PST; WorkSafeBC —
report payroll and pay premiums; PowerPay — BC WCB assessable earnings; Ecosire month-end checklist;
Xero fraud-prevention tips.
