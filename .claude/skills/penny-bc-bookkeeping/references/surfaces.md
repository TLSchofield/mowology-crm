# iPhone, Android and web — keeping Penny the same everywhere

## How the three relate
- **Web** (desktop + phone browser) — CRM pages, e.g. `public/crm/expenses_appstack.php`.
- **Android** (crew app, Capacitor) — shows the same web pages. A web change IS an Android change
  (after deploy); only native pieces (camera permission, GPS service) live in `mowology-android`.
- **iPhone** (SwiftUI, `ios/MowologyCRM`) — native screens calling JWT endpoints under
  `app/Modules/*/Api/` (e.g. `expense-line-items.php`, `bookkeeper-mobile.php`).

## The rules
1. **Logic lives in one server service** (see `references/one-place.md`). Endpoints and
   pages only call it. Then a rule change reaches all three at once — the GST fix of 2026-10-11
   reached save, desktop, Penny's desk, the inbox and both apps through `detectAnomalies()`.
2. **Every Penny feature has a row below.** Adding or changing a feature on one surface means
   updating the others in the same piece of work, or writing the gap here so it is visible.
3. **iPhone needs a build** (bump MARKETING_VERSION, push `feature/language` → TestFlight);
   web/Android need a deploy (lftp, OPcache). Server first, then the app that relies on it.

## Parity table (checked 2026-10-13, Chevron #355 pass; likely cheques row 2026-11-06; desktop job strip 2026-11-10)

| Feature | iPhone | Web / Android | Server (one place) | Gap |
|---|---|---|---|---|
| Receipt line items: view, rename, remove, add, link | `ReceiptDetailView` (tap / swipe) | expense pop-up `#expLineItemsSection` (buttons) | `ExpenseLineItemService` | Android uses the desktop pop-up — not phone-shaped |
| Rescan a saved receipt photo (also fixes impossible stored amounts, re-reads Penny's open read) | "Rescan the photo" (1.3.22) → `expense-line-items.php` op `rescan` | `#expRescanBtn` → `expenses.php` action `rescan` (fills the corrected amounts, reloads Penny's panel) | `ExpenseRescanService::rescan()` | iPhone reloads the receipt itself after a rescan — check it re-reads amounts |
| "Add Item" picker: this vendor's usual items first; a pick fills what the items don't cover (`fill_remainder`, 2026-11-06) | not built — iPhone has only "Link Product" (typed search of CRM products); the JWT line endpoint already accepts `fill_remainder` | `#expAddItemPanel` → `expenses.php` `item_suggestions` | `ExpenseLookupService::vendorItemSuggestions()` (JWT: `expense-lookup` `type=vendor_items`) | iPhone |
| Item load failure shown | yes, with Try again (1.3.22) | no — empty looks like failure | — | web/Android |
| Penny's reasons for a risk score | sentences (`RiskExplainer::say()` via `bookkeeper-mobile.php`) | score badge + flag chips; list icon is hover-only | `RiskExplainer` / `detectAnomalies()` | web/Android should show the sentences |
| Approve / reject | Penny ring + buttons | buttons (needs `expenses.approve`) | `ExpenseApprovalService` | — |
| Approving teaches SKU names | yes (server) | yes (server) | `recordConfirmedSkuNames()` | — |
| Receipt photo cleanup + phone line boxes (2026-11-06) | crop to edges + "Hold flat, fill the frame" hint (`ReceiptPhotoPrep`), `client_ocr_lines` (1.3.85) | Android 2.2.9: ML Kit Document Scanner (switch `android.receipt_doc_scanner`, off), blur hint, `client_ocr_lines`; web upload: none | `reconstructLinesFromDeviceBoxes()` via `cleanDeviceOcr()` / `deviceOcrRaw()` | Android skew/fill check only inside the scanner's own UI; headers still from plain text |
| Receipt camera that waits for steady and keeps the light on (2026-11-09) | `ReceiptCameraView` + `ReceiptSteadyGate` on the job-photo `CameraController` (1.3.133); the photo's angle comes from the screen, then `ReceiptPhotoPrep` turns it by the printing (1.3.134); `ios.receipt_own_camera: false` in app-version.json goes back to Apple's document camera | not built (backlog) | — | Apple's document camera has no setting for either; the crop and "Hold flat" question stay in `ReceiptPhotoPrep` / `ReceiptsView` |
| Duplicate receipts: Penny's verdict + record, facts side by side, Merge / Different square buttons, Undo for 6 s, "Keep the other instead" (2026-11-06) | `PennyCardView` duplicates section (1.3.89), photos open in a swipe pager; receipt detail "Also on this purchase" + Split off (admins) | web Penny card (`bookkeeper-card.js`, same words); Review receipts page keeps its own merge; no "Also on this purchase" on the web expense modal yet | `DuplicateReceiptService::presentGroups()` / `removeCopies()` / `unmerge()` / `mergedInto()` | Web: Split off only from the API (no screen); Android crew app has no duplicate screen (office work) |
| Month balancing as a desktop job (2026-11-10, owner only, off unless `it_month_job`): month discs, bank lines one at a time, "Add to Ricole's list" for a gap she cannot match; the strip always shows (a bare line, "Nothing to decide", when nothing waits) | no iPhone or Android equivalent yet | desktop only: `ittai-job.php` + `ittai-job.js`; the Bank card on the web Penny card stays | `PennyMonthJobAdapter`; writes through `bookkeeper.php` (`bank_decide`, `accountant_question`) | Gap: phone and Android still use the Bank card; the accountant list is web-only |
| Duplicate compare as a desktop job strip (2026-11-10, Ittai shell only, owner only): Penny's confidence, the facts as a rail of ticks, both photos, Merge / Different, Undo for 6 s, then the next pair | the same words as `PennyCardView` duplicates (iPhone 1.3.89) | desktop only: `ittai-job.php` + `ittai-job.js` at the top of Penny's pages (`it_design` on, a waiting pair); the web Penny card stays | `PennyJobAdapter` over `DuplicateReceiptService::presentGroups()`; actions via `bookkeeper.php` (`dupe_merge`, `not_dupe`, `unmerge`) | No new merge logic; the phone browser shows the card, not the strip |
| Likely payment first on Penny's card (2026-11-06, supersedes the cheque row below): every proposed deposit, Record / Not now, then the paid invoice(s) centred with Next and Undo | `PennyPaymentCard` / `PennyPaymentDoneCard` (1.3.108) | web Penny card payment panel + paid state (`bookkeeper-card.js`) | `PaymentMatchService::pennyCards()` | Web still shows duplicates only after the payments are answered |
| Likely cheque first on Penny's card (2026-11-06): verdict, cheque vs invoice side by side with ticks, Record / Not now squares, "Or INV-…" for a same-amount invoice | `PennyChequeCard` (1.3.97), before the duplicates | web Penny card panel before the duplicates (`bookkeeper-card.js`, sideways from 768 px; Record → `bank_invoice_record`); invoices list chip "Likely cheque" | `InvoiceReconciliationService::likelyCheques()` | Cheques received but not yet banked are recorded nowhere; "Not now" is per view, not remembered; web shows duplicates only after every cheque is answered (iPhone 1.3.99 shows both — Tim 2026-11-06: leave the web for now) |
| Filed-year receipt question ("File it there?" / Undo, 2026-11-08) | not built: the receipt stays waiting on the phone, and approving it is refused for its closed year | Penny's card `#mw-pq` | `ClosedYearReceiptTrace`, `PennyQuestionService` kind `file_closed_year` | iPhone, Android |
| Mobile receipt capture | native camera, queue; from 1.3.81 the Receipts tab's bead is the camera (tap the selected Receipts tab again), label photos and the library are in the ⋯ menu | `expenses_appstack.php?mode=quick`; Android 1.7.0 native camera + queue (switch `native_receipts`) | intake services; Android: `ReceiptCaptureService` (one idempotent call) | iPhone still two calls (receipt-upload + expense-save) — could move to receipt-capture. iPhone writes the photo at the shot, every document-camera page is a receipt, refused items stay with Try again / Discard (2026-11-08). Android owes: text readability check, retry on refused (`docs/plans/2026-11-08-android-receipt-capture-parity.md`) |
| Payment source corrected from the bank line (notice / question) | not shown (gap) | Penny's card `#mw-pq` ("Got it" / "Use the bank line"), Kaname's brief | `ReceiptFundingCheck`, `PennyQuestionService` kind `payment_source` | iPhone |
| Penny's read on a receipt (her fields that differ + reasons, her items, notes; Use hers / Keep mine) | "Penny's read" section in `ReceiptDetailView` (1.3.31), pull to refresh | `#expPennyRead` in the expense modal (`penny-read.js`) | `PennyReadService` via `bookkeeper-mobile.php` `mode=suggestion` / `penny_apply` / `penny_keep` and `expenses.php` `penny_read` / `penny_apply` / `penny_keep` | "Ask Penny" (no read of hers yet) is web/Android only — iPhone gap (server: `PennyReadService::ask()`); crew see / apply (as a draft) only their own open receipts |
| Supplier forms, Otto buttons | server-sent buttons (1.3.20) | server-sent buttons (`otto-card.js`) | `SupplierFormService` | — |
| Supplier stop + Otto's job pick on a receipt (office only) | not built | `#expSupplierStop` in the expense modal (`supplier-stop.js`) | `SupplierJobSuggestService` via `supplier-stops.php` | iPhone |

When you change a row, change the date in this file's heading.

## Receipt lines ↔ shared catalogue (2026-10-19)
Receipt window, under each saved line: "Catalogue: X (Penny knew it / matched by name / confirmed / added by you)",
"Is this X? Yes / No / another one", or "Add to catalogue". `ReceiptCatalogService` (migration 1378); actions
`catalog_lines|search|confirm|dismiss|add` on `/crm/api/expenses.php`. Matching is automatic; a person's answer
teaches the shared aliases and a Penny lesson. Never creates a catalogue product without the office's tap.

## Review receipts (2026-10-20)
`/crm/receipts-review_appstack.php` — the review layer: evidence per waiting receipt (paper vs saved vs bank, Penny's
category + doubts, GST, photo), vendor-grouped; tick what you checked → Approve checked. Duplicates keeper-first (green =
in the books); merge copies into it. API `/crm/api/receipt-review.php` (`mode` approve | merge | not_dupe).

