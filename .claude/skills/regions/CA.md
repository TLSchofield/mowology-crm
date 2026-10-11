# Canada (federal)

Status key in `SKILL.md`. Every table row ends with its review-by date (the test checks them).

## GST
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| GST 5% in provinces without HST (incl. BC). | Verified (code + filed invoices) | CRA | `tax_rates` GST | 2027-01-31 |
| Basic groceries are zero-rated (bottled water, most store food); bottle deposits carry no GST; some supplies are exempt. So GST below 5% of a mixed receipt's subtotal is normal; GST above 5% of the pre-tax subtotal is impossible from GST alone. | Verified on a receipt (Chevron 2026-09-22); categories = Research — read CRA GST/HST Memo 4.3 *Basic Groceries* | CRA Memo 4.3 | — | 2027-01-31 |
| Claiming GST back (ITCs), what the receipt must show: under $30 — vendor, date, total; $30–$149.99 — + vendor's GST number and the GST (or "GST included"); $150+ — + buyer's name, payment terms, description of each item. | Research | CRA GST/HST Memo 8-4 | — | 2027-01-31 |
| GST filing frequency for Mowology | Confirm with accountant | — | — | 2027-01-31 |

## Payroll (CRA / Service Canada)
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| Source deductions (CPP, EI, income tax) due by the 15th of the following month for a regular remitter. | Research | PowerPay 2026 payroll calendar | Charlie's calendar `payroll_remit` (monthly:15) | 2027-01-31 |
| Record of Employment on an interruption of earnings (layoff, or 7 days with no work and no pay): electronic ROE due within 5 days after the end of that pay period. | Research | Achkar Law ROE FAQs; Service Canada | — | 2027-01-31 |
| T4 slips + T4 Summary due the last day of February; late slips are penalised per slip per day. | Research | PowerPay 2026 calendar; CRA | — | 2027-01-31 |
| Business records kept 6 years. | Research | CRA | — | 2027-01-31 |

## Marketing and messages
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| Commercial email needs consent (express or implied), sender identification and a working unsubscribe — CASL. | Research | CASL (fightspam.gc.ca) | consent tables (see Mia's skill) | 2027-01-31 |
| Carrier email-to-SMS gateways silently drop messages with URLs or over 160 characters; plain text only. | Verified (repo CLAUDE.md rule 11, field experience) | Mowology CLAUDE.md | `sendSms()` | 2027-01-31 |
