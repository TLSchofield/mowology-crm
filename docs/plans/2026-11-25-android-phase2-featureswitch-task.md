# Task: one FeatureSwitch helper instead of 35 copy-pasted `*Switch.kt` objects

Branch codex/native, package ca.mowology.crew. Do not touch iOS or any server file. Behaviour must not change.

## Problem
Every `Native*Switch.kt` / `*Switch.kt` (find with `find . -name '*Switch.kt'`, 35 files) repeats the same
fetch / cache / parse code: read a boolean from `AppVersionFeed.json()` -> `android.<feed_key>`, store it in
`TrackingStore.prefs` as `<name>_last_seen` plus `<name>_fetched_at`, and treat a cached ON as valid for 24 hours.
Each has its own `parseSwitch` / `resolveSwitch` in a `*Logic.kt`, written slightly differently. Some read prefs with
`safeBoolean`, some without; Ittai (`ServerConfig.isIttai`) builds treat several as default-on.

## Build
1. New file `config/FeatureSwitch.kt`:
   - a pure part (no Android types) holding the rules: `parse(json, feedKey): Boolean?` (null json -> null; otherwise
     literal `true` only, anything else false — the current production rule), `resolve(fetched, cached, fetchedAtMs, nowMs)`
     (fetched wins; else cached==true, fetchedAt>0, 0<=age<24h), and the Ittai variants (`parseExplicit`, default-on until a
     fresh cached false — copy the exact semantics from `NativeLaunchLogic.testTenantSwitch` / `parseExplicit`, reuse them).
   - a small class `FeatureSwitch(prefKey: String, feedKey: String, ittaiDefaultOn: Boolean = false)` with
     `isOn(context, nowMs)` and `refresh(context, nowMs)` (blocking, same contract as today: a failed fetch does not
     renew the cache and returns the cached answer). Use the SAME pref key names each switch uses today
     (`native_x_last_seen`, `native_x_fetched_at`) so cached values survive the update.
2. Convert every switch whose behaviour is provably identical so the object becomes a thin wrapper:
   `object NativeFooSwitch { private val s = FeatureSwitch("native_foo", "native_foo"); fun isOn(c, n=..) = s.isOn(c, n); fun refresh(c, n=..) = s.refresh(c, n) }`.
   KEEP each object and its public method names/signatures — callers must not change.
3. Do NOT convert (leave as is, list them with the reason): switches with extra logic (QueueSafetySwitch legacy-owner code,
   NativeAuthSwitch.active, NativeDaySwitch if it differs, anything with a different TTL, a different parse key than its
   pref key, or an extra method). Extra methods stay in the object next to the helper call.
4. Delete the now-unused `parseSwitch`/`resolveSwitch` of each converted `*Logic.kt` ONLY if nothing else calls them
   (grep first, including tests). If a test calls them, move that test's assertions to `FeatureSwitchTest` and delete the
   old one only when the same case is covered there.

## Tests (behaviour, not source scans)
`FeatureSwitchTest`: parse (null json, missing android block, true, false, non-boolean, string "true"), resolve (fetched wins,
cached fresh, cached expired at exactly 24h, cached false, clock went backwards, never fetched), Ittai default-on
(no cache, fresh cached false -> off, expired cached false -> on, cached true -> on). Plus one equivalence test over a table
of the converted switches asserting each one's (prefKey, feedKey) pair against the pair in git HEAD's old code — write the
table from the old files BEFORE you edit them.

## Rules
- No new dependencies, Kotlin only. Do not widen DayViewModel/DayScreens/DayRepository.
- `./gradlew --offline testMowologyDebugUnitTest assembleMowologyDebug` must pass (and the Ittai flavour tests if that task exists).
- Finish with: files added/changed, the list of switches converted vs left (with reasons), lines removed.
