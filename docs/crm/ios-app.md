# iOS App — Mowology CRM Field

SwiftUI app that wraps the field-side workflow: schedule, time clock, receipts.

- **Min iOS:** 17.0 · **Swift:** 5.9 · **Bundle ID:** `ca.mowology.crm-field`
- **Project:** generated from `ios/MowologyCRM/project.yml` via XcodeGen
- **iOS-specific rules:** [`ios/MowologyCRM/CLAUDE.md`](../../ios/MowologyCRM/CLAUDE.md)

---

## Offline queues

Three queues persist work that has to survive offline gaps. All three reuse the same idempotency key across retries so the server's `idempotency_keys` table can dedupe.

| Queue | Purpose | Storage | Drain trigger |
|-------|---------|---------|---------------|
| `PingQueue` | GPS pings during a job timer | UserDefaults | Foreground tick / reconnect |
| `TransitionQueue` | Job-timer start/stop actions | SwiftData | User retap / reconnect |
| `ReceiptQueue` | Failed receipt uploads (camera capture) | UserDefaults + temp dir | `NWPathMonitor` reconnect |

### ReceiptQueue — retry & backoff

`ios/MowologyCRM/MowologyCRM/Core/Offline/ReceiptQueue.swift`

Each pending receipt is a `PendingItem` with:

| Field | Purpose |
|-------|---------|
| `id` | UUID — also the `Idempotency-Key` header on every retry |
| `imageFilename` | File in `FileManager.temporaryDirectory` (`receipt-<uuid>.jpg`) |
| `lat` / `lng` / `jobId` | Original capture metadata |
| `attempts` | Per-item retry counter |
| `nextAttemptAt` | Earliest moment this item is eligible for the next attempt |
| `failedUnrecoverable` | True after 5 attempts; surfaces an alert + a "X failed" badge |

**drain() loop:**

1. Skip items where `failedUnrecoverable == true` or `Date() < nextAttemptAt`.
2. Try the upload via the injected `uploadHandler` (passes the item's `id` as the idempotency key).
3. On success: remove from the queue, delete the temp file.
4. On failure: increment `attempts`, set `nextAttemptAt = Date() + min(2^attempts, 3600)` seconds, **continue** to the next item (does not abandon the batch).
5. After `attempts >= 5` the item is marked `failedUnrecoverable` and `lastFailureMessage` is published. `ReceiptsView` shows an alert and a red "X failed" toolbar badge.

Backoff schedule: 2s · 4s · 8s · 16s · then unrecoverable (cap is 1 hour for safety; never reached at the current `maxAttempts = 5`).

The user can recover a `failedUnrecoverable` item via `ReceiptQueue.shared.retry(itemId:)` or drop it via `discard(itemId:)`.

### Idempotency on the first attempt

`ReceiptsViewModel.uploadImage` generates the UUID up-front and reuses it for both the live upload (`Idempotency-Key` header) and any later enqueue. A first-attempt timeout where the server already accepted the upload but the response never reached the client is therefore safe to retry — the server returns the original `media_id` instead of producing a duplicate expense.

### Temp-file cleanup

`ReceiptQueue.cleanupOldTempFiles()` runs once per app launch (off the main thread) and deletes `receipt-*.jpg` files older than 7 days from the temp directory. Scoped to the `receipt-` prefix so other temp consumers are untouched.

### Wiring

`MowologyCRMApp` calls `ReceiptQueue.shared.startMonitoring { ... }` from the root `.task` so reconnects automatically drain the queue. The closure captures an `APIClient` bound to the current `AuthSession`.

---

## Other systems

- **Auth:** JWT in Keychain, managed by `AuthSession`. 401 responses log out automatically via `APIClient`.
- **GPS tracking:** `LocationManager` + `GPSTrackingService.shared`. Background refresh task `ca.mowology.gps-refresh` nudges the app every 8 minutes when a job timer is active.
- **Version gating:** `VersionCheckService` runs in `RootView.task` and shows `AppUpdateView` if `mustUpdate`.
- **Job timer transitions:** `TransitionQueue` (SwiftData) — see `Features/Schedule/VisitDetailViewModel.swift` for the prepare → request → confirm flow.
