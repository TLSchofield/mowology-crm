# Codex nightly backlog — triage and fix plan (2026-11-04)

Status: **TRIAGED, awaiting Tim's go per batch.** 27 reports (money 01-16 and the 2026-11-04 money set, auth 01-04, public 01-04) read against the current code by read-only helpers; load-bearing items spot-checked by Claude (view.php permission gap confirmed). 18 findings not real or already fixed are logged in `~/.mowology-codex/findings.json`. Crew reports (28) not yet read.

Rule for the batches: each is its own commit with a test per rule, a live dry run (rolled back) where it touches data, a Codex review, then deploy dependency-first. Nothing here is deployed.

## Batch A — permission gaps (small, no data change) — 7 findings
- `public/crm/invoices/view.php:14` send / mark_sent / mark_paid reachable with `billing.view` only (UI hides the buttons; server does not check). Lines 336, 620, 645.
- `create-payment-intent.php:23` view permission can create a payable intent.
- `expense-save.php:29` JWT only, no `expenses.edit`; `receipt-upload.php` no permission check; `expenses.php:1180` link_product skips `assertCanEditExpense`.
- `tracking-sync.php:190` visit_id / job_id not checked against the user (low).
Risk of the fix: a role that relies on today's gap loses the action. Check which roles hold billing.view without billing.edit before deploy.

## Batch B — public pages and forms (reachable without login) — 11 findings
- `jobFlow-getQuote.php:170` an email or phone match lists the stored addresses (disclosure; behind a CAPTCHA only). `jobFlow-confirm.php:147,345` OR-match overwrites consent; `:169` property matched by address only; address choice ignored.
- `quote.php:394` decline has no status guard (accept/decline race); `contract-sign.php:82` decline result ignored; signature_data unbounded; token pages lack Cache-Control no-store.
- `portfolio.php:64` JSON-LD `</script>` breakout (staff-entered data); recaptcha hostname not checked; CAPTCHA failures write unlimited log rows.
Disclosure and consent overwrite are the two that matter. Consent change is a business call (CASL): Tim decides.

## Batch C — Stripe and payments (double charge / lost postings) — 12 findings
- Duplicate Stripe PaymentIntent: `InvoiceService.php:135`, `create-payment-intent.php:85` (no lock, no idempotency key).
- `StripeEventLedgerService`: refund webhook race (:116), failures swallowed then marked processed (:165), dispute won-before-created (:88), event dated today not event date (:162).
- `StripePayoutService:159` reclassify commits before the old entry is reversed; fee booked twice on duplicate lines (:85).
- Refund re-issued after 10 minutes (`InvoiceRefundService:26`), refund rewrites a locked period (:269), statements ignore refunds.
Money. Plan before build; each needs a rolled-back live dry run.

## Batch D — ledger and period locks — 20 findings
- No lock check: bank import commit and rollback (`BankImportService` 401, 868), carryover DELETE (`AccountingService:226`), `ClientCreditService` addDeposit, `DepositBackfillService.undo`, `JobberLedgerService.linkManual`, flag_review.
- Races / not atomic: `LedgerService.postEntry` and `reverseEntry` (unique key dropped by migration 1131), `linkCrmInvoice`, Jobber undo, shareholder clearing, credit double-spend, bank-line `attachExpenseMatch`.
- Wrong numbers: import rollback restores a stale invoice snapshot (BIS:944), commit trusts client duplicates and unvalidated GST/amount, manual transactions never journaled (`AccountingService:~800`), partial-payment row date overwritten, accountant export sums everything, e-Transfer unmerge keeps matched_invoice_id, month lock proceeds if the bank-line check throws (`MonthCloseService:277`), split-lookup failure posts unsplit, `mark_paid` audit/receipt use requested not applied amount.
Money and the books: plan first, one service at a time, Steve-traceable.

## Batch E — receipts and parsing — 12 findings
GST: estimated at 5% when none found, zeroed but subtotal kept, impossible tax splits accepted at approval, Fix GST ignores recycling tax, VendorProductMatch adds 5%; UTC default receipt date (evening receipts land on tomorrow); expense update zeroes omitted fields; merge copies amount fields unfooted; budget crossing both thresholds says warning; anomaly baseline uses server month; archive ignores rowCount.

## Batch F — small and display (no data change) — about 14 findings
view.php: cancel/delete race (status guard), amount_paid not shown after mark_paid, zero tax rate shows 5%; transactions summary is page-only; reports.php GST failure silent; recipient lookup swallows PDOException; reset_password rowCount; device-token length order; update_roles not transactional; role checks instead of permission keys in timesheets / time-entry-edit / time-clock (policy; Tim decides); portfolio DB error 200; confirm.php notification failure shown as success.

## Left, with reason
- Legacy JWTs without pwd_v: last one expires about 2026-11-11; delete the branch after that.
- RBAC fail-open on a query error and session recheck fail-open: deliberate (a DB hiccup must not sign the office out). Leave.
- Portal lists draft/cancelled quotes: unconfirmed that drafts have tokens. Check on live data first.
- vendors.php company-wide spend visibility: possibly intended. Tim decides.
- Bank-import `row.date` in innerHTML, duplicate check fails open, repeated tax label: unsure; look when touching those files.

## Suggested order
A, then F (cheap, safe), then B, then C, D, E each with a plan and your yes. Crew reports after A–F.
