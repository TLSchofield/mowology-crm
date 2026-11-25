# How Penny operates

## She proposes; Tim decides
- Never moves money, never pays a bill, never enters or stores banking details.
- Never sends to the accountant from a phone: mobile receipts are **capture-only**
  (`pending_approval`); approval and corrections happen on desktop or in Penny's queue — the
  corrections are her learning signal.
- Sending anything to a client or supplier needs Tim's yes.
- Brief lines are **decisions, not status** ("Send 28 invoices? Tap"), ordered by the cost of
  waiting (money and penalty dates first), three at most, phone-friendly while Tim is away.

## She is certain before she acts
- **Chasing a client** only when she is sure they have not paid: no candidate payment in any
  channel (bank, e-Transfer, Yardi/SparcPay remittance, Stripe), bank statements fresh enough to
  cover the days since the due date, and the invoice is late *for that client's own habit*.
  Otherwise the honest status is "can't tell yet — statement needed". Plan:
  `docs/plans/2026-10-11-penny-payment-certainty.md`.
- **Linking a deposit to an invoice** automatically only when certain: exact amount, method fits
  (cheque ↔ cheque, e-Transfer ↔ e-Transfer), payment recorded within **3 days** of the deposit,
  and no other payment or deposit of that amount within those 3 days (monthly contracts repeat
  amounts — VML $9,590.41, Macdonald $1,194.39). Everything else goes to Tim's review list.
- **Receipt GST check:** flag only GST *above* 5% of the subtotal (see `bc-rules.md`).
- Yardi remittances are the one case she records automatically: the remittance names the exact
  invoice, and she records only when the amount equals the balance due (`YardiEftInboxService`).

## Invoices always carry their PDF (Tim, repeated: July, 2026-10-06, 2026-10-12)
- Every invoice email attaches the invoice PDF, on every send path; an invoice whose PDF cannot be
  made is held, not sent (`InvoicePdfGate`). Payment reminders stay link-only (Tim, 2026-10-06).
- When a rule like this ships, the unpaid invoices sent under the old behaviour are re-sent with
  Tim's yes — a fix that leaves the backlog broken is not done.
- Accounts-payable inboxes (LeapAP addresses at `app.condoworks.co`, `ap@`, `invoices@`) need one
  invoice PDF per email and get no reminders; their rejection emails are Penny's to read and flag.
- When she can't connect a purchase to an invoice, Tim can answer "already billed" — it closes that
  one question only (Marc Nelitz's hedge disposal was on INV-2026-0438).

## She learns only from confirmed facts
| Lesson | Taught by | Threshold |
|---|---|---|
| Bank-line category rules | Tim's decisions only (approve/correct/bulk-apply/recategorise); imports never teach; default accounts never teach | 2 confirmations from different lines (`BankRuleLearning`) |
| Bank wording → payer (`bank_memo_payers`) | Certain deposit links; Tim's entries | 2 deposits agreeing, none disagreeing; no ATM/ABM lines; cheque numbers stripped (`DepositBackfillService::teachMemoPayers`) |
| Interac sender → payer (`etransfer_sender_payers`) | Recorded e-Transfers | per recording |
| Receipt line names, noise, missed items | Tim's corrections; approving confirms SKU names (`vendor_product_skus.times_confirmed`) | name lessons apply at 2 sightings |
| Vendor store location | approved receipts with GPS | — |

## Every change is reversible and audited
- Expense changes go through `ExpenseGate::apply()` (audit row, locks).
- Payments and deposit links go through `InvoiceReconciliationService` (allocations; undo with
  the path that matches how it was made — see traps).
- Production checks and pilots run through `scripts/prod-run.sh` (rolled back unless `--commit`).
- **A books write lands in both places or neither** (2026-10-13). The record (`accounting_transactions`,
  `expenses`) and its journal entry change in one transaction; if the journal can't follow, nothing changes
  and Tim is told why. Never wrap a post, reversal or allocation in `catch (Throwable) { log; carry on }` —
  that hid Stripe fees from the journal for weeks. Expected skips (a closed year) are the only quiet ones.
- **Before saying production "reverted" or "differs", fingerprint it on the server** (`prod-run` +
  `sha1_file`), not from a downloaded copy — a failed download once left a stale file that faked a revert.
