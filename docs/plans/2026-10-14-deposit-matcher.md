# Plan — payments land in holding accounts, and deposits clear them (the "deposit matcher")

Status: Tim asked for it on 2026-10-14 ("deposit matcher — yes"). This plan absorbs and extends
`2026-10-13-stripe-clearing-account.md`, which Tim had OK'd as an idea and which was never built.
Owner: Penny. Nothing gets built until Tim says yes to this plan.

## Evidence, from production on 2026-10-14
- Chequing books minus bank, at each month end: Feb −477, Mar −530, Apr +337, May +2,470, Jun +1,316, Jul +1,471,
  Aug +1,879, Sep +2,344.
- Codex's root-cause review (27 files) was confirmed with queries. The main cause: **every recorded customer payment
  posts straight into chequing (1010)**, and the bank deposit it arrived in is linked only some of the time.
  - Payments posted to 1010 with no allocation row at all, so the existing linker can't see them:
    Apr 5, May 46, Jun 77, Jul 69, Aug 36, Sep 53.
  - By method (Apr–Oct): Stripe card 236 payments ($33.0K); cheque 20 ($25.2K); e-Transfer 38 ($7.9K).
  - Stripe payouts in the bank: some were filed as transfers, and some were left as income and posted to 1010 again
    (Apr $4,987, May $3,760, Jun $595). Card money is counted at the gross amount on the payment day; the bank gets
    the net amount 2–5 days later.
  - Exact one-to-one doubles (a payment plus an income deposit of the same amount): Apr $2,835 (2), Jun $686 (3).
- Smaller causes, each confirmed:
  - 8 debit receipts booked twice ($262);
  - possible duplicate statement lines Jan–Jun (~$5.2K, to be checked one by one);
  - $17 of savings interest.
- Ruled out: card charges booked twice; lost or doubled reposts.

## The fix — the standard bookkeeping "undeposited funds" pattern
Money you've received but that isn't in the bank yet sits in a holding account. The deposit moves it into the bank.
1. **1015 Stripe clearing**, as in the earlier plan.
   - A card payment posts DR 1015 / CR A/R.
   - A payout posts DR 1010 (net) + DR 6850 fees / CR 1015 (gross), on the bank line's date.
     `StripePayoutService` already reads every charge and fee in a payout.
2. **1016 Undeposited funds**, for cheques, e-Transfers, EFT and cash.
   - A recorded payment posts DR 1016 / CR A/R.
   - The bank deposit posts DR 1010 / CR 1016.
3. **The deposit matcher** (Penny's bank desk) links each customer deposit to the payments it carries.
   - Same payer, or a payer alias; the payments dated up to 10 days before the deposit; amounts that sum exactly.
   - Several invoices per deposit are allowed.
   - An exact unique match is proposed for one-tap approval. Ties and near-misses go to a short "you decide" list.
   - Uses the existing pieces: `InvoiceReconciliationService::linkAllocationsToDeposit`, `PayerIdentityService`,
     and the e-Transfer email match.
4. **Visible holding balances.**
   - 1015 must equal Stripe's balance (pending + in transit).
   - 1016 is the list of "recorded, not yet seen in the bank" payments. Anything older than 10 days is a question
     for Tim: was it ever deposited?
   - Chequing then compares line for line, so any drift left is a real error with a date.
5. **Unchanged:** revenue (it's on the invoice), fees in total, locked months, and the rule that deposits before
   2026-02-25 (Jobber era) stay income.

## Unknowns, and how each is settled (cheapest first)
| Unknown | How it's settled |
|---|---|
| Every code path that posts a customer payment to 1010 | `graphify touches` on `payment`, `stripe_payment`, `payment_allocation`, `InvoicePaymentPlanner::entryFor` and `LedgerService` payment builders; read each one. Codex's list already names `LedgerService.php:275-293`, `InvoicePaymentPlanner.php:106-163` and `LedgerSyncService.php:254-370`. |
| What a "payment" entry with no allocation is | It comes from invoices marked paid (source_id = invoice id). Confirm on 3 real invoices: how each was marked paid, and where its money is in the bank. |
| Does the repost land April–September at $0 drift? | Read-only replay before any code ships: rebuild 1010, 1015 and 1016 for 2026 from the journal, the statements and Stripe's API (read-only), using the new rules. Pass: each month's 1010 drift is $0 or a named leftover item (duplicate line, receipt double, interest). |
| Stripe's balance per date | `StripePayoutService` already calls payouts→all and balanceTransactions→all, read-only. |
| The accountant's view | Revenue and expense totals are unchanged; only timing between cash accounts moves. Tell Ricole in the held email, and only with Tim's yes. |
| Refunds and disputes | Payouts that contain them stay manual, as they are today (`StripePayoutService::check`). |

## Steps, each proven before the next
1. **Replay** (read-only, no AI): the month-by-month 1010 / 1015 / 1016 table under the new rules, compared with
   the bank. Shown to Tim.
2. **Code:**
   - chart accounts 1015 and 1016 (migration);
   - posting helpers (payment → holding account by method; payout or deposit → bank);
   - the matcher service and tests;
   - Penny's bank desk "Deposits" list.
   - Local site first, then a Codex review.
3. **Repost 2026 (after 2026-02-25)**: payments and deposits are reversed and reposted. Preview, then commit.
   No locked month is touched.
4. **Matcher pass:** link everything with an exact unique match, then give Tim the short list for the rest.
5. **The smaller causes, one at a time with Tim:** the 8 receipt doubles, duplicate lines checked against the
   statements, savings interest.
6. **Done when** Jan–Sep chequing shows $0.00 drift, or every leftover cent is named. Then months can lock.
   Penny's skill and traps are updated.
