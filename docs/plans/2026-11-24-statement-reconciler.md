# Statement reconciler — every chequing line against the books (plan, 2026-11-24)

**Goal.** One read-only tool that takes the Vancity statement CSVs as the truth and says, for every month, which statement lines
the books do not carry and which book entries the statement does not carry — each with the reason a pair matched or did not.
It replaces the hand chase done on 2026-11-24 (Aug #294, Oct #414/#20, May duplicates, #247, #116, the Jobber fee), which found
every difference but took dozens of one-off queries and misfired twice (the Chevron $6.92 and Shell $20.00 "double bookings"
were not real).

**Why a tool, not another chase.** Four things made the ad-hoc passes unreliable:
1. `bank_import_rows` holds the same statement line two or three times under different transaction ids (28 groups in 2026), so it cannot be the reference.
2. Deposits are booked per invoice payment, not per bank line, so one deposit rarely equals one entry.
3. A receipt replaces its bank entry, often on another date (#247 off by 26 days, #116 by 30), and every replacement leaves a reversal pair.
4. A receipt's bank link can point at the wrong line (#91 Esso is linked to the Shell Apr 30 line) without the books being wrong.

## Design

- **Reference.** The Vancity CSV files (`Date,Description,Debits,Credits,Balance`). One statement line = one distinct `(date, signed amount, running balance)`; the balance column is what separates two identical same-day charges and collapses a file exported twice. Built-in check: for each month, closing − opening balance must equal the sum of its lines, and the month-end balance must equal the figure `BankBalanceCheckService` reads.
- **Books side.** Journal lines on account 1010, status `posted`, with every reversal chain collapsed (an entry and the entry that says `Reversal of #N` cancel). What remains is what the books really carry. Sources kept for the "reason" column: `bank_import`, `expense`, `payment`, `payment_allocation`, `stripe_payment`, Jobber, adjusting.
- **Matching, in tiers, each pair recorded with its tier:**
  1. same source link (bank transaction ↔ its own entry; receipt ↔ its entry);
  2. same signed amount, nearest date within 40 days;
  3. group sums: one deposit equal to the sum of 2–6 unmatched invoice-payment entries within 14 days (small subset search, capped);
  4. anything left is reported unmatched on its side.
- **Output.** Per month: statement net, books net, difference, and the difference from the service's drift for the same month (they must agree to the cent). Then the unmatched lists, a count of pairs by tier, and JSON for later tooling.
- **Read-only.** A shell wrapper reads the CSVs locally, inlines the lines into a PHP snippet and runs it through `scripts/prod-run.sh` in preview (rolled back). No writes, no emails, nothing outside the transaction.
- **Fixes stay as they are today.** The tool only finds; every correction still goes through `ExpenseGate` / `InvoiceReconciliationService` with a preview and Tim's yes.

## Files (new logic in a service, per CLAUDE.md rule 10)

- `app/Modules/Accounting/Services/StatementReconcileService.php` — pure matcher (arrays in, arrays out, no DB) plus a thin loader for the journal side. No namespace, no autoloader; PHP 7.4-compatible; MySQL 5.7 SQL only.
- `scripts/reconcile-statement.sh` — wrapper (CSV → snippet → `prod-run.sh` → report).
- `tests/Unit/Accounting/StatementReconcileServiceTest.php` — synthetic fixtures only; registered in `tests/bootstrap.php`.

## Production and other tenants (added 2026-11-24, Tim asked)

Yes — worth building for the product, if v1 is built tenant-neutral from the start. As first drafted it was Mowology-only (a local
Vancity CSV, `prod-run.sh`, account 1010 and the year 2026 hard-coded). Changes to the design above:

- **Reference = statement lines the tenant has already imported**, read from `bank_import_rows.raw_row` (the running balance the importer already keeps — `StatementCoverageService` reads it the same way), de-duplicated by `(date, signed amount, running balance)`. A local CSV is only an optional override (Mowology's clean Vancity exports, used for the acceptance run). Cards with no running balance (TD credit card) fall back to `(date, amount, description)` plus the statement's printed totals, which the TD parser already exposes (`balance_meta`).
- **Any bank or card account**, not 1010: the account comes from `bank_import_sessions.bank_account_id` and the chart's account row; no hard-coded codes, years or bank names.
- **Reversal chains from the column, not the memo.** The journal already carries `reversed_by_entry_id` (used by `HoldingRepostService`); use it, so the tool does not depend on Mowology's memo wording. The memo text stays as a fallback only for older entries.
- **Runs inside the app, per tenant.** The service takes the tenant's own PDO (`getDB()` / `Database::pdo()`), so it can never read another business's books. Surfaces: a read-only "Statement check" in Ledger → Exceptions (permission `ledger.view`, so an accountant login can see it) and a nightly Ada/Penny check that raises a question only when a month's difference is not explained. `prod-run.sh` stays the way *we* run it on Mowology.
- **It finds; the owner decides.** Same rule as Penny everywhere: it reports differences and the reason for each pairing, never edits the books, never gives tax or accounting advice. Fixes go through the existing gates with a preview.
- **Why it sells.** A new tenant coming from Jobber or QuickBooks gets proof that their opening books and every month since agree with their statements, line by line, before the accountant asks. It also overlaps `BankBalanceCheckService`: that one explains a month's drift by cause; this one lists the lines. v1 should feed the same page, not sit beside it.
- **Tenant risks to test:** a bank with no running balance; two banks on one account; a tenant whose fiscal year does not start in January; statements imported in different formats; tenants with no statements yet (must say "nothing to check", not "all clear").

## Unknowns and how each is settled (cheapest first)

| Unknown | Settled by |
|---|---|
| CSV shape and dedupe key | Already read: 32 files, 1,967 distinct lines, Jan 1–Nov 24; Jan–Apr gives 444 lines after dedupe. Add the balance-chain check above. |
| Journal sign and reversal wording | Already seen: `Reversal of #N — …` memos; reversal date can equal the original's date. Matcher keys on the `#N` in the memo. |
| How deposits split across payments | Tier 3, capped; anything it cannot place is *reported*, not guessed. |
| Time-zone / posting-date shifts | The 40-day window; report the day gap for every pair so a consistently odd gap is visible. |
| Other accounts | Out of scope for v1 (chequing 1010 only). 1020/1025 have tiny CSVs; 2400 Vancity Visa needs its own statements (only May has a balance today); TD 0937 is a personal card booked owner-paid. |

## Steps and how each is proved

1. **Spec approved** (this file).
2. **Codex builds** the service, wrapper and test through `scripts/codex-web-build.sh reconcile-statement <task> --scope app/Modules/Accounting scripts tests/Unit/Accounting`. Codex sees code and synthetic fixtures only — never the statement CSVs or any client data. Exit 75 means Codex is out of usage: hand the same task to `web-builder`.
3. **Claude reviews** the real-file diff, runs `vendor/bin/phpunit`, then a Codex review (`scripts/codex-review.sh`) on the final diff; real findings fixed.
4. **Acceptance run (read-only)** on Jan–Nov chequing. It must pass all of:
   - per-month `books − statement` equals the service's drift change for that month (±$0.01);
   - the statement balance chain checks out for every month;
   - it re-finds the items already fixed on 2026-11-24 when pointed at the pre-fix data described in `traps.md` (the five documented signatures), and finds no false "double booking" for Chevron $6.92 or Shell $20.00.
5. **Use it:** list the Jan–Apr differences (the remaining +$21.38 of drift), then work them with previews as before.
6. **Afterwards:** a `traps.md` entry for anything new, the Obsidian write-back, and a line in `ittai-backlog.md` for v2 (savings, GST reserve, Vancity Visa).

## Not doing

- No auto-fixing, no marking bank rows as duplicates (separate plan: clean the re-imported rows).
- No use of `bank_import_rows` as the reference.
- No client statements or receipts given to Codex.
