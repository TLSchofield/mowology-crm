# Receipts — Async OCR Pipeline (server-side substrate)

Last updated: 2026-05-09

## Overview

This is the **server-side foundation** for moving receipt OCR off the
upload request thread. iOS already gets fast UX via on-device Apple
Vision pre-fill (shipped in `feature/language` — see ReceiptsView), so
this PR doesn't change iOS behaviour. What it adds is the **queue table,
worker, and status endpoint** that future endpoints can lean on once
they're ready to enqueue OCR instead of running it inline.

Today the pipeline is dormant — no endpoint inserts into `expense_ocr_jobs`
yet. The cron will run, find no rows, and exit. The wiring becomes live
when a follow-up converts an upload endpoint to enqueue.

## Components

| Component | Path | Purpose |
|-----------|------|---------|
| Migration | [`database/migrations/994_expense_ocr_jobs.sql`](../../database/migrations/994_expense_ocr_jobs.sql) | Creates the `expense_ocr_jobs` queue table |
| OCR worker | [`app/Modules/Expenses/Cron/process_ocr_queue.php`](../../app/Modules/Expenses/Cron/process_ocr_queue.php) | Drains pending rows; runs the existing OCR pipeline (Tesseract → Vision → parse → suggest) |
| Status (job-keyed) | [`app/Modules/Expenses/Api/receipt-ocr-status.php`](../../app/Modules/Expenses/Api/receipt-ocr-status.php) | `GET ?id=<ocr_job_id>` — JWT or session, scoped to `user_id` |
| Status (media-keyed) | [`public/crm/api/receipt-status.php`](../../public/crm/api/receipt-status.php) | Now prefers `expense_ocr_jobs`, falls back to `media_assets.status` for receipts that pre-date the queue |
| Web client | [`public/crm/js/offline-receipts.js`](../../public/crm/js/offline-receipts.js) | IDB schema v2 + `pending-ocr` store + `pollOcrJob()` API — accepts both 200 and 202 responses |
| Service worker | [`public/service-worker.js`](../../public/service-worker.js) | Cache `mw-v31`, captures `ocr_job_id` from 202 envelopes during background sync |

**Not changed in this PR:** `receipt-upload.php` (iOS/JWT) keeps its
inline OCR pipeline; `receipt-intake.php` (web/CSRF) keeps its inline
OCR pipeline; iOS code is untouched (main's Vision pre-fill flow is
already optimal for iOS UX).

## Wire format

### Queue row

```sql
expense_ocr_jobs
  id, media_id, expense_id, user_id,
  status ENUM('pending','processing','complete','failed'),
  parsed_vendor, parsed_total, parsed_date,
  parsed_raw_text MEDIUMTEXT, parsed_json MEDIUMTEXT,
  error TEXT, attempts TINYINT,
  started_at, completed_at, created_at
```

### GET `/crm/api/receipt-ocr-status.php?id=<ocr_job_id>` → 200 OK

```json
{
  "success": true,
  "data": {
    "id": 678,
    "media_id": 12345,
    "status": "complete",
    "parsed_vendor": "HOME DEPOT",
    "parsed_total":  "47.32",
    "parsed_date":   "2026-05-09",
    "parsed_raw_text": "...",
    "parsed":      { ... },
    "suggestions": { ... },
    "ocr_source": "tesseract",
    "ocr_available": true,
    "field_confidences": { ... },
    "gst_validation":    { ... },
    "job_suggestions":   [ ... ],
    "error": null,
    "attempts": 1,
    "completed_at": "2026-05-09 14:22:08"
  }
}
```

`status` flow: `pending` → `processing` → (`complete` | `failed`).
Clients should poll until they see a terminal state or give up after ~60s.

## Deploy sequence (post-merge)

Once the PR merges to `main` and cPanel auto-deploys:

1. **Apply the migration via the in-app runner.** Open
   [`/crm/database_appstack.php`](../../public/crm/database_appstack.php) (admin only)
   → **Migrations** tab → find `994_expense_ocr_jobs.sql` in the
   pending list → click **Execute**. The runner is wired through
   [`migrations-manager.js`](../../public/crm/js/migrations-manager.js)
   to [`app/Modules/Database/Api/migrations-manager.php`](../../app/Modules/Database/Api/migrations-manager.php),
   which logs each apply so the migration can't run twice.
2. **Register the cron** in cPanel (run every minute):
   ```
   * * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Expenses/Cron/process_ocr_queue.php >/dev/null 2>&1
   ```
   The worker self-locks via `flock` on
   `sys_get_temp_dir()/mowology_cron_process_ocr_queue.lock` so
   overlapping runs exit cleanly with code 0.

No iOS rebuild required — this PR doesn't change iOS code.

## Retry & failure semantics

* `attempts` is incremented on each claim (atomic `UPDATE ... WHERE status='pending'`).
* On exception:
  * `attempts < 3` → row reverts to `status='pending'` for the next tick.
  * `attempts >= 3` → row is set to `status='failed'` with the error message; not retried.
* If a worker crashes between claim and write-back, the row stays `processing` until the next tick recovers it (any `processing` row whose `started_at` is older than 5 minutes is bumped back to `pending`).
* Per tick: up to 20 rows processed, bounded by `LIMIT 20` on the candidate fetch.

## What the worker does

When a queue row exists, the cron runs the same pipeline that
`receipt-upload.php` / `receipt-intake.php` run today:

1. **HEIC → JPEG conversion** (Imagick `stripImage()`).
2. **EXIF strip** via GD re-encode (JPEG/PNG/WebP).
3. **Tesseract pre-screen** — fast local OCR for vendor matching.
4. **Vision API fallback** when Tesseract score is below the per-vendor threshold.
5. Receipt text **parsing** (totals, dates, GST, line items).
6. **Vendor + category suggestions** + learned-pattern application.
7. **Auto-vendor creation** when OCR vendor hint matches no existing record.
8. **GPS-matched job suggestions** from the user's schedule.

All output is written to `parsed_*` columns and a structured `parsed_json`
blob that the status endpoint hands back to clients.

## Follow-ups (out of scope here)

- Convert `receipt-intake.php` (web/CSRF) to enqueue → 202 → poll. The
  existing `expenses_appstack.php` review-modal poll loop already
  supports this via `receipt-status.php`; it's gated on the endpoint
  starting to return `ocr_status='processing'`.
- `App\Core\Api\ApiResponse` / `ApiHandler` envelope helpers (Task 13)
  and `App\Core\CronLock` mutex (Task 10) live on parallel branches
  not yet merged to main. New code uses inline `json_encode` envelopes
  and a plain `flock` block, marked with `TODO(api-envelope)` /
  `TODO(cron-lock)` comments. Swap is a one-line change in each spot
  once those branches land.
