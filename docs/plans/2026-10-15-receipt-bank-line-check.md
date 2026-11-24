# Receipt ↔ bank line check, and the side-by-side view (2026-10-15)

Tim: "If a bank line doesn't exist for it it should return to draft … with a note to check other methods."
"Ensuring that if the bank statement for its date has not been imported yet it waits." "If we find the
transaction though this would mean a code error or import error." "Is it [the PDF] redundant if you have the csv?"

## Facts settled (production, this session)

- 17 approved debit/blank-method 2026 receipts ($861.56) have no linked bank line, though chequing
  statements cover their dates. 14 have no same-amount line on chequing or the Visa (±3/+5 days).
- The three that do have a line, and why they were missed:
  - #394 LAWNBOY $25.20 Apr 2: line #6902 is free and exact. Missed because the import matches only
    receipts already approved; the receipt was approved on Oct 5 and nothing looks back at approval time. **Code gap.**
  - #372 landfill $30 Sep 8: the only $30 line that day is linked to #371, another $30 landfill receipt the
    same day. Either a duplicate receipt or a second trip whose line is missing. **Tim decides.**
  - #373 Jo's Italian Deli $14.42 Sep 8: line #41206 is exact and same day, but reads "Mangia's Sandwiches",
    so the name score is too weak to auto-link. **Person confirms.**
- Draft/pending/rejected receipts are never in the books (sync posts approved/forwarded only; no
  non-approved receipt has a live entry).
- Re-opening an approved receipt must go through ExpenseGate's audited path; `ExpenseGateHooks::unpost()`
  reverses its entry (append-only).
- Statement PDFs are not stored: the import reads the upload and discards it. Chequing has monthly PDF
  sections Jan–Sep; Visa Mar–Apr, Apr–May and Jul–Aug were imported from CSV (lines present, no
  closing balance).
- The CSV has every line with date, description, amount and running balance — enough for the visual
  check. The PDF adds only an official copy for the accountant and the printed closing balance.

## A. The check (approved by Tim)

`ReceiptBankCheck` (Accounting service, one place; registered in tests/bootstrap.php, unit tests):
`check($expenseId)` for an approved/forwarded receipt paid from a bank or card account, 2026 onward:
1. **Linked already** → nothing.
2. **Statement not in yet** for that account through date + 5 days (bank_statement_sections, or an
   imported session's date range for CSV imports) → **wait**, nothing changes.
3. **One clear free line** (exact amount, −3/+5 days, the receipts page's own scoring ≥ STRONG, no rival) →
   link it through `attachExpenseMatch()` (funding check + retire the line's own posting follow), and record
   it as a **matcher miss** (a Penny lesson + activity log) so the cause is visible.
4. **A line exists but not clearly** (name differs, a rival receipt, linked to another receipt) → Penny asks
   (penny_questions), shown side by side; nothing moves until a person picks.
5. **No line anywhere** on the paying account or the other accounts → back to draft through ExpenseGate's
   audited re-open (entry reversed), with the note "No [chequing] line for this on the [month] statement —
   check whether it was paid by card, cash, cheque or personally", and a Penny payment-source question.

Runs: right after approval (gate hook), nightly (sync-ledger cron), after each statement import.
First run previewed on production (rolled back), then committed on Tim's yes.

## A2. Duplicates (Tim: "where two receipts appear with only one bank line, duplication should be addressed")

Settled: #371/#372 (landfill $30, Sep 8) are ONE receipt saved twice — same photo (media #902), same second.
Same-photo groups on production: #340–342 (drafts, $2.61), #371–372 (approved, both in the books), #408–410
(rejected). The save path writes a second row for a photo already used (idempotency_key never set).
- **Stop it at the source:** the receipt save path refuses a second receipt for a photo already on one
  (a deliberate split keeps one photo through ExpenseSplitService, not a second receipt).
- **In the check:** two receipts for one bank line → same photo = duplicate (Penny proposes rejecting the later
  one; Tim's yes); different photos → compare purchase time, then Otto's GPS trip runs (`ops_trip_runs`, dump /
  supplier runs) for that day; still unclear → ask, side by side.
- **First run:** reject #372 and the draft copies #341, #342 (previewed, Tim's yes).

## A3. Purchase time

The reader extracts the purchase time (`extractPurchaseTime()`), but it is kept only inside ocr_parsed_json,
which is mostly empty: 13 of 368 2026 receipts have it. Store it in its own column at every save
(migration, MySQL 5.7 TIME NULL), show and edit it on the receipt, use it for duplicates and GPS stop
matching. Backfill: re-read photos only where the rescan is free (no paid model calls) — or leave old ones.

Otto's GPS trip runs start 2026-10-06 (1 dump run so far), so dates before that have no trip record;
whether older raw GPS points exist to rebuild them is unverified.

## B. Side by side (receipt | statement)

No PDF needed: left = the receipt photo (existing serve-receipt.php), right = that account's statement lines
from the database for the date −7/+7 days, with running balance where the CSV gave one, exact-amount lines
highlighted, and buttons "This is the one" (link) / "Not on this statement" (step 5). Stacks on a phone.
Shared component (`mw-receipt-statement.js` + `.mw-rs-*` in mowology-brand.css, logged in COMPONENTS.md),
opened from: the returned-to-draft note, Penny's question, the statement checker's "nothing posted" lines.
Permission: `expenses.approve` (view lines) — never isAdmin().

Later (optional): keep each uploaded statement PDF (outside the web root, `ledger.view`) and add "open the PDF".

## Verification
phpunit → local site as admin and manager → Codex review (money) → deploy → production preview of the first
run → Tim's yes → commit → statement checker.
