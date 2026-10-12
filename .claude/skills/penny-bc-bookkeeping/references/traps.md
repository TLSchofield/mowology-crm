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
