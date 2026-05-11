# Receipt System — Cross-Platform Flow

Last updated: 2026-05-11

## Overview

The receipt system has **two client paths** feeding a shared PHP/MySQL backend.

| Client | Entry point | Auth | OCR Layer 1 | OCR Layer 2 |
|--------|-------------|------|-------------|-------------|
| **iOS native** (SwiftUI) | `receipt-upload.php` | JWT Bearer | Apple Vision (on-device, ~200ms) | Google Cloud Vision |
| **Capacitor / CRM web** | `receipt-intake.php` | CSRF + session | Tesseract (server-side, free) | Google Cloud Vision |

Both paths converge on the same parsing services (`ReceiptParser`, `ReceiptSmartMatch`, etc.) and write to the same `media_assets` + `expenses` tables.

---

## iOS Native Path

### File map

```
ios/MowologyCRM/MowologyCRM/
├── Features/Receipts/
│   ├── ReceiptsView.swift            Main screen + camera trigger + upload orchestration
│   ├── ReceiptCaptureView.swift      UIImagePickerController wrapper (CameraPicker)
│   └── ReceiptReviewView.swift       Two-phase form: Vision pre-fill → server enrichment
├── Core/
│   ├── Vision/VisionOCRService.swift On-device OCR via VNRecognizeTextRequest
│   ├── Offline/ReceiptQueue.swift    Disk-backed offline queue (NWPathMonitor drain)
│   ├── Models/ReceiptIntakeResponse.swift  Decodable models for server JSON
│   └── Network/APIClient.swift       Multipart upload + Bearer auth
```

### Flow

```
User taps camera button
        │
        ▼
ReceiptCaptureView (UIImagePickerController)
        │  image captured
        ▼
ReceiptsView.handleCapture()   ← runs Vision + compression in parallel
        │
        ├─ VisionOCRService.scan()    ~200ms on Neural Engine
        │     normalizeOrientation()  — redraws UIImage upright before cgImage extraction
        │     VNRecognizeTextRequest (level: .accurate, lang correction: OFF)
        │     extractVendor() — first non-blocked line ≥5 chars; blocklist: SANS, VISA,
        │                        CASH, DEBIT, INTERAC, TOTAL, TAXES, RECEIPT, etc.
        │     extractAmounts() — explicit PST/TVQ, GST/TPS/HST, generic TAX fallback;
        │                        back-calculates subtotal + GST when only total present
        │     extractDate()    — 4 date format patterns, en_CA locale
        │     extractPaymentMethod() — VISA/DEBIT/INTERAC/etc
        │     → VisionPreFill { rawText, vendorHint, total, subtotal, gst, pst, date, paymentMethod }
        │
        └─ resizeAndCompress()        1920px @ 0.78 JPEG quality (matches Capacitor web)
                  │
                  ▼
        viewModel.capturedImage set → showReview = true
        ReceiptReviewView opens immediately (Phase 1: Vision pre-fill)
                  │
                  ▼ (background)
        APIClient.uploadReceipt()     POST multipart to /api/expenses/receipt-upload.php
          fields: receipt_photo, vision_text (rawText), lat, lng, job_id
                  │
                  ▼
        Server processes (see Server Pipeline below)
        Returns ReceiptIntakeResponse { parsed, suggestions, ocrSource, mediaId, … }
                  │
                  ▼
        ReceiptReviewView.mergeServerData()   (Phase 2: server enrichment)
          — merges into unedited fields only
          — sets vendor name from suggestions.vendorName (higher confidence)
          — sets category from suggestions.accountingCategory
```

### Offline behaviour

`ReceiptQueue.swift` enqueues uploads that fail with `.networkError`. On reconnect, `NWPathMonitor` drains the queue automatically. Items persist to **Application Support directory** (not temp — survives OS low-storage cleanup) + UserDefaults index across app restarts. The drain continues past individual upload failures so one bad item doesn't strand the rest of the queue.

---

## Capacitor / CRM Web Path

### File map

```
public/crm/
├── expenses_appstack.php             Main expenses page (receipt capture UI + review panel)
│                                     ~5500 lines; inline JS handles form binding
└── js/
    └── offline-receipts.js           IndexedDB queueing, upload retry, confidence display

app/Modules/Expenses/Api/
├── receipt-intake.php                Web/Capacitor upload endpoint (CSRF + session)
└── receipt-ocr-status.php            Status poll endpoint (dormant — see note below)
```

### Flow

```
User taps "Snap Receipt" or selects from gallery
        │
        ▼
expenses_appstack.php (JS)
  triggerCamera() → <input type="file" accept="image/*" capture="environment">
        │  file selected
        ▼
  Canvas compression: 1920px @ 78% JPEG quality
  GPS captured silently (navigator.geolocation, 10s timeout)
  Photo saved to IndexedDB pending-receipts store (offline safety net)
        │
        ▼
  POST multipart to /crm/api/receipt-intake.php
    fields: receipt_photo, csrf_token, lat, lng
        │
        ▼
  Server processes (see Server Pipeline below)
  Response: JSON 200 (always synchronous — no 202/polling)
        │
        ▼
  Review panel populates:
    — Receipt image preview (Canvas data URL)
    — Vendor dropdown + hidden vendor_id
    — Date, Total, Subtotal, GST, PST
    — Confidence dots (green ≥70, yellow 40–70, gray <40)
    — Category pickers (Accounting + GBP)
    — Job suggestion pills (GPS-matched)
    — Line items table
    — Duplicate warning if SHA-256 match
    — GST math validation banner
```

### Offline behaviour

`offline-receipts.js` saves the photo to IndexedDB before sending. If the upload fetch fails, a retry button appears. On app-foreground (`visibilitychange`) the queue is drained automatically.

> **Note on async OCR queue:** `expense_ocr_jobs` table, `process_ocr_queue.php` cron, and `receipt-ocr-status.php` are built infrastructure for a future async path. No endpoint currently returns 202 — the web path is fully synchronous. Do not remove these components; they will be wired up when Vision API latency becomes a UX problem.

---

## Server Pipeline (shared)

Both `receipt-upload.php` (iOS) and `receipt-intake.php` (web) run the same pipeline after saving the image.

### Two-layer OCR

```
Layer 1 (fast / free)                    Layer 2 (accurate / cloud)
─────────────────────────────────────    ──────────────────────────────────────────
iOS:  VisionOCRService raw text          Google Cloud Vision DOCUMENT_TEXT_DETECTION
      sent as vision_text POST field     Always runs when GOOGLE_VISION_CREDENTIALS
      → skip Layer 1 server OCR          is defined in secrets.php

Web:  Tesseract pre-screen               Same Google Cloud Vision call
      score ≥ 70 → use Tesseract
      score < 70 → phase1Text = null

Merge rule:
  Google Vision succeeds → use it (wins), ocrSource = 'ios_vision+google' | 'google' | 'tesseract+google'
  Google Vision unavailable / fails → use Layer 1 result, ocrSource = 'ios_vision' | 'tesseract'
  Both absent → ocrSource = 'none', user fills manually
```

### Per-vendor Tesseract threshold

`getVendorTesseractThreshold(vendorId)` — defined in `TesseractPreScreen.php`. Returns `vendors.tesseract_threshold` when set, otherwise 70. Clamps to [30, 100].

### Parsing services

| Service | File | What it does |
|---------|------|--------------|
| `parseReceiptText()` | `ReceiptParser.php` | Extracts total, GST, PST, subtotal, date, vendor_hint, payment_method, line_items from raw OCR text |
| `suggestReceiptMeta()` | `ReceiptSmartMatch.php` | Fuzzy-matches vendor_hint → vendor DB record; GPS proximity boost; accounting + GBP category suggestions |
| `applyLearnedPatterns()` | `ReceiptLearning.php` | Per-vendor correction patterns (e.g., a vendor always miscategorised gets auto-corrected) |
| `matchVendorProducts()` | `VendorProductMatch.php` | Levenshtein cross-reference against vendor product catalog → line-item suggestions |
| `gateVendorAutoCreation()` | `ReceiptSmartMatch.php` | Decides whether to fuzzy-match, flag for review, or auto-create a new vendor row |
| `validateGstMath()` | `ReceiptParser.php` | Verifies subtotal + GST + PST = total; flags discrepancies |
| `extractWordConfidenceMap()` | `ReceiptOCR.php` | Extracts per-word confidence scores from Vision bounding-box response |
| `calculateFieldConfidences()` | `ReceiptOCR.php` | Maps word confidences onto parsed fields |

**Call order (both endpoints):**
1. `parseReceiptText()` — requires OCR text
2. `suggestReceiptMeta(ocrText, lat, lng, jobId)` — requires parsed vendor_hint
3. `applyLearnedPatterns(vendorId, parsed, ocrText)` — requires vendor_id from step 2
4. `matchVendorProducts(vendorId, ocrText, parsed)` — requires vendor_id from step 2
5. GST-exempt override (inline, using `suggestions.vendor_gst_exempt`)
6. `gateVendorAutoCreation()` — only when step 2 found no vendor match
7. `validateGstMath()` — only in receipt-intake.php (web path)
8. `extractWordConfidenceMap()` + `calculateFieldConfidences()` — only when raw Vision response available

### Response shape

Both endpoints return the same JSON envelope:

```json
{
  "success": true,
  "media_id": 12345,
  "file_path": "/uploads/receipts/2026/05/abc123.jpg",
  "ocr_text": "SOUTHLANDS NURSERY\n...",
  "ocr_available": true,
  "ocr_source": "ios_vision+google",
  "parsed": {
    "total": "96.04",
    "subtotal": "90.62",
    "gst": "4.53",
    "pst": "0.80",
    "date": "2026-05-10",
    "vendor_hint": "Southlands Nursery",
    "payment_method": "debit",
    "line_items": [ { "name": "Herbs/Veggies ×4", "amount": "20.97" } ]
  },
  "suggestions": {
    "vendor_id": 14,
    "vendor_name": "Southlands Nursery",
    "vendor_confidence": 92,
    "accounting_category": "Materials",
    "category_confidence": 78
  },
  "job_suggestions": [ { "id": 5, "plan_number": "JOB-2026-0012", "address": "..." } ],
  "field_confidences": { "total": 95, "date": 88, "vendor": 72 },
  "duplicate_image": null
}
```

---

## Configuration

### secrets.php constants

| Constant | Required | Purpose |
|----------|----------|---------|
| `GOOGLE_VISION_CREDENTIALS` | For Layer 2 OCR | Absolute path to Google Cloud service account JSON |

Without `GOOGLE_VISION_CREDENTIALS`, Layer 1 OCR runs only. Receipts still process; accuracy is lower on complex/angled images.

### Google Cloud service account JSON

Must contain `client_email`, `private_key`, `token_uri`. The OAuth2 flow in `ReceiptOCR.php` builds a JWT, exchanges it for a bearer token, and calls `https://vision.googleapis.com/v1/images:annotate` with `DOCUMENT_TEXT_DETECTION`.

---

## Database tables

| Table | Purpose |
|-------|---------|
| `media_assets` | Stores uploaded receipt image metadata (path, SHA-256, EXIF-stripped dimensions, GPS) |
| `expenses` | Expense record (vendor_id, date, amounts, category, payment_method, receipt_media_id) |
| `vendors` | Vendor directory with fuzzy-match support and `tesseract_threshold` per-vendor override |
| `vendor_products` | Per-vendor product catalog for line-item cross-reference |
| `expense_learned_patterns` | Per-vendor OCR correction patterns (ReceiptLearning) |
| `expense_ocr_jobs` | Async OCR queue (dormant — future use; currently zero rows) |
| `upload_rate_limits` | 20 uploads/user/hour cap |

---

## Known gaps & follow-ups

| Item | File | Severity |
|------|------|----------|
| `validateGstMath()` not called in `receipt-upload.php` | receipt-upload.php | Medium — iOS receipts skip math validation |
| `extractWordConfidenceMap()` not called in `receipt-upload.php` | receipt-upload.php | Medium — iOS receipts have no per-field confidence scores |
| Rate limit check off-by-one (upload.php allows 20, intake.php allows 21) | Both endpoints | Low |
| `isTesseractAvailable()` exported but never called — callers assume Tesseract present | TesseractPreScreen.php | Low |
| Async OCR queue infrastructure dormant | receipt-intake.php, process_ocr_queue.php | Design debt — wire 202 when Vision API latency becomes a UX problem |
| `vendors.tesseract_threshold` column may not exist on production | migration needed | Low — `getVendorTesseractThreshold()` catches Throwable and returns default 70 |
