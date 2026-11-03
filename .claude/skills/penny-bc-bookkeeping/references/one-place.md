# One place for each job (improve it there, and every screen and app gets it)

Moved out of SKILL.md 2026-10-29 so the entry file stays short. Tests still check every name here.

| Job | The one place | Used by |
|---|---|---|
| Receipt risk flags | `detectAnomalies()` in `app/Services/Receipts/AnomalyDetector.php` | save, desktop, Penny desk, inbox, mobile |
| Penny's plain-English reasons | `RiskExplainer::say()` in `app/Modules/Expenses/Services/RiskExplainer.php` | iPhone + web |
| Penny's receipt checks before approval | `ReceiptBookkeeperService::check()` | penny_prepare cron, desk |
| Reading a receipt | `parseReceiptText()` in `app/Services/Receipts/ReceiptParser.php` | 10 callers |
| Every expense change (create, edit, approve, lines, flags) | `ExpenseGate::apply()` in `app/Modules/Expenses/Services/ExpenseGate.php` | all writers |
| Re-reading a saved receipt photo | `ExpenseRescanService::rescan()` | web + iPhone |
| Recording a customer payment | `InvoiceReconciliationService::applyAllocation()` | bank, e-Transfer, Yardi, invoice screens |
| Tying a deposit to payments already recorded | `InvoiceReconciliationService::linkAllocationsToDeposit()` | Penny's bank card, backfill |
| Stripe payout → invoices + fee (fee on 6800, never 6850 Meals) | `StripePayoutService` (Stripe is read-only) | import, income clean-up |
| Could a card have been charged? (forget an intent / retry a charge) | `StripeErrorRules::canForgetIntent()` / `chargeOutcomeUnknown()` | customer pay page, CRM pay page, autopay |
| GST return (GST34) | `GstReportService` | tax report, reports, accounting |
| Bank-line category rules | `BankRuleLearning` | transactions, Penny's bank desk |
| Month-end proof per statement | `StatementCloseService` (+ `StatementCoverageService` for gaps) | Penny |
| Cash-flow forecast | `CashFlowForecastService` | cash-flow page, Penny's brief |
| Orphan deposits → what they paid | `DepositBackfillService` | one-off backfill, re-runnable |
| Journal posting | `LedgerSyncService`, `LedgerAccountMap`, `JobberLedgerService` | nightly sync |
| Nightly sync failures named, not skipped (cron warns) | `LedgerSyncService::noteFailure()` / `isExpectedSkip()`; `AccountingService::journalProblem()` | sync-ledger cron |
| Moving a bank line to another account (row + journal together, or nothing) | `BankLineMoveService::move()` | transactions, Penny's bank desk, payroll + shareholder moves, bulk |
| Penny's bank-line rules given to Claude word for word (Tim's rulings) | `BankGuidanceService::RULES` | Penny's bank desk guidance |
| Invoice PDF before any invoice email (no PDF → held, not sent) | `InvoicePdfGate` (`ensurePdf()`); monthly contract invoices through `ContractInvoiceSender::send()` | desktop send, visit invoices, contract billing, bulk resend |
| Who an invoice goes to (company/manager routing) | `companyRoutingRecipients()`, `resolveCompanyBillingRecipient()`, `resolveManagementBillingRecipient()`, `preferRecipients()` in `app/Modules/Invoices/Services/InvoiceRouting.php` | invoice screen, contract billing, visit invoices |
| The "Bill to" company on every invoice header | `BillToResolver::INVOICE_COMPANY_NAME` + `INVOICE_COMPANY_JOINS` | PDF, CRM view, customer portal |
| Wave payroll month → journal entry; e-Transfers = net pay; each shareholder's excess → their own loan (Tim 1300, Katrenna 1302) | `WavePayrollImportService::matchMonth()` / `sweeps()` / `buildEntry()` (list: `shareholders()`, ops_settings `payroll_shareholders`) | payroll import page, Penny's brief |
| Penny's "unbilled materials" questions and answers | `PennyQuestionService` (answers: invoice, contract, not billable, already billed) | Penny's card |
| A receipt linked to a bank / card line: its payment method follows the line (bank line is the truth) | `ReceiptFundingCheck::onLink()` (funding code: `LedgerSyncService::fundingCodeFor()`) | receipts page attach, Penny's bank desk, bank receipt sweep (all via `BankImportService::attachExpenseMatch()`), import auto-match, `sweep()` |
| Single-entry reports count a linked bank line once | `AccountingService::countedSql()` | P&L, trend, cash flow, tax summary, job profit, dashboard, expense breakdown |
| Penny's read on a receipt screen (her pending suggestion where it differs; "Use Penny's version" / "Keep mine") | `PennyReadService` (`read()`, `apply()` → `BookkeeperDeskService::decide()` with `line_items = 'penny'`, `keep()`) | iPhone receipt detail, web/Android expense modal (`penny-read.js`) |
| "Ask Penny" on a receipt she hasn't read (one live read, daily cap + `LlmDailyCap`) | `BookkeeperDeskService::askOne()` (via `PennyReadService::ask()`) | web/Android expense modal (`penny-read.js`) |
| Impossible receipt amounts (negative subtotal, tax ≥ total) and tax lines read as items | `sanitizeReceiptAmounts()` / `dropTaxLineItems()` in `ReceiptParser.php`; stored ones fixed on rescan by `ExpenseRescanService::amountRepair()` | every receipt read, web + iPhone rescan |
| The receipt "Add Item" picker (this vendor's usual items, its catalogue, other products only when typed) | `ExpenseLookupService::vendorItemSuggestions()` | web/Android modal (`expenses.php` `item_suggestions`); iPhone endpoint `expense-lookup` `type=vendor_items` (not used yet) |
| The reader learning a vendor's item layout from Penny's accepted items | `teachReaderFromPenny()` (on approval, via `ExpenseGateHooks::learnConfirmed`) → `recordLineLayoutLessons()`; read back by `parseReceiptText()` through `getVendorLineItemProfile()['layout']` / `applyLineLayout()` | every receipt read |
| The accountant's login: the Ledger and nothing else (deny by default; every view / export logged) | `AccountantFence::verdict()` in `app/Core/Auth/AccountantFence.php` (enforced from session_config.php + auth.php) | every session request from a users.role = 'accountant' login |
| GIFI mapping + the export validator (RC4088 Rev. 23) | `GifiRules::build()` in `app/Modules/Ledger/Services/GifiRules.php`; data + saves `GifiService` | Ledger → GIFI mapping |
| A journal entry's source document (invoice, receipt photo, bank line, Jobber row…) | `JournalBrowserService::source()` in `app/Modules/Ledger/Services/JournalBrowserService.php` | Ledger journal drill-down |
| Changes inside a locked period after its lock | `LockExceptionService::classify()` in `app/Modules/Ledger/Services/LockExceptionService.php` | Ledger → Exceptions |
| Which supplier stop a receipt came from, and Otto's job pick for a material receipt with no job (shadow: one tap confirms through `ExpenseGate::apply()`; every answer is a Penny lesson vendor + material → job type) | `SupplierJobSuggestService::forEditor()` / `SupplierJobSuggestService::confirm()` (Otto's service, Operations module) | web/Android expense modal (`supplier-stop.js`); iPhone gap |
| Prepaid client credit used before an invoice is sent (before the PDF and autopay) | `ClientCreditService::applyOnSend()` | visit invoices, contract billing, invoice screen send, bulk resend |
| A client's payment text / Interac alert text → payer hints on a deposit | `PaymentClueService::clueFor()` / `alertSendersFor()` (texts via `TextBridgeService::ingest()` / `ingestInterac()`) | Penny's which-invoice card (`BankInvoiceMatchService::suggest()`) |
| Books vs bank every night + quiet signals (bank import, e-Transfer notices, text bridge) + blanked ENUM values → one Charlie email | `BooksWatchService::run()` (sync-ledger cron) / `urgent()` | Charlie's urgent pass |
| Who pays as whom (bank memo words, e-Transfer senders, Interac alert names, emails, phones; owner's word first) | `PayerIdentityService` (`payersFor()`, `learn()`, `remove()`) on `payer_aliases` (1362) | Penny's which-invoice card, e-Transfer learning, deposit backfill, client page "Also pays as" |
| The monthly close list (oldest open month, after the grace; never locks) | `MonthCloseListService` (`due()`, `items()`, `email()`) | Charlie's 7 am run |
| A statement imported under the wrong bank account → refused | `BankImportService::statementAccountMismatch()` | bank import commit |
| Receipt list thumbnails | `ReceiptThumb::ensure()` via `serve-receipt.php?thumb=1` | receipts list |
| An overpayment kept as the client's credit (DR Bank / CR 2160; settles its bank deposit) | `EtransferInboxService::creditRest()` → `ClientCreditService::addOverpayment()` | Penny's e-Transfer card (web/Android); iPhone has no e-Transfer card yet |
| Don't chase an invoice whose payment is on the way (pending e-Transfer matched to it or named in its memo) | `PaymentOnTheWay::sql()` | invoice_reminders, invoice_overdue, automation_runner, autopay_charge |
| When an overdue reminder may go out at all: AP inboxes never chased, first reminder after the payer's own habit (p75 lag + 3, min 7; defaults 14 company / 7 individual), held while an unlinked deposit matches or no statement covers the due date; `ops_settings.reminder_grace_mode` off / shadow / on; holds in `invoice_reminder_holds` (1424) | `ReminderGate::decide()` in `app/Modules/Invoices/Services/ReminderGate.php` (`isApInbox()` is the one AP-inbox rule, built on `InvoiceReminderRules::needsPdf()` + `AutomatedInvoiceInbox::isAutomated()`) | invoice_reminders, automation_runner |
| Recording a person's override of Penny (one lesson row) | `HeadLessons::record()` in `app/Services/HeadLessons.php` (Penny calls it through `PennyLessons`) | receipt gate, Penny's card, bank desk / line moves, receipt link, reconciliation, deposit card, questions, Jobber links |
| Duplicate approved receipts and bank lines linked to the wrong receipt (detect only; asks Tim, changes nothing) | `TwinFinder::find()` in `app/Modules/Expenses/Services/TwinFinder.php`; questions written daily by `TwinCheckService` (from the `penny_prepare` cron) as `penny_questions` kinds `possible_twin` / `bad_link` | Penny's card (`#mw-pq`), the brief (`PennyBriefAdapter::fromStats()`) |
