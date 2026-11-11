# British Columbia (province)

Status key in `SKILL.md`. Every table row ends with its review-by date (the test checks them).

## PST
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| PST 7% on taxable goods; most services (incl. landscaping labour) are not PST-taxable. | Research | BC Gov PST guides | `tax_rates` PST (applies_to expense) | 2027-01-31 |
| Contractors pay PST on materials they install into real property (soil, plants, pavers…) rather than charging it to the client; goods bought without PST (e.g. out of province) → self-assess. How Mowology invoices materials: confirm with accountant. | Research | BC Gov *Contractors paying PST* | — | 2027-01-31 |
| A receipt's PST is at most 7% of its subtotal. | Verified (code) | `ReceiptBookkeeperService::check()` | — | 2027-01-31 |

## Employment and safety
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| A 30-minute meal break before 5 hours of work. Mowology pays breaks (owner, 2026-10-10). | Verified (owner) / Research (ESA wording) | BC Employment Standards Act | `ops_settings.breaks_paid` = 1 | 2027-01-31 |
| Vacation pay is at least 4% of **all wages** (overtime and stat pay included), 6% from the 5th anniversary; it may go on each cheque only with a written agreement; anything accrued is paid when employment ends (incl. a seasonal layoff). How Mowology pays it (each cheque or accrued): confirm with accountant. | Verified 2026-10-21 / Confirm with accountant (method) | gov.bc.ca *Vacation*; ESA s.58 | — | 2027-01-31 |
| Overtime: over 8 h a day 1.5×, over 12 h 2×; over 40 h a week 1.5×, counting only the first 8 h of each day; the overtime **week runs Sunday–Saturday** (ESA s.1). Averaging agreements only in writing, before they start. | Verified 2026-10-21 | gov.bc.ca *Overtime pay*; ESA definitions | `OvertimeCalculator` (Sunday week since 2026-10-21) | 2027-01-31 |
| 11 statutory holidays in 2026 (Jan 1, Feb 16, Apr 3, May 18, Jul 1, Aug 3, Sep 7, Sep 30, Oct 12, Nov 11, Dec 25). Eligible after 30 days employed and wages on 15 of the last 30 days; pay = wages in the 30 days before ÷ days worked, **overtime excluded**; working the holiday = 1.5× (2× after 12 h) plus that average day's pay. | Verified 2026-10-21 | gov.bc.ca *Statutory holidays*; *Calculate statutory holiday pay* | — | 2026-12-15 |
| Minimum wage $18.25/h from 1 Jun 2026 (indexed each 1 June). | Verified 2026-10-21 | gov.bc.ca *Minimum wage* | — | 2027-05-31 |
| Paid at least semi-monthly, within 8 days after the pay period; final pay within 48 h of a termination, 6 days after a quit. | Verified 2026-10-21 (frequency) / Research (final-pay days) | ESA s.17, s.18 | — | 2027-01-31 |
| A wage statement each payday: employer name and address, hours, rate, overtime hours and rate, every payment and allowance, each deduction and why, gross and net, time-bank balance. Payroll records kept 4 years in BC. | Verified 2026-10-21 | ESA s.27, s.28 | — | 2027-01-31 |
| WorkSafeBC: quarterly reporting and payment if expected premiums are $2,000+ a year (or the classification unit requires it), due 20 Apr, 20 Jul, 20 Oct, 20 Jan; maximum assessable earnings $127,500 per worker in 2026; assessable payroll lines up with T4 boxes 14 and 71. | Verified 2026-10-21 (threshold, dates, cap) / Research (T4 boxes) | WorkSafeBC *Premium payment schedule*; *2026 maximum wage rate* | — | 2026-12-15 |

## Year-end
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| Mowology's fiscal year ends Dec 31; FY2025 filed and locked (migration 1237). | Verified (code) | `Fy2026OpeningService` | — | 2027-01-31 |
