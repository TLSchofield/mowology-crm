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
| Vacation pay owing when a seasonal employee is laid off (Nigel, every Dec 1). | Confirm with accountant | — | — | 2027-01-31 |
| WorkSafeBC: employers with annual assessments of $1,500+ report payroll and pay quarterly; assessable payroll lines up with T4 boxes 14 and 71. | Research | WorkSafeBC *Report payroll and pay premiums*; PowerPay *BC WCB assessable earnings* | — | 2027-01-31 |

## Year-end
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| Mowology's fiscal year ends Dec 31; FY2025 filed and locked (migration 1237). | Verified (code) | `Fy2026OpeningService` | — | 2027-01-31 |
