# How Mowology's books work (verified against code, 2026-10-11)

Longer history: Obsidian `30-PROJECTS/Active/Mowology-CRM/Architecture/Accounting-Ledger.md`.

**e-Transfers from chequing to Tim Schofield are NOT his pay — open question.** Tim is on Wave payroll
(2026-10-12), so wages filed from these transfers double-count his pay. They are filed to 5100 Crew
Wages by rules 37 (learned) and 307 ("owner said so"): $49,086.89 across 33 in 2026. Possibly the
shareholder loan payout, reimbursements, or money he passes to crew — ask Tim; don't reclassify
until he says, and check with Steve Lim.

**Payer names that differ from the client record** (Tim, 2026-10-12): Darren Cannon (darren.cannon@colliers.com) pays for Kelly Cannon; Theresa Roberge = Terry Baird
(same client — cheques say ROBERGE, invoices say Baird); Obsidian Property = The Mix BCS4428's manager;
KANCO cheques = Beedie Development – Kanco. VML and Laburnum pay by cheque; Dorset and Norsan by Yardi EFT.

**Reading the chequing statement** (Vancity export, 2026-10-12): crew are paid by e-Transfer debits
(Nigel Casey, Katrenna Marenych, Trevor James, Harry Grant, Himal Bam, Dmitry) alongside Wave PYRL runs;
"Chargeback debit" + $7.50 = a returned cheque (Jul 3: $456.84, the VR1450/Colyvan amount); a cash deposit
that equals a same-day Vancity Visa bill payment ($1,800 on Jul 31) is the owner covering the card, not
income — ask. "sparc pay" cheques come from a strata on SparcPay (not necessarily BCS4428).

**Contract prices rise 5% each March/April** (Tim, 2026-10-12): e.g. Oakridge $9,133.72 → $9,590.41,
Laburnum $855.11 → $897.87. A payment short by exactly ~4.76% of the invoice is the old price — the
payer missed the increase (Quay Pacific paid INV-0088/0089 at $855.11, $42.76 each still owing), not GST.
**SparcPay = Quay Pacific** (Laburnum Heights' manager, registered Aug 27 2026) and BCS4428 (The Mix);
remittances come from noreply@sparchub.net.

**Refunds to clients are not expenses** (2026-10-12): Macdonald Realty paid 4 months on Aug 27–28 for 2
due (incl. a 3-month "MCR ITF – 1262" cheque, $3,583.17); the 2 extra months ($2,388.78) were refunded
Sep 18 by e-Transfer. Book the overpayment to 2160 Customer Deposits and the refund out of 2160 — never
6900. "MCR ITF" cheques are Macdonald Realty's strata trust accounts (VR1450/VR1540, $1,194.39/month).

**FY2025 is filed and closed.** Don't propose 2025 write-offs, adjustments or clean-up lists (the
accountant's June 19 write-off question is settled — Tim, 2026-10-12). 2026 opens from the filed statements.

## Two representations of the same money

1. **Single-entry `accounting_transactions`** — what the Accounting page, P&L, monthly trend and
   dashboard sum. `type` is `income` / `expense` / `transfer`; reports count only income and
   expense. Written by `AccountingService::syncFromInvoices()` / `syncFromExpenses()`, the bank
   import, and the reconciliation services.
2. **Double-entry journal** (`journal_entries` + `journal_lines`) — posted by `LedgerSyncService`
   (accrual: invoice raised → DR AR / CR revenue + GST; payment → DR bank / CR AR; expense → DR
   expense + ITC / CR funding), `JobberLedgerService` (2026 Jobber invoices and deposits) and
   `Fy2026OpeningService`. Per `StripePayoutService` and `IncomeCleanupService` docblocks, the
   journal **skips revenue deposits**, so the deposit double-count lived only in (1).
   When a report disagrees, check both.

## Income is recognised once — on the invoice

- A paid CRM invoice has its own income row (`reference_type='invoice'`).
- The bank deposit that paid it must become `type='transfer'` once linked (allocation rows in
  `invoice_payment_allocations` with `transaction_id` = the deposit). Unlinked, it stays income and
  **double-counts** — measured 2026-10-06 at ~$210K for 2026.
- Fixes so far: Stripe payouts (`StripePayoutService` — payout → invoices + fee line on 6850,
  Stripe read-only, books only when it adds up to the cent; 79 payouts linked by 2026-10-11);
  Penny's bank card (`BankInvoiceMatchService`); `IncomeCleanupService`; the deposit backfill
  (`DepositBackfillService`, 2026-10-11: 32 deposits linked, 2026 income $387,762.92 → $307,000.59).

## The Jobber era

- **CRM cutover = 2026-02-25.** Before it the business ran on Jobber.
- Jobber-era bank deposits are the **only** income record for that work → they stay income.
- `jobber_payments` / `jobber_invoices` (imported 2026-10-07) say which Jobber invoice a deposit
  paid; the backfill writes it on the bank line ("Jobber #11851 — St Faith's") and in
  `bank_deposit_sources`, without changing income.
- "Preauthorized credit Jobber - By Stripe" deposits are **Jobber Payments** payouts (Jobber's
  Stripe), not the CRM's Stripe.

## How customers actually pay (2026, by value)

Cheques ~67% (strata / property managers: VML, Macdonald, Colyvan, VCH…), Stripe ~22% (many
small residential payments), e-Transfer ~10%. Property managers also pay by EFT with a
**remittance email naming the invoice numbers**: Yardi (Tribe; Dorset — forwarded to info@),
SparcPay (strata BCS4428 via Obsidian). Remittances arrive at office@, info@ and iCloud.
VCHA (Little Mountain Residential Care) pays by numbered cheque 2–6 weeks after the invoice, once a
facilities coordinator approves it; VCH Accounts Payable (`VCHaccountspayable@phsa.ca`) confirms by
email with the cheque number, invoice number and date cashed — a remittance as good as Yardi's
(e.g. cheque 356183 for INV-2026-0371, 2026-10-05). Bank wording "COASTAL HEALTH" is a learned payer
(2026-10-12). Don't treat VCHA as late before ~45 days. Monthly contract invoices go to
`vcfmoinvoices@vch.ca` (the company billing email); one-off work goes to Johnly Sareno
(`laguiar@littlemountaincare.org`), who signs it off — leave the routing as "primary contact" (Tim, 2026-10-12).

## Lessons record (`head_lessons`, migration 1348, 2026-10-13)

Every time a person overrides Penny, one row is written: head, subject (`vendor`, `bank_wording`,
`invoice`, `etransfer_sender`, `service_type`), field, what she said, what was right, who, the code path
(`source`) and when. `site_id` keeps it tenant-private. Written by `HeadLessons::record()` through
`PennyLessons` (`expense()`, `bankLine()`, `subject()`); a failure is logged and never blocks the person.
Recording only so far: no learner reads it yet (plan 2026-10-13 A2 will, via `countConsistent()`).
Wired paths: `ExpenseGate` (a person changing category, payment method, job, vendor, tag or split;
sources `penny_card`, `bank_desk`, `bank_line` skip it), Penny's card (`BookkeeperDeskService::decide`,
against her suggestion), `BankLineMoveService::move` (not when Penny proposed the account), bank desk
"keep", `attachExpenseMatch` / unlink, `ReceiptFundingCheck` (bank line corrects the method), Penny's
question answers, reconciliation attach / detach / already-recorded, the deposit card, payer teaching,
`correctManualPayment`, Jobber `linkManual` / `bookMatched`, "not a duplicate", "Keep mine" on Penny's read
(source `receipt_keep_mine`, `PennyReadService::keep`). **The reader, not Penny:** when an approved receipt carries
Penny's items (`line_items_source = 'penny'`) and they differ from what the reader read, one row with field
`reader_line_items`, source `reader_corrected_by_penny` (`teachReaderFromPenny()`) — count those for "how often the
reader needs Penny". `bank_memo_payers` has no
person path (only `DepositBackfillService`). The 2026-10-12 mistakes are tests in
`tests/Unit/Penny/Lessons20261012Test.php`.

## The Accountant's Ledger (step 1 built 2026-10-13; plan `docs/plans/2026-10-13-accountants-ledger.md`)

- **Pages:** `/crm/ledger/` (overview, journal, entry drill-down, GIFI mapping, exceptions, accountant access) + the existing
  trial balance / balance sheet / income statement, now gated on `ledger.view` (admin, manager, accountant; crew no longer).
- **Accountant login** (migration 1355): users.role `accountant` + RBAC role `accountant` (ledger.view, ledger.propose).
  `ledger.post` is admin only. `AccountantFence` confines the login to the Ledger (403 elsewhere, dashboard → Ledger),
  refuses app tokens, and writes an activity_log row per page view (`ledger.view`) or export (`ledger.export`).
  Invite: Settings → Accountant, or `/crm/ledger/invite.php` — creates the login and shows a 72-hour set-password link
  (password_reset_tokens); sends nothing.
- **GIFI:** `chart_of_accounts.gifi_code / gifi_status ('suggested' | 'confirmed') / lead_group`; shared table `gifi_codes`.
  Suggestions never count as mapped: the export is blocked until a person confirms every account with activity and the
  totals recompute (2599 = 3499 + 3620; 9999 = the ledger's net income). Retained earnings start (3660) includes any prior
  year's income-statement balance that no FY opening entry closed.
- **journal_entries.entry_kind:** system | manual | aje | rje | reversal (`LedgerService::entryKindFor()` on every post).
- **Locks:** `accounting_periods.status = 'locked'` (closed_at = when). FY2025's twelve months were locked 2026-10-07.

## Tables and columns that mislead

| Column | What it really is |
|---|---|
| `bank_import_rows.account_id` | the **category** account (e.g. 4900 Other Services), NOT the bank account. The bank account is `bank_import_sessions.bank_account_id`. |
| `bank_import_sessions.status = 'rolled_back'` | a cancelled import — its rows stay as history; filter them out. |
| `invoices.payment_date` | mostly NULL; the paid date lives in `invoices.paid_at`. |
| `accounting_transactions.amount` on a deposit | equals its **unallocated remainder** while partly allocated (`InvoiceReconciliationService::recomputeDepositRow`). |
| `expenses.anomaly_flags` / `anomaly_score` | stored at save time; a rule change does not update old receipts — re-run through `ExpenseGate::apply()`. |
| `bank_memo_payers` | bank wording → payer name ("VML" → Vancouver Management Ltd) that Penny's bank card reads. |

## Accounts referred to by number

1010 Chequing · 1020 Reserve savings · 1025 GST reserve · 1100 AR · 2160 Customer deposits ·
2200 GST · 2400 Credit card payable · 3200 Retained earnings · 4050 Contract income ·
4900 Other services (default revenue) · 6800 Bank charges · 6850 Stripe fees ·
6900 Miscellaneous (default expense). Default accounts (4900, 6900) never teach bank rules.
