# App vitals, automatic — iPhone + Android (plan, 2026-11-15, awaiting Tim's approval)

Goal: every phone reports what went wrong (and how the app performs) to our server with no export step; the server groups it, tells us about new problems, and compares each build with the last. Free only: our own server, MetricKit, Android's own APIs. No Sentry/Firebase.

## Split of work
- **This session (iOS + server):** wire contract, server endpoint/tables/service, iOS client, Ada card + alerts, symbol upload.
- **`android-vitals` session** ([notes](/Users/timschofield/Library/Application%20Support/Claude/side-session-notes/local_221b95bf-4eff-44a6-b147-bc8efc49f111/android-measurement-plan.md)): Android's on-device collection (DiagLog, exit reasons, watchdog, JankStats) is already its job. Added once this plan is approved: an `VitalsUploader` that sends the same wire format, and R8 `mapping.txt` upload for readable stacks.

## Wire contract (both platforms send this; the server never needs to know which)
POST batch, max 64 KB, ≤ 50 events, JWT-authenticated like the other mobile endpoints:
`{device:{platform, model, os, app_version, build, install_id}, events:[{id (uuid, idempotency), at (ISO UTC), kind, severity, message (≤ 600 chars, already redacted), signature_parts:{cause, frame, binary_uuid?}, breadcrumbs?:[≤30 short strings]}]}`
`kind` ∈ `crash`, `cpu_exception`, `hang`, `slow_launch`, `watchdog`, `unclean_exit`, `exit_summary`, `daily_metrics`, `net_errors`, `user_report`.
Rules: no request bodies, no tokens, no client/property names (existing `AppDiagnostics.redacted` stays the gate); the server rejects oversize rows rather than truncating silently.

## Unknowns and how each is settled
| Unknown | Settle by |
|---|---|
| Where the data lives: each business DB, or the platform/hub DB (multi-tenant: Tim, as operator, wants one view across businesses) | **Tim's call** — default: business DB with `business_id`, aggregate later |
| Auth/rate-limit pattern for a new mobile endpoint | read `public/crm/api/location-ping.php` + `app-version.php`, copy the pattern |
| `MXAppExitMetric` field names (exit counts by reason: memory, watchdog, bad access…) | typed API in the typecheck; fixture from first real payload |
| Unclean-exit detection without false positives (normal background kills are not problems) | marker set at foreground, cleared on background; only a kill **while foreground** counts; logic-tested. No UserDefaults writes in View init/body (build 627 black-screen lesson) |
| Symbol names on shared hosting (no `atos`) | CI script builds a small offset→name map per binary UUID from the dSYM (`nm` + `swift-demangle`), uploads it; server looks up nearest symbol ≤ offset. Needs an upload token as an Xcode Cloud secret — **Tim to add** |
| Retention/volume | 90-day purge cron; per-device cap (e.g. 200 events/day) so a loop cannot flood |

## Steps (each proven before the next)
1. **Server:** migration (1445+ self-applies): `app_vitals_events`, `app_vitals_issues` (signature hash, first/last seen, first build, count, status). `AppVitalsService` (signature, dedupe by event id, upsert issue, caps) + `tests/Unit` + register in `tests/bootstrap.php`; endpoint `public/crm/api/app-vitals.php` thin. Proof: phpunit, local POST of the two real iOS payload lines.
2. **iOS client:** file-backed `VitalsOutbox` (like the other offline queues), batch/backoff, hooks from `AppDiagnostics` for the kinds above; add `MXAppExitMetric` to the daily row; unclean-exit marker. Logic tests in `ios/LogicTests`; whole-app typecheck. No TestFlight push without Tim's go.
3. **Ada:** "App health" card — new issues, per-build comparison (launch, hang rate, crash-free sessions vs previous build), alert through the existing urgent path on a new signature or a spike; update the `ada-technology` skill in the same commit.
4. **Symbols:** `ios/MowologyCRM/ci_scripts/ci_post_xcodebuild.sh` + server lookup; Android mapping upload by `android-vitals`.
5. **Later, own plans:** per-screen timings (schedule load, clock-in, photo upload, receipt capture), "Report a problem" button, remote kill switch per feature.

## Risks
- Upload must never block or crash the app (fire-and-forget, size-capped, offline-safe).
- A crash-looping phone could flood: per-device daily cap on both sides.
- Privacy: events are technical, tied to a user/device id; say so in the privacy page before real rollout beyond the crew.
