# Traps already hit — read before any write to the books

Add a new entry the same day a mistake is found: date, what happened, the signature, the rule.

## 2026-10-11 — `InvoiceReconciliationService::detach()` un-pays the invoice
Built for deposits attached to *unpaid* invoices: it subtracts the allocation from `amount_paid`,
sets the invoice back to unpaid/overdue, NULLs `paid_at` and `payment_reference`, and deletes the
invoice's income row. Used to undo a link onto an invoice paid months earlier, it turned
INV-2026-0010 "overdue $1,194.39" on production (restored from activity_log 1408 + journal entry
20). **Rule:** undo a backfill link with `DepositBackfillService::undo()`; read a function's
writes before using it as an undo.

## 2026-10-11 — Claims made without checking
"Two bank accounts have future-dated data" (grouped by `bank_import_rows.account_id`, which is the
category); "Stripe deposits are double-counted" (Stripe payouts were already reconciled by
`StripePayoutService`). **Rule:** cite the query; search code + this skill before claiming a gap.

## 2026-10-11 — GST below 5% flagged as wrong
Risk check and Penny's preparer both required GST ≈ 5% of the subtotal; mixed baskets (zero-rated
groceries, deposits) were flagged (Chevron $0.12). Fixed to "at most 5%" in `checkGstMismatch()`
and `ReceiptBookkeeperService::check()`; 14 stored false flags cleared through the gate.
**Rule:** tax rules belong in one place (see SKILL.md "Not yet central").

## 2026-10-11 — Remittance emails are mostly not read (coverage gap; "broken" was an overclaim)
`yardi_eft_inbox_poll` ran 2,663 times reporting "Scanned 0 email(s)". First read as broken; the
likelier cause (from its code, not yet checked against the mailbox): it only searches exact
DoNotReply@yardi.com mail in office@, looks back 21 days and ignores anything before 2026-08-05 —
the office@ Yardi remittances are all May–Jul. It never opens info@ or iCloud (where Dorset's land),
and nothing reads SparcPay (strata BCS4428). **Rule:** a poller reporting zero needs its search
window and mailbox checked before it is called broken; and the remittances that name invoice numbers
are not being used yet.

## 2026-10-05 — Future-dated bank rows
A December 2025 statement imported before the year fix was dated 2026 (session 13, 122 rows).
Fixed by fix-1122 (session rolled back). **Rule:** before re-dating rows, look for a later
session covering the same period and walk the running balance.

## 2026-10-06 — Income double count (~$210K)
Unmatched bank deposits stayed `type='income'` beside their invoices' own income rows.
Stripe payouts, income clean-up and the deposit backfill have removed most of it; 2026 reports
should be re-checked with Steve before he relies on them.

## Jobber import must not re-record income
Importing Jobber invoices as *paid* would make `syncFromInvoices()` record their income again on
top of the deposits. Jobber-era deposits stay the income record; Jobber data only annotates them.

## A question kept the receipt as it was when Penny asked (2026-10-12, Lawnboy #412)
`penny_questions` stored the job, amount and contract at scan time. The owner had split #412
(seed → shop stock, mulch → a job) and the mulch was on the wrong job (998 W 19th); after the
correction Penny still asked "$200 for 998 West 19th — covered by their contract?". The three $84
mulch loads that day (#412's mulch line, #413, #417) all went to Oakridge Gardens (6015 Tisdall).
Fixed: `PennyQuestionService::open()` reads the expense's current job and that job's contract,
and for a split receipt asks only about the lines allocated to the job (`splitPart()`); seed in
stock is never "unbilled". **Rule:** a question shown to Tim is rebuilt from the record as it is
now, never from a snapshot.

## Invoices bounced off a client's accounts-payable system for two months (found 2026-10-12)
Colyvan Pacific (strata VR 1450, Mark dela Cruz) moved to LeapAP on 30 July; Tim registered and the
CRM sent VR 1450's invoices to the LeapAP address — link-only, because monthly contract billing had
no PDF until 2026-10-06. LeapAP rejected each one ("No PDF or image attachments found") and every
reminder; INV-2026-0314/-0372/-0431 ($1,370.52) went unpaid and nobody saw the rejection emails in
office@. The 2026-10-06 PDF gate fixed new invoices but the backlog was never re-sent: 57 unpaid
invoices were sent before it, 8 never had a PDF at all.
**Signature:** an invoice sitting "overdue" for a client who pays reliably; LeapAP "not accepted"
mail in office@. **Rule:** read accounts-payable rejection emails and flag them the same day;
re-send the backlog whenever an invoice-sending rule changes.

## `applyAllocation()` with a deposit id does not reconcile the deposit (found 2026-10-12)
Recording VCHA's INV-2026-0371 against the 14 Sep cheque with `applyAllocation(..., $transactionId, ...)`
marked the invoice paid and wrote an income row, but left the deposit as `income` — the same $1,235.44
counted twice (caught in the prod-run preview). **Rule:** paying an invoice FROM a bank deposit goes
through `InvoiceReconciliationService::attach($transactionId, [[invoice_id, amount]], $userId)`, which
records the payment AND flips the deposit to `transfer`; a payment already recorded is tied to its
deposit with `linkAllocationsToDeposit()`. Check the deposit's `type` after every such write.

## 2026-10-12 — Independent review (Fable, 6 sections), fixed the same day
Each rule below is now enforced in code; listed so a future change doesn't undo it.
- **Ledger revenue = issued invoices only.** Drafts/cancelled/void are never posted; an entry is
  reversed when an invoice is cancelled or goes back to draft (`LedgerSyncService::invoiceIsIssued`).
  4 sat in revenue ($1,181.55). Reversing alone is useless — the nightly sync re-posts reversed sources.
- **Client prepayment = 2160 Customer Deposits**, not income; applying credit posts DR 2160 / CR AR
  (`ClientCreditService`). Roberge cheque #011 counted twice.
- **No automatic posting dated before 2026** (`LedgerService::FY_START`): late 2025 records used to be
  re-dated into 2026 on top of the opening. Tests building 2025 history use `allowingClosedYear()`.
- **Journal posting joins an outer transaction** (`postEntry`, `reverseEntry`): reverse + change is
  all-or-nothing (receipt repost, delete, duplicate clean-up, account-map repost).
- **Yardi email alone never pays an invoice** — the From header is forgeable and the host adds no
  verification stamp (checked); the remittance's deposit must be in the bank (`recordWhenBanked`).
- **Interac:** replies skipped; a notice not from Interac (or our own Fwd:) is never 'high'.
- **Payments are dated when the money moved** (remittance / notice date), not the day recorded.
- **Deposit ↔ recorded-payment auto-link:** never before 2026-02-25, 3-day window, unique on both
  sides (another same-amount deposit nearby = leave it for a person). `attach()` locks the deposit.
- **A visit is invoiced once** (row lock; INV-0179/0185 and INV-0181/0183 were both paid twice).
- **Duplicates are compared within one kind of statement** (card vs bank) — a card payoff was
  dropped as a "duplicate" of the card statement's own payment line.
- **No bank rule from generic wording** (`BankRuleLearning::GENERIC_WORDS`).
- **Emailed receipts:** no ITC on GST the parser estimated; exempt vendors honoured; PST kept.
- **Phone receipts never go to the accountant unapproved**; set-aside copies can't be approved.
- **Contract billing:** one run at a time, GST from Settings, failed emails held and retried,
  greeting/SMS to whoever the invoice is addressed to; only `monthly` counts as contract-billed.
- **Not fixed — needs a decision or other work:** Laburnum Heights INV-0088/0089 (GST unpaid,
  possible double count — Tim); the monthly-statement proof (no session has both balances saved,
  and card vs bank amounts are signed differently); `JobberLedgerService::bookProposal` is not
  atomic (live file carries another session's work); statements still include invoices billed to a
  PM firm for a rep's properties; the Interac poller keeps no state across a >21-day outage.
