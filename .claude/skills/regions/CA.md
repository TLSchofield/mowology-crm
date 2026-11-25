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
| Source deductions are grouped by **pay date**. Remitter type is set by CRA from average monthly withholding two years back and can change: quarterly (AMWA under $3,000 + clean record; due 15 Apr/Jul/Oct/Jan), regular (due the 15th of the next month), accelerated 1 ($25,000–$99,999.99: 25th for the 1st–15th, 10th of next month for the rest) and 2 ($100,000+: 3rd working day after the period). Late penalty 3% (1–3 days), 5% (4–5), 7% (6–7), 10% (later or none), 20% if knowing. | Verified 2026-10-21 | CRA *Due dates for remitting source deductions* | Kaname's calendar `payroll_remit` (monthly:15) — Mowology's remitter type to confirm from the PD7A | 2027-01-31 |
| Tax deductions use CRA T4127 by **pay date**: 122nd edition from 1 Jan 2026, 123rd from 1 Jul 2026 (BC only: lowest rate 6.14% and reduction $805 for July–December, a catch-up for BC's 5.60%/$690 2026 increase). | Verified 2026-10-21 (CRA CSV tables + formulas pages) | CRA T4127 | `app/Modules/Payroll/Data/rules-2026.php` | 2026-12-15 |
| 2026 CPP 5.95% (base 4.95% + first additional 1%), YMPE $74,600, basic exemption $3,500, max $4,230.45 each; CPP2 4% from $74,600 to YAMPE $85,000, max $416; EI 1.63%, max insurable $68,900, max $1,123.07 (employer 1.4× = $1,572.30). | Verified 2026-10-21 (CRA Tables 8.3–8.7) | CRA T4127 | `rules-2026.php` | 2026-12-15 |
| A shareholder-employee with more than 40% of the voting shares is not in insurable employment: no EI; CPP still applies. Who qualifies at Mowology: confirm with accountant. | Verified 2026-10-21 / Confirm with accountant (per person) | CRA *Determine if employment is pensionable or insurable* | — | 2027-01-31 |
| Record of Employment: an interruption is 7 consecutive days with no work and no insurable earnings (or a layoff/quit). Electronic ROE due 5 days after the end of the pay period of the interruption (weekly, bi-weekly, semi-monthly); monthly or 13-period pay: the earlier of 5 days after the period ends or 15 days after the first day of the interruption. ROE Web payroll extract XML 2.0. | Verified 2026-10-21 | Service Canada *ROE guide*; ROE Web user requirements | — | 2027-01-31 |
| T4 slips + T4 Summary due the last day of February; electronic filing required above 5 slips; each XML file needs a T619 transmittal (2026V4). | Verified 2026-10-21 | CRA *How to file*; *T619 2026* | — | 2027-01-31 |
| Payroll records kept 6 years from the end of the tax year they relate to. | Verified 2026-10-21 | CRA RC4409 *Keeping records* | — | 2027-01-31 |

## Marketing and messages
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| Commercial email needs consent (express or implied), sender identification and a working unsubscribe — CASL. | Research | CASL (fightspam.gc.ca) | consent tables (see Mia's skill) | 2027-01-31 |
| Carrier email-to-SMS gateways silently drop messages with URLs or over 160 characters; plain text only. | Verified (repo CLAUDE.md rule 11, field experience) | Mowology CLAUDE.md | `sendSms()` | 2027-01-31 |
