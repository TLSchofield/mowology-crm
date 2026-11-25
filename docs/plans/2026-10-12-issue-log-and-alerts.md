# One issue log for iPhone, Android, web and server — flagged the moment they happen (plan, 2026-10-12)

Tim 2026-10-12: "plan for crashes, bug logging, anything to ensure issues are flagged the very
moment they occur." Must land before Android 1.4.0 (native day screens) reaches crew phones.

## Today (checked 2026-10-12)
- No crash reporting in the iPhone app or the Android app (no SDK, no handler).
- No error catching on web pages — which ARE the Android crew screens today.
- PHP errors go to the server's text log only; `system_log` has 1 row since March;
  `CRMErrorHandler` (app/Core/ErrorHandler.php) is opt-in per page; a PHP fatal still returns a
  truncated page with HTTP 200 (known trap).
- `compliance_events` (17 rows) carries GPS/battery events from the phones — not errors.
- Push to Tim works (APNs live; `PushDispatcher::notifyUsers`). Ada checks crons/connections only.

## What "the moment" can mean, per kind
| Kind | Earliest possible flag |
|---|---|
| Server error (PHP fatal, exception, 5xx) | instant |
| Web page script error / failed request | instant (sent from the page) |
| Failed server call from either app | instant, or when signal returns |
| Problem a crew member reports | instant |
| App crash or freeze | when the app is next opened (usually seconds) — a crashed app cannot send anything |

## Unknowns and how each is settled
| # | Unknown | Settled by |
|---|---|---|
| 1 | MetricKit delivery | iOS 15+ delivers crash/hang diagnostics on next launch; app targets iOS 17 (project.yml). Known Apple forum report of misses on 17.2–17.5 → add our own "previous session ended badly" marker as a second source |
| 2 | Android crash/ANR capture | `Thread.setDefaultUncaughtExceptionHandler` (all versions) + `ApplicationExitInfo` (Android 11+; minSdk is 23, so the marker method below covers older phones) |
| 3 | Alert spam | fingerprint each issue (kind + place + top of stack + app version); push only for a NEW fingerprint, a spike (5+ in 10 min), or any crash in a version released in the last 48 h; everything else goes to Ada's page and Charlie's brief |
| 4 | Privacy | never send passwords, tokens, card or bank details: scrub `Bearer …`, `token=`, `password`, 13–19 digit runs before storing; keep user id, app version, device model, screen, message, top 30 stack lines; 90-day retention via the Privacy module |
| 5 | Web pages with no login (customer portal, public site) | same endpoint, no user id, rate-limited per IP |
| 6 | Quiet hours | Tim is in England now; pushes always for crashes/new issues, Charlie's brief for the rest — confirm with Tim |

## Design
- **One table** `app_issues` (+ `app_issue_events` per occurrence): fingerprint, kind (crash, hang,
  error, failed_call, report), source (ios, android, web, server), app version, first/last seen,
  count, status (open / known / fixed-in-version), and who reported it.
- **One intake endpoint** `/api/issues` — JWT (apps) or session (web), batched, rate-limited;
  `IssueService` does fingerprinting, scrubbing, spike detection and the alert decision.
- **Alert:** push to Tim (and Ada's line in Charlie's brief) the moment a rule fires:
  "Android 1.4.0 crashed on clock-out — Nigel, 07:42. Tap for details."
- **Ada's System Health page** gains an Issues section: open issues by count, the version they
  started in, and a Copy-task hand-off for a developer session. "Fixed in 1.4.1" closes an issue;
  if it comes back in 1.4.1 it reopens and alerts.

## Steps (each proven before the next)
1. **Server:** migration (Tim runs), `IssueService` + tests, `/api/issues`, global PHP fatal and
   exception capture (one include every page and API already loads), push rules, Ada's section.
   Proof: a deliberate test error on a hidden test URL arrives as a push within a minute.
2. **Web:** one small script on every CRM and crew page: script errors, unhandled promise
   errors, failed `fetch` calls (4xx/5xx/network), sent with `sendBeacon` so it survives page
   changes. "Report a problem" in the crew menu and the CRM top bar. Proof: a forced error on a
   test page → push.
3. **Android (inside 1.4.0, before it ships):** crash handler writes the crash to disk; sent on
   next launch; exit reasons on Android 11+; failed native calls reported; "Report a problem"
   on the Today screen. Proof: a test crash in a debug build → push on relaunch.
4. **iPhone (next TestFlight build):** MetricKit subscriber, "ended badly" marker, failed API
   calls from `APIClient`, "Report a problem" on the Team/Account screen. Proof: Xcode's
   simulated MetricKit payload + a forced failed call → push.
5. **Skills:** Ada's skill and every head's `surfaces.md` note: a new screen reports its errors
   through the one reporter, never its own.
