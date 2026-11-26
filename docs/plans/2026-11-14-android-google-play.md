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

## Update 2026-11-26 (Tim: "fully native and ready for playstore")
Nothing in the 2026-11-14 plan was built yet; targetSdk is still 34 and the Play build does not exist. What changed since: the parity audit (`parity-audit-2026-11-26/AUDIT.md`) shows the app is already all native Compose except the Capacitor fallback shell, so "fully native" for the Play build can mean **no Capacitor and no WebView at all**.

**Why that matters:** the API 36 audit's biggest item is Capacitor 6 to 8 (JDK 21, AGP 8.13, Gradle 8.14.3, Kotlin 2.2.20). If the Play (`ittai`) flavour carries no Capacitor, that migration shrinks to AGP/Kotlin/Compose/CameraX/ML Kit and Play services only, and the Mowology APK line can stay on Capacitor 6 until we choose to move it.

**Order (each step is its own Codex build, Claude reviews every diff, tests green before the next):**
1. **Split the shell out.** Move `WebShellActivity`, `WebErrorScreen`, `MwCameraPlugin`, `MwTrackingPlugin` and every Capacitor import into a `webshell` source set used only by the `mowology` and `tenanttest` flavours. The `ittai` flavour compiles with no Capacitor dependency. Every screen that today falls back to `openWeb(...)` when a switch is off gets a native answer or is removed in the Play build (list in `parity-audit-2026-11-26/android-inventory.md`, "WebView uses").
2. **Play flavour = all native, switches fixed.** `IS_PLAY_DISTRIBUTION` flag: the nine dark features are decided one by one (on, or not in this build), the 19 hand-rolled switch objects collapse into one `FeatureSwitch` shape (the refactor), no remote switch can turn a native screen off into a web page.
3. **Play-only manifest.** Remove `REQUEST_INSTALL_PACKAGES`, `UPDATE_PACKAGES_WITHOUT_USER_ACTION`, the self-updater classes, `ACCESS_BACKGROUND_LOCATION`, the tracking service and its boot/geofence receivers, `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` (as already planned). Keep camera, notifications, contacts (declared).
4. **Toolchain to target API 36**, then edge-to-edge, predictive back, large screens, foreground-service tests, 16 KB native-library alignment (check on the first built bundle).
5. **Account deletion** (Play requires it for an app that lets people create an account): an in-app "Delete my account" path plus the web page (`privacy-request.php` exists on the server; the in-app route is NOT built; no deletion code was found in the Android app). Offline: unavailable with no signal, says so.
6. **Offline hardening for the Play build:** the P0 items from the audit are in (sign-out wipe, queued Add job); add the remaining audit items and a queue-drain test per queue.
7. **AAB + Play checks:** release bundle signed with an upload key, `bundletool` size and permission check, pre-launch report, Data safety answers re-checked against the merged manifest (`2026-11-14-play-store-answers.md`).

**Existing sideloaded installs:** Play re-signs with its own key, so a phone that has the APK installed cannot update to the Play build (signature mismatch). The Mowology crew phones stay on the APK line; Ittai owners (Claudia) install fresh from Play. No migration is attempted for installs that hold unsent queue rows.

**Tim only (nothing I can do):** the Play Console organisation account for Mowology Lawns & Landscapes Ltd and its D-U-N-S number (verification takes days), the permanent application id (`ca.ittai.app` recommended), the testers' Google emails, and a demo business login for Google's reviewers.
**Not promised:** a Play release date. The account verification and the toolchain step decide it.
