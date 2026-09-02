# Receipts & Email-In Expense Capture

How vendor receipts/invoices become expenses, and how forwarding by email is handled.

## Capture paths

| Path | Entry point | Notes |
|------|-------------|-------|
| Camera / gallery (web + Android WebView) | `public/crm/expenses_appstack.php` → `app/Modules/Expenses/Api/receipt-intake.php` | OCR on upload, mobile review card, Save / Save & Send, then a Snap Another / Done loop. |
| iOS app (native, JWT) | `receipt-upload.php` → `expense-save.php` (+ `expense-lookup.php`, `expense-delete.php`, `receipt-actions.php`) | Same review-form data as the web card (see below). Saves as `draft`; `and_send: true` forwards in the same call. |
| **Email** | forward to **receipts@mowology.ca** → `app/Modules/Expenses/Cron/receipt_inbox_poll.php` | This document. |

### Web ↔ iOS review-form parity

Both review forms read the same lookups through `ExpenseLookupService`
(`app/Modules/Expenses/Services/ExpenseLookupService.php`): vendor autocomplete,
job search (rows include `property_id`/`contact_id`), the canonical category /
payment-method lists, and the amount/date duplicate check. Session clients hit
`vendors.php?action=search|categories` and `expenses.php?action=search_jobs|check_duplicates`;
iOS hits `GET /api/expenses/expense-lookup?type=vendors|jobs|categories|duplicates`
(`type=`, never `action=` — the `/api/` router's rewrite appends its own `action`).
Both save payloads carry `raw_ocr_json` + `ocr_parsed` (feeds `ReceiptLearning`),
`receipt_lat/lng`, `job_id`/`property_id`/`contact_id`, and `pst_amount`.

Delete goes through `ExpenseService::delete()` on both clients: owner-or-admin, and
anything already forwarded to accounting is refused (web `update` has the same guard).

**Offline replays create a draft.** When a queued capture is replayed (iOS
`ReceiptQueue`, web `offline-receipts.js`, or the service worker's Background Sync),
the intake result is immediately saved as a draft expense with the description
"Auto-saved from offline queue — please review". Before this, replays only re-ran
intake, leaving an orphaned `media_assets` row with no expense.

All paths store the file in `media_assets` (`context_type='expense'`, served via
`public/crm/api/serve-receipt.php?id=<media_id>`) and create a row in `expenses`.
Approved/forwarded expenses post to the ledger via
`AccountingService::syncFromExpenses()` (run by the `sync-ledger` cron).

## Email-in flow

1. **Forward** a vendor receipt/invoice (PDF or image attachment) to
   `receipts@mowology.ca`. Both self-forwarded and vendor-direct emails work.
2. The **`receipt_inbox_poll` cron** (every 15 min) reads the mailbox over IMAP,
   walks the MIME tree, and extracts each PDF/image attachment.
3. `ReceiptInboxService::ingestAttachment()` stores the file, runs the shared OCR
   pipeline (`ReceiptOCR` → `ReceiptParser` → `ReceiptSmartMatch`; PDFs are
   rasterised page-1 via Imagick), and creates the expense.
4. **Auto-post gate** (`ReceiptInboxService::isCleanMatch()`): the expense is
   created `status='approved'` (posts to the books) **only** when all of:
   - vendor matched in the vendor directory (`vendor_id`),
   - a positive total parsed,
   - a valid date parsed,
   - the matched vendor has a `default_accounting_category`.
   PDFs that couldn't be OCR'd never auto-post. Everything else is `status='draft'`.
5. **Review**: drafts from email (`source='email_inbox'`) appear in the
   "Receipts from email — pending review" panel on the Expenses page. Edit
   vendor/date/total/GST/PST/category, then **Approve** (→ `approved`) or
   **Dismiss** (→ `cancelled`). API: `public/crm/api/receipt-inbox-confirm.php`.
6. **Notify**: each poll run that processed anything emails a summary to
   `mowology@icloud.com` (auto-posted vs needs-review).

### Dedup
`receipt_inbox_messages.dedup_key` = `message-id:sha256` (or `sha:sha256` when the
message has no id). Same email re-polled, or the same file seen twice, collapses to
one expense; two different attachments on one email are ingested separately.

## Activation checklist (one-time)

1. Create the `receipts@mowology.ca` mailbox in cPanel.
2. Add `define('RECEIPTS_IMAP_PASS', '...');` to `public/app_config/secrets.php`.
3. Run migration `database/migrations/1100_receipt_inbox.sql`.
4. Add the cPanel cron:
   `*/15 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Expenses/Cron/receipt_inbox_poll.php`
5. Confirm Imagick is present (`php -m | grep imagick`) so PDFs can be OCR'd;
   without it PDFs are stored and routed to manual review only.

Test by browsing `/crm/cron/receipt_inbox_poll.php` as an admin (prints a log).

## Archival & export (reclaim disk)

Receipt images in `/uploads/receipts/` grow disk indefinitely. `ReceiptArchiveService`
bundles **confirmed** receipts (`status IN ('approved','forwarded')`) into ZIP(s), emails
them to the configured recipients, then — only after a confirmed send to **every**
recipient — deletes the full-res original and replaces it with a thumbnail. The retained
copy is the emailed ZIP (off-server); on single-disk shared hosting "moving" the file
reclaims nothing, so deleting + keeping a thumbnail is what actually frees space. If no
recipients are set or any send fails, **nothing is deleted**.

- **Manual:** "Export / Archive receipts" button + date-range modal on the Expenses page →
  `public/crm/api/receipt-export.php` (manual runs ignore the age gate).
- **Cron:** `app/Modules/Expenses/Cron/receipt_archive.php` — bundles everything older than
  `receipt_archive_after_days`. cPanel: `0 3 2 * *` (3 AM, 2nd of each month). *Not scheduled by default.*
- **Serving:** archived receipts serve their kept thumbnail via the existing
  `serve-receipt.php?id=<media_id>` link (410 if no thumb); full-res lives in the emailed ZIP.
- **Audit:** one row per run in `receipt_archive_batches`.

### Config (ops_settings, `receipt_*` keys)
`receipt_archive_enabled` (default on) · `receipt_archive_after_days` (90) ·
`receipt_archive_keep_thumb` (1) · `receipt_archive_max_zip_mb` (25) ·
`receipt_export_email_owner` · `receipt_export_email_accountant` ·
`receipt_accounting_email` (existing bookkeeping/QuickBooks inbox, reused as a recipient).

### Activation checklist (one-time)
1. Run migration via admin endpoint `/crm/api/run-migration-1066-receipt-archive.php`
   (adds `media_assets` archive columns + `receipt_archive_batches`; idempotent).
   Migrations `1066_receipt_archive_media.sql` + `1067_receipt_archive_batches.sql`.
2. Set the recipient emails in `ops_settings` (above). With none set, nothing is deleted.
3. (Optional) add the monthly cPanel cron above to automate.

## Approval

`ExpenseApprovalService::approve()` blocks self-approval (the creator of an
expense can't approve their own) — deliberate, since a past bug let any
edit-permission user bypass it. Exception: `users.can_approve_own_expenses`
(migration 1108, off by default), toggled per employee in Team management —
for someone with no one else to approve their purchases (e.g. an owner who
also submits a lot of his own receipts). Checked fresh from the DB inside
`approve()`, not trusted from the session/JWT payload.

## Line items

`ReceiptParser::extractLineItems()` parses each OCR'd product line and persists them
to `expense_line_items` (`ExpenseLineItems.php`'s `saveLineItems()`). Discount/markdown
lines (`RSN:`/`DISCOUNT`/`MARKDOWN`/`MKDN`, or a standalone negative amount) are **netted
into the most recently committed product line** rather than becoming their own item —
e.g. "Topsoil x4 $59.96" + "Discount -$14.99" persists as one $44.97 line, with
`original_unit_price` retaining the pre-discount price for a struck-through display in
the Edit Expense modal. A discount only falls back to a standalone row (flagged
`is_adjustment=1`, no CRM Product link affordance) when there's genuinely no preceding
product to net into — an order-level coupon, or the discount line is first in the
receipt. `DEPOSIT` lines always stay standalone (`is_adjustment=1`) — a container/bottle
deposit is a real separate charge, not a price reduction. Migration `1111` added the two
columns; run via admin endpoint `/crm/run-migration-1111.php`.

Note: a bare product-name line with no barcode/SKU prefix and no price on the same OCR
line is never captured as a pending item at all (a separate, pre-existing parser gap) —
if that happens, a following discount line still falls back to a standalone
`is_adjustment` row rather than netting into a product that was never captured.

**Editing a stored line item**: the Edit (pencil) button in the Line Items table calls
`ExpenseLineItemService::update()` via `action: 'update_line_item'` in
`app/Modules/Expenses/Api/expenses.php` — corrects name/quantity/unit_price/line_total
in place (re-syncing `products.current_stock` if quantity changes on a linked product).
This does **not** touch the expense header's Subtotal/Total — those stay independently
staff-verified fields, same as `add_line_item`/`delete_line_item`.

## Bank-transaction matching

`BankImportService::findExpenseMatch()` auto-matches a bank statement row to an
expense once, inline during import `commit()` — amount ±$0.01, date ±3 days,
`approved`/`forwarded` expenses only. There's no rescan afterward, so anything
that misses that single pass (receipt still `draft` at import time, amount/date
drift) stays unmatched forever unless someone attaches it by hand.

Manual attach: `BankImportService::candidateTransactionsForExpense()` /
`candidateExpensesForTransaction()` (±14 day window, includes draft/pending
expenses — a human confirms) + `attachExpenseMatch()` / `detachExpenseMatch()`.
Reachable from the Edit Expense modal's "Matched Transaction" section and from
unmatched rows on `public/crm/accounting/transactions.php` ("Find Expense
Match"). API: `app/Modules/Accounting/Api/reconciliation.php`
(`expense_candidates`/`transaction_expense_candidates`/`attach_expense`/`detach_expense`).

## Key files

- `app/Modules/Expenses/Services/ReceiptArchiveService.php` (+ `tests/Unit/Expenses/ReceiptArchiveServiceTest.php`)
- `app/Modules/Expenses/Cron/receipt_archive.php`, `public/crm/api/receipt-export.php`
- `app/Modules/Expenses/Services/ReceiptInboxService.php`
- `app/Modules/Expenses/Services/ExpenseLookupService.php` (+ `tests/Unit/Expenses/ExpenseLookupServiceTest.php`) — vendor/job/category/duplicate lookups shared by web + iOS
- `app/Modules/Expenses/Services/ExpenseService.php` (`update()` + `delete()`, + `tests/Unit/Expenses/ExpenseServiceTest.php`)
- iOS JWT endpoints: `app/Modules/Expenses/Api/receipt-upload.php`, `expense-save.php`, `expense-lookup.php`, `expense-delete.php`, `expense-update.php`, `expense-list.php`, `receipt-actions.php`
- iOS client: `ios/MowologyCRM/MowologyCRM/Features/Receipts/*.swift`, `Core/Models/ReceiptIntakeResponse.swift`, `Core/Offline/ReceiptQueue.swift`
- Offline replay (web): `public/crm/js/offline-receipts.js`, `public/service-worker.js` (`syncPendingReceipts`)
- `app/Services/Receipts/ReceiptParser.php` (line-item discount netting), `app/Services/Receipts/ExpenseLineItems.php` (persistence)
- `app/Modules/Expenses/Services/ExpenseLineItemService.php` (+ `tests/Unit/Expenses/ExpenseLineItemServiceTest.php`, `tests/Unit/Expenses/ReceiptParserDiscountTest.php`)
- `app/Modules/Expenses/Cron/receipt_inbox_poll.php` (+ shim `public/crm/cron/receipt_inbox_poll.php`)
- `public/crm/api/receipt-inbox-confirm.php`
- Review panel + JS in `public/crm/expenses_appstack.php`; styles `.mw-receipt-inbox` in `mowology-brand.css`
- `app/Modules/Accounting/Services/BankImportService.php` (manual matching methods, + `tests/Unit/Accounting/BankImportServiceTest.php`), `app/Modules/Accounting/Api/reconciliation.php`
- `tests/Unit/Expenses/ReceiptInboxServiceTest.php`
