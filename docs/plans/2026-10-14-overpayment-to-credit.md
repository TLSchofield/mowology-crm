# Plan: Penny keeps an overpayment as client credit

Status: **Tim asked for it (2026-10-14: "Penny should be able to do these sorts of things"). Waiting for his yes to this plan.** Owner: Penny.

## Why
On 2026-10-14 Alberta 3025 Strata sent $165.38 for "Invs 2026-0251 and 0383". INV-0251 was already paid ($82.69 on Aug 1, Interac CAhnZCFP), so only INV-0383 was open.
- Tim decided: pay INV-0383 and keep the other $82.69 as credit for the next invoice.
- What's done today: the $82.69 is recorded to INV-0383 (e-Transfer #31). The other $82.69 sits on #31 as "not yet allocated".
- Penny had no way to do the rest. There is no "the rest becomes credit" step.
- If the bank line were linked today, `InvoiceReconciliationService::recomputeDepositRow()` would book the leftover $82.69 as **income**. It should be a liability (2160 Customer Deposits) until the credit pays an invoice.

## What changes
1. **One service method:** `ClientCreditService::addOverpayment(clientId, amount, notificationId?, bankTxId?, userId)`.
   - It writes a `client_credits` 'deposit' row that records where the money came from (new columns `etransfer_notification_id` and `bank_transaction_id`, migration 1363).
   - It posts **DR 1010 Bank / CR 2160 Customer Deposits** on the day the money arrived. This mirrors how the invoice part posts DR Bank / CR AR, so the bank line itself is never income.
2. **e-Transfers:** `EtransferInboxService::recordPayment()` gains `credit_rest: true`.
   - After the invoices, whatever is left becomes credit through `addOverpayment`, and the notification is marked fully recorded.
3. **Bank deposits:** `recomputeDepositRow()` counts that credit as settled.
   - Credit linked to the line directly, or through its e-Transfer, counts like an allocation.
   - So a $165.38 line made of $82.69 invoice and $82.69 credit becomes a cash-clearing transfer, not $82.69 income.
4. **Penny proposes it.** When a payment is more than the open invoices it names (or more than all the payer's open invoices), her e-Transfer and bank cards say: "$82.69 pays INV-0383; $82.69 is extra — INV-0251 was already paid Aug 1. Keep it as credit?"
   - One tap does it. Refunds stay a person's job.
   - The credit is used automatically before the next invoice is sent, through `ClientCreditService::applyOnSend()`, which already exists.
5. **Finish #31:** run step 2 for the strata's leftover $82.69 with Tim's standing yes.

## Unknowns, and how each is settled
| Unknown | How |
|---|---|
| Does the payment journal for an e-Transfer allocation post at record time or at the nightly sync, and to 1010 or to undeposited funds? | Read `LedgerSyncService`'s payment_allocation path. The credit entry must use the same account and timing. |
| Which `client_id` is the credit's: invoices store contact_id 51; `getBalanceForInvoiceClient(393)` gives client 2107 | Already answered on production: 2107. `applyOnSend` reads the same resolver. |
| Does `applyOnSend` find credit by that client for the next invoice? | A test: credit for client 2107, a new invoice for contact 51, then send. |
| MySQL 5.7, non-strict | New columns are INT NULL; no ENUM changes. |

## Steps and proof
1. Migration 1363 and `addOverpayment`, with tests: credit row, journal entry, nothing booked as income.
2. `recordPayment(credit_rest)` and the `recomputeDepositRow` change, with a test using the strata's numbers: $165.38 line equals $82.69 allocation plus $82.69 credit, giving a transfer with no income.
3. Penny's card proposal and one tap, checked on the local site as admin and as manager.
4. Deploy, check server fingerprints, then preview #31 on production before committing.
5. Penny skill: a traps entry, plus the "one place" table.
