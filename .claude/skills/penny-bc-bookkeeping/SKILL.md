---
name: penny-bc-bookkeeping
description: Use BEFORE touching anything money-related in the Mowology CRM / Ittai — Penny (the AI bookkeeper), receipts and OCR, expenses, GST/PST/ITCs, invoices and payments, bank or credit-card statements, deposits, Stripe payouts, e-Transfers, Yardi/SparcPay remittances, the ledger/journal, cash-flow forecast, payroll remittances, ROEs, T4s, WorkSafeBC, year-end, or any question about Tim's books. Also when Tim asks "how much…", "why is income…", "is this paid…", or reports a wrong flag on a receipt. Holds British Columbia bookkeeping rules, how this ledger works (and where it already double-counted), how Penny is allowed to operate, the single source of truth for each job, and the traps already hit.
covers:
  - app/Modules/Accounting/*
  - app/Modules/Expenses/*
  - app/Services/Receipts/*
  - app/Services/Pdf/PdfFormFiller.php
  - app/Modules/ChiefOfStaff/Services/PennyBriefAdapter.php
  - public/crm/accounting/*
  - app/Modules/Ledger/*
  - public/crm/ledger/*
  - app/Core/Auth/AccountantFence.php
  - public/crm/expenses_appstack.php
  - public/crm/invoices/*
  - app/Modules/Invoices/*
  - app/Modules/Contracts/Cron/contract_billing.php
  - app/Modules/Contracts/Services/ContractInvoiceSender.php
  - app/Modules/Clients/Services/BillToResolver.php
  - ios/MowologyCRM/MowologyCRM/Features/Receipts/*
  - database/migrations/*
metadata:
  version: 1.0.0
  created: 2026-10-11
  owner: Tim Schofield (Mowology) — tax facts to be confirmed by Steve Lim CPA (Sit Lim CPA)
---

# Penny — BC bookkeeping for Mowology (and Ittai)

Penny is the CRM's bookkeeper head. This skill is what a careful human bookkeeper would know
before touching Tim's books: the **province's rules**, **how this ledger works**, **how Penny is
allowed to act**, and **where each job lives in code**. Read the reference that matches the task:

| Task | Read |
|---|---|
| GST/PST/ITCs, payroll, ROE, T4, WorkSafeBC — the rules themselves | the shared `regions` skill (`.claude/skills/regions/`) |
| What Penny does with those rules | `references/bc-rules.md` |
| Income, deposits, payments, Stripe, Jobber era, journal vs P&L, tables | `references/ledger.md` |
| What Penny may do, learn, flag, chase; approvals | `references/operating-rules.md` |
| Traps already hit (read before ANY write to the books) | `references/traps.md` |
| iPhone vs Android vs web — keeping Penny the same everywhere | `references/surfaces.md` |

## Before and after every change (Obsidian + Graphify)

**Before** writing or changing anything money-related:
1. Read the Obsidian notes for the area —
   `~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/`
   `Accounting-Ledger.md` first, then `grep -n -i "<area>" Known-Failure-Patterns.md`
   (receipts, deposit, stripe, gst, payroll, jobber…) and the Decision-Log entry if one exists.
2. Graph what you are about to touch: `python3 ~/.claude/tools/graphify.py touches <function>`
   (who calls it — every screen/app a change reaches) and `… file <path>` (what it uses).
   If the function is called from several places, change it once there; if the same rule is
   written in several places, say so and fix all of them (the GST rule was in two).
3. On production data: `scripts/prod-run.sh` preview first, then `--commit`.

**After** the change:
1. A mistake found or a rule learned → `references/traps.md` (this skill) and
   Known-Failure-Patterns.md (Obsidian) the same day; a "why it's built this way" → Decision-Log.
2. A Penny feature changed on one surface → update `references/surfaces.md` (and the other
   surfaces, or record the gap there).
3. Names, paths or rules in this skill changed → update the skill in the same commit
   (`tests/Unit/Docs/HeadSkillsTest.php` fails otherwise).
4. `/obsidian` (bare) at the end of the session writes the rest back.

## The five rules that are never broken

1. **Never claim a fact about the books you did not check this session.** Cite the query or
   file. "Unverified" is an acceptable answer; a confident wrong one is not (Tim, 2026-10-11).
   Before saying something is missing, broken or double-counted, search the code (`graphify
   touches <symbol>`) and this skill — it is usually already handled (Stripe payouts are).
2. **Income is counted once — on the invoice.** A bank deposit that pays an invoice is a
   cash-clearing *transfer*, never a second income line. Jobber-era deposits (before
   2026-02-25) are the only record of that income and stay income. See `references/ledger.md`.
3. **Penny never moves money and never enters banking details.** She proposes; Tim approves.
   Mobile receipt capture is capture-only (approval happens on desktop or in Penny's queue).
4. **Every write to the live books is previewed first.** Use `scripts/prod-run.sh` (one
   transaction, rolled back unless `--commit`). Read every function a write path calls before
   using it — `InvoiceReconciliationService::detach()` un-pays invoices (see traps).
5. **Penny learns only from confirmed facts.** Owner decisions and certain matches teach;
   imports, guesses and single sightings do not (thresholds in `references/operating-rules.md`).

## One place for each job (improve it there, and every screen and app gets it)

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
| Recording a person's override of Penny (one lesson row) | `HeadLessons::record()` in `app/Services/HeadLessons.php` (Penny calls it through `PennyLessons`) | receipt gate, Penny's card, bank desk / line moves, receipt link, reconciliation, deposit card, questions, Jobber links |

**GST rate — one source since 2026-10-11:** `TaxEngine::gstRate()` reads the Settings value
(`business_settings.gst_rate`); `TaxEngine::getRates()` (ledger, GST report) and the two on-screen
calculators use it, as the invoice screens already did. **Still separate (known):** the receipt
parser, product matcher, Jobber back-calculation and risk checks assume 5% for *reading* receipts —
move each onto `TaxEngine::gstRate()` when touched (`grep -rn "0\.05" app public/crm`).

## Keeping this skill true

- `tests/Unit/Docs/HeadSkillsTest.php` fails the test suite when a file, class or function this
  skill names no longer exists, when a region rule is past its review date, or when the skill
  loses its Obsidian + Graphify section or its `covers:` list. Fix the skill in the same commit as the code.
- At the end of any session that changed code listed in `covers:`, the Stop hook
  (`~/.claude/hooks/vault-writeback.py`) names this skill and the changed files, and sends you to update it.
- When a mistake is found in Tim's books or in Penny's behaviour, add it to `references/traps.md`
  the same day (what happened, the signature, the rule that prevents it).
- Tax facts carry a source and a `verified`/`review by` date. Rates and deadlines are re-checked
  every January (and whenever CRA/BC announce a change) — with Steve Lim for anything marked
  *confirm with accountant*.
