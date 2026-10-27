# Android — the Mowology flavor in the Ittai design (review, 2026-10-27)

Tim, 2026-10-27: "review the mowology app and brand it with ittai design". iOS has been Ittai-only
since 1.3.56, so "the mowology app" is the Android crew app's production flavor (`mowology`,
`ca.mowology.crew`, host mowology.ca) in `~/Projects/mowology-android-codex` (branch `codex/native`).

## What the review found

**The Ittai design is already in the Mowology flavor's code.** Both flavors share every native
screen (`ui/ittai/*`, day, field tools, sign-in, AI Office, receipts, the adopted navigation from
Tasks 26–27). The flavors differ only in:

| Piece | `mowology` | `tenanttest` |
|---|---|---|
| Tenant colour tokens (`ItTokens.kt`, generated) | Mowology green `#2D8659` → tenant `#1A5A39` | Ittai indigo `#233A5E` |
| Launcher icon | Mowology leaf (`mipmap/ic_launcher_foreground`) | Ittai symbol on page colour |
| Splash | `drawable/splash.png` (Mowology) | Ittai symbol, 96 dp, page background |
| App name | "Mowology Crew" | "Ittai Test" |
| Native screens | **behind `android.native_*` switches in `/crm/api/app-version.php` — all OFF except receipts and plants** | forced ON (`ServerConfig.isTestTenant`) |

So a Mowology crew member on the released 1.6.0 APK sees the **WebView** of `my-schedule.php`
(the old web design). The Ittai screens exist in the 2.x build on `codex/native` and have never run
against mowology.ca on a device.

Per the design system the tenant colour is Mowology's own green (correct: `tenant` is generated per
tenant; `brand` indigo is for brand moments only). Nothing in the tokens needs to change.

## What "brand it" means in practice

1. **Switch the native screens on for the new APK** — set `native_crew_schedule`, `native_auth`,
   `native_field_tools`, `native_visit`, `native_timeclock`, `native_ai_office`, `native_schedule_plus`,
   `native_schedule_header`, `native_photo_gallery` (the keys the 2.x build reads; full list in
   `NativeCrewScheduleSwitch.kt` and siblings) to `true` in the live `app-version.json`.
   **Safe for today's crews:** the released 1.6.0 reads only `native_camera / native_day / native_plants /
   native_receipts / native_visit` (checked against `~/Projects/mowology-android`); unknown keys are
   ignored. `native_visit` is the one key both builds read — leave it `false` until Tim has tried the
   Finish sheet.
2. **Ittai brand moments on the Mowology flavor** — launcher icon and splash as the test flavor
   (`ic_ittai_launcher_foreground`, `ic_ittai_splash_icon`, `ittai_launcher_background`), copied
   into `app/src/mowology/res/`. **Decision for Tim:** keep the label "Mowology Crew" (tenant name,
   like iOS "Mowology") or rename to "Ittai". Recommendation: keep "Mowology Crew" — the icon says
   Ittai, the name says whose crew it is, and the crews already look for that name.
3. **Build a test APK of the Mowology flavor** from `codex/native` (`assembleMowologyRelease`,
   re-signed with the debug key the crews' installs trust — see memory
   `project_android_native_complete_phase0_test_tenant`), publish it as
   `/crm/downloads/mowology-crew-2.x-test.apk`, and **Tim tries it on his phone against mowology.ca**
   (a real crew login is needed; the emulator harness only covers the local tenant). Nothing is
   released to crews (`app-version.json` version/min_build untouched) until that trial.
4. Only then: release (bump version, copy to `mowology-crew.apk`), per the usual forced-update flow.

## Not done in this session

- Steps 1–3 need Tim's go (switch flip on production, icon/name call, a phone trial with his login).
- The emulator cannot sign in to mowology.ca with a made-up account, so the Mowology flavor was
  reviewed from the code and the live `app-version.json`, not run.
