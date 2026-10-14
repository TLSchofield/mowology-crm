# Plan: the ledger never fails silently, and chequing balances

Status: **Tim asked for this plan on 2026-10-13. Nothing gets built until he says yes.** Owner: Penny.
Source: Fable's read-only review of 2026-10-13. Claims 1, 2, 4 and 5 were spot-checked against the code the same day:
- `LedgerSyncService.php:473-475` and `:500-502` swallow errors as "skipped".
- `LedgerRepostService` is only called by `run-repost-ledger.php` and `BankLineMoveService`, so there is no cron.
- `BankLineMoveService::move` runs its UPDATE outside a transaction.
- `BankImportService` has no `LedgerService` reference.

## The rule this plan enforces
A money write either lands in **both** `accounting_transactions` and the journal, or in **neither**. When one side fails, the owner sees it on Penny's card the same day, with the record and the reason.
The pattern to remove is `catch (Throwable) { log or count; carry on }` around a journal post. It caused the Stripe fee bug (fixed today, `b5…`) and every item below.

## Part A: silent failures (the six High findings), in this order
1. **Journal problems become visible.** `LedgerSyncService` stops counting failures as "skipped":
   - each failure is written to a new `ledger_sync_failures` table (source type and id, reason, first and last seen, count);
   - Penny's card shows the open ones ("3 invoices could not post: unmapped account 4120").
   - Pre-FY skips are counted separately.

   This comes first because it is how every later bug gets caught.
2. **Bank line moves are atomic.** `BankLineMoveService::move()` puts the UPDATE, reversal and repost in one transaction, and returns `ok=false` unless the journal is posted. Payroll and shareholder callers already check `ok`.
3. **Deleting a posted receipt.** The journal unpost runs inside the delete transaction and its failure is rethrown. A locked month refuses the delete with a plain message.
4. **Receipt edits from Penny's card.** The re-post joins the caller's transaction (as `postEntry` already can), so "waits for the nightly repost" goes away. A nightly safety net, `LedgerRepostService` in `sync-ledger.php`, re-posts anything whose entry no longer matches its record, and reports through item 1.
5. **Undoing an import reverses its journal entries** in the same transaction, for both the rollback and duplicate removal.
6. **Card-statement "PAYMENT" guard.** A line is only a card payoff when it is a credit (`cc_role !== 'charge'`). First a read-only query counts real 2400 lines whose wording matched but whose sign says "charge". Any it finds are listed for Tim, not auto-fixed.

## Part B: make chequing balance (data, each one previewed, then committed with Tim's yes)
7. **Five income deposits with no cash in the journal: $3,130.56** (tx 53285, 6992, 7016, 28014, 51339). Each one is matched to its invoice or payment, or posted as income, deposit by deposit. They are shown to Tim first.
8. **16 chequing debits matched to draft receipts: $1,290.12.** The receipts are finished (approved), or the match is undone so the bank line posts by itself.
9. **Cheque payments recorded before their deposit** (Kanco pattern). The auto-link in `linkRecordedPaymentsToDeposit` is extended from e-Transfers to cheques and EFT, with the exact-sum rule. Today's Kanco line is linked by it.
10. **The balance check reads the wrong statement line** for chequing March–May (a GST Reserves interest line, $8.59) and can't read card balances. Lines that `chainWithinDays` flags as another account's are left out of an account's month-end. Card balance parsing is fixed or the card is marked "counts only".
11. **The Stripe holding account** (separate plan `2026-10-13-stripe-clearing-account.md`) goes after 7–10, so the remainder is small enough to read.

## Part C: the Medium and Low findings, after A and B
- `ClientCreditService` posts inside its transaction and matches a deposit by payer as well as amount.
- 2026 payments on 2025 invoices post even though the invoice entry is refused.
- Reverse-then-repost is made atomic in `BankTransferDirectionRepair`, `BankDeskService` and `IncomeCleanupService`.
- Stripe refunds and disputes are dated by the event, not by today.
- `orphanEntries` stops proposing mass reversals when its query fails.

## Unknowns, and how each is settled (cheapest first)
| Unknown | How it's settled |
|---|---|
| How many invoices and expenses are failing today | A read-only replay of `syncAll` on production that captures each exception (preview, rolled back). This sizes item 1 before building. |
| Whether item 6 has ever happened | A read-only query of 2400 lines (see item 6). |
| Whether the five deposits (item 7) are real income, Jobber-era, or already paid | Read each one with its bank memo and the invoices of that payer. Tim decides any that are unclear. |
| Does wrapping moves in one transaction break callers that already hold one? | `postEntry` and `reverseEntry` already join a caller's transaction. A unit test covers move inside and outside a transaction. |

## Steps and proof
- **Each item:** a unit test that fails first and then passes; `vendor/bin/phpunit`; the local site, including the smoke test and the page involved; `deploy-merged`; `prod-file` returns `same`; OPcache reset; a production check of that page.
- **After Part A:** a production integrity query must show the same zero counts Fable found (unbalanced, duplicates, orphans), and the new failures list must be empty or explained.
- **After Part B:** the chequing balance check shows month-end drift within card timing for every 2026 month, each remainder named.
- **Afterwards:** Penny's traps get one entry per pattern, and the vault (Known-Failure-Patterns) gets "swallowed journal post".
