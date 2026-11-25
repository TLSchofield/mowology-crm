# Codex review fixes — every real finding (2026-10-14)

Source: Codex reviews (crew, receipts, payroll, portal, penny-money, penny-books, penny-desk), each
finding checked against the real code. "Not real" findings are left out. Rule for every batch:
phpunit → local site → `scripts/codex-review.sh` on the changed files → deploy merged with live →
verify on production.

## Unknowns and how each is settled

1. **GST report and Jobber invoices.** Production has 242 Jobber invoices from 2026 with no
   `crm_invoice_id`: 120 in Q1 (GST $3,835.52) and 122 in Q2 (GST $1,180.31). These are queried
   this session. The CRM took over on 2026-02-25, yet Q2 still has 122, so some may be the same job
   invoiced again in the CRM but never linked. Adding them blindly could double-count GST.
   *Settle:* match them by client + date + total against CRM invoices before any code is written.
   Count the true Jobber-only ones and report that number before the fix ships.
2. **Stripe idempotency key.** The key must change whenever the amount changes, or Stripe returns
   the old intent. *Settle:* one Stripe docs page; key on invoice + amount + attempt.
3. **The ledger P&L matches the old tab.** *Settle:* compare `ReportingService` against the legacy
   P&L for one closed month locally, before switching the tab.
4. **Bank rules force-apply through `BankLineMoveService`.** It reposts journal entries. *Settle:*
   dry run on a production preview (rolled back), checking the count of lines that would move.
5. **Locks inside existing transactions** (`FOR UPDATE` while a caller already holds a
   transaction). *Settle:* read each caller; PDO nested `beginTransaction` throws, so use the
   existing "own transaction" pattern (ExpenseGate `$own`).

## Batches (in order, each verified before the next)

**A. Money in/out — highest risk**
1. GST report adds the true Jobber-only invoices (after unknown 1).
2. `ClientCreditService::applyOnSend`: when the client has credit and applying it fails, autopay
   skips that invoice and Penny gets a notice. The invoice still goes out.
3. Card payment intent: Stripe idempotency key, and the amount is re-checked against the balance
   before saved-card confirm.
4. Prepayment credit: the insert and the bank-line move go in one transaction; roll back if the
   move fails.
5. Bank import: a savepoint around the deposit relink, so a failure can't leave a double count.

**B. Payroll and time**
6. Bulk approve runs the same three checks as single approval: no open shift, recalculate,
   no self-approval.
7. Clock-in and job-timer start: lock the user row so two shifts or two timers can't open.
8. `auto_clockout` / orphaned timers: guarded updates (`AND clock_out IS NULL`), and no SMS when
   nothing changed.
9. Wave payroll approve in one transaction; undo sets preview only when every move came back.

**C. Books integrity**
10. P&L tab moves to the ledger income statement (after unknown 3).
11. Bank rules: force-apply skips locked months and moves journaled lines through
    `BankLineMoveService`.
12. The nightly invoice sync skips locked months on the legacy table.
13. Meals: an unsplit meals receipt posts 50% ITC in the ledger, matching the GST report.
14. `lockMonth` checks readiness itself and refuses unlisted months.
15. Jobber item booking in one transaction; a missing revenue log row is written on retry.
16. Bank-account split / line move: the pair moves in one transaction.

**D. Receipts and Penny's desk**
17. Merge expenses: ownership check, same as edit.
18. `receipt_media_id` must belong to the user (or office).
19. A failed repost after an edit shows a warning on the expense.
20. Receipt forward: the send is marked sent in its own write, so a retry can't email twice.
21. Penny `decide`: reject non-numeric or negative amounts and totals that don't add up; lock the
    suggestion and update only while it's still pending; approve before marking accepted.
22. "Use Penny's items" uses the server's all-at-once path.

**E. Client pages**
23. Quote accept: status-guarded update, with no second email.
24. Upsell line: a transaction with the quote row locked.
25. Nightly quote sweep also retries a snow-contract setup that failed.

## Not fixed (by design or Tim's call, said so before)
- Other crews' jobs embedded on each phone, and the per-visit geofence check: Tim to decide.
- `rebook.php` duplicate window: two office emails at worst.
- Two-tab races on admin-only tools beyond the ones above.
