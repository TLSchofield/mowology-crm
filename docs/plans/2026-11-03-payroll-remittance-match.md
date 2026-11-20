# Payroll remittance reminder: ask for the Wave report first, match, then (only then) alarm (2026-11-03)

Status: **PLAN, awaiting Tim's approval.** Tim, 2026-11-03: "a payroll run whose CRA remittance matches the Wave debits closes the Payroll remittance reminder" and "Kaname should be asking for the Wave payroll to be uploaded via Penny BEFORE he sweats about it not being paid."

## What went wrong
The "Payroll remittance to CRA" reminder (charlie_deadlines id 7, monthly on the 15th) only knew the date. Nobody pressed Done, so Kaname's new status called it overdue and made the whole brief RED. Wave actually remits to CRA by debiting the bank on pay day ("Wave PYRL"). September's report says $4,653.27 owed to CRA; Sep 2 ($2,303.38) + Sep 15 ($2,349.89) = $4,653.27 exactly. Done already for Oct 15 (2026-11-03, with that evidence in its note). September's run is still in `preview` (not booked) and October's report is not imported.

## The rule: three states, in this order
For the occurrence due on D (it covers the previous month M = month before D):
1. **No payroll run for M imported** -> NOT red. A quiet "ask" line from Penny's side: "Upload {M}'s Wave Wage & Tax report (Penny > Payroll import) so the CRA remittance can be checked." Kaname shows it as amber at most, worded as a request, never as "overdue". Grace: until D + 7 days the ask stays amber; it never forces red.
2. **Run for M imported, remittance matched** (the run's `remittance` equals a subset of Wave PYRL bank debits dated up to D, within the importer's tolerance) -> the occurrence is closed automatically as Done, note "Paid by Wave: <dates and amounts> = $<remittance>, matched to the <M> report". Green.
3. **Run for M imported, no matching debits by D** (or the debits fall short) -> NOW red: "Payroll remittance of $X for M: no matching Wave debit found by D". This is the only real overdue.

## Pieces (pure class + test per rule; no migration)
- `PayrollRemitMatch` (pure, `app/Modules/Accounting/Services/`): given a run row, bank lines and the due date -> `matched | short | no_report`. Reuses `WavePayrollImportService::WAVE_REMIT_RE`, `subsetSum()` and `bankLines()`, not a second matcher.
- `DeadlineService::autoClose()`, called from the existing 15-minute Charlie cron (the brief read path stays write-free): closes a payroll_remit occurrence in state 2, writes the note. Uses the existing `mark()` (user id 0/system, never a person).
- Brief wording in `DeadlineService::item()` for the payroll category: state 1 gets the ask text and priority 2; state 3 keeps priority 1 and the amount (`value`) so `BriefStatus` can force red honestly.
- `BriefStatus::forcedRed()` keeps working unchanged; the ask line carries no amount and no overdue word, so it cannot force red.
- Penny's side: the "upload" ask also appears in her brief through the existing `WavePayrollImportService::briefItems()` (check it already covers a missing month; extend only if not).

## Unknowns and how each is settled
1. Whether `bankLines()` + `subsetSum()` match September today (expect yes: two debits, $4,653.27). Settled by a rolled-back `prod-run.sh` dry run before any code ships.
2. Whether a booked-vs-preview run both count (September is preview). Proposal: preview counts for the match, because the report is real; booking stays Tim's tap.
3. Remitter type: monthly by the 15th assumed (the seed says confirm with the accountant). Not changed here.
4. WorkSafeBC (due Oct 31) stays a plain calendar reminder: no WCB payment appears in the bank, so it may be real. Tim to say how it is paid; not part of this build.

## Steps and proof
1. Dry run (rolled back) on live data for Aug, Sep, Oct: expect Aug and Sep matched, Oct = no report (ask, not red).
2. `PayrollRemitMatch` + tests (matched, short by $1, no report, report but no debits, debit after the due date, fee line not counted).
3. `autoClose()` + wording + tests; Penny ask line; Codex review; suite green.
4. Deploy dependency-first, OPcache, check Charlie's card and email: status no longer RED for payroll; the ask line shows; Nov 15 shows as upcoming.
5. Record: plan build record, Charlie + Penny skill traps, vault, Tim-To-Do (upload Oct report; book Sep).

## Not built
Booking the September run (Tim's tap), importing October (needs the PDF from Wave), any payment, any change to WorkSafeBC.

## Build record (2026-11-03)
- Built: `PayrollRemitMatch` (pure) and `PayrollRemitCheck` (read-only loader) in `app/Modules/Accounting/Services/`; `DeadlineService::askItem()` / `autoClose()` / `payrollAssessment()`; `brief()` swaps the reminder for the ask, drops a matched one, keeps a real miss red; the 15-minute cron calls `autoClose()` after `sync()`; `CharlieInboxService::fromDeadlines()` gives no Done button to a request. `tests/Unit/ChiefOfStaff/PayrollRemitMatchTest.php` (15 tests). Suite 3613 green.
- **Dry run on live (rolled back):** the July, August and September remittances each match their Wave debits to the cent (Jul $5,192.65, Aug $6,522.37, Sep $4,653.27); November's asks for October's report. Simulated Nov 10: the ask line appears (priority 2), no red; Nov 3: no payroll line at all.
- **Live card:** the status is still RED but now for WorkSafeBC ("overdue since Oct 31"), not payroll. WorkSafeBC is the same kind of date-only reminder; Tim has not said how it is paid, so it is left as it was.
- **Codex** (2 reviews, 9 findings): fixed: debits of other months can no longer make a match (claimed lines of other runs and the previous month are skipped; debits inside the month are tried before the wider window), bank coverage is read from the bank account that carries the Wave debits and ignores void rows, a $0 or missing remittance never closes the reminder, the ask item carries `overdue`. Not changed, with reasons: closing a snoozed occurrence when its debits match (it is paid); an equal-amount coincidence with an unclaimed debit of the neighbouring month (needs the real debit missing AND an equal sum; the importer accepts the same window).
- Penny already asks "The {Month} payroll report isn't imported" from the 5th (booked months only), so from Nov 8 two heads ask for October's report. Left as two lines on purpose: Penny's is her desk, Kaname's replaces the calendar line.
- Needs Tim: upload October's Wave report in Penny; book September's run (still preview); say how WorkSafeBC is paid.
