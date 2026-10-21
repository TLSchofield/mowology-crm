# Penny Payroll — plan (v2, after Codex + Fable reviews, 2026-10-21)

Owner's ask (2026-10-21): "Research audit Wave and QuickBooks and any other payroll. Build to exceed."
Scope: a payroll engine inside the CRM / Ittai for small Canadian service businesses (2–20 staff: hourly
field crew + owner-shareholders). First tenant: a BC landscaping company now paying through Wave Payroll.
Tenant-neutral by rule: no trade-specific words; province rules are data, not code.

## 1. Where we start (audited in the code)

| Have | Where |
|---|---|
| GPS time clock, per-job time, weekly timesheets with approval (pending → submitted → approved) | `time_clock_entries`, `job_time_entries`, `timesheets` (migration 120); `TimesheetApprovalService` |
| Break handling (BC meal break; unpaid tapped breaks come off before overtime) | `BreakService` |
| BC overtime split + gross pay per employee + CSV | `public/crm/timeclock/payroll-summary.php` (logic in a page file) |
| Staff pay data: hourly_rate, hire_date, address/province, DOB, SIN and bank account encrypted (AES-256-CBC, no auth tag, in an API file), TD1 federal/provincial totals | migration 550; `app/Modules/Team/Api/employees.php:50` |
| Payroll in the ledger: 5100 crew wages, 5110 employer CPP & EI, 2310 source deductions payable, 2520 net-pay clearing; shareholder pay sweep to 1300/1302 | `WavePayrollImportService` (reads Wave's monthly Wage & Tax Report PDF), migrations 1255, 1361 |
| Labour cost per job (minutes × hourly_rate) | `OwnerFreedomService` |
| PDF + email infrastructure | `PdfGenerator`, `sendCrmEmail()` |

Missing: tax/CPP/EI calculation, pay runs with approval, pay stubs, vacation accrual, stat-holiday pay,
pay calendars, remittance tracking, T4/ROE files, multi-rate pay, deductions (garnishment etc.).

## 2. What the market does (research, sources in the research notes)

Wave ($25/mo + $6/person; no Quebec; remits for you, T4s, ROE prep), QuickBooks Payroll CA (~$30–55/mo +
$5–8/person), Wagepoint ($20–40/mo + $4–6/person; direct deposit, remits, T4/ROE; weak time tracking; QBO/Xero
export only), Payworks (~$44/person; Quebec; time & attendance). Recurring complaints: Wave's 2025 processor
switch broke filings and wiped history; wrong/missed remittances (Wave, Wagepoint); Intuit holds funds for
weeks; incorrect T4s (Wagepoint); chatbot-only support; time tracking bolted on.

## 3. How we exceed them (the design goals)

1. **Hours never re-keyed.** Approved GPS timesheets become the pay run; every hour on a stub links to the
   day and job it came from.
2. **Explainable pay.** Each stub line shows its rule (e.g. "Tue: 9.5 h → 8 regular, 1.5 at 1.5× — BC daily
   overtime"; "Stat pay: average day's pay over the last 30 days = $…").
3. **Books by itself, exactly.** Each approved run posts one balanced journal entry (wages by job, employer
   CPP/EI, source deductions payable, vacation accrued, net pay), with labour cost on each job.
4. **Self-checking.** Before money moves: deductions recomputed independently and compared; year-to-date
   maxima; minimum-wage floor; every remittance reconciled against what was withheld and the bank debit;
   T4 totals reconciled to the ledger before the file is made. Penny states the check, not just the answer.
5. **Never silently wrong; warned before late.** Remittance amount and due date per remitter type in Kaname's
   brief days ahead (the engine can't see money move until the bank line arrives, so it warns before the due
   date and confirms after); a run that fails any check stays a draft with the reason in plain words.
6. **Owner-shareholders handled.** EI exemption for >40% voting control (confirm per person); pay up to
   net with the excess to their own shareholder loan (the existing sweep rule).
7. **Your history stays yours.** Every run, stub and file kept; export everything; no processor to switch.
8. **One price.** Payroll is part of the crew app the business already pays for (pricing decision is the
   owner's; noted, not built).

## 4. Out of scope (stated plainly)

- Moving money: no direct deposit, no automatic CRA payment. Staff keep being paid by e-Transfer; the owner
  pays CRA in online banking. The engine gives the exact amounts, due dates and a bank-line match afterwards.
  (A payments partner can be added later for tenants who want it.)
- Filing on the owner's behalf: we produce the T4/T4 Summary XML and the ROE XML; the owner uploads them
  (CRA Internet File Transfer / ROE Web). No accuracy guarantee is offered.
- Quebec (QPP, QPIP, Revenu Québec, RL-1). Tenants there are refused at setup.
- Benefits plans, garnishments beyond a simple fixed deduction, union rules, commission plans: later.

## 5. Unknowns and how each is settled (cheapest first)

| # | Assumption | Settled by |
|---|---|---|
| U1 | Tax formulas = CRA T4127, 122nd edition (from 1 Jan 2026) and 123rd (from 1 Jul 2026: BC lowest rate 6.14%, BC reduction basic $805 — **unverified**) | Read both T4127 PDFs + the CSV rate/claim-code tables; encode as dated data rows, not code |
| U2 | 2026 CPP 5.95% / YMPE $74,600 / exemption $3,500 / max $4,230.45; CPP2 4% to YAMPE $85,000 (**unverified, secondary source**); EI 1.63% / MIE $68,900 / employer 1.4× | canada.ca pages + T4127 tables |
| U3 | Calculation correct to the cent | CRA's PDOC calculator as the oracle: ≥40 cases (pay frequencies, claim codes, CPP/EI maxing mid-year, CPP2 band, shareholder EI-exempt, July BC change, bonus/vacation payout); fixtures checked in |
| U4 | Our results match Wave for real past runs | Replay 2026 pay periods from the imported Wave reports (`payroll_run_employees`) — every line to the cent or an explained difference |
| U5 | BC ESA: daily OT >8 1.5×, >12 2×; weekly >40 1.5× counting only the first 8 h of each day; averaging agreements; vacation 4%/6% after 5 years; 11 stat holidays 2026; stat pay = average day's pay, 30 days employed + 15 of 30 days worked; min wage $18.25 from 1 Jun 2026; semi-monthly pay within 8 days; wage statement contents (s.27); records 4 years (s.28) | gov.bc.ca pages (cited in research); check `payroll-summary.php`'s weekly rule against "first 8 h of each day" |
| U6 | Remitter type & due dates (quarterly / regular 15th / accelerated); penalties | CRA due-dates page; the owner confirms their remitter type from the PD7A |
| U7 | T4 XML schema for tax year 2026 + T619 transmittal 2026V4; electronic filing required above 5 slips (**unverified**) | CRA "Get ready to file" page + XML spec; validate our file against the published XSD |
| U8 | ROE Web payroll-extract XML 2.0, blocks per appendix; deadline 5 days after the pay period of the interruption | Service Canada user requirements + appendices; validate against the schema |
| U9 | WorkSafeBC: assessable earnings cap $127,500 (2026); quarterly if premiums ≥ $2,000; due 20 Apr/Jul/Oct/Jan + annual by 28 Feb | worksafebc.com pages |
| U10 | Shareholder >40% voting control → EI-exempt (CPP still applies) | Employment Insurance Act + the accountant confirms per shareholder |
| U11 | Record keeping: CRA 6 years (**unverified**), BC 4 years | CRA page; keep 7 years to be safe |
| U12 | Taxable benefits for crew (vehicle, phone, tools) | CRA T4130; v1 supports a manual taxable-benefit line only |

Anything not settled before shipping stays behind a per-tenant switch: the engine runs **in shadow** next to
Wave (calculates, does not post) until U3 and U4 pass for three consecutive pay periods.

## 6. Build steps (each proven before the next)

1. **Rules as data** — `payroll_rules` (jurisdiction, item, value, effective_from, effective_to, source_url,
   edition). Federal + BC 2026 (both editions) loaded from the T4127 tables; a test fails if a rule has no
   source or no date range. Our regions skill files (CA.md / CA-BC.md) corrected (5 stale items found).
2. **Hours → gross** — move the BC overtime split out of `payroll-summary.php` into
   `app/Modules/Payroll/Services/PayCalculator` (tenant province from settings); add stat-holiday pay,
   vacation pay (per-cheque or accrued, per written agreement), averaging agreements (optional). Unit tests
   per ESA rule. `payroll-summary.php` then calls the service (same numbers as today, proven by a test).
3. **Gross → deductions** — `DeductionCalculator` implementing T4127 (federal + BC; CPP, CPP2, EI with
   year-to-date maxima; claim codes from TD1/TD1BC amounts; shareholder EI flag). PDOC fixture suite (U3).
4. **Pay runs** — `pay_calendars` (weekly / bi-weekly / semi-monthly), `pay_runs` (draft → checked →
   approved → posted), `pay_run_lines`, `pay_stub_items`. Approval by permission key (admins and managers),
   never isAdmin(). Checks from goal 4 block approval with plain reasons.
5. **Stubs** — PDF wage statement with every s.27 item and the rule behind each line; emailed via
   `sendCrmEmail()` with the PDF (employee consent to electronic statements recorded); visible in the crew
   app (web / iOS / Android parity is a later step, listed per platform).
6. **Books** — one journal entry per posted run via the ledger services (5100 wages by job, 5110 employer
   CPP/EI, 2310 source deductions payable, a new vacation-payable account, 2520 net pay); e-Transfers then
   match net pay (existing bank matching); the shareholder sweep reused. Wave import stays for history.
7. **Remittances** — remittance periods per remitter type, amount due, due date in the brief (Penny's, shown
   by Kaname), match to the CRA bank debit, penalty warning before the due date.
8. **Year end** — T4 / T4 Summary XML (+ T619), validated against CRA's schema; reconciled to the ledger
   first. ROE XML on termination/interruption. WorkSafeBC assessable payroll per quarter.
9. **Security** — SIN and bank account moved to AES-256-GCM in one service (re-encrypt existing rows);
   SIN shown masked, decrypt only when making a T4/ROE file; access logged.
10. **Cut-over** — shadow next to Wave for ≥3 pay periods with zero unexplained differences; then the owner
    switches; Wave kept read-only until year-end T4s reconcile.

Each step: unit tests + `vendor/bin/phpunit`; local render as the owner role; Codex review (money); deploy
only after the owner approves the step.

## 7. Risks

- **Getting a deduction wrong costs real penalties** (CRA: 10% failure to deduct/remit, up to 20% if
  repeated or knowing). Mitigation: PDOC fixtures, Wave replay, shadow period, independent recompute check.
- **Twice-a-year rule updates** (January, sometimes July) — a missed update is silently wrong. Mitigation:
  rules carry effective dates; a run dated after the last loaded edition is blocked with "load the new CRA
  tables"; a reminder in the brief each December and June.
- **Sensitive data** (SIN, bank) — encryption upgrade, masking, access log.
- **Scope creep** into money movement and filing — kept out by section 4.

## 8. Changes from the reviews (v2) — these are requirements, not options

Both reviewers worked independently (Codex on the plan text only; Fable with the code and the web). Every
point below was checked against the cited source or code before being accepted.

**Already fixed (live 2026-10-21):** BC's overtime week runs Sunday–Saturday (ESA s.1); the code grouped
Monday–Sunday, so a Sunday shift never counted toward that week's 40 h (Fable; verified on gov.bc.ca; fixed in
`OvertimeCalculator::calculatePeriod`, commit before aee2c94e). 2026 effect estimated at ~8 OT hours for one
crew member — the owner decides any back-pay.

**Data model (step 4 grows):**
- **Earnings and deduction codes** — every line type carries: taxable, pensionable (CPP), insurable (EI),
  vacationable, counts-toward-overtime, T4 box, ROE block. Regular, overtime 1.5/2, stat pay, stat-worked
  premium, vacation paid/accrued/paid-out, bonus, retro, taxable benefit, reimbursement (non-taxable),
  manual deduction. (Both.)
- **Year-to-date from posted lines, never stored** — per employee and per business number: pensionable,
  insurable, taxable earnings; CPP, CPP2, EI, tax; insurable hours. **Opening balances imported from the Wave
  runs already in `payroll_run_employees`** so a mid-year switch doesn't restart CPP/EI maxima at zero.
  (Codex critical.)
- **Immutable posting** — a run's inputs, rule edition and results are frozen when approved; posting is
  atomic with a unique (employer, calendar, period, version) key; corrections are new adjustment lines or a
  reversal + replacement run, never edits; journal reversals likewise. (Both critical.)
- **Pay calendar fixes P for the year** (26 vs 27, 52 vs 53 decided at the year's first pay date); off-cycle
  runs (bonus-only, final pay) defined. (Both.)
- **Rule edition chosen by pay date**, not work period (the July 2026 BC rate 6.14% is a Jul–Dec catch-up, so
  stubs explain it that way). (Fable.)
- **Pay only approved time** — runs read approved timesheets; today's summary reads raw clock entries
  (Fable). Dates in the tenant's local time zone, not the DB connection's fixed offset (DST). Money in
  integer cents; YTD maxima compared on unrounded amounts.
- **Per-employee flags**: EI-exempt shareholder (>40% voting shares; CPP still applies — verified CRA),
  CPP start/stop (age 18 / 70, CPT30), vacation rate and anniversary, province of employment, consent to
  electronic stubs.

**Calculation (steps 2–3 grow):**
- T4127 non-periodic method for bonus / retro / vacation payout (tax on annual + payment minus annual);
  CPP basic exemption per period not prorated on first/last pay. (Both.)
- BC: vacation 4% of **all wages incl. overtime and stat pay**, 6% from the 5-year anniversary, accrued as a
  liability, paid out at termination (verified gov.bc.ca); stat pay = wages in the 30 days before ÷ days
  worked, **overtime excluded**, eligibility 30 days employed + 15 of 30 worked; working a stat = 1.5× (2×
  after 12 h) plus the average day's pay (verified); final pay within 48 h of termination / 6 days after a
  quit; also in scope: paid sick days (ESA), minimum daily pay / call-in, split shifts, time banks — each a
  rule row with its source. (Both.)
- WorkSafeBC premium = rate × assessable payroll (cap $127,500/worker, 2026) per classification unit. (Fable.)

**Remittances (step 7):** grouped by **pay date**; remitter type stored with history (it can change); nil
remittance handled; weekends/holidays move due dates; the 2026 penalty scale shown before the due date. (Codex.)

**Year end and ROE (step 8):** T4 box mapping (14, 16, 16A, 17, 18, 22, 24, 26, 40, employment code) tested
against fixtures, T4 Summary balancing, amendments/cancellations, rejected-file recovery; ROE from per-period
insurable earnings and hours kept from day one (53 weeks), a 7-day no-work detector (seasonal layoffs), the
deadline rule per pay frequency (monthly: earlier of 5 days after period / 15 days after interruption). (Both.)

**Security (step 9):** per-purpose keys derived from raw key bytes (SIN ≠ bank ≠ other), key rotation,
bank transit/institution encrypted too, step-up re-auth + reason logged for any SIN/bank reveal (bank reveal
isn't logged today), tenant-scoped access to T4/ROE exports, stubs delivered as an expiring link with the PDF
only on request, retention: CRA 6 years from end of tax year (verified), BC 4 years. (Both.)

**Tests:** PDOC fixtures stay the oracle, plus boundary/golden tests: every bracket and cent edge, 26/27 &
52/53 years, maxima hit exactly and mid-run, bonus-only runs, hire / termination / rehire, the July edition,
midnight / year / DST boundaries, stat + overtime, vacation anniversary, corrections and reversals, double
approval, posting retry, T4/ROE amendments, and a full ledger → remittance → T4 reconciliation. (Codex.)

**Order unchanged** (rules → hours → deductions → runs → stubs → books → remittances → year end → security →
shadow next to Wave), with the data-model items above built in step 4 before any run can be approved.
