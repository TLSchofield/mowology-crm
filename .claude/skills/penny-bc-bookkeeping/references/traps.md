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

## 2026-10-11 — Yardi remittance reader scans nothing
`yardi_eft_inbox_poll` ran 2,663 times reporting "Scanned 0 email(s)" while office@ held 5 Yardi
remittances (May–Jul) and info@ had forwarded Dorset ones. **Open** — remittances are not being
used. A poller that always finds zero is a broken poller, not a quiet inbox.

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
