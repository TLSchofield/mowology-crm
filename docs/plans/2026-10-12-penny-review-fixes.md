# Penny review fixes (2026-10-12)

Six independent (Fable) reviews of Penny's services, checked against the code and live data.
Tim handles the two double-charged visits (INV-0179/0185, INV-0181/0183) himself; this plan is
everything else. Each batch: unit tests → `prod-run.sh` preview → 3-way-merged deploy → OPcache →
live check → Penny `traps.md` + vault Known-Failure-Patterns the same day.

**Correction to the report:** INV-0088/0089 (Laburnum Heights) are not "income missing". Each has
$855.11 recorded against $897.87 — the GST ($42.76) unpaid — no allocation, and several matching
cheque deposits that are themselves income. They need Penny's look (A3), not a status flip.

## Unknowns and how each is settled (cheapest first)

| # | Unknown | Settled by |
|---|---|---|
| U1 | Does mail in office@ carry `Authentication-Results` (DKIM/SPF) for Yardi/Interac? | Read-only IMAP header fetch of one real remittance |
| U2 | Which column says which bank account an `accounting_transactions` row came from | `SHOW COLUMNS` + one imported row |
| U3 | Is there a customer-deposit (liability) account in `LedgerAccountMap` | Read the map |
| U4 | Do imported bank lines carry `gst_amount` (journal ITCs the GST34 never sees) | One count query |
| U5 | How FY2025 is locked (`accounting_periods` columns) | `SHOW COLUMNS` + rows |
| U6 | Contract 46 (custom cycle) — how its 2 invoices were made | Its invoices' `created_by`/notes |
| U7 | Who calls each changed function | `graphify touches` before each edit |

## A — money already wrong, with its cause (first)
1. **Ledger books draft/cancelled invoices.** Sync posts only issued invoices; an invoice that becomes draft/cancelled/void gets its entry reversed. Then reverse entries 189, 2854, 2692, 2779 ($1,181.55). Both must ship together — the nightly sync ignores reversed entries and would re-post them.
2. **Client credit counted twice.** The advance cheque ($529.20, cheque #011) moves from income to a customer-deposit liability; applying credit makes the invoice the income (fixes the $68.54 on INV-0236). Needs U3.
3. **Laburnum Heights INV-0088/0089:** Penny lays out invoices, payments and the $855.11/$897.87 deposits for Tim; no change without his yes.
4. **Part-paid invoice turned "overdue" drops its income** (sync counts paid/partial only): count any issued invoice's `amount_paid`. Ships after A3 so it can't double the Laburnum deposits.

## B — payments in
1. Yardi auto-record only when the sender is authenticated (U1); otherwise a pending row for Tim.
2. Interac: sender + authentication required for "high"; replies/forwards never "high".
3. e-Transfer/Yardi payments dated when the money moved (email date / remittance date), refused in locked months.
4. Bank auto-link to recorded payments: payer must agree or the match be the only one; 3-day window (operating rule); never before the Jobber cutover (2026-02-25).
5. `attach()` locks the deposit row inside its transaction.
6. Visit invoice once: lock the visit, back-link only if still empty. Contract billing run holds a lock.
7. Stripe fee line once (rowCount); claim reminders matched by sender, not amount alone; Yardi lines on one invoice kept apart and totals checked.

## C — bank and card statements
1. Duplicate check keys on bank account + description too (U2) — card payoffs stop being dropped.
2. Card PDFs/photos use card rules (or are refused with a message until they do).
3. One list of "card payment" wordings, shared.
4. Bank rules never learned from generic words (cheque, bill payment, withdrawal, transfer, deposit).
5. Card refunds reach the journal; month-end proof counts transfers; a month locks only when proven.
6. Duplicate clean-up atomic; Stripe range-match requires the payout's own data; date format detected per file.

## D — receipts
1. Editing a booked receipt in a locked period: the replacement posts with the reversal's date (or the edit is refused) — never reversal only.
2. Emailed receipts: no invented GST — flagged "estimated", exempt vendors honoured, PST kept.
3. `approve()` refuses rejected/forwarded/held duplicates.
4. Rescan can't rewrite a sent receipt or a split's choices.
5. Phone edits change only fields sent; crew can't edit approved receipts.
6. Delete reverses the ledger inside the delete's transaction.
7. Phone "Save & Send" requires approval; the email goes after the record is written.

## E — ledger and GST
1. Nothing dated before FY2026 is posted automatically (re-dating into 2026 undid the opening) — U5.
2. ITCs: journal and GST34 from the same records (U4).
3. Void bank lines not posted; part-payment GST proportional; Jobber booking atomic; reversal link in the same transaction; gross-to-net rounding to the cent.
4. GST34 excludes void invoices; refunds/bad debts listed for Steve Lim, not guessed.

## F — invoices out
1. Contract cycles: the cron bills what `isPlanContractBilled()` says is billed (U6).
2. Visit-invoice SMS fixed (wrong arguments). PM-routed contract invoices greet/text the firm, not the rep.
3. A contract invoice whose email fails is held and Charlie told; held invoices skip cancelled contracts.
4. Statements: no cancelled invoices, routing respected. Contract cron reads the GST rate from `TaxEngine::gstRate()`; one company per contact, chosen by rule.
5. Penny questions: the answer must fit the question; the month total uses the split amount.

## After
Re-run the Fable review on the changed files; then a second round for what wasn't reviewed:
Penny's screens and their APIs (`public/crm/accounting/*`, `Accounting/Api`, `Expenses/Api`),
her crons, the iPhone receipt screens and `bookkeeper-card.js`.
