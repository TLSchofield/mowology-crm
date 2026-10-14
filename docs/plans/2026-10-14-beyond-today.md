# Beyond today: what would make the CRM better than it is, ranked

Written 2026-10-14 at Tim's request. Every item comes from something that went wrong, or was done by hand, in the 2026-10-12 to 10-14 sessions; the evidence is named. **Nothing here is built without Tim's yes.**

Ranking: the money or trust it protects first, then how often it recurs, then cost.

## 1. The books tell you they're wrong, the day they go wrong
**Evidence:** chequing drift was found by hand, month by month (Feb −$477.37 through Sep +$2,344.10). Journal posts failed silently until Part A of the ledger plan. Autodeposit has been off since Jun 12 and nobody was told.

**Improvement:** a nightly **books-vs-bank check** for every account. It uses the per-account test that MonthCloseService already runs. Any new difference over $1 becomes a Charlie alert that names the day and the lines. Add **canaries** for things that should happen and stopped: no Interac autodeposit in 14 days, no Stripe payout in 10, no text-bridge contact in 24 h, no bank import in 35 days.

**Size:** small. The checks exist; this schedules them and alerts.

## 2. Payer identity (separate plan: `2026-10-14-payer-identity.md`)
One record of who pays as whom, used by Penny, Yui, Sam, payroll and consent.

## 3. Month-close becomes a routine, not a project
**Evidence:** January needed 11 bank lines answered and one receipt approved; Feb–Sep are untraced.

**Improvement:** on the 16th of each month (after the 15-day grace), Penny sends Tim **one list** for the month just ended. Each line has her best guess and a one-tap answer (the receipt is already there, this is personal, this is diesel). The month locks when the list is empty. Tim spends 10 minutes a month instead of a day a quarter, and Ricole gets locked months.

**Size:** medium. MonthCloseService::blockers already produces the list; this adds the guesses and the one-tap answers.

## 4. Stripe clearing account (plan already written: `2026-10-13-stripe-clearing-account.md`, awaiting yes)
Card payments meet their payouts in one account, so every fee and every payout is provably matched. This settles the "deposit ≠ invoice" puzzles at the source.

## 5. Receipts made outside the business accounts
**Evidence:** Tim asked how they are handled. Today a receipt paid personally has no bank line to meet, so it can sit as a draft forever (16 drafts, $1,290.12 on chequing).

**Improvement:** a receipt's "paid from" choice (business card, chequing, **personally — owe Tim back**). A personal one posts to the shareholder loan (1300 / 1302) at once and never blocks a month.

**Size:** small. Account 1300 and BankGuidanceService's shareholder rules exist.

## 6. The importer trusts the statement, not the upload choice
**Evidence:** a session took its bank account from the dropdown, not the statement section. That caused the GST-Reserves/chequing mix-ups.

**Improvement:** read the account number from the statement header. Refuse the upload if it disagrees with the dropdown.

**Size:** small; open item from 10-13.

## 7. Collections on a cadence, with Tim's yes
**Evidence:** John Hughes has 7 open invoices ($352.80, since Jul 16); Kelly Cannon has 6 ($409.50). These were found only by asking.

**Improvement:** a weekly "who owes what" card. A friendly reminder is drafted at 30 days and a call note at 60. **Nothing is sent without Tim's tap.** Credit is applied before autopay (already shipped).

**Size:** small; reminder sending exists.

## 8. Data the database can't hold silently drops
**Evidence:** production MySQL is in non-strict mode. `accounting_transactions.status='void'` and `invoices.payment_method='account_credit'` were stored as `''` without an error, and code checking for 'void' was dead.

**Improvement:** a test that reads each enum the code writes and asserts the column allows it, plus a nightly check for `''` in enum columns. Longer term, turn on strict mode for the CRM's own connection (`SET SESSION sql_mode`) after a week of logging what would fail.

**Size:** small for the test and check; strict mode is a separate decision.

## 9. Fable reviews the remaining segments (plan: `2026-10-13-fable-full-crm-review.md`)
The two reviews done found 9 High problems that tests missed. Wave A (receipts, payroll, crew app, customer portal) is the next-highest risk.

## 10. Permissions as data, per tenant
**Evidence:** Tim's 10-12 rule: admins *and* managers edit, gates on permission keys, never `isAdmin()`.

**Improvement:** the role × permission page Tim asked for, with a test that fails on any new `isAdmin()` gate. This is required for Ittai.

## 11. Ittai: what today's work hands to the product
- **The month-close list** (item 3) is a selling point no field-service CRM has: "your books close themselves; you answer ten questions a month".
- **Payer identity and the text bridge** turn e-Transfer-heavy Canadian businesses' worst chore (matching Interac payments) into a one-tap job. That is a CAD-market edge over Jobber.
- **Keep the split:** payer identity, books and messages stay tenant-private. Only vendor and category knowledge is shared (the commons that already exists).

## Order I'd suggest
1, 5, 6 and 8 are each under a day and stop silent damage. Then 2 (payer identity) and 3 (month-close routine), which remove the most hand work. 4 and 7 follow, and 9 runs alongside in the background.
