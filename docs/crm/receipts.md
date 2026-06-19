# Receipts & Email-In Expense Capture

How vendor receipts/invoices become expenses, and how forwarding by email is handled.

## Capture paths

| Path | Entry point | Notes |
|------|-------------|-------|
| Camera / gallery | `public/crm/expenses_appstack.php` → `app/Modules/Expenses/Api/receipt-intake.php` | OCR on upload, manual review form. |
| iOS app | `app/Modules/Expenses/Api/expense-save.php` (JWT) | Saves as `draft`. |
| **Email** | forward to **receipts@mowology.ca** → `app/Modules/Expenses/Cron/receipt_inbox_poll.php` | This document. |

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

## Key files

- `app/Modules/Expenses/Services/ReceiptInboxService.php`
- `app/Modules/Expenses/Cron/receipt_inbox_poll.php` (+ shim `public/crm/cron/receipt_inbox_poll.php`)
- `public/crm/api/receipt-inbox-confirm.php`
- Review panel + JS in `public/crm/expenses_appstack.php`; styles `.mw-receipt-inbox` in `mowology-brand.css`
- `tests/Unit/Expenses/ReceiptInboxServiceTest.php`
