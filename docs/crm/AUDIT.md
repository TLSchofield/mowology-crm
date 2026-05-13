# Mowology CRM — Architectural Audit

**Date:** 2026-05-09
**Scope:** Production field-service platform (PHP 7.4 / MySQL 8.0 / Capacitor Android / Native iOS / PWA)
**Method:** Five-parallel deep-dive across security, reliability, performance, code quality, and mobile/offline. No code changes — audit only.

---

## Executive Summary

The platform shows mature foundations — prepared statements are uniform, CSRF and rate-limiting are in place, the schedule's hot path (`getCalendarStops`) has already been refactored to ~4 batched queries, and session-lock fixes for mobile are correctly applied. **The dominant risk class is not security but offline-mobile reliability:** the receipt-upload, visit-photo, and time-clock flows lack idempotency keys, so any WorkManager/iOS retry can create duplicate revenue rows. The iOS receipt queue silently abandons remaining photos on the first failure (`break` on error), and Android service-worker sync skips Filesystem-stored photos entirely — both are revenue-critical defects. Three fallback secrets in JWT/unsubscribe/CMS-preview code paths will degrade auth strength if `secrets.php` is misconfigured. Test coverage is concentrated in Accounting; Jobs, Marketing, and Team modules have effectively zero coverage despite being the operational core. Overall, the codebase is fit for production today but will not survive Jobber-replacement scale (Feb 2027 target) without addressing the idempotency, transaction-boundary, and async-queue gaps below.

---

## Risk Matrix

Each issue rated by severity and rough fix effort. Estimates assume one engineer familiar with the codebase.

### Critical

| # | Issue | File / Location | Effort |
|---|-------|----------------|--------|
| C1 | Receipt upload not idempotent — mobile retries duplicate expenses | `app/Modules/Expenses/Api/expense-save.php`, `app/Modules/Expenses/Api/receipt-upload.php`, `public/crm/js/offline-receipts.js:213` | 6h |
| C2 | Visit-photo upload not idempotent — duplicate proof rows on retry | `public/crm/api/visit-photo-upload.php` | 4h |
| C3 | iOS receipt queue `break`s on first failure — abandons remaining photos | `ios/MowologyCRM/MowologyCRM/Core/Offline/ReceiptQueue.swift:82-94` | 4h |
| C4 | Android Filesystem-queued photos never drained by service worker | `public/service-worker.js:472`, `public/crm/js/photo-queue.js` | 6h |
| C5 | Inconsistent API response envelopes (`{ok}` vs `{success}` vs raw) — mobile clients can't reliably parse | 220+ endpoints across `public/crm/api/` and `app/Modules/*/Api/` | 16h (incremental) |
| C6 | Zero test coverage for Jobs, Marketing, and Team modules | `app/Modules/{Jobs,Marketing,Team}/` | 24h (initial) |
| C7 | Contract billing cron sends invoices fire-and-forget; SMTP failure → invoice silently lost | `app/Modules/Contracts/Cron/contract_billing.php` | 8h |

### High

| # | Issue | File / Location | Effort |
|---|-------|----------------|--------|
| H1 | JWT signing secret falls back to `hash('sha256', DB_PASS . ...)` if env var missing | `app/Core/Auth/JwtAuth.php:40-41`, `public/api/auth/token.php:210-211`, `app/Modules/Social/Services/SocialEncryption.php:80` | 1h |
| H2 | Receipts uploaded uncompressed (8MB JPEGs over cellular) | `public/crm/js/offline-receipts.js:213` | 3h |
| H3 | Receipt OCR + image re-encoding run synchronously on the request thread | `app/Modules/Expenses/Api/receipt-upload.php:114-125` (and ReceiptOCR/Parser imports) | 12h (queue) |
| H4 | Service-worker background sync deletes records on `r.ok` only; 4xx leaves them queued forever, no idempotency | `public/service-worker.js:371-387` | 3h |
| H5 | Expense save does INSERT → UPDATE → INSERT line items without a transaction | `app/Modules/Expenses/Api/expenses.php` `handleSaveExpense()` | 2h |
| H6 | `ReceiptService::sendReceiptToAccounting` writes log → sends email → updates status; no transaction | `app/Modules/Services/Receipts/ReceiptService.php:93-118` | 2h |
| H7 | `VisitCompletionService::capture` UPDATEs visit then INSERTs margin snapshot without a transaction | `app/Modules/Jobs/Services/VisitCompletionService.php:86-140` | 1h |
| H8 | `ContactService::deleteCompaniesInBatch` issues N COUNT queries inside a foreach | `app/Modules/Contacts/Services/ContactService.php:343-362` | 1h |
| H9 | `AccountingService::syncFromInvoices` / `syncFromExpenses` upsert per row → 1000s of queries on bulk sync | `app/Modules/Accounting/Services/AccountingService.php:124-151, 171-210` | 4h |
| H10 | Forgot-clockout SMS cron + marketing campaign sender have no retry queue or delivery log | `app/Modules/Team/Cron/forgot_clockout_sms.php`, `app/Modules/Marketing/Cron/campaign_sender.php` | 6h |
| H11 | Many missing indexes on hot paths — `job_visits(stop_id,status)`, `calendar_stops(stop_date,crew_id)`, `expenses(status,expense_date)`, etc. | `database/migrations/` | 2h |
| ~~H12~~ | ~~Empty/log-only catch blocks in product schema checks — silent migration drift~~ — **RESOLVED 2026-05-09**: all column-existence catches now `error_log()` with file/line/context; `$hasColumn` defaults explicitly to `false` so failure mode is conservative. | `app/Modules/Products/Api/api-products.php`; `field-observations.php` | 2h |
| H13 | Two admin POST endpoints lack CSRF validation | `public/crm/api/system-log-clear.php:26-34`, `public/crm/api/forgot-clockout-cron.php:13-17` | 1h |
| H14 | Wrong HTTP status codes — validation errors thrown as 500, blocks retry/parsing logic on mobile | Pattern across `app/Modules/*/Api/` | 6h (sweep) |
| H15 | Input validation absent on most legacy endpoints (no length/format/range checks; implicit type coercion) | Sample: `time-clock.php`, `delete-page.php`, `marketing/campaigns.php` | 12h (sweep) |
| H16 | Service worker has no cache-version-hash + no fetch timeout on network-first → stale JS post-deploy and 30s hangs on 2G | `public/service-worker.js:22-26, 181-184` | 3h |

### Medium

| # | Issue | Location | Effort |
|---|-------|----------|--------|
| M1 | Hardcoded fallback for `UNSUBSCRIBE_SECRET` (`'mowology-unsub-2026'`) and `CMS_PREVIEW_SECRET` (`'mwly_cms_preview'`) | `app/Modules/Marketing/Api/optin.php:238`; `public/unsubscribe.php:32`; `app/Services/Messaging/TemplateRenderer.php:166`; `public/cms-render.php:68` | 1h |
| M2 | GPS tracking-sync batch lacks batch-id idempotency at HTTP layer | `public/crm/api/tracking-sync.php` | 4h |
| M3 | `BankImportService::commit()` multi-step (stage → apply rules → learn) lacks end-to-end idempotency | `app/Modules/Accounting/Services/BankImportService.php` | 3h |
| M4 | Crons (`weather_schedule_guard`, `campaign_sender`, `sync-ledger`) have no flock/lockfile; long runs can overlap | `app/Modules/{Jobs,Marketing,Accounting}/Cron/` | 3h |
| M5 | Time-clock has no client-side debounce; impatient double-tap = two overlapping requests (server idempotency saves it but adds load) | `public/crm/js/capacitor-bridge.js`, `app/Modules/Team/Api/time-clock.php` | 1h |
| M6 | `dashboard-map-data.php` uses unbounded `GROUP_CONCAT` over visits; large schedules truncate or stall | `public/crm/api/dashboard-map-data.php:108-110` | 2h |
| M7 | `getCalendarStops` main JOIN is fine but missing composite index on `job_visits(stop_id, status)` | `app/Modules/Jobs/Services/PlanFunctions.php:1102` | 0.5h |
| M8 | `ops_settings` re-queried on every page load — no caching | `public/crm/jobs/schedule.php:38-46` and similar | 2h |
| M9 | `SELECT *` on `job_plans`/`job_visits` ships large TEXT columns (checklist_template, weather_snapshot_raw) every query | `PlanFunctions.php:595, 1712, 2354` | 2h |
| M10 | `expenses` list COUNT(*) full-scans on every paginated request | `app/Modules/Expenses/Api/expense-list.php:69` | 0.5h (with index) |
| M11 | Email send (`MessagingService::sendEmail`) does not always log result to `email_log` from caller | `app/Services/Messaging/MessagingService.php:86-111` | 2h |
| M12 | iOS image processing on main thread — UI freeze on 8MP capture | `ios/MowologyCRM/MowologyCRM/Features/Receipts/ReceiptCaptureView.swift:13-48` | 2h |
| M13 | `QuoteService::insertLineItems` executes per-row instead of one batched INSERT | `app/Modules/Quotes/Services/QuoteService.php:490-517` | 1h |
| M14 | Strict types coverage 54% on legacy `public/crm/api/`; new code at 85% | Multi-file | 8h (sweep) |
| M15 | Stale CSRF token in offline receipt queue → 403 on retry, blocks queue indefinitely | `public/crm/js/offline-receipts.js:206-210` | 2h |

### Low

| # | Issue | Location | Effort |
|---|-------|----------|--------|
| ~~L1~~ | ~~`data_retention.php:51` swallows Throwable silently~~ — **RESOLVED 2026-05-09**: cron_runs_log catches now log to `error_log`; `PrivacyService::purgeExpiredLocationData` wraps each table-purge DELETE in its own try/catch so a failure on one table no longer blocks the rest, and per-table errors are returned in the result. | `app/Modules/Privacy/Cron/data_retention.php`, `app/Modules/Privacy/Services/PrivacyService.php` | 0.25h |
| L2 | `media-uploader.js` blur detection uses canvas on main thread (100-200ms freeze on large images) | `public/crm/js/media-uploader.js:131-150` | 1h |
| L3 | Three large legacy endpoint files (`quiz.php` 1501 LOC, `pow-actions.php` 972 LOC, `jobber-reconsent.php` 857 LOC) — should be extracted to services | `public/crm/api/` | 16h+ |
| ~~L4~~ | ~~`cms/save-media.php:157,162` empty catch on media save failures~~ — **RESOLVED 2026-05-09**: column-check catches log via `error_log`; `PDOException`/`Throwable` handlers now log and return a clear 500 JSON body instead of an opaque "Database error" so the UI can surface the real failure. | `app/Modules/CMS/Api/save-media.php` | 0.25h |

---

## Ordered Improvement Roadmap

The order is chosen by **revenue/operational impact first**, **field-reliability second**, **scale-readiness third**. A receipt that double-bills a customer or a photo that vanishes are immediate revenue events; a slow query is not.

### Phase 1 — Stop the bleeding (this sprint, ~3 days)

1. **C3 — Fix iOS `ReceiptQueue.drain` `break`-on-failure.** Single-line bug today (line 93 of `ReceiptQueue.swift`); the rest of the queue is sound. Change `break` to `continue` with retry-counter increment, add exponential backoff, and a 5-attempt deadletter. **Today's fix that recovers tomorrow's lost photos.**
2. **C1 + C2 — Add idempotency keys to receipt and visit-photo upload.** Generate UUIDv4 client-side at capture, send as `Idempotency-Key` header, server checks against `media_assets.idempotency_key` (new column) before insert, returns existing row on collision. Use `app/Core/IdempotencyHelper.php`. Wire it into `expense-save.php`, `receipt-upload.php`, `visit-photo-upload.php`.
3. **C4 — Stop losing Android Filesystem photos.** Either (a) drop the SW Filesystem-skip and route Android photos through IDB so SW can drain them, or (b) make `MwPhotoQueue.processQueue()` auto-run on app foreground and add a visible "N pending" indicator so users know to open the app. Option (a) is preferred — disk-cost is small compared to revenue impact.
4. **H1 — Remove JWT/secret fallbacks.** Three lines of code (`JwtAuth.php:40`, `token.php:210`, `SocialEncryption.php:80`); change to `defined('BLUEMOON_JWT_SECRET') or throw new RuntimeException(...)`. Verify production has the env var set before deploying. Same pattern for `UNSUBSCRIBE_SECRET` and `CMS_PREVIEW_SECRET` (M1).
5. **H13 — Add CSRF to the two admin endpoints** (`system-log-clear.php`, `forgot-clockout-cron.php`). One-line `verifyCSRFToken()` call each.
6. **H2 — Compress receipts client-side before queue.** Reuse `compressForUpload()` from `photo-queue.js` (already exists at line 567+). Cap at 2MB / 1600px / Q80. 90% bandwidth saving on cellular.

### Phase 2 — Reliability hardening (next sprint, ~5 days)

7. **C7 + H10 — Build a generic email/SMS retry queue.** `messaging_outbox` table with `(id, channel, recipient, payload, attempts, next_attempt_at, status, last_error)`. `MessagingService::sendEmail/sendSms` enqueue on send-failure; a 5-min cron drains with exponential backoff up to 5 attempts. Critical for contract billing (`contract_billing.php`) and forgot-clockout SMS — both currently lose messages on transient SMTP/carrier failures.
8. **H4 + M15 — Fix service-worker sync.** Verify successful upload before deleting from IDB. On 4xx mark `failed_unrecoverable` and surface to user. Eliminate stale-CSRF blocking by switching offline queue to idempotency-key-based auth (no CSRF required when key is unique).
9. **H5, H6, H7 — Wrap multi-write services in transactions.** `expenses.php::handleSaveExpense`, `ReceiptService::sendReceiptToAccounting`, `VisitCompletionService::capture`. Standard `try { beginTransaction; ...; commit } catch { rollBack; throw }` pattern.
10. **M4 — Add flock-based lockfiles to long-running crons.** Standard helper at `app/Core/CronLock.php`; one-line wrap at the top of `weather_schedule_guard.php`, `campaign_sender.php`, `sync-ledger.php`.
11. ~~**H12, L1, L4 — Replace empty catch blocks with `error_log()` lines.** Quick PR; the silent failures in product/field-observation/CMS code paths are landmines for future debugging.~~ — **DONE 2026-05-09.** Also extended to non-fatal catches in `app/Modules/Marketing/Cron/seasonal_triggers.php` (table/column existence checks + activity_log insert) which had the same silent-failure pattern.

### Phase 3 — Performance & API consistency (following sprint, ~5 days)

12. **H11 + M7 + M10 — Add the missing indexes.** Single migration file with the index list below. `CREATE INDEX` on `job_visits(stop_id, status)`, `calendar_stops(stop_date, crew_id, route_order)`, `job_plans(is_recurring, status, visits_generated_through)`, `job_visits(plan_id, scheduled_date)`, `calendar_stops(property_id, stop_date)`, `expenses(status, expense_date DESC)`, `quotes(status, created_at DESC)`, `invoices(company_id, status, due_date)`. Will not block writes meaningfully on tables of current size.
13. **C5 + H14 — Standardize response envelope.** Adopt `{success: bool, data: ..., error: string, message: string}` with HTTP 200/422/403/401/404/500. Build `ApiResponse::ok($data)` / `ApiResponse::error($code, $msg, $field)` helper. Migrate top 30 endpoints (most-called) first; the long tail can be done as endpoints are touched. Mobile clients should parse defensively but the canonical envelope reduces error-path bugs significantly.
14. **H8 + H9 + M13 — Eliminate three known N+1 patterns.** `ContactService::deleteCompaniesInBatch` → single grouped query; `AccountingService::syncFromInvoices` → batch upsert; `QuoteService::insertLineItems` → single multi-VALUES INSERT.
15. **H3 — Move OCR + image re-encode off the request thread.** `expense_ocr_jobs` table; receipt-upload.php inserts a row, returns 202 with task ID and the unprocessed media id; a cron worker (or invoked sub-process) processes pending OCR rows; client polls `/api/expense-ocr-status?id=N`. Biggest UX win after Phase 1.

### Phase 4 — Test coverage & hardening (parallel track, ongoing)

16. **C6 — Build integration test suite for Jobs, Marketing, Team modules.** Start with the most revenue-critical paths: campaign send, schedule-visit, time-clock punch, route optimization, crew assignment. Aim for 50+ tests covering happy-path + at least one error path per service method. Add to `tests/bootstrap.php`. PHPUnit suite already exists at the same scaffolding pattern as `tests/Unit/AccountingServiceTest.php`.
17. **H15 + M14 — Validation sweep.** Define `ValidationException` with `field` + `message`. Apply to top 20 mobile-facing endpoints first. Add `declare(strict_types=1)` as part of each touched file.

---

## Critical / High Findings — Detail

### C1, C2 — Receipt & visit-photo upload not idempotent

**Files:**
- `public/crm/js/offline-receipts.js:213-214` (client-side queue)
- `app/Modules/Expenses/Api/receipt-upload.php` (server)
- `app/Modules/Expenses/Api/expense-save.php` (server)
- `public/crm/api/visit-photo-upload.php` (server)

**What's wrong:** When the client retries (network timeout, app crash, WorkManager wake-up), the server has no way to recognize the second POST as the same photo. It writes a fresh DB row with a fresh filename. Result: duplicate expenses appear in the books, duplicate proof photos in job records.

**Fix:** Generate a UUIDv4 at capture time, persist alongside the blob in IDB / Filesystem / Room, send as `Idempotency-Key` HTTP header (or as a form field on the receipt-intake endpoint, which already accepts multipart). Server-side, add `idempotency_key VARCHAR(36) UNIQUE` to `media_assets` and the receipt/expense tables, check before insert via `app/Core/IdempotencyHelper.php`, return the original row on collision with a 200 OK so the client can clear the queue.

---

### C3 — iOS receipt queue abandons photos on first failure

**File:** `ios/MowologyCRM/MowologyCRM/Core/Offline/ReceiptQueue.swift:82-94`

**What's wrong:**
```swift
for item in pending {
    ...
    do {
        _ = try await uploadHandler(...)
        items = items.filter { $0.id != item.id }
        try? FileManager.default.removeItem(at: url)
    } catch {
        break  // ← halts entire batch on first error
    }
}
```
A single 500 from the server (or a transient network blip on the first item) abandons every remaining receipt in the queue. There is also no retry counter or exponential backoff, so reconnection causes immediate full-batch re-attempts.

**Fix:** Replace `break` with `continue` + per-item retry counter; add exponential backoff (`pow(2, attempt)` seconds); deadletter at 5 attempts; periodically clean temp directory of files older than 7 days.

---

### C4 — Android Filesystem-stored photos never reach the server

**File:** `public/service-worker.js:472`, `public/crm/js/photo-queue.js`

**What's wrong:** On Android (Capacitor), `MwPhotoQueue` writes images to the native Filesystem. The service-worker drain path explicitly skips non-IDB records (`if (r.storageType !== 'idb') continue`). The SW cannot reach Filesystem because that's a native layer. So if the worker captures photos, then receives a phone call and never returns to the app, those photos sit on disk forever — invisible to user, invisible to server.

**Fix:** Preferred — store all queued photos in IDB on Android too (small disk overhead, big reliability win, single drain path). Alternative — invoke `MwPhotoQueue.processQueue()` from `MainActivity.onResume()` and add a persistent notification when the queue is non-empty so the worker knows to open the app.

---

### C5 — Inconsistent API response envelopes

**Files:** Pattern across 220+ endpoints. Examples:
- `accounts.php:73` returns `{ok: true, accounts: []}`
- `assign-crew.php:208` returns `{success: true, message: ..., crew_ids: []}`
- `app-version.php:46` returns `{error: "..."}` (no success flag)
- `csrf-token.php:34` returns `{csrf_token: "..."}` (bare payload)
- `transactions.php:185` returns `{ok: false, error: "..."}`

**What's wrong:** Mobile clients parse responses with multiple branches (`if response.ok || response.success || response.data`), error-path code is brittle, and a 403 with `{error: "..."}` looks identical to a 200 success body. Non-trivial source of bugs in the iOS Swift client and Android bridge.

**Fix:** Adopt a single canonical envelope, document it, and migrate. Helper:
```php
final class ApiResponse {
    public static function ok($data = null): void { /* echo {success:true, data:$data} */ }
    public static function error(int $http, string $code, string $message, ?string $field = null): void { /* set status, echo standardized error */ }
}
```
Status codes: 200 success, 422 validation, 403 forbidden, 401 unauthorized, 404 not found, 500 server. Mobile clients then have a single `extract<T>(response)` helper.

---

### C6 — Zero test coverage for Jobs, Marketing, Team modules

**Files:** `app/Modules/Jobs/`, `app/Modules/Marketing/`, `app/Modules/Team/` (46 PHP files, 0 tests)

**What's wrong:** The operational core of the platform — schedule routing, crew assignment, campaign send, payroll/time-tracking — has no automated regression coverage. Every refactor or new feature is a flying-blind change. With Jobber-replacement deadline of Feb 2027, the rate of change will increase, not decrease.

**Fix:** Use `tests/Unit/AccountingServiceTest.php` as the template. Prioritize: `JobService::createFromQuote`, `ScheduleService::optimizeRoute`, `CampaignSender::sendBatch`, `TimeClockService::punchIn/Out`. Aim for ~50 tests across the three modules in the first pass. Add a `composer test` step to `.cpanel.yml` post-deploy hook (or a GitHub Action) so the suite runs automatically.

---

### C7 — Contract billing emails are fire-and-forget

**File:** `app/Modules/Contracts/Cron/contract_billing.php`

**What's wrong:** The monthly billing cron loops contracts, inserts an invoice, and sends an email. If `sendEmail()` returns false (SMTP timeout, transient relay failure), the invoice exists but the customer never receives it. Payment doesn't get collected. The user discovers this when the customer calls in 30 days asking why no invoice arrived.

**Fix:** Build `messaging_outbox` table; on send failure, enqueue with `attempts=1, next_attempt_at=now+5min`. A `messaging_retry` cron drains every 5 minutes, exponential backoff, deadletter at 5 attempts (admin notification on deadletter). Same pattern serves SMS retry needs (`forgot_clockout_sms.php`) and Marketing campaign sends.

---

### H1 — JWT secret falls back to derived-from-DB-password

**Files:** `app/Core/Auth/JwtAuth.php:40-41`, `public/api/auth/token.php:210-211`, `app/Modules/Social/Services/SocialEncryption.php:80`

**What's wrong:**
```php
$base = defined('DB_PASS') ? DB_PASS : 'bluemoon_fallback';
return hash('sha256', $base . '_bluemoon_jwt_v1');
```
If `BLUEMOON_JWT_SECRET` is not defined in `secrets.php` (a misconfiguration that fails silently), JWTs are signed with a value derived from the database password. A leaked DB password also compromises every issued token. The literal `'bluemoon_fallback'` string makes the worst-case fully predictable.

**Fix:** Hard-fail on missing secret:
```php
if (!defined('BLUEMOON_JWT_SECRET')) {
    throw new RuntimeException('BLUEMOON_JWT_SECRET must be defined in secrets.php');
}
return BLUEMOON_JWT_SECRET;
```
Same pattern for `UNSUBSCRIBE_SECRET` (`optin.php:238`, `unsubscribe.php:32`) and `CMS_PREVIEW_SECRET` (`cms-render.php:68`).

---

### H2 — Receipts uploaded uncompressed

**File:** `public/crm/js/offline-receipts.js:213`

**What's wrong:** The offline receipt queue stores the raw `<input type="file">` blob — typically 6-10MB on a modern phone. Visit photos go through `compressForUpload()` (1920px @ 85%, ~300KB). Receipts skip it entirely. On 2G in rural BC: 8MB × ~30s/MB ≈ 4-minute upload, often dropped mid-stream.

**Fix:** Apply the existing `compressForUpload` helper from `photo-queue.js:567-615` to receipts before queueing. Target 1600×1200 @ 80% JPEG (~250KB). Server OCR already downsamples to 1024×768 internally, so no quality loss.

---

### H3 — Receipt OCR runs synchronously on the request thread

**File:** `app/Modules/Expenses/Api/receipt-upload.php:114-125` (and ReceiptOCR/Parser/TesseractPreScreen imports)

**What's wrong:** Tesseract OCR + EXIF strip + JPEG re-encode all happen during the upload POST. Total wall-clock 5-10s per receipt on shared hosting. Mobile users see a long spinner; a connection drop at second 8 means starting over.

**Fix:** Save the file, insert an `expense_ocr_jobs` row, return 202 Accepted with the receipt ID immediately (~200ms response). A cron worker (every minute) picks up pending OCR jobs and processes them out-of-band. Client polls `/api/expense-ocr-status?id=N` for parsed vendor/total when needed.

---

### H4 — Service worker doesn't verify upload success before deleting

**File:** `public/service-worker.js:371-387`

**What's wrong:**
```javascript
.then(function(r) {
  if (r.ok) {
    var delTx = db.transaction('pending-receipts', 'readwrite');
    delTx.objectStore('pending-receipts').delete(receipt.id);
  }  // 4xx: record stays queued forever, no logging
}).catch(function() { /* retry on next sync */ });
```
On a 403 (stale CSRF), record stays queued indefinitely. On a 5xx, same. There is no per-record retry counter. A bad batch can starve real uploads.

**Fix:** Add `attempts` and `lastError` fields per record. On 2xx with `media_id` returned: delete. On 4xx (validation/auth): mark `failed_unrecoverable`, surface to user. On 5xx: increment attempts; deadletter after 5. Switch to idempotency-key auth so CSRF rotation can't break the queue (M15).

---

### H5, H6, H7 — Multi-step writes without transactions

| File | Operation | Fix |
|------|-----------|-----|
| `app/Modules/Expenses/Api/expenses.php::handleSaveExpense` (lines ~460-938) | INSERT expense → UPDATE with media_id → INSERT line items | Wrap in `beginTransaction`/`commit`/`rollBack` |
| `app/Modules/Services/Receipts/ReceiptService.php:93-118` | INSERT email_log → sendEmail → UPDATE expense.status='forwarded' | Same pattern; rollback on email failure |
| `app/Modules/Jobs/Services/VisitCompletionService.php:86-140` | UPDATE job_visit → INSERT visit_margin_snapshot | Same pattern |

Each currently leaves orphaned rows on partial failure. **CLAUDE.md memory note: do NOT include `ALTER TABLE` in transactions** — these services don't, but it's a documented landmine for future authors.

---

### H8 — N+1 in batch-delete companies

**File:** `app/Modules/Contacts/Services/ContactService.php:343-362`

**What's wrong:** `foreach ($ids as $id)` issues a `SELECT (subquery jobs) + (subquery invoices)` count per company. Bulk delete of 50 companies = 50 round-trips of full COUNT scans.

**Fix:** Single batch query:
```sql
SELECT c.id, COALESCE(j.cnt,0) + COALESCE(i.cnt,0) AS total
FROM companies c
LEFT JOIN (SELECT company_id, COUNT(*) cnt FROM jobs WHERE company_id IN (?,?,...) GROUP BY company_id) j ON j.company_id = c.id
LEFT JOIN (SELECT company_id, COUNT(*) cnt FROM invoices WHERE company_id IN (?,?,...) GROUP BY company_id) i ON i.company_id = c.id
WHERE c.id IN (?,?,...) HAVING total > 0
```

---

### H9 — Per-row upserts in accounting sync

**File:** `app/Modules/Accounting/Services/AccountingService.php:124-151, 171-210`

**What's wrong:** Both `syncFromInvoices()` and `syncFromExpenses()` loop and call `upsertTransaction()` for each row — and `upsertTransaction` does a `SELECT` then `INSERT` or `UPDATE`. A 1000-invoice sync = 2000+ queries.

**Fix:** Stage all transactions into a temp table or array, then a single `INSERT ... ON DUPLICATE KEY UPDATE` covers the upsert in one round-trip.

---

### H10 — No retry/log for SMS and campaign sends

**Files:** `app/Modules/Team/Cron/forgot_clockout_sms.php`, `app/Modules/Marketing/Cron/campaign_sender.php`

**What's wrong:** Both fire SMS/email and discard the success/failure result. Carrier-level failures (SMS) and SMTP timeouts (campaigns) become invisible. Crew can stay clocked in overnight because the reminder SMS silently never arrived.

**Fix:** Generic `messaging_outbox` retry queue (see C7). For SMS specifically, also add an `sms_delivery_log(id, phone, message, carrier_gateway, status, sent_at, error)` table — reminder messages are stricter (160 chars, no URLs per CLAUDE.md rule 11) and we need an audit trail.

---

### H11 — Missing indexes on hot paths

Run as one migration:
```sql
CREATE INDEX idx_jv_stop_status        ON job_visits (stop_id, status);
CREATE INDEX idx_cs_date_crew_route    ON calendar_stops (stop_date, crew_id, route_order);
CREATE INDEX idx_jp_recur_status_horiz ON job_plans (is_recurring, status, visits_generated_through);
CREATE INDEX idx_jv_plan_date          ON job_visits (plan_id, scheduled_date);
CREATE INDEX idx_cs_property_date      ON calendar_stops (property_id, stop_date);
CREATE INDEX idx_e_status_date         ON expenses (status, expense_date);
CREATE INDEX idx_q_status_created      ON quotes (status, created_at);
CREATE INDEX idx_i_company_status_due  ON invoices (company_id, status, due_date);
CREATE INDEX idx_p_status_created      ON properties (status, created_at);
CREATE INDEX idx_c_prospect_lifecycle  ON contacts (prospect_status, lifecycle_stage);
```
Per CLAUDE.md memory: MySQL 8.0 doesn't accept `ADD COLUMN IF NOT EXISTS` — same applies to indexes; use plain `CREATE INDEX` and let the migration log prevent re-runs.

---

### H12 — Empty/log-only catch blocks ✅ RESOLVED 2026-05-09

**Files:**
- `app/Modules/Products/Api/api-products.php` — schema-existence checks for product history columns; silent failure meant downstream branched on the wrong condition.
- `app/Modules/Products/Api/field-observations.php` — same pattern.
- `app/Modules/CMS/Api/save-media.php` (L4) — column checks + DB-error catch now log and return a useful response body.
- `app/Modules/Privacy/Cron/data_retention.php` (L1) — cron_runs_log catches now log; `PrivacyService::purgeExpiredLocationData` runs each table DELETE in its own try/catch so one bad table no longer blocks the rest.
- `app/Modules/Marketing/Cron/seasonal_triggers.php` — same pattern, also fixed.

**Fix applied:** Replaced silent catches with the standard pattern:
```php
} catch (\Throwable $e) {
    $hasColumn = false;  // explicit conservative default
    error_log(sprintf('[%s] %s — %s in %s:%d',
        basename(__FILE__), 'context description',
        $e->getMessage(), $e->getFile(), $e->getLine()));
}
```

---

### H13 — Missing CSRF on two admin endpoints

| File | Endpoint behavior | Fix |
|------|-------------------|-----|
| `public/crm/api/system-log-clear.php:26-34` | TRUNCATEs `system_log` on POST | Add `verifyCSRFToken($_POST['csrf_token'] ?? '')` before the TRUNCATE |
| `public/crm/api/forgot-clockout-cron.php:13-17` | Triggers SMS campaign on POST | Same |

Both are admin-only and would require an admin to visit a malicious page, but the fix is one line.

---

### H14 — Wrong HTTP status codes

**Pattern:** Many endpoints throw a generic `Exception` for validation failures. The catch block returns 500 because the exception code is missing. Mobile clients then either retry indefinitely (treating 500 as transient) or show a generic "server error" instead of the real validation message.

**Fix:** Define `ValidationException`, `PermissionException`, `NotFoundException`. Map to 422/403/404 in a top-level exception handler. Return 500 only on genuinely unexpected `Throwable`s.

---

### H15 — Inconsistent input validation

**Pattern:** Endpoints rely on PHP type juggling (`(int)$_POST['id']` returns 0 for `'abc'`, which then matches "all" or "none" depending on the query). No length/format/range checks. Wrong-method handling absent — endpoints accept GET when they expect POST.

**Fix:** Adopt this canonical pattern (and apply incrementally as endpoints are touched):
```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new ValidationException('method', 'POST required');
}
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id = (int)($input['id'] ?? 0);
if ($id < 1) throw new ValidationException('id', 'Invalid id');
$name = (string)($input['name'] ?? '');
if ($name === '' || strlen($name) > 255) {
    throw new ValidationException('name', '1–255 chars required');
}
```

---

### H16 — Service worker cache invalidation + fetch timeout

**File:** `public/service-worker.js:22-26, 181-184`

**What's wrong:**
1. `CACHE_VERSION = 'mw-v43'` is manual. Easy to forget on deploy. Stale clients then run old JS against new APIs.
2. Network-first cache strategy has no timeout. On 2G, fetch can hang 30s before falling back to cache. Worker switches apps long before that.

**Fix:**
1. Generate cache version from a build-time content hash of `app.js + master.css`. Deploy step writes the hash into `service-worker.js`.
2. Wrap network-first fetches with `Promise.race([fetch, setTimeout(5000)])`. 5s timeout, then fall back to cache.

---

## Ideal offline-first receipt flow (target architecture)

1. **Capture → UUIDv4.** Generate `idempotency_key` immediately on photo selection. Persist in IDB alongside the blob.
2. **Compress before queue.** Resize to 1600×1200 @ 80% JPEG (~250KB) before storing. Discard original.
3. **Persistent queue with backoff.** Track `{id, key, blob_ref, attempts, last_attempt_ms, status, last_error}`. On failure: `attempts++`, schedule retry at `now + 2^attempts s`. Deadletter at 5.
4. **Server idempotency + return ID.** Server checks `media_assets.idempotency_key`. If found, return existing `media_id`. Else insert and return new ID. Always return the same `idempotency_key` so client knows the server has it.
5. **Client tombstone after server ack.** Delete queue record only on 2xx with `media_id` returned. On 4xx-permanent: mark failed, surface to user. On 5xx: keep, retry with backoff.

This is the same pattern that should govern visit photos, time-clock punches, and GPS tracking sync.

---

## Out of Scope for This Audit

- Frontend accessibility (WCAG) and i18n
- Database migration system review (note from CLAUDE.md memory: working but ad-hoc)
- CMS/marketing module deep dive (separate concern from field operations)
- Stripe payment processing flow
- iOS app feature completeness (the audit covered reliability of existing features only)

These are worth their own audits.
