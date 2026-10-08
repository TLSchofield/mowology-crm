# Receipts & Email-In Expense Capture

How vendor receipts/invoices become expenses, and how forwarding by email is handled.

## Capture paths

| Path | Entry point | Notes |
|------|-------------|-------|
| Camera / gallery (web + Android WebView) | `public/crm/expenses_appstack.php` → `app/Modules/Expenses/Api/receipt-intake.php` | OCR on upload, mobile review card, Save / Save & Send, then a Snap Another / Done loop. |
| iOS app (native, JWT) | `receipt-upload.php` → `expense-save.php` (+ `expense-lookup.php`, `expense-delete.php`, `receipt-actions.php`) | Same review-form data as the web card (see below). Saves as `draft`; `and_send: true` forwards in the same call. |
| **Email** | forward to **receipts@mowology.ca** → `app/Modules/Expenses/Cron/receipt_inbox_poll.php` | This document. |

### Mobile is capture-only

Phones (iOS native, Android/Capacitor WebView) **submit** receipts — status
`pending_approval`, button "Submit for Review" / "Submit" — and never send to accounting.
The admin corrects the expense in the desktop edit modal (those corrections are the
parser's learning signal) and sends from there. `expense-save.php` accepts only
`draft`/`pending_approval` (default `pending_approval`); offline replays land as
`pending_approval` too. iOS keeps approve/reject triage; "Send to Accounting" and
"Save & Send" exist on the desktop only. Once forwarded, the desktop modal locks the
header (Save disabled + lock note) but line items can still be linked to products.

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
4. **Confidence gate** (`ReceiptInboxService::isCleanMatch()` → `inboxStatus()`).
   Nothing is approved automatically (since 2026-10-07). A clean match — all of:
   - vendor matched in the vendor directory (`vendor_id`),
   - a positive total parsed,
   - a valid date parsed,
   - the matched vendor has a `default_accounting_category` —
   is created `status='pending_approval'`, flagged high-confidence (inbox note
   "clean match — high confidence"). Penny prepares `pending_approval` receipts
   first; the owner approves. PDFs that couldn't be OCR'd are never clean.
   Everything else is `status='draft'`.
5. **Review**: emailed receipts (`source='email_inbox'`, draft or pending_approval)
   appear in the "Receipts from email — pending review" panel on the Expenses page
   and on Penny's card. Edit vendor/date/total/GST/PST/category, then **Approve**
   (→ `approved`) or **Dismiss** (→ `cancelled`). API: `public/crm/api/receipt-inbox-confirm.php`.
6. **Notify**: each poll run that processed anything emails a summary to
   `mowology@icloud.com` (clean matches awaiting approval vs needs-review).
7. **Penny prepares in the background**: `app/Modules/Expenses/Cron/penny_prepare.php`
   (every 15 min, `5,20,35,50 * * * *`) prepares up to 5 receipts per run within the
   daily cap (`ops_settings bookkeeper_daily_cap`, default 40), holding back possible
   duplicates. `--dry-run` lists what it would prepare without calling the AI.

### Dedup
`receipt_inbox_messages.dedup_key` = `message-id:sha256` (or `sha:sha256` when the
message has no id). Same email re-polled, or the same file seen twice, collapses to
one expense; two different attachments on one email are ingested separately.
Since 2026-10-07 the same file under a *different* Message-ID (forwarded from office@ to
iCloud, a vendor's resend) is also refused (`shaSeenElsewhere`, note "same file as an
earlier email"), and the expense INSERT goes through `ExpenseCreateGuard`.

### Mailboxes (2026-10-07)
Hosts, users and secrets.php constants live in `app/Services/Mail/MailboxConfig.php`;
every mailbox is opened read-only through `app/Services/Mail/ImapReader.php`
(OP_READONLY + FT_PEEK). This cron reads receipts@ (everything) and office@ (receipt-looking
mail only). Tim's iCloud is **not** read here any more: `IcloudInboxRouter`
(`app/Modules/Comms/Cron/icloud_inbox_poll.php`) reads it once and hands receipt mail to
the same `ingestAttachment()` / `ingestEmailBody()` — only mail dated after its first run.

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

## Header learning (migration 1123)

Header fields (total, GST, subtotal, date, vendor) and the accounting category are
learned **once per receipt, when it is confirmed** — approved
(`ExpenseApprovalService`, `ReceiptInboxService::approve` for emailed receipts) or
sent to accounting (`sendReceiptToAccounting`, since "Save & Send" skips approve) —
by `learnFromConfirmedExpense()` in `ReceiptLearning.php`.

- **Baseline** = what the user was shown at capture: the client echoes intake's
  `parsed` back as `ocr_parsed`, stored once in `expenses.ocr_parsed_json`
  (`storeCaptureBaseline()`, never overwritten). Intake adds
  `parsed.suggested_accounting_category` so mobile/offline saves carry the category
  suggestion too. Emailed receipts use the row as loaded before the approver's edits.
- **Idempotent** via `expenses.learning_recorded_at` — re-saves and a later send don't
  re-count. Receipts with no baseline (pre-1123, never OCR'd) are skipped, not diffed
  against a re-parse (the old behaviour, which wasn't what the user saw).
- Category is a lesson only when something was suggested and the user changed it.
- **Line items too, for receipts with a baseline:** at confirmation the captured
  items (`ocr_parsed_json.line_items`) are diffed against the kept rows
  (`lineItemCorrections()`): renamed → `line_item_name`, dropped → `line_item_noise`,
  hand-added → `line_item_missed`, one stats update. Save-time paths and
  `ExpenseLineItemService` edits then update SKU memory only, so re-saves don't
  re-count. Receipts without a baseline keep the per-save lessons below.
- `approve()` refuses a `forwarded` expense (a bulk approve used to un-send it).
- Store details: `extractVendorLocationFromOcr()` / `extractPhoneNumbers()`
  (ReceiptParser) back-fill vendor phone/website/location at intake; the vendor
  phone match compares normalised 10-digit numbers.
- Before 1123 runs, header lessons pause; nothing errors.

## Recognition by time and place (migration 1124)

- **Purchase time:** `extractPurchaseTime()` reads the printed time of day (80% of
  live receipts print one; store-hours ranges are skipped) → `parsed.time`.
- **Job:** `suggestJobFromSchedule($user, $lat, $lng, $date, $time)` matches the
  receipt's own date against that day's stops for all crews — bought during a visit
  +30, the purchaser's next stop within 3h +20 (supplies are bought on the way),
  same day +10 (needs a second signal). No receipt date → today/tomorrow vs upload time.
- **Store locations:** at confirmation `learnStoreLocation()` attaches the capture
  point to the vendor's nearest spot within 150 m (running centroid) or starts a
  `source='learned'` spot; a learned spot is used after 2 receipts. A spot carrying
  3+ different vendors is home/office/truck and is never learned or used. When the
  text names no vendor, `nearestKnownStore()` (≤300 m) does.
- **Capture:** web/Android await a fresh fix (≤4 s) and send no location from
  non-touch (desktop) devices; iOS uses the shared one-shot fix; intake reads EXIF GPS
  before stripping when the client sent none.

## AI bookkeeper (migration 1125)

`ReceiptBookkeeperService` prepares each receipt for the owner: category, asset tag
(`expenses.asset_tag` truck | equipment — one Fuel category, split for the GGOB cost
drill-down), job, subtotal/GST/PST/total and line items, each with a reason and a
confidence. Inputs: text (+ photo), captured values, the vendor's recent approved
receipts, `ReceiptBookkeeperRules` hits (owner's rules: diesel → truck, gas ≤ $50 →
equipment, EGO → equipment (soft)), the category list and time/place job candidates.
Claude Opus 5.5 over HTTP (structured JSON, effort medium, `fallbacks: "default"`),
key `ANTHROPIC_API_KEY` in secrets.php. Hard checks after the model: sums, GST 5% /
PST ≤ 7%, category list, **firm owner rules override the model**, fuel must be tagged,
the job must be a schedule candidate. Stored in `expense_suggestions`; nothing changes
an expense until the owner decides. `/crm/api/bookkeeper.php`: `?mode=status`,
`?mode=report`, POST `mode=backtest` (final values hidden, scored per field) —
`expenses.approve` only.

## Penny's desk — dashboard card + carousel

`BookkeeperDeskService` (via `/crm/api/bookkeeper.php` `?mode=stats|queue`, POST
`decide|prepare`) drives the department-heads deck on the dashboard
(`public/crm/includes/dept-heads-deck.php`, `public/crm/js/bookkeeper-card.js`,
styles in `mowology-brand.css` "DEPARTMENT HEADS DECK"; headshots `/crm/img/heads/`).
- **Decide:** writes category, `asset_tag`, job (+ property), subtotal/GST/PST/total;
  records per-field accepted/overridden in `expense_suggestions.outcome_json`
  (scorecard + worked examples); then `ExpenseApprovalService::approve()` — the
  self-approval rule still applies, and approval teaches the reader. Line items are
  shown, not changed, in this version.
- **Vendor:** Penny reads the business off the receipt (`vendor` in her schema); the
  carousel's Vendor box searches `/crm/api/vendors.php?action=search` (name/alias), or
  takes a typed new name. `decide` changes `expenses.vendor_id`/`vendor_name_raw` only
  when it is a different business (`sameVendorName` — "HOME DEPOT #7054" stays Home
  Depot); an unknown name becomes a new vendor on approval, never on a saved draft.
- **Prepare:** text-only first, photo retry only when the amounts don't add up; up to 3
  rounds of 2 per visible page view while < 5 are ready; daily cap
  `ops_settings.bookkeeper_daily_cap` (default 40).
- **Numbers:** right-first-time from real decisions (backtest until 5 exist); net
  saving only from real decisions × the Owner Freedom rate − AI cost.

### Penny's card — what's on it (2026-10-05)

- **Duplicates first:** `DuplicateReceiptService` reuses `ExpenseLookupService::findDuplicates`
  (same total to the cent, ±3 days, same vendor — the receipts page's rule) over the next
  60 waiting receipts, groups linked pairs, and holds every waiting member out of the
  approval queue and out of Penny's AI read. Removing a copy calls `expenses.php
  action=merge` (approved/sent keepers are merged with `fields: {receipt: 'keep'}` so
  they don't change). "Not duplicates" → `expense_duplicate_dismissals` (migration 1127).
  Expenses sharing one `receipt_media_id` are always paired, whatever their OCR'd dates.
- **Phone: "Keep this one"** (iOS 1.3.4, `bookkeeper-mobile.php` `mode=remove_dupes
  {keep_id, remove_ids[]}` → `DuplicateReceiptService::removeCopies`): every other waiting
  copy in the group is rejected "Duplicate of receipt #keep" (soft, never a DELETE), after
  its job/category/tag/notes/line items/photo fill whatever the kept one has empty. One
  group only, waiting copies only, all-or-nothing. When a member is already approved, only
  that one can be kept.

### One photo, one expense (2026-10-07)
`ExpenseCreateGuard` in `expenses.php action=create` and iOS `expense-save.php`: a create
whose `receipt_media_id` already has a non-rejected expense returns that expense
(`deduplicated: true`) instead of inserting — a MySQL `GET_LOCK` on the media id covers
same-second repeats. Splitting one photo into several expenses on purpose will need its
own path.
- **Fields:** vendor (search + new), receipt date (MwDatePicker), category, tag, totals,
  job; line items edited in place via `expenses.php` `add/update/delete_line_item`
  (saved immediately; same learning as the receipts page), "Use Penny's items".
- **Re-check:** `?mode=recheck` — Penny re-reads one receipt with the photo; old
  suggestion becomes `superseded`. Different from the receipts page Rescan (OCR).
- **Rotate:** view only, like the receipts page lightbox (↻, R key).
- **Self-approval:** checked before anything is written; blocked → edits kept as a
  draft and the reason shown. Owner exemption: Team → Approvals.
- **Vendor strength:** `PennyBadgeService::vendorStrength` — per vendor run toward
  trusted (5 unchanged in a row), % unchanged, receipts seen; shown as "Vendors I know"
  and under each receipt's Vendor field.

### Penny's brain

`PennyBrainService::learned()` counts trusted vendors, earned badges, learned store
locations (`vendor_locations.source='learned'`), learned vendor categories
(`vendor_parse_profiles`) and `receipt_parse_lessons`. `public/crm/js/penny-brain.js`
draws shape N = units + 1 of 500 (deterministic: tetrahedron, double / twisted double
pyramids up to 90, then folded geodesic spheres of the tetra/octa/icosahedron, each
with its own seeded folds and orientation; shape N has ≥ N + 3 triangles), lights one
triangle per unit, pulses the newest and glows with right-first-time. Canvas 2D, no
library, reduced-motion aware. Also on the card: Reject with a reason, anomaly rules +
bank match in the checks, product search on item names (`link_product`).

### Penny's badges

`PennyBadgeService` (chips under her photo, from `dept-heads-deck.php`). Earned only
from real decisions (`expense_suggestions` live, accepted/edited), and lost again
when she slips, since the streaks count her most recent decisions: vendor trust (last
5 from one vendor unchanged, max 3 shown), Fuel pro (last 10 fuel calls kept), Job
finder (5 kept job picks), Tax ace (last 20 with total/GST/PST kept), 10 in a row,
and $ found to bill (`penny_questions` answered `invoice`). The closest unearned
badge shows dimmed with its progress.

## Where the truck went (job from the trail) and Penny's questions (migration 1126)

- **Trail:** `ReceiptTrailService::candidates($purchaseAt, $userId)` — materials are used
  the same day, so the job is where the crew actually went after the purchase: visits
  started (`job_visits.started_at`), truck stops (`vehicle_location_pings`, Trackimo) and
  the purchaser's phone stops (`crew_location_history`, `is_office` excluded). Stop =
  ≥5 min within 120 m → nearest property within 200 m → its job plan. These lead Penny's
  job candidates (`source: where the truck/crew went`) ahead of the schedule.
- **Questions:** `PennyQuestionService` — approved Materials / Disposal/Dump /
  Subcontractors receipts on a job, 3–60 days old, with no invoice line for that
  job/property mentioning them (−7/+45 days) become `penny_questions`; asked on Penny's
  card. Answers: `invoice` (opens `invoices/create.php?plan_id=`, counts to "found to
  bill"), `contract` / `not_billable` (never ask again for that job or contract + vendor).

## Line-item learning (migration 1115)

The parser's self-learning loop covers line items, not just header fields. Signals
and where they come from:

| Signal | Recorded by | Applied on the next scan by |
|---|---|---|
| Rename (`line_item_name`), "not an item" (`line_item_noise`), manual add (`line_item_missed`) | Review card save on every client (`ocr_name` / `removed` / `manual` flags per item → `recordLineItemCorrectionsFromPayload()`), and post-save edits via `ExpenseLineItemService` (web modal + iOS `expense-line-items.php`) | `applyLineItemLearning()` — drops noise, renames misreads (≥2 sightings); `deriveLineItemPatterns()` fills `vendor_parse_profiles.noise_patterns` / `barcode_length` which `extractLineItems()` reads |
| SKU → product (`vendor_product_skus`) | Every save records SKU→name; a product link records SKU→product | `applyLineItemLearning()` auto-links `product_id` by SKU, then by `vendor_products` name/alias; `matchVendorProducts()` step 5 links by confident catalog match |
| Purchase history (`expense_line_items` names per vendor) | — | `extractLineItems()` queues a bare line matching a known name; the LLM tier gets the list as its dictionary |
| Items-sum vs subtotal (`line_items_quality`) | `assessLineItemQuality()` in `parseReceiptText()` | `ReceiptLineItemIntelligence.php`: Tesseract→Vision re-OCR, then the LLM tier, only when items don't add up; `line_item_accuracy` feeds `getVendorTesseractThreshold()` |

**LLM tier** (`app/Services/Receipts/ReceiptLlmExtractor.php`): line items only, never
header fields; runs only after Vision still doesn't add up; output accepted only if rows
sum to the regex subtotal and every name is present in the OCR text. Needs
`ANTHROPIC_API_KEY` in `secrets.php` (optional `RECEIPT_LLM_MODEL`; kill switch
`ops_settings.receipt_llm_extraction = '0'`). Results are tagged
`expenses.line_items_source = 'llm'` and flagged "AI extracted — please verify" on every card.

Both review cards (web mobile + iOS) let the user rename / mark "not an item" / add a
missed item before saving; the desktop review panel does the same inline. After save,
the desktop edit modal and the iOS detail sheet edit, delete, add and link items through
the same service. `raw_ocr_json` may be a JSON Vision blob after a rescan —
`ocrTextFromStored()` recovers the text before any re-parse.

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

## Trip receipts tagged to jobs (migration 1218)

`TripAttributionService` (run nightly by `trip_runs_daily`) links the receipts on a dump or supply run to the job visited from that property that day. It sets `expenses.job_id` only when the receipt has a single purpose, and only when `job_id` is still empty. It never overwrites a job that is already there.

A mixed supplier receipt is split by its line items into `ops_trip_job_costs`. For example, mulch that is on the job's quote goes to the job, and grass seed bought for the shop stays as job-less stock on 5200. A receipt with no line items is left untagged. See `heads-shared-facts.md`.

## Receipt facts (migration 1227)

`ReceiptFactsService` keeps what is printed on a receipt in `receipt_facts`, one row per expense: the printed date, the till time (or a scale ticket's Time In and Time Out), the ticket, invoice or transaction number, the card's last 4 and brand, the terminal and the store number. The facts are parsed whenever OCR text is saved. The `penny_prepare` cron also runs a backfill batch every time it runs. The batch parses up to 40 receipts. It queues receipts that were never OCR'd but have a photo for the OCR worker, with at most 40 a day. When a queued job finishes, its text is copied onto the receipt. An approved receipt gets only the text. A waiting receipt also gets its empty total, date and vendor filled in. You can run a batch on demand at `/crm/api/receipt-facts.php` (admin only).

The facts are used in four places:
- **Duplicates.** Two receipts from the same vendor with the same ticket number are the same receipt. A different ticket number or a printed time 2 or more minutes apart means two receipts, and the pair is remembered as not a duplicate.
- **Bank matching.** A matching card last 4, printed date or printed time raises the candidate's confidence and adds a reason. Nothing is matched automatically from them.
- **Otto's stop evidence.** The stored times are read first.
- **Penny's card and the receipts page.** These show a short facts line.

Job attribution (`ReceiptTrailService::forReceipt`) uses the evidence in this order:
1. The printed time, then where the truck went next that day.
2. The photo's GPS, within 150 m of a client property.
3. The truck's next client stop within 3 h of the photo, on the same day.

It never guesses from the start of the day. Times outside 06:00–21:00 are flagged.

## Statements check (migration 1231)

`StatementCoverageService` answers "is every bank and card statement in?". It walks each account's running balance, which is kept on every imported line (`raw_line` in `bank_import_rows.raw_row`: the last CSV column after the amount, or the second of two trailing amounts on a PDF line). Each balance must equal the one before plus or minus the line. A balance that does not follow is a gap, reported with its dates and the missing amount ("TD chequing: 12–19 Feb, $1,240 missing between balances"). A line that follows a balance of its own is another account printed on the same statement, not a gap. An account whose lines carry no balance falls back to weaker signs: 14 or more days with no lines, or a month with less than half the usual count ("possible gap").

`bank_statement_accounts` lists the statements expected every month. It is seeded from every account that has had an import, plus the credit card accounts, which are expected even if never imported. The owner can rename a row, switch it off, or set the statement closing day on the bank import page.

The `penny_prepare` cron computes the check at most once a day and caches it in `ops_settings` (`penny_statements_check`). An import or an undo drops the cache. Penny's card shows a Statements strip. From the 3rd of the month, each expected account whose last-month statement is not in becomes a Penny brief item on the Action Board. Lines that the import preview found already in the CRM, and that were left unticked, are now counted in the session's `duplicate_count`.

## Label photos and receipts → products (migration 1225)
- **Label mode** of the capture (web/Android "Label" chip → `label-capture.js` → `/crm/api/label-products.php?mode=capture`; iOS Receipt|Label toggle → `/api/expenses/label-upload`): same upload + OCR as a receipt, flagged `media_assets.context_type='label'` + `label_captures`. **Never an expense.**
- `LabelReaderService` reads the text for free; `ProductProposalService` makes a card: Penny for products (match by SKU, then name; same-day ±1 receipt line gives cost, qty → stock, vendor), Otto for machines (equipment by serial/model; purchase receipt by model on a same-day line).
- **Every receipt source feeds products**: `saveLineItems()`, `ExpenseLineItemService::add/update`, the email inbox (parsed lines in `raw_ocr_json`) and a backfill (120 days, 10 receipts per `penny_prepare` run; admin `mode=backfill`). Unlinked lines → link to a product (+ cost update, + stock on tracked products via `ExpenseLineItemService::link`) or a new product when it carries a SKU or was bought on ≥ 2 receipts from the vendor. Fuel, meals, fees, taxes, deposits, delivery and cheap one-off tools are skipped. One card per product-to-be; each line is proposed once (`product_proposal_lines.line_ref`); "Not now" sticks.
- Add writes through explicit column lists — never `api-products.php` save-product (prod's copy is behind the repo). Label photos go to `products.label_media_id`; `image_url` is set only when the product had no picture, with `photo_marketing_ok = 0` (Mia may not use it until Tim ticks it).
- Care: storage / safety lines are copied from the label only (`products.care_notes`); nothing is invented. Otto's card lists them with SDS links (Tim pastes the link). Service intervals stay Tim's numbers — "Ask Otto to read the manual" (`OttoManualService`, Claude on click, `otto_manual_daily_cap`) only proposes, quoting the page; each is confirmed before it's written.

## One gate for every expense change (migration 1233)

Every write that changes an expense goes through `ExpenseGate::apply($expenseId, $changes, $actor, $source, $opts)` (`app/Modules/Expenses/Services/ExpenseGate.php`): the desktop modal and receipts page (`expenses.php`: create, update, reassign job, merge, re-scan, line items, approve / reject, delete), Penny's card (`BookkeeperDeskService::decide`), the iOS endpoints (`expense-save.php`, `expense-update.php`, `expense-delete.php`, `expense-line-items.php`, `receipt-actions.php`, and `bookkeeper-mobile.php`, which wraps the card's services and keeps its request / response shapes), the receipts@ inbox (create, approve, dismiss), the bank desk (category alignment), trip attribution (tagging a single-purpose receipt), duplicate clean-up, receipt facts (finished OCR) and send-to-accounting. The gate:

1. validates: the date, the amounts; a status can only close (approved, rejected, forwarded, cancelled) through the audited transition that owns it; a receipt sent to accounting keeps its money;
2. writes only the fields that changed (plus line items, one line, or a split by line);
3. records `expense_change_log`: who (`actor_user_id`, `actor_kind` user / penny / system), where from (`source`), which fields, before and after;
4. re-reads the printed facts and re-runs the duplicate check;
5. teaches Penny once per change (`ExpenseGateHooks`): capture baseline and line-item lessons on a save, a single line's rename / add / remove lesson, header lessons on approve or send (`learnFromConfirmedExpense`), the owner's split (`expense_split_lessons`), and a bank rule when a person re-categorises a receipt that a bank line carries (never from the bank desk itself);
6. keeps the books append-only: a posted receipt whose books fields change is reversed and posted again; one rejected, cancelled or deleted after posting is reversed. Inside someone else's transaction the re-post waits (noted on the audit row) for the repost runner.

Writes deliberately outside the gate (none touches money): `saveLineItems()` (called only by the gate), the learning bookkeeping columns in `ReceiptLearning.php`, a line's product link (`ExpenseLineItemService::link`), `overhead_item_id` (`api-cost-factors.php`) and `purchase_task_id` (`tasks.php`). `ExpenseGateCallersTest::test_no_expense_write_outside_the_gate` fails when a new one appears.

## Split a receipt by line (migration 1233)

A mixed receipt (Lawnboy #412: seed for shop stock, mulch for Oakridge Gardens) is split in `expense_line_allocations`, one row per line: job (`job_plans.id`), category, For tag, stock flag, and its share of the money — net (the line; a difference from the receipt's net is spread by size), GST (by net), PST (only on the taxable lines: the owner's tick, else the one set of lines whose net × 7% is the printed PST, else spread). The shares always add up to the receipt.

- **Penny pre-fills** (`ExpenseSplitService::propose`): what the owner did with that vendor's line before; diesel → Fuel for the truck, gas ≤ $50 → Fuel for the equipment; food or drink → Meals; a line on a candidate job's quote → that job (the receipt's job, Penny's, the trip it was bought on, that day's visits); a product that tracks stock → shop stock; else the receipt's own category and job. Her card shows the split switched on when the lines go to different places; the edit modal shows it off until switched on.
- **Books:** `LedgerService::postExpense` picks the split up by itself (the nightly sync and the repost runner need no change): one debit per share with its job, GST ITC for what can be claimed (a Meals share at the GST report's ITC rate, the rest is cost), PST in cost, the total to the funding account.
- **Job costing:** a share's job cost is net + PST + GST not claimable — Oakridge carries $80.00 of mulch (its $4 GST is an ITC), stock carries $128.40 of seed. `AccountingService::getJobProfitability` swaps the whole receipt for the job's shares; `TripAttributionService` uses the shares instead of guessing from the quote and never tags a split receipt whole; the GST report counts a split by its shares.
- **UI:** `MwExpenseSplit` (`public/crm/js/expense-split.js`, see `COMPONENTS.md`) on Penny's card and in the edit modal; `GET /crm/api/expenses.php?action=split&id=N`.

## Look-back review of 2026 (migration 1250)
`/crm/accounting/lookback.php` (admin) — Penny re-checks every 2026 approved / forwarded receipt, bank / card line and journal entry and stores **proposals only** (`lookback_proposals`). Rules first (`LookbackRules`, free): Tim's fuel / EGO rules, vendor memory, all-food → Meals, split by line from learned splits, approved duplicates (same photo / ticket #), GST / PST sanity (no GST on landfill, transit, insurance, bank fees), personal signals → category **Personal** (→ 1300 Due from Shareholder, no ITC); bank: TD loan → 2610, card payment → 2400, savings → 1020/1025, CRA by the accountant's amounts (never an expense), payee consistency, default 4900/6900; journal vs source. Wave payroll, deposits = invoices and missing receipts are counted only (payroll import, income clean-up, receipt chaser). Claude (`claude-sonnet-5-5`) only where rules can't decide, once per vendor / payee, total cap `ops_settings.penny_lookback_budget` (USD 15), every call in `lookback_ai_calls`, answers reused (`lookback_rules`, `bank_guidance`). Approve → ExpenseGate / BankLineMoveService / reverseEntry (append-only, locked months refused, changed records refused as stale), Skip (never proposed again), Undo. Nightly scan in `penny_prepare` (01–04 h, 5 Claude calls a run). Penny's card shows "Look-back: N proposals ($X, GST $Y)". Render: `docs/crm/renders/lookback.jpg`.
