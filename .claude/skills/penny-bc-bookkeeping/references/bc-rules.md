# British Columbia bookkeeping rules (Mowology Lawns & Landscapes Ltd)

Every rule has a **source** and a **review by** date. `tests/Unit/Docs/PennySkillTest.php` fails
once a review date passes, so a stale rule cannot sit here unnoticed. **Confirm with accountant**
= ask Steve Lim CPA (Sit Lim CPA) before Penny acts on it.

Status key: **Verified** (read in the source on the date shown) · **Research** (from a research
pass 2026-10-11 — practitioner sources, not yet read in the primary source) · **Confirm with accountant**.

## Sales taxes

| Rule | Status | Source | Review by |
|---|---|---|---|
| GST is 5% in BC (no HST). Business GST number on file: `business_settings.gst_registration`. | Verified (code + filed invoices) | `TaxEngine` rates table; CRA | 2027-01-31 |
| **GST below 5% of a receipt's subtotal is normal.** Basic groceries are zero-rated (bottled water, most store food), some supplies are exempt, and bottle deposits carry no GST — they share the subtotal with taxable items. Only GST **above** 5% of the pre-tax subtotal is impossible from GST alone (a misread, or PST read as GST). Example: Chevron 2026-09-22 — $0.12 GST = 5% of the $2.49 hot drink only, in a $15.57 basket; correct. | Verified on the receipt; zero-rating of basic groceries = Research — read CRA GST/HST Memo 4.3 *Basic Groceries* to confirm the categories | CRA Memo 4.3 | 2027-01-31 |
| BC PST is 7% on taxable goods. Most services (incl. landscaping labour) are not PST-taxable; **contractors pay PST on materials they install into real property** (soil, plants, pavers…) rather than charging it to the client. Out-of-province goods bought without PST → self-assess PST. | Research — confirm with accountant how Mowology invoices materials | BC Gov: *Contractors paying PST* (gov.bc.ca … /pst-real-property-contractors/contractors-paying-pst) | 2027-01-31 |
| A receipt's PST can be at most 7% of its subtotal (same logic as GST). | Verified (code) | `ReceiptBookkeeperService::check()` | 2027-01-31 |

## Claiming GST back (input tax credits) — what a receipt must show

| Purchase (incl. tax) | Receipt must show | Status | Source | Review by |
|---|---|---|---|---|
| Under $30 | Vendor name, date, total | Research | CRA GST/HST Memo 8-4 *Documentary requirements for claiming ITCs* | 2027-01-31 |
| $30 – $149.99 | + vendor's GST number, and the GST charged (or that the total includes GST) | Research | CRA Memo 8-4 | 2027-01-31 |
| $150 and over | + buyer's name (Mowology), terms of payment, a description of each item | Research | CRA Memo 8-4 | 2027-01-31 |

Penny should flag a deficient receipt **at capture**, while a corrected copy is still easy to get.
Missing GST numbers on mid-sized receipts are a common reason ITCs are denied on audit.
**Not built yet (2026-10-11):** vendors have no GST-number field (`vendors.gst_exempt` only),
expenses store no vendor GST number, and the parser does not read it — though 37 of 58 approved
2026 receipts of $30+ carry a 9-digit GST/BN number in their OCR text.

## Payroll and staff

| Rule | Status | Source | Review by |
|---|---|---|---|
| Source deductions (CPP, EI, income tax) are due to CRA by the **15th of the following month** for a regular remitter. Charlie's calendar carries it (`payroll_remit`, monthly:15). | Research + code | PowerPay 2026 payroll calendar; `DeadlineService` | 2027-01-31 |
| **Record of Employment** when an employee has an interruption of earnings (layoff, or 7 days with no work and no pay): the electronic ROE is due within **5 days after the end of the pay period** in which it happens. Nigel is laid off every Dec 1 → ROE every December. Reason code chosen with the owner. | Research | Achkar Law *ROE FAQs*; Service Canada | 2027-01-31 |
| Vacation pay owing on layoff | Confirm with accountant | — | 2027-01-31 |
| **T4 slips and T4 Summary** due the **last day of February**; late slips are penalised per slip per day. | Research | PowerPay 2026 calendar; CRA | 2027-01-31 |
| **WorkSafeBC**: employers with annual assessments of $1,500+ report payroll and pay **quarterly**; assessable payroll lines up with T4 boxes 14 and 71. | Research | WorkSafeBC *Report payroll and pay premiums*; PowerPay *BC WCB assessable earnings* | 2027-01-31 |
| Breaks are **paid** at Mowology (`ops_settings.breaks_paid` = 1). BC: a 30-minute meal break before 5 hours of work. | Verified (owner, 2026-10-10) | BreakService; BC Employment Standards | 2027-01-31 |

## Income tax and filing

| Rule | Status | Source | Review by |
|---|---|---|---|
| Fiscal year ends **Dec 31**. FY2025 is filed and **locked** (migration 1237); 2026 opens from the filed statements (`Fy2026OpeningService`). | Verified (code) | `Fy2026OpeningService` docblock | 2027-01-31 |
| Tax instalments: Tim pays **when cash allows**, no fixed schedule (owner, 2026-10-10). Penny shows "could spare for tax" per week instead of instalment dates. | Verified (owner) | `CashFlowForecastService::safeToPay()` | 2027-01-31 |
| GST filing frequency | Confirm with accountant | — | 2027-01-31 |
| Records kept **6 years**, searchable. | Research | CRA | 2027-01-31 |

## Accountant

Steve Lim, Sit Lim CPA. 2025 tax payable $10,454 (memory `reference_accountant_and_tax_instalments`).
Anything marked *Confirm with accountant* is asked of him before Penny acts on it.
