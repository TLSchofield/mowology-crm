# Ittai — "Switch from Jobber" (requirements from Mowology's own move, 2026-10-12)

Mowology moved off Jobber by hand: an import (`JobberImportService`, `JobberLedgerService`,
`PaymentMatchService`), a filed-FS opening (`Fy2026OpeningService`) and a bank reconciliation
(`BankBalanceCheckService`). Reconciling 2026 chequing to Vancity showed a −$87K gap between books
and bank. Every requirement below comes from something that went wrong or nearly did. Status: requirements only; nothing
built for customers yet.

## What the tool must do, in order

1. **Get the customer's year-end from their accountant first.** The filed financial statements are the
   truth for the opening balance sheet (cash, receivables, shareholder loan, tax owing). Jobber's own
   numbers are not. Store the opening as *top-ups over whatever history was imported* and show balances
   as sums, never the top-up lines (they were misread once).
2. **Import Jobber's exports — and know what each lacks.**
   - Invoices Report: every invoice ever, but no paid date or tax split.
   - Transaction List: **one year only** (the year chosen in Jobber) — ask for the switch year; invoice
     rows in it duplicate the Invoices Report and are skipped.
   - Jobber Payments list: card payments with fee and Stripe payout id.
   - They arrive **by email**; let the customer forward them or connect the inbox.
3. **Ask how each big payer pays.** Jobber only knows payments someone marked paid in Jobber.
   Property managers' cheques (Mowology: VML, Laburnum) and EFTs (Dorset, Norsan via Yardi) went
   straight to the bank and were never marked paid — $47.7K of deposits had no Jobber payment. A short
   interview ("who pays by cheque / EFT / card / e-Transfer?") drives the matching.
4. **Read remittances from every inbox.** Yardi remittances name invoice #, property and amount — 54
   sat unread in a personal iCloud inbox. Scan all connected mailboxes for remittance senders and use
   them to tie EFT deposits to invoices exactly.
5. **Import the bank first and prove it.** Bank CSVs with a running balance (Vancity) tie to the cent;
   check every month opening + lines = closing and that there are no missing months **before** matching
   anything. Missing Mar–Jun lines and overlapping imports (79 duplicate ledger entries, $23.7K) hid the
   real picture at Mowology.
6. **Don't trust Jobber's payment dates or methods.** Owners mark invoices paid in Jobber late and in
   bulk: Dorset's Jan/Feb Yardi EFTs (in the bank Jan 13 / Feb 17, one deposit per invoice) were marked
   paid in Jobber on Jun 16 as two "e-Transfer" payments of $2,971.62 and $1,891.53 covering 16
   invoices. Match a deposit to the **invoice** (by remittance, amount, payer), then consume that
   invoice's share of whatever Jobber payment closed it — never require Jobber's payment date or
   amount to equal the deposit.
7. **Match deposits to open invoice balances, not only to Jobber payments.** Exact cents, close in date,
   one-to-one; anything ambiguous goes to a short owner review list.
8. **Split by invoice date (accrual).** A deposit paying an invoice dated before the switch year settles
   the opening receivable; paying a switch-year invoice is income unless already counted. Never count a
   payment as income twice.
9. **Ask about owner transfers before any rule files them.** Mowology's bank rules filed 33 e-Transfers
   to the owner ($49K) as crew wages while he was on payroll. Never learn a rule from the owner's name,
   or from generic bank words ("bill payment", "cheque").
10. **Tax payments are never expenses.** CRA/government payments → instalments / tax payable. Check the
   accountant's instalment schedule against the bank and flag missed instalments.
11. **Finish with a reconciliation report the customer and their accountant can read:** bank vs books
    per month, what was matched, what's left and why.

## Multi-tenant notes
- Shared (all tenants): Jobber export formats, remittance senders (Yardi, SparcPay, Interac), the
  matching rules above. Private (per tenant): payer channels, owner transfers, accountant figures.
- The Mowology code is single-tenant today (`JobberLedgerService` constants such as `FY_START`); the
  tool needs the switch date and year-end per tenant.

Sources: vault `Accounting-Ledger.md` "Jobber import — lessons learned"; Penny `traps.md` 2026-10-12.
