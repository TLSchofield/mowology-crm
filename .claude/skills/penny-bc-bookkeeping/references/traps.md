# Traps already hit — read before any write to the books

Add a new entry the same day a mistake is found: date, what happened, the signature, the rule.

## 2026-10-11 — `InvoiceReconciliationService::detach()` un-pays the invoice
Built for deposits attached to *unpaid* invoices: it subtracts the allocation from `amount_paid`,
sets the invoice back to unpaid/overdue, NULLs `paid_at` and `payment_reference`, and deletes the
invoice's income row. Used to undo a link onto an invoice paid months earlier, it turned
INV-2026-0010 "overdue $1,194.39" on production (restored from activity_log 1408 + journal entry
20). **Rule:** undo a backfill link with `DepositBackfillService::undo()`; read a function's
writes before using it as an undo.

## 2026-10-11 — Claims made without checking
"Two bank accounts have future-dated data" (grouped by `bank_import_rows.account_id`, which is the
category); "Stripe deposits are double-counted" (Stripe payouts were already reconciled by
`StripePayoutService`). **Rule:** cite the query; search code + this skill before claiming a gap.

## 2026-10-11 — GST below 5% flagged as wrong
Risk check and Penny's preparer both required GST ≈ 5% of the subtotal; mixed baskets (zero-rated
groceries, deposits) were flagged (Chevron $0.12). Fixed to "at most 5%" in `checkGstMismatch()`
and `ReceiptBookkeeperService::check()`; 14 stored false flags cleared through the gate.
**Rule:** tax rules belong in one place (see SKILL.md "Not yet central").

## 2026-10-11 — Remittance emails are mostly not read (coverage gap; "broken" was an overclaim)
`yardi_eft_inbox_poll` ran 2,663 times reporting "Scanned 0 email(s)". First read as broken; the
likelier cause (from its code, not yet checked against the mailbox): it only searches exact
DoNotReply@yardi.com mail in office@, looks back 21 days and ignores anything before 2026-08-05 —
the office@ Yardi remittances are all May–Jul. It never opens info@ or iCloud (where Dorset's land),
and nothing reads SparcPay (strata BCS4428). **Rule:** a poller reporting zero needs its search
window and mailbox checked before it is called broken; and the remittances that name invoice numbers
are not being used yet.

## 2026-10-05 — Future-dated bank rows
A December 2025 statement imported before the year fix was dated 2026 (session 13, 122 rows).
Fixed by fix-1122 (session rolled back). **Rule:** before re-dating rows, look for a later
session covering the same period and walk the running balance.

## 2026-10-06 — Income double count (~$210K)
Unmatched bank deposits stayed `type='income'` beside their invoices' own income rows.
Stripe payouts, income clean-up and the deposit backfill have removed most of it; 2026 reports
should be re-checked with Steve before he relies on them.

## Jobber import must not re-record income
Importing Jobber invoices as *paid* would make `syncFromInvoices()` record their income again on
top of the deposits. Jobber-era deposits stay the income record; Jobber data only annotates them.

## A question kept the receipt as it was when Penny asked (2026-10-12, Lawnboy #412)
`penny_questions` stored the job, amount and contract at scan time. The owner had split #412
(seed → shop stock, mulch → a job) and the mulch was on the wrong job (998 W 19th); after the
correction Penny still asked "$200 for 998 West 19th — covered by their contract?". The three $84
mulch loads that day (#412's mulch line, #413, #417) all went to Oakridge Gardens (6015 Tisdall).
Fixed: `PennyQuestionService::open()` reads the expense's current job and that job's contract,
and for a split receipt asks only about the lines allocated to the job (`splitPart()`); seed in
stock is never "unbilled". **Rule:** a question shown to Tim is rebuilt from the record as it is
now, never from a snapshot.

## Invoices bounced off a client's accounts-payable system for two months (found 2026-10-12)
Colyvan Pacific (strata VR 1450, Mark dela Cruz) moved to LeapAP on 30 July; Tim registered and the
CRM sent VR 1450's invoices to the LeapAP address — link-only, because monthly contract billing had
no PDF until 2026-10-06. LeapAP rejected each one ("No PDF or image attachments found") and every
reminder; INV-2026-0314/-0372/-0431 ($1,370.52) went unpaid and nobody saw the rejection emails in
office@. The 2026-10-06 PDF gate fixed new invoices but the backlog was never re-sent: 57 unpaid
invoices were sent before it, 8 never had a PDF at all.
**Signature:** an invoice sitting "overdue" for a client who pays reliably; LeapAP "not accepted"
mail in office@. **Rule:** read accounts-payable rejection emails and flag them the same day;
re-send the backlog whenever an invoice-sending rule changes.

## `applyAllocation()` with a deposit id does not reconcile the deposit (found 2026-10-12)
Recording VCHA's INV-2026-0371 against the 14 Sep cheque with `applyAllocation(..., $transactionId, ...)`
marked the invoice paid and wrote an income row, but left the deposit as `income` — the same $1,235.44
counted twice (caught in the prod-run preview). **Rule:** paying an invoice FROM a bank deposit goes
through `InvoiceReconciliationService::attach($transactionId, [[invoice_id, amount]], $userId)`, which
records the payment AND flips the deposit to `transfer`; a payment already recorded is tied to its
deposit with `linkAllocationsToDeposit()`. Check the deposit's `type` after every such write.

## 2026-10-12 — Independent review (Fable, 6 sections), fixed the same day
Each rule below is now enforced in code; listed so a future change doesn't undo it.
- **Ledger revenue = issued invoices only.** Drafts/cancelled/void are never posted; an entry is
  reversed when an invoice is cancelled or goes back to draft (`LedgerSyncService::invoiceIsIssued`).
  4 sat in revenue ($1,181.55). Reversing alone is useless — the nightly sync re-posts reversed sources.
- **Client prepayment = 2160 Customer Deposits**, not income; applying credit posts DR 2160 / CR AR
  (`ClientCreditService`). Roberge cheque #011 counted twice.
- **No automatic posting dated before 2026** (`LedgerService::FY_START`): late 2025 records used to be
  re-dated into 2026 on top of the opening. Tests building 2025 history use `allowingClosedYear()`.
- **Journal posting joins an outer transaction** (`postEntry`, `reverseEntry`): reverse + change is
  all-or-nothing (receipt repost, delete, duplicate clean-up, account-map repost).
- **Yardi email alone never pays an invoice** — the From header is forgeable and the host adds no
  verification stamp (checked); the remittance's deposit must be in the bank (`recordWhenBanked`).
- **Interac:** replies skipped; a notice not from Interac (or our own Fwd:) is never 'high'.
- **Payments are dated when the money moved** (remittance / notice date), not the day recorded.
- **Deposit ↔ recorded-payment auto-link:** never before 2026-02-25, 3-day window, unique on both
  sides (another same-amount deposit nearby = leave it for a person). `attach()` locks the deposit.
- **A visit is invoiced once** (row lock; INV-0179/0185 and INV-0181/0183 were both paid twice).
- **Duplicates are compared within one kind of statement** (card vs bank) — a card payoff was
  dropped as a "duplicate" of the card statement's own payment line.
- **No bank rule from generic wording** (`BankRuleLearning::GENERIC_WORDS`).
- **Emailed receipts:** no ITC on GST the parser estimated; exempt vendors honoured; PST kept.
- **Phone receipts never go to the accountant unapproved**; set-aside copies can't be approved.
- **Contract billing:** one run at a time, GST from Settings, failed emails held and retried,
  greeting/SMS to whoever the invoice is addressed to; only `monthly` counts as contract-billed.
- Also fixed (same day, second pass): Jobber-era deposit booking is all-or-nothing; statements
  leave off invoices billed to a company the contact isn't part of (own company, or its primary/
  billing contact, counts as theirs); Interac/Yardi readers look back to their last good run after an
  outage (≤120 days); clean-up "record payment" is one transaction; no manual correction of a
  Stripe-paid invoice; credit top-ups keep the first payment date; a plain edit can't re-open an
  approved receipt; day-first statement files are detected per file.
- **Not fixed — needs Tim:** Laburnum Heights INV-0088/0089 (GST unpaid, possible double count),
  and counting part-paid "overdue" invoices as income waits on that answer. The bank statement
  balance proof can't run: no imported statement has both opening and closing balances saved, and
  card vs bank amounts are signed differently — fix needs real statements with balances.

## 2026-10-12 — Jobber-era deposits: Jobber never saw cheques and EFTs
Payers who paid by cheque (VML, Laburnum) or Yardi EFT (Dorset, Norsan) were often never marked paid
in Jobber, so their 2026 deposits have no Jobber payment and sat as income. **Rule:** match such a
deposit to the open balance of the Jobber invoice(s) it pays (`JobberLedgerService::bookMatched`),
using the Yardi remittance (mowology@icloud.com / office@) where there is one; the invoice date decides
2025 receivable vs 2026 income. Full lessons: Obsidian Accounting-Ledger.md.

## 2026-10-12 — review round 2 (Fable, screens / crons / iPhone / card)
Now enforced in code — don't undo:
- **Every session POST to the money APIs carries the CSRF token** (bank-import, transactions, accounts,
  rules, expenses batch_forward / qb_retry), read before `session_write_close()`. JS sends
  `X-CSRF-Token: window.MW_CSRF_TOKEN`. Ledger sync and rules apply are POST-only.
- **Undoing a bank import needs billing.edit** (staff have expenses.edit).
- **The transactions screen can't change a bank / invoice / expense line's amount, tax, date or status**,
  and nothing in a locked month (`AccountingService::editTransaction`); a bank line's account moves with
  its journal entry (BankLineMoveService).
- **API errors:** database / PHP errors are logged and the user sees a generic sentence; only int
  400–599 codes reach `http_response_code()` (`app/Core/ApiResponse.php`).
- **Only approved receipts go to the accountant from every path** — `ExpenseService::sendBlockReason()`
  (receipt-actions send, QBService batch forward + retry); create never auto-sends; the review queue
  lists approved only. Desktop edit / line items / reassign / rescan follow the iPhone rule
  (`ExpenseService::editBlockReason`); crew see / export only their own receipts.
- **Crons never report a silent success:** journal sync failure = error (sync_ledger); no e-Transfer
  mailbox = error; receipt poll office@ login failure = warning and it looks back after an outage;
  receipt_archive records in `cron_runs`. OCR stale window 20 min. One Penny prepare round at a time
  (MySQL `GET_LOCK`).
- **Penny's card / brief:** chase only invoices late for that client (past due and past their habit);
  GST to claim back applies the meals ITC rate and skips held duplicates; AI cost = live only;
  "Found to bill" counts only once an issued invoice exists on the job; questions on rejected receipts /
  receipts moved off the job are gone; "asking the crew" counts only ASKABLE items; carousels remove a
  decided item by id and block Next/Prev while busy. Freedom profit: revenue before GST, void out,
  costs = approved/sent receipts less GST.
- **iPhone 1.3.28:** queued receipt photos live in Application Support; a 4xx is never queued and never
  blocks the queue; a failed save after a good upload keeps the item and retries only the save; queues
  drain only the signed-in person's items; "12,50" reads as 12.50.

Dropped / not done:
- `QBService::_qbLoadExpense` selects `e.tax_amount`, which doesn't exist on production, so batch forward /
  retry fail. **Tim decided (2026-10-12): receipts are NEVER emailed to the accountant.** The accountant
  downloads them or asks for them; each month's receipts get zipped and stored once that month is reconciled.
  Leave batch forward dead.
- Desktop `public/crm/api/receipt-send.php` still sends a draft (the office sending from the desktop is
  treated as approval) — left as is.
- Freedom dashboard: **Tim decided that drafts count** (2026-10-12). Costs = draft + pending + approved + forwarded,
  pre-GST; rejected/cancelled excluded (2026 costs $33,396 at the time).
- `process_ocr_queue` records a run only when there were jobs (a no-op every minute would flood
  cron_runs), so its "last run" can look old.

## 2026-10-12 — bank balancing lessons (chequing vs Vancity)

- **The bank line says where money came from.** Receipt #384 (Vital Auto $1,711.70) was saved as
  credit_card and posted Cr 2400. The chequing debit was linked to it, which reversed the bank entry, so
  chequing was never credited and a phantom card debt sat on the books. Tim: "no cheques, only debit card".
  Linking a receipt to a bank/card line must make the receipt's payment source match that line, and tell the
  user. `fundingAccountFor()` used to send anything containing "card" to 2400 (a debit card is chequing).
- **Measure the gap the same way every time:** books = SUM(debit−credit) on 1010 to the statement's last
  date; bank = the CSV running balance on that date; leave the +$5,325.33 opening out (waiting on the
  accountant). Vancity changes same-day running balances between exports, so dedupe statement lines on
  (date, amount), keeping the most copies any single file has. Never dedupe on balance.
- **Same-day "deposit and take back" pairs are not payments.** Jan 20 $225.69 and Feb 4 $282.50: a
  "Cheque deposit-branch" credit and a debit with the same wording the same day. Move both to 1100 as
  transfers so they net to zero.
- **Jobber "marked paid later":** a deposit can be days or months before the Jobber payment date, and
  of a different kind (BGC's EFT was marked as an e-Transfer 7 days later). Match by invoice and amount,
  then use the catch-up path. Never require the Jobber date to fall within 3 days.
- **A chargeback after a batch of cheques** (Colyvan, Jul 3 $456.84 + $7.50) usually means one of them
  was a duplicate payment. Pair it with the duplicate as transfers on 1100 and book the fee to bank
  charges. NEVER undo a whole Jobber batch to move one deposit; batches hold many.
- **Debit-marked receipts with no chequing line** are usually card purchases or duplicate captures
  (Home Depot $64.26 ×4). Ask; don't book them to chequing.
- **QuickBooks (the accountant's books)** held $51,948 of A/R at YE2025, about $48K of it pre-2025 and stale.
  2025 itself was clean. Ricole (ricole@sitlim.com) was asked on 2026-10-12 about writing it off and about
  the 2026 opening A/R. Don't open 2026 from the $51,948 until she answers.
- **Colyvan (VR1450) pays through LeapAP.** Invoices go to their LeapAP address with the PDF. Reminders
  without a PDF are rejected there, so send them to the property manager instead.

## 2026-10-12 — Receipt said credit card, the bank line said chequing (Vital Auto #384)
#384 ($1,711.70) was saved as `credit_card` (posted Cr 2400) and then linked to chequing line tx 43028
(Sep 16), which reversed the line's own entry: chequing was never credited and 2400 held a phantom
liability. Also: `fundingAccountFor()` treated anything containing "card" as credit ("debit_card" →
2400), and the receipt parser read "Visa Debit" as a credit card. Fixed: every link goes through
`ReceiptFundingCheck::onLink()` — a clear mismatch sets the method from the line (gate source
`bank_line`, re-posted, activity logged, Penny notice); company card on chequing or cash/cheque on the
card statement is a Penny question, nothing changed. Sweep of all 98 links on 2026-10-12: no others.
**Also found:** a manual link left the receipt's own `accounting_transactions` row (reference_type
'expense') AND the bank line both counted as expenses in single-entry reports — 29 pairs, $3,565.96 in
2026 (journal was fine: matched lines are not posted). Reports now use `AccountingService::countedSql()`.
**Rule:** the statement line decides where money came from; a receipt's method is a claim to check.


## 2026-10-12 — Security audit group C (money)
Now enforced in code — don't undo:
- **A payment email records nothing unless its sender is proven** (exact address + verified DKIM,
  `MailAuth`): unproven Yardi lines are 'medium' and `recordWhenBanked()` skips them; Interac notices
  that are unproven or forwarded are capped at 'medium'; `autoRecordFullyCertain()` and
  `recordWhenBanked()` need `etransfer_notifications.sender_verified = 1` (migration 1340 — until it
  runs nothing auto-records).
- **Stripe disputes and refunds reach the journal** (`StripeEventLedgerService`, migration 1342,
  idempotent per event id): dispute → invoice `dispute_status`, DR 1100 / CR 1010, Charlie emails Tim;
  won → reversed; refund → DR 2160 Customer Deposits / CR 1010 (the invoice is left for a person).
  A late `payment_failed` never overwrites a succeeded payment.
- **Autopay is switched on only by the cardholder entering the card** (SetupIntent, or a payment with
  a NEW card) — never from an invoice link's "use saved card".
- Scanned bank statements OCR through Google Vision or local Tesseract only, never OCR.space.
- AI receipt calls have a daily ceiling (`LlmDailyCap`; ops_settings `llm_daily_cap_<bucket>`).

## 2026-10-13 — Penny read the receipt right, but only her desktop card showed it (Chevron #424)
The reader (Vision text, `line_items_source = 'vision'`) kept two "CRF 0.07" lines and guessed Fuel; Penny's
suggestion #248 read the coffee, two Diet Cokes, the deposit and the CRF ($8.83) and said Meals. The phone receipt
screens showed only the reader's version, so it looked as if Penny had missed the drinks. The same receipt also
read "GST 5.0000 % 0.42" as GST 5.00 (the rate's "5.00", subtotal −2.33): fixed in `extractLabeledTax()`.
**Fixed:** her pending read shows on the iPhone detail and the web/Android modal (`PennyReadService`), "Use Penny's
version" goes through `decide()` (her items replace the reader's), and approval teaches the reader the vendor's
layout (`teachReaderFromPenny()`): this Vision text prints each price ABOVE its name, which the generic pass can't
pair. **Rule:** whatever Penny knows about a receipt is shown wherever that receipt is opened; an accepted
correction of the reader must teach the reader, not only Penny.


## 2026-10-13 — Balance sheet out by twice the GST ITC balance
`ReportingService::buildBalanceSheet()` signed each line by its own normal balance, so 2210 GST/HST Input Tax Credits
(a debit-normal *liability*) was ADDED to liabilities: the sheet showed "does not balance" by $1,021.78 (2 × $510.89)
on production while the trial balance and income statement agreed with journal_lines to the cent. Fixed: lines are
signed by their section (assets debit-positive, liabilities/equity credit-positive). **Rule:** contra accounts are
signed by the statement section they sit in, never by their own normal balance.

## 2026-10-13 — 6850 is two things (not fixed — needs Tim / the accountant)
On production 6850 is named "Meals & Entertainment" (BankDeskService, BankGuidanceService and the bank desk file meals
there), but `StripePayoutService` books Stripe processing fees to 6850 as "Payment Processing Fees". Meals are 50% for
ITCs and income tax (GIFI 8523); card fees are 100% (8716). Until they are split, the GIFI mapping of 6850 is wrong for
one of them. **Rule:** one account number, one meaning — check the chart's name before posting to a code.

## 2026-10-13 — Rescan kept a $2.61 coffee at subtotal −2.39 (Chevron #355)
Stored at capture with GST 5.00 (the old "GST 5.0000 % 0.12" misread), subtotal −2.39. Rescan re-ran the (fixed)
parser, but Vision printed the amount on the line BELOW "GST 5.0000 %": the split-line pattern didn't allow the space
before "%", so GST was missed, the column fallback read GST 2.61 / subtotal 0.00, and the bare "0.12" pulled the tax
label in as the only line item. The coffee line ("… 1 2.49 2.49P") matched no item pattern. And the rescan only filled
EMPTY form fields, so the bad stored amounts survived every rescan. Penny had never read it (she prepares only some
receipts a day) and the Add Item picker offered Crusher Dust / Road Base (Chevron has no vendor_products, so typing
fell through to all CRM products). **Fixed:** split-line rate with a space; `NAME QTY UNIT TOTAL<flag>` items (flag
required — a tilted photo's rebuilt rows add up by chance); `isTaxOrSummaryLabel()` / `dropTaxLineItems()` on every
pass; `sanitizeReceiptAmounts()` (negative subtotal, tax ≥ total or > 14% → fall back); rescan repairs impossible
stored amounts on open receipts when the read agrees on the total (`amountRepair()`), and re-reads Penny's open read;
"Ask Penny" in the modal; picker = `vendorItemSuggestions()`. **Rule:** an amount that cannot be true is a failed
read, never a value; a re-read must be able to replace a value that cannot be true.


## Same-amount monthly payers swap deposits (2026-10-13)
- Strata BCS4079 pays $262.50 every month. The Oct 8 deposit (for INV-0425, email "invoice 2026 0425") was attached to INV-0366 from the invoice page's amount-and-name list. On 2026-10-12 the Sep 9 deposit (really INV-0366's) was marked "already recorded" against INV-0308, which already had its own Aug 21 deposit. Result: INV-0425 looked unpaid and its card said "claim it" for money already in the bank.
- Fixed by re-linking: Sep 9 → INV-0366, Oct 8 → INV-0425 (+$262.50 income that had been missing).
- Guard: `InvoiceReconciliationService::emailNamesOtherInvoice()` — `attach()` and the bank desk refuse a deposit whose Interac email names a different open invoice by number (override: Record anyway / `$force`).
- Before marking a deposit "already recorded", check the invoice it names doesn't already have its own deposit.
- e-Transfer cards: a same-amount bank credit after the email date shows "probably deposited <date>", not the claim link.

## Transfers to Katrenna = her draws (2026-10-13)
- "Funds transfer … Pay As You Go Chequing (…6912)" (Jul onward) and "FUNDS TRANSFER TO REL #4659904" (Feb–Jun,
  her Vancity membership; once mis-booked INTO GST Reserves 1025) are both Katrenna's.
- "Funds transfer … Pay As You Go Chequing (…6912)" is Katrenna's personal account. The business sends her
  money (no money of hers comes in): these are her **draws**, never an expense. Book to **1302 Shareholder
  loan — Katrenna Marenych** (child of 1300; 15 transfers Feb–Oct, $2,170, moved there 2026-10-13).
- Shareholder loans are kept **per person**: s.15(2) (repay or include within one year after year-end) and
  s.80.4 interest apply per shareholder. The accountant clears a debit balance by salary/dividend at year-end.

## Quiet accounts looked "Not imported" every month (2026-10-13)
- The statements check only knew an account's month was in from its lines. Reserve funds ••6819 has had no
  line since Nov 2025, so every month showed red although each Vancity PDF prints its section ("0.69, no
  activity"); GST Reserves ••6827 went red in any month without an interest line.
- Fixed: the PDF importer records every account section it reads, quiet ones too
  (`BankImportService::statementSections()` → `recordSections()`, table `bank_statement_sections`, migration
  1360; Vancity's page-1 ACCOUNT SUMMARY via `vancitySummary()` gives each account's opening/closing). The
  check (`StatementCoverageService::monthStatus()`) calls such a month **quiet — in, no activity** with its
  balance; a quiet month opening away from the last known balance is a break (`quietSectionBreaks()`).
  Backfill = re-import the monthly PDFs (a statement with nothing new can be recorded on its own).
- Same day: identical lines inside one statement are real (30 Mar 2026 7-Eleven −6.47 / +6.47 / −6.47);
  `orderLines()` drops a line as a copy only when an EARLIER import had at least as many. Breaks and possible
  gaps in closed years (before `StatementCoverageService::OPEN_FROM`, or a later fully locked year) are summed
  into one quiet line ("FY2025 (closed): …"), never listed as alarms.
- **Rule:** "no lines" is not "not imported" — ask the statement what it printed.

## Shareholders' e-Transfers: pay up to Wave net, the rest is loan (Tim, 2026-10-13)
- e-Transfers to Tim or Katrenna are PAY up to that person's Wave net pay for the month; anything beyond goes
  to their own shareholder loan (Tim 1300, Katrenna 1302). WavePayrollImportService does the match once the
  month's Wave "Wage & Tax Report" is imported and approved.
- Built 2026-10-13: the shareholder list is ops_settings `payroll_shareholders` ("Name=code" per line, migration
  1361; `WavePayrollImportService::shareholders()`); each one's sweep is `WavePayrollImportService::sweeps()` and
  `buildEntry()` posts one DR/CR pair per shareholder on their own account. A shortfall (transfers below net) is
  CR their loan — wages still owed to them. Booked months show the entry as posted (journal lines), not rebuilt.
- **Trap found doing it:** half the year's e-Transfers print the name run together —
  "ETRANSFERDEBIT(TIMSCHOFIELD)", "ETRANSFERDEBIT(KATRENNAMARENYCH)" (Jan–Feb, Jun). Word-based name matching
  (`nameMatches()`, `ShareholderAccountService::isFromShareholder()`) never saw them; both now also match
  FIRSTLAST with the letters joined. Her "Funds transfer … Pay As You Go Chequing" and "FUNDS TRANSFER TO REL
  #4659904" lines carry no name and are never matched as pay.

## Two Visa cards (Tim, 2026-10-13)
- Vancity Visa …9843 = the BUSINESS card (Credit Card Payable 2400; statements imported there).
- Vancity Visa …1023 = Tim's PERSONAL card. A payment to it from business chequing is a draw → 1300 (the Jul 31
  $1,800 moved there). Never import its statements into 2400; only its business charges (e.g. Wave payroll fees)
  are booked, as paid by Tim (owed back to him).
- The business account usually makes TWO Visa auto-payments a month (~$82 and a larger one) — find out which card
  each belongs to before booking both to 2400.
