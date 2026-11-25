# Plan — The Accountant's Ledger (everything a CPA needs, export with ease)

Status: **steps 1–2 BUILT 2026-10-13** — step 1 ee7cb50e (migration 1355); review fixes 0df28f4a (migration 1356: lock history, late_for, fiscal_year_end, crm_url); step 2 comparative TB, GL, exports, QBO journal CSV. Steps 3–8 not started. This comes before the bad-debt write-off,
which becomes the first real accountant's adjustment in it.

## Why
- Under CSRS 4200, Sit Lim must reconcile our records to the statements they compile, including
  every adjusting entry.
- They then re-key our numbers into GIFI for the T2, and keep their own copy in QuickBooks Online.
- The goal is that they never re-key anything. Every number traces back to its source document;
  every adjustment flows both ways; every export opens cleanly in their tools.
- Built per business, so it carries over to Ittai: each tenant invites its own accountant, and a firm
  serves many clients from one login.

## What it must do (the CPA's toolkit)

**Views**
- **Trial balance:**
  - columns for unadjusted, proposed AJEs, AJE, adjusted, prior year (and the year before);
  - GIFI and lead-sheet group on every line;
  - must balance, or it refuses to export.
- **General ledger:** by account and period, with an opening balance. Every line clicks through to
  the source document: invoice, receipt photo with Penny's notes, bank-statement line, payroll run,
  or Jobber import row.
- **Journal:**
  - every entry, numbered and typed (system / manual / AJE / RJE / reversing);
  - shows who made it, who approved it, and when;
  - shows reversals linked to their originals.
- **AR and AP aging** at any date (current / 30 / 60 / 90 / 120+), tied to the control account.
- **Bank and Visa reconciliations** per month: statement balance, outstanding items, difference = 0,
  and the statement attached. This reuses Penny's tie-out, upgrade step B1.
- **Fixed-asset register** feeding Schedule 8:
  - class, rate, cost, acquired and available-for-use dates, business-use %, 10.1 flag,
    disposals, AIIP/RIIP flag, opening UCC;
  - links to Otto's equipment register (built 2026-10-13).
- **Sales tax:**
  - GST worksheet (lines 101/105/108/109);
  - BC PST worksheet (collected, self-assessed, commission; no input credits; nil returns tracked);
  - each tied to its GL control account.
- **Payroll:** a monthly table of deducted vs remitted vs the PD7A statement, year to date tied to the
  T4 Summary. Mowology runs payroll in Wave, so Wave's figures are the input.
- **Shareholder loan:**
  - continuity per shareholder;
  - each draw shown by year, with its s.15(2) must-repay-by date;
  - a flag on a repay-then-reborrow pattern.
- **CRA instalments:** a log (date, amount, confirmation) reconciled to the tax account. It shows
  missed instalments, e.g. the RC schedule $3,690 then $875/month.
- **Accruals and prepaids:** opening, additions, amortization, closing. Phase 2.

**Canada-specific**
- **GIFI:**
  - every account carries a stable account number and a GIFI code, set on a mapping screen;
  - a validator blocks export if anything is unmapped or the required totals don't recompute
    (2599, 3499, 3620, 3849, 8299, 9368, 9999);
  - amounts in whole dollars.
- **Small business deduction:** a short questionnaire (associated corporations, taxable capital,
  passive income), not ledger data.

**Adjustments**
- **The accountant proposes entries** (PAJE) with a note. They show in a "proposed" column without
  touching the books.
- **Tim approves** (in Penny's queue) → it posts as AJE-2026-nn. Optionally, Tim lets the accountant
  post directly.
- **Reversing entries** link to the original and post automatically on the first day of the next
  period.
- **Locks:**
  - a monthly soft lock and a year-end hard lock;
  - reopening needs a named person and a reason;
  - an automatic **exception report** lists every change inside a locked period.
- **Audit trail:** append-only; no hard deletes; corrections only by reversal.
  Already partly built: the 2026-10-12 audit trail and hash-chained export.

**Exports**, one click each. Every file carries entity, period, run time and lock status; no merged
cells; numbers stored as numbers.
- **XLSX/CSV/PDF** of every view and schedule.
- **QuickBooks Online journal import CSV** (Canada format: Journal No., Date, Account Name, Description,
  Debits, Credits; Parent:Sub). The monthly summary journal lets Sit Lim keep their QBO in step
  with no typing.
- **TaxCycle GIFI** (.CSV/.GFI). The exact layout comes from the sample file in Sit Lim's TaxCycle.
- **CaseWare** Working Papers and Cloud trial-balance CSV/XLSX, if they use CaseWare.
- **Xero** manual journal CSV, for Ittai tenants whose accountant uses Xero.
- **PBC year-end package**, one zip with an index:
  - adjusted TB and GL;
  - bank and credit-card reconciliations with the December statements;
  - AR/AP aging;
  - asset additions and disposals with their invoices;
  - loan statements;
  - shareholder loan continuity;
  - GST and PST returns with their reconciliations;
  - PD7A, T4 Summary and the payroll reconciliation;
  - the instalment log.

**Working papers** (phase 2)
- Lead sheets with variance explanations.
- Tickmarks, preparer and reviewer sign-off.
- Notes and attachments on any line.
- **A question thread tied to a GL line** ("Ricole asks, Tim answers, Ricole resolves"), instead of
  email.

**Access**
- An **Accountant** role:
  - read-only by default, and may propose entries;
  - never sees payroll SINs or bank credentials;
  - every view, export and change is logged.
- Owner-revocable.
- For Ittai: a firm login with a client switcher and staff seats.

**Design**
- Calm, dense and professional, closer to a modern accounting tool than a CRM screen.
- Fast tables with keyboard navigation; drill-down in place; laptop first, readable on a phone.
- Built with the website-design skill, CRM brand tokens and the CRM's page rules.

## Unknowns, and how each is settled (cheapest first)

| Unknown | How it's settled |
|---|---|
| What software Sit Lim use (CaseWare? TaxCycle or Taxprep? QBO only?) | **One email to Ricole** (draft below, sent only with Tim's yes). It decides which exports come first. |
| The exact TaxCycle GIFI CSV layout | Ricole sends TaxCycle's "Sample QuickBooks GIFI Export.csv", or we match QBO's GIFI export, which TaxCycle accepts. |
| Whether the existing report pages agree with the journal | Recompute the TB, balance sheet and income statement from journal_lines on the local copy and on production (read-only), then compare. |
| Whether every journal line can link to its source | Check journal_entries.source_type/source_id coverage by type on production (read-only); fill the gaps. |
| GIFI code for each of our accounts | Map from CRA RC4088 and the 2025 filed statements' groupings; Ricole confirms the list once. |
| RIIP / Bill C-15 status and the 2026 class 10.1 limit | Store the flags only; TaxCycle calculates. Confirm with Ricole. |

## Build order (each step tested on the local site, then deployed)
1. **Foundation:**
   - accountant role and login;
   - stable account numbers + the GIFI mapping screen and validator;
   - the journal browser with source links;
   - audit trail and exception report on locked periods.
2. **Comparative trial balance + GL drill-down,** with XLSX/CSV/PDF exports and the QBO journal CSV.
3. **Proposed adjustments → Tim approves → posted AJE.** Reversing entries; period locks with reasons.
   *The bad-debt write-off is posted here as AJE-2026-01, Ricole-visible.*
4. **Bank/Visa reconciliation reports** (from Penny's B1), plus **AR/AP aging**.
5. **GST/PST worksheets, payroll (Wave) reconciliation, CRA instalment log, shareholder loan
   continuity.**
6. **Fixed-asset register → Schedule 8** (linked to Otto's equipment).
7. **TaxCycle GIFI export + CaseWare TB export** (per the answer from Ricole), then the **PBC zip**.
8. Phase 2: working papers, the question thread, accruals/prepaids, the firm console for Ittai,
   and direct QBO/Xero API sync.

## Draft email to Ricole (send only on Tim's yes)
> Hi Ricole, thank you so much for the quick reply. Writing off the older balances and matching the
> opening A/R to Jobber works perfectly.
>
> I'm setting up an accountant's view of our books so you can get anything you need without
> re-keying (trial balance, GL, bank recs, GST/PST, exports). So I build the exports in the right
> format, could you tell me which software you use for year-end and tax? For example: QuickBooks
> Online only, CaseWare, TaxCycle or Taxprep. If you use TaxCycle, its sample GIFI import file would
> be very helpful.
>
> No rush at all. Thank you again!
> Tim
