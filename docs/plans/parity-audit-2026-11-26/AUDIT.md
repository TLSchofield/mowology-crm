# Android ↔ iPhone audit — phases 0–1 (2026-11-26)

Read-only. Built from the two inventories next to this file (`ios-inventory.md` 51 rows, `android-inventory.md` 46 rows). Both were produced by grep and targeted reads, **not** line-by-line, and rows marked (?) are inferred. Anything below marked **verify** must be opened in code before a fix is written. Nothing here is measured on a device: startup time, scroll smoothness, battery and queue size are **not yet audited** (phase 0b).

## Shape of the two apps
- iPhone: 214 Swift files, ~60.7k lines, no per-business flags, Ittai design always on.
- Android: 290 Kotlin files in `main` (424 with tests), all native Compose. The only WebView is the Capacitor fallback shell, reached when a native switch is off.
- Android gates: about 30 switches in **three shapes** (a `FeatureSwitch` class, 19 hand-rolled `Native*Switch` objects, and legacy parse functions), 24 h cache expiry. On Ittai most keys are forced on; **nine native features stay dark** even there: job screen, receipt intake, vendor items, penny receipt card, product add, geofence power, office quotes/invoices, office carousels, onboarding brain/contacts. Production is off until the feed says true.

## Ranked gaps

### P0 — privacy and data loss
1. **iPhone schedule cache survives sign-out and has no user id in its key** (`ScheduleFilterRules.swift:43`; `AuthSession.logout()` clears only OfflineDirectory). On a shared phone the next person can see the previous person's cached schedule offline. Confirmed in code. Fix: key by user id and clear on sign-out.
2. **iPhone visit work (checklist, materials, notes) is not queued** (`VisitWorkViewModel.swift:159`): "changes are still on screen", lost if the app is killed. Android already queues each toggle in `pending_actions`. Fix on iPhone: queue like Android.
3. **Android Add job / Add client is online only** (`FieldToolsScreens.kt`, server first, no queue). iPhone queues these in `CreateOutbox` with a dedupe key. Fix on Android: an outbox with a request id, same as `OfficeDocumentsStore`.
4. **Android sign-out wipe: verify** that `StoredDayStore` (`offline_day.*`, `offline_known_people`), `SchedulePlusStore` and `OfficeStore` are cleared or per-user-scoped on sign-out. Not confirmed either way.
5. iPhone sign-out leaves all queues in place by design (unsent punches wait for next sign-in). That is deliberate, so only record it: a different user on the same phone must not be able to see or send them. **verify** ownership checks.

### P1 — missing or dark features
- **Android has no Add task** and no "outbox status line" equivalent (only sync badges). iPhone has both.
- **Android special-request gate: not found** (iPhone row 17, `SpecialRequestOutbox`). **verify** that it truly does not exist.
- **Android Recommend a service**: drafts in prefs and photos queued, but sending is server-first. iPhone queues the whole thing (`RecommendationQueue`). Partial.
- **Owner one-box search + ask** (iPhone) vs Android client search plus a separate Ask section. Partial.
- **Nine native Android features are built but dark.** Each needs a decision: turn on for Ittai, finish first, or remove. They are the cheapest parity wins and carry the most untested code.
- iPhone has no equivalent of Android's tracking-setup screen or geofence arrival prompts. Android-only, not a gap.

### P2 — forms and design (both apps)
- **iPhone:** no keyboard Done on any number pad (14 files, 24 fields); disabled buttons give no reason in 9 screens; `IttaiField` (the labelled, 44pt, error-aware field) is used in only 15 places, every other form is a raw `TextField`; 10 sheets skip `.itAppForm()`; target sizes hard-coded 44/48 in about 70 places versus `ItSize.targetPhone` (60 uses); head tier colours and the update screen use raw colours.
- **Android:** four numeric fields without a numeric keyboard (`OfficeDocumentsScreens.kt:75-76`, `FinishScreens.kt:216`, `OttoScreens.kt:276-277`); onboarding Email without an email keyboard; two clickables under 48dp (`ReviewScreens.kt:133` at 40, `SchedulePlusScreens.kt:575` at 36) and about 15 with no minimum; 14 raw colours, 13 of them camera scrims (acceptable) and one gold literal at `SignInScreen.kt:208`.
- The two apps use **different minimum target sizes** (iPhone 44, Android 48) and different field components. The shared rule set is `mobile-ui-craft` M16/M17 and Ittai v1.2.

### P3 — refactor candidates (only after P0–P2 land)
- Android: collapse the three switch shapes into one `FeatureSwitch` with a single cache rule. Today the same key can behave differently depending on its shape (for example `receipt_steady` defaults ON, everything else OFF).
- Android: one shared form-field component with label, keyboard, error and 48dp, used by every form.
- iPhone: `IttaiField` everywhere, one Done toolbar, one target-size token.
- Both: one queue-drain contract written down (order, idempotency key, retry, caps) and a test per queue.

## Offline: works / queues / unavailable (crew features)
| feature | iPhone | Android |
|---|---|---|
| clock in/out, breaks | queues (ClockQueue) | queues (pending_actions) |
| job start/complete/skip | queues (TransitionQueue) | queues |
| visit checklist/materials/notes | **screen only** | queues |
| before/after photos | queues | queues (Room) |
| receipts capture | queues | queues |
| receipt approve/reject | queues | queues (actions ledger) |
| trip reports | queues | queues (Room) |
| GPS fixes | FixStore | Room fixes |
| add job / client | queues | **online only** |
| add task | queues | **missing** |
| add quote / invoice draft | queues | queues, but switch is OFF |
| recommend a service | queues | partial |
| move stop, endorse | unavailable | unavailable (server-confirmed) |
| invoice send, Plant ID, search, heads | unavailable | unavailable (Plant ID queues on Android) |
| schedule read | cached 14 days | cached 14 days, plus offline-start worker |

## Phase 2 order (for approval)
1. P0 items 1–4 (privacy and data loss), each with a test, then Codex review (sign-in/queues are on the high-risk list).
2. Dark features: one decision per switch with Tim.
3. P1 missing features, Android first (Add task, queued Add job/client, special requests if absent).
4. P2 forms on both apps, using the shared Done button and field component.
5. Phase 0b measurements (startup, scrolling, battery, queue size) before any claim about efficiency.
6. P3 refactor last.

Not verified: everything marked **verify**, all of the efficiency claims, how any screen looks on a device.
