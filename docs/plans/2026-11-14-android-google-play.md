# Android on Google Play (private testing first), plan 2026-11-14

Tim, 2026-11-14: "Google play, I want it figured out today." Goal: Claudia (and every Ittai owner after her) installs the Android app from the Play Store, no "install unknown apps" switch and no Samsung Auto Blocker. Sources: Android Developers "Target API level requirements" and Play Console Help "App testing requirements for new personal developer accounts" (answer 14151465).

## What Play requires (researched 2026-11-14)
- **New apps must target Android 16 (API 36)** (deadline 31 Aug 2026, already past). Our app targets API 34 (`variables.gradle`: compileSdk 34, targetSdk 34; Compose BOM 2024.02 and Kotlin 1.9.22 are pinned "because they build on compileSdk 34"). The upload is refused below the target. **This is the biggest piece of work.**
- **New personal developer accounts** (created after 13 Nov 2023) must run a closed test with 12 testers for 14 days before applying for production. **Internal testing** (up to 100 testers, no review) needs none of that, so it is enough for Claudia. An organisation account skips the 12-tester rule but needs a D-U-N-S number (up to 30 days).
- Account set-up needs identity verification (Tim's own ID, payment and phone). I cannot do this part.

## What in our app Play will not accept (found in `AndroidManifest.xml` / `build.gradle`)
- `REQUEST_INSTALL_PACKAGES` and `UPDATE_PACKAGES_WITHOUT_USER_ACTION` (the APK self-updater): not allowed in a Play app. The Play build removes the updater; Play updates it.
- `ACCESS_BACKGROUND_LOCATION` + the location foreground service (crew GPS tracking): a restricted permission needing a declaration form and video. Not needed by an Ittai owner; the Play build leaves it out.
- `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`: restricted, normally rejected. Left out of the Play build.
- `READ_CONTACTS`, `ACTIVITY_RECOGNITION`, camera, notifications: allowed with the privacy policy and the Data safety form filled in honestly.
- Flavour: the `ittai` flavour today uses a placeholder id (`ca.mowology.crew.test`). **The Play application id is permanent. Recommended: `ca.ittai.app`.** The Mowology crew app (`ca.mowology.crew`) stays on its APK pipeline.

## Plan
1. **Tim (only he can):** Play Console developer account (type, identity, fee); pick the application id; give Claudia's Google account email for the testers list.
2. **Codex (Android), in order:** (a) read-only audit: what blocks compileSdk/targetSdk 36 (AGP, Gradle, Kotlin, Compose BOM, Capacitor, CameraX, ML Kit, edge-to-edge and predictive-back behaviour); (b) the migration; (c) a `play` build type/flavour of Ittai with `ca.ittai.app`, no updater, no restricted permissions, `PLAY` flag in BuildConfig; (d) `bundlePlayRelease` producing an AAB signed with a NEW upload key kept in `~/.mowology-android-signing/` (never in git; Play App Signing holds the real key).
3. **Claude reviews every diff; emulator walk** of sign-up → onboarding → Receipts on the new target.
4. **Me, for Tim to paste:** Data safety answers, content rating answers, app description, short description, privacy-policy URL (`ittai.ca/privacy`), the test-account note for reviewers, a 512 px icon and screenshots from the emulator.
5. **Tim uploads the AAB to Internal testing** (or I do through the Play API if Tim creates a service account; his call), adds Claudia, sends her the opt-in link. Claudia taps it, installs from Play. Updates arrive through Play.
6. Later: closed test (12 testers, 14 days) only when we want a public Play listing.

## Today, realistically
Step 1 (account verification) decides the day: it can be instant or take days. Step 2(b) (API 36) is real work with unknowns; I will know within the first audit how big. Nothing is promised until the audit is done.

## Questions for Tim
1. Do you already have a Google Play Console account? Personal or organisation (D-U-N-S)?
2. OK with application id `ca.ittai.app` (permanent)?
3. Claudia's Google account email for the testers list.

## Decision 2026-11-14 (Tim: "Mowology is incorporated and will trade until the app can support a business")
Account type: **Organization**, registered to Mowology Lawns & Landscapes Ltd, i.e. Mowology's corporation, which publishes Ittai until Ittai can stand as its own business (later: transfer the app to an Ittai company's account). Needs the corporation's exact legal name, its D-U-N-S number (free lookup/application with Dun & Bradstreet; up to 30 days if it has to be created), a business website (mowology.ca / ittai.ca) and a business phone and address that match. Org accounts skip the 12-tester / 14-day rule. Realistic timing: the Play Console verification is days, not hours, so Claudia probably cannot install from Play today. **Interim for Claudia:** the guided sideload page + 60-second video (Samsung Auto Blocker steps) while the account is verified. Ask Tim: legal name, whether the D-U-N-S already exists, which Google account owns the console.

## Legal name (Tim, 2026-11-14): **Mowology Lawns & Landscapes Ltd** (confirm exact punctuation, e.g. "Ltd.", from the BC Registry / certificate of incorporation: the Play form, the D-U-N-S record and the registry must match character for character). A public search found no D-U-N-S number for it, so assume one has to be requested from Dun & Bradstreet (free, up to 30 days; the Play sign-up has its own D-U-N-S lookup link).

## Audit result 2026-11-14 (Codex): see `2026-11-14-android-api36-audit.md`
Not a version bump: Capacitor 6 to 8 (JDK 21, AGP 8.13, Gradle 8.14.3, Kotlin 2.2.20 with the Compose compiler plugin, minSdk 24), then edge-to-edge on every activity and the web shell, predictive back (`MwCameraActivity` and `DiagnosticsActivity` still use `onBackPressed`), large-screen orientation, foreground-service tests, 16 KB native-library alignment (unknown until a bundle is built). The Play-only `ittai` build drops `REQUEST_INSTALL_PACKAGES`, `UPDATE_PACKAGES_WITHOUT_USER_ACTION`, the updater classes, `ACCESS_BACKGROUND_LOCATION`, the tracking service, boot and geofence receivers and the battery-optimisation request, behind a new `IS_PLAY_DISTRIBUTION` flag and a manifest overlay. Order: Play split (L), native-library audit (M), toolchain (L), edge-to-edge (L), back (S/M), large screens (L), service tests (M/L), final AAB checks (M). Estimate one to two days of Codex work plus review and slow emulator testing. Interim for Claudia: guided sideload page and video.
