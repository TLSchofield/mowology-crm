# Android API 36 / Google Play audit (Codex, read-only, 2026-11-14)

Source: Codex audit of the Android project snapshot (code only). Verify against current docs before acting; versions are Codex's reading of the Capacitor 8 / AGP tables.

## Audit result

This is not safely solved by changing only `compileSdkVersion` and `targetSdkVersion`. The supported API-36 route is a coordinated Capacitor 8/toolchain migration, followed by edge-to-edge, back-navigation, large-screen, Play-policy, and native-library verification.

The supplied audit snapshot does not include `gradle-wrapper.properties`, `package.json`, lockfiles, merged manifests, or a built APK/AAB. Therefore the current Gradle and Capacitor versions—and actual packaged `.so` files—cannot be proven from this snapshot.

## 1. Version migration

Current evidence:

- API levels and Kotlin: `variables.gradle:2-5`.
- AGP/Kotlin plugins: `build.gradle:10-12`.
- Compose compiler/BOM: `app/build.gradle:153-155,223-230`.
- CameraX/ML Kit/location: `app/build.gradle:185,205-217`.

### Supported API-36 stack, in change order

| Order | Component | Current | First supported choice for this combined migration |
|---|---|---:|---:|
| 1 | JDK | Not shown | **21** for Capacitor 8 |
| 2 | Capacitor Android/CLI/plugins | Not shown; project structure appears Capacitor 6-era | **8.0.0** |
| 3 | Android Gradle Plugin | 8.2.1 | **8.13.0** for Capacitor 8 |
| 4 | Gradle wrapper | Not supplied | **8.14.3** for Capacitor 8 |
| 5 | Kotlin plugin/stdlib | 1.9.22 | **2.2.20** |
| 6 | Compose compiler | 1.5.10 | Replace with `org.jetbrains.kotlin.plugin.compose` **2.2.20** |
| 7 | SDK levels | min 23 / compile 34 / target 34 | **min 24 / compile 36 / target 36** |
| 8 | AndroidX Activity | variables 1.8.0; direct Compose 1.8.2 | **1.11.0**, consistently |
| 9 | AppCompat | 1.6.1 | **1.7.1** |
| 10 | CoordinatorLayout | 1.2.0 | **1.3.0** |
| 11 | Core | 1.12.0 | **1.17.0** |
| 12 | Fragment | 1.6.2 | **1.8.9** |
| 13 | Core Splashscreen | 1.0.1 | **1.2.0** |
| 14 | WebKit | 1.9.0 | **1.14.0** |
| 15 | AndroidX test JUnit/Espresso | 1.1.5 / 3.5.1 | **1.3.0 / 3.7.0** |
| 16 | Cordova Android | 10.1.1 | **14.0.1** |
| 17 | Google Services Gradle plugin | 4.4.0 | **4.4.4** |

These are Capacitor 8’s documented API-36 values. The Android platform’s bare minimum is only AGP **8.9.1** with Gradle **8.11.1**, but that leaves the Capacitor shell on an unsupported target: Capacitor 6 supports target 34, 7 supports 35, and 8 supports 36. [Android AGP compatibility](https://developer.android.com/build/releases/about-agp), [Capacitor target-SDK matrix](https://next.capacitorjs.com/docs/next/android/setting-target-sdk), [Capacitor 8 migration](https://capacitorjs.com/docs/updating/8-0).

### Compose compatibility

The known hard incompatibility is:

- Kotlin **2.2.20** cannot use Compose compiler extension **1.5.10**, which was specifically paired with Kotlin 1.9.22 at `app/build.gradle:102,153-155`.
- Remove `kotlinCompilerExtensionVersion '1.5.10'`; apply the Kotlin Compose plugin at the same version as Kotlin, **2.2.20**.
- Compose BOM **2024.02.00** is not itself tied to `compileSdk 34` and does not strictly have to change merely to compile API 36. Its comment at `app/build.gradle:223` overstates the constraint. Upgrade it separately after establishing a green Kotlin/Capacitor build; no meaningful “first API-36-compatible BOM” exists because BOM compatibility is not keyed to target SDK.
- Do not mix the current direct `activity-compose:1.8.2` at `app/build.gradle:229` with Capacitor 8’s `androidxActivityVersion=1.11.0`.

For Kotlin before 2.0, compiler compatibility is separate from the BOM; from Kotlin 2.0 onward the Compose plugin follows Kotlin’s version. [Compose BOM/compiler guidance](https://developer.android.com/develop/ui/compose/bom).

### Libraries that do not inherently require an API-36 bump

These existing versions are not known to prevent compilation against API 36:

- CameraX **1.3.4** (`app/build.gradle:207-210`)
- ExifInterface **1.3.7** (`:211`)
- ML Kit text recognition **16.0.1** (`:216`)
- Play-services ML Kit document scanner **16.0.0** (`:217`)
- Play-services location **21.1.0** (`:185`)
- Room 2.6.1, WorkManager 2.9.0, Lifecycle 2.7.0 (`:180,196-200,230-232`)

They should be updated only after the toolchain build is green, unless a built-AAB 16-KB audit identifies one as the source of an incompatible native library. “Latest” is not the same as “first compatible.”

## 2. Target 35/36 behavior impact

### Edge-to-edge: definite, high-risk

Target 35 enforces edge-to-edge; target 36 removes the Android-16 opt-out. System-bar color setters and `setDecorFitsSystemWindows` are deprecated/disabled in relevant modes. [Android 15 details](https://developer.android.com/about/versions/15/behavior-changes-15), [Android 16 details](https://developer.android.com/about/versions/16/behavior-changes-16).

Affected activities:

- `MainActivity`, plain `Activity`: `MainActivity.kt:34`
- Capacitor `WebShellActivity`: `WebShellActivity.java:33`
- `DiagnosticsActivity`: `launch/DiagnosticsActivity.kt:28`
- Compose activities:
  - `auth/SignInActivity.kt:26,87`
  - `day/NativeDayActivity.kt:27,151`
  - `camera/MwCameraActivity.kt:103,261`
  - `update/UpdateActivity.kt:45,52`

None calls `enableEdgeToEdge()`.

Concrete hazards:

- `SignInActivity` manually writes transparent bar colors: `auth/SignInActivity.kt:57-60`.
- `WelcomeScreen` toggles `setDecorFitsSystemWindows(false)` and later restores `true`, plus reads/writes deprecated bar colors: `auth/WelcomeScreen.kt:92-127`. Restoring `true` will not restore the old target-34 layout on Android 15/16.
- Custom meniscus bar correctly reads navigation insets at `ui/ittai/ItMeniscusBar.kt:297,693`, but also relies on changing `navigationBarColor` at `:506-514`; that color is ineffective under gesture navigation.
- Camera applies top/bottom padding at `camera/MwCameraActivity.kt:933,1002`, which is promising, but the full screen still needs cutout/landscape/tablet testing.
- Some screens already use `safeDrawingPadding`, `statusBarsPadding`, or `navigationBarsPadding`; that does not establish that every root/container is safe.
- The Capacitor WebView shell needs Capacitor 8’s System Bars/CSS `env(safe-area-inset-*)` migration. Capacitor 8 removed its old automatic edge-to-edge margin adjustment.

Test all low-traffic screens, dialogs, IME states, 3-button navigation, gesture navigation, and display cutouts. The meniscus/bar geometry is the most likely custom-UI regression.

### Predictive back: one definite failure

At target 36 on Android 16, legacy `Activity.onBackPressed()` is not invoked unless the app temporarily opts out.

- Definite break: `MwCameraActivity.onBackPressed()` at `camera/MwCameraActivity.kt:328-331`.
- Also legacy: `launch/DiagnosticsActivity.kt:49-51`.
- Already on supported dispatch:
  - `auth/SignInActivity.kt:72-75`
  - `day/NativeDayActivity.kt:143-146`
  - `update/UpdateActivity.kt:49`
  - Compose `BackHandler` usages throughout screens.

Do not set `android:enableOnBackInvokedCallback="false"` as the final fix. Replace the two overrides with dispatcher callbacks and test ordering, especially the explicitly order-dependent callback at `NativeDayActivity.kt:143-145`. [Predictive-back migration](https://developer.android.com/guide/navigation/custom-back/predictive-back-gesture).

### Location foreground service and boot

The base declaration is correct for modern Android:

- Permissions: `AndroidManifest.xml:11-12`
- `foregroundServiceType="location"`: `AndroidManifest.xml:150-154`
- Typed `startForeground`: `tracking/MwTrackingService.kt:828-833`

Risk remains in background starts:

- Boot receiver starts tracking: `tracking/BootReceiver.kt:22-34`.
- Geofence receiver starts an FGS: `tracking/GeofenceReceiver.kt:43-51`.
- Other resumptions call the same start path, including `day/NativeDayActivity.kt:210-211` and `tracking/SyncScheduler.kt:55-59`.

Android 15’s new boot restriction does not list the `location` type, so this is not automatically prohibited by the new target-35 list. However, location FGS startup still depends on background-start exemptions and while-in-use/background location permission; the existing comment at `BootReceiver.kt:31-32` correctly acknowledges refusal. Treat reboot recovery as non-guaranteed and test both `BOOT_COMPLETED` and `MY_PACKAGE_REPLACED`.

### Exact alarms

No `AlarmManager`, `setExact*`, `SCHEDULE_EXACT_ALARM`, or `USE_EXACT_ALARM` usage exists in the supplied code. No migration is indicated.

### Broadcast receivers

Manifest receivers are explicit and have `exported` values:

- Self-update: `AndroidManifest.xml:129-131`
- Prompt actions: `:133-136`
- Geofence: `:156-159`
- Boot/update: `:161-169`
- Activity transitions: `:180-183`

No dynamic `registerReceiver` call appears in supplied source. Pending intents generally specify mutability. Background activity-launch restrictions could affect the updater confirmation started at `update/SelfUpdater.kt:341-348`, so test installer-return behavior.

### Notification permission

The declaration and native request flow exist:

- Manifest: `AndroidManifest.xml:13`
- Native request: `tracking/TrackingSetupScreen.kt:137-140`
- Capacitor alias/status: `tracking/MwTrackingPlugin.kt:33-37,108-111,295-297`

Risk: the location FGS can start without notification permission, but its notification will be suppressed from the drawer. Ensure tracking setup does not claim the app is fully ready merely because the service started.

### `READ_CONTACTS` and activity recognition

- `READ_CONTACTS` is declared at `AndroidManifest.xml:33` and requested through Activity Result APIs at `onboarding/PhoneContactsCard.kt:31-36`; the denial path goes to Settings at `:47-51`. This is structurally correct.
- Contact reads occur only behind that flow: `contacts/PhoneContactsReader.kt:24-67`.
- Activity recognition is declared at `AndroidManifest.xml:26-27`, exposed as a Capacitor permission at `tracking/MwTrackingPlugin.kt:33-37`, and requested at `:258-259`.
- Play Services registration catches `SecurityException`: `tracking/ActivityHelper.kt:61-68`.

No target-35/36 permission-model change specifically breaks these flows, but both need deny/permanent-deny tests.

### 16-KB page sizes: unknown from supplied files

AGP 8.13 packaging is sufficient, but every packaged native library must also have 16-KB-aligned ELF segments. Android’s guidance requires AGP 8.5.1+ plus compatible prebuilts and verification of the final APK/AAB. [16-KB guidance](https://developer.android.com/guide/practices/page-sizes).

I cannot tell whether ML Kit, CameraX, Capacitor/community plugins, or transitive dependencies package `.so` files because:

- no APK/AAB is supplied;
- no Gradle dependency report is supplied;
- `app/libs` and Capacitor plugin library contents are absent.

CameraX itself is predominantly Java/Kotlin, but camera/document-scanner/ML dependencies may introduce native transitively. Build the Play AAB, list every `.so`, run APK Analyzer/`check_elf_alignment.sh`, and verify the AAB reports `PAGE_ALIGNMENT_16K`. Android 16 compatibility mode is a temporary fallback, not proof of compliance.

### Large screens and orientation

`MwCameraActivity` is fixed portrait at `AndroidManifest.xml:95-99`. On target 36, Android 16 ignores that restriction on displays `sw600dp` or larger. The camera preview, capture rotation, controls, and meniscus/status-bar layout therefore need tablet, foldable, split-screen, and landscape testing.

No explicit `resizeableActivity` setting exists. The app also handles configuration changes itself on several activities (`AndroidManifest.xml:75-81,101-127`), increasing the need to check stale geometry and state after resize. The temporary compatibility property can defer this on API 36, but it stops working with API 37.

### Deprecated/disabled APIs

No source API is removed from the API-36 SDK such that these files necessarily stop compiling. Runtime semantics are the issue:

- `Window.statusBarColor`, `navigationBarColor`, and `setDecorFitsSystemWindows`: deprecated/partly disabled under edge-to-edge.
- `Activity.onBackPressed()`: still present but no longer dispatched at target 36 on Android 16.
- Orientation restrictions: ignored on large screens.
- `elegantTextHeight` is disabled at target 36; relevant only if layouts support affected scripts.
- Android 16 also deprecates disruptive accessibility announcements; no `announceForAccessibility` usage was found.

## 3. Play-only `ittai` build

The current flavor split provides `IS_ITTAI` but not a Play-policy flag: `app/build.gradle:70-100`. `tenanttest` also sets `IS_ITTAI=true`, so that flag cannot distinguish Play from non-Play distribution.

Add a separate compile-time value such as `IS_PLAY_DISTRIBUTION`, and preferably use an `src/ittaiPlay/AndroidManifest.xml` overlay with `tools:node="remove"`.

### Remove from the Play manifest

- `REQUEST_INSTALL_PACKAGES`: `AndroidManifest.xml:24`
- `UPDATE_PACKAGES_WITHOUT_USER_ACTION`: `:25`
- `ACCESS_BACKGROUND_LOCATION`: `:10`
- `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`: `:15-19`
- `MwTrackingService`: `:148-154`, if the Play flavor must have no background tracking service
- `BootReceiver`: `:161-169`
- `GeofenceReceiver`: `:156-159`, if geofence-triggered background tracking is removed
- `ActivityTransitionReceiver`: `:180-183`, if used only by background tracking
- Community background geolocation service merge: `:171-178`
- `SelfUpdateReceiver`: `:129-131`
- `UpdateActivity`: `:120-127`

`FOREGROUND_SERVICE` and `FOREGROUND_SERVICE_LOCATION` at `:11-12` can also be removed if no Play-only feature retains a location FGS.

### Gate or exclude updater paths

- `update/SelfUpdater.kt` — download, validation, `PackageInstaller`, silent-update request: especially `:78-84,307-355`
- `update/SelfUpdateReceiver.kt:10-27`
- `update/SelfUpdateGate.kt:22-65`
- `update/UpdateActivity.kt:45-75`
- `update/UpdateScreen.kt`
- `update/SelfUpdateLogic.kt`
- Startup hooks:
  - `MwApp.kt:33`
  - `MainActivity.kt:73-77`
- Account UI: `auth/AccountScreen.kt:207-224`

Play explicitly prohibits using `REQUEST_INSTALL_PACKAGES` for self-updates except qualifying device-management cases. [Google Play policy](https://support.google.com/googleplay/android-developer/answer/18265346).

For Play builds, update availability should lead to the Play listing or Play in-app updates, not `PackageInstaller`.

### Gate or exclude background tracking/battery paths

Core classes and entry points:

- `tracking/MwTrackingService.kt`
- `tracking/BootReceiver.kt`
- `tracking/GeofenceReceiver.kt`
- `tracking/ActivityHelper.kt`
- `tracking/MwTrackingPlugin.kt`
  - permission aliases: `:33-37`
  - background checks: `:295-306`
  - battery-exemption action: `:308-310`
- `tracking/BatteryHelper.kt`, especially direct exemption request at `:31-35`
- `tracking/TrackingSetupScreen.kt`
  - notification flow: `:137-140`
  - unrestricted-battery UI: `:147-150`
  - background-location checks: `:226-229`
- `tracking/TrackingApi.kt`
- `tracking/SyncScheduler.kt:55-59`
- `day/NativeDayActivity.kt:210-211`
- Any WebView/Capacitor calls into `MwTrackingPlugin`

The tracking database/queue can remain if it supports foreground-only location, but the snapshot does not establish whether it is independently useful.

Background location is not categorically forbidden by Play, but it requires approved core functionality, prominent disclosure, and declaration. Since the requested Play flavor must drop it, remove both permission and reachable code—not merely hide the setup button. [Background-location policy](https://support.google.com/googleplay/android-developer/answer/9799150).

## 4. Migration order and size

1. **Split `ittai` Play policy surface — L, highest risk.** Add a genuine Play-distribution flag/source set; remove privileged manifest entries and ensure updater/tracking code cannot be reached. Verify merged manifest and Play policy declarations.

2. **Build and audit the current release AAB for native libraries — M.** Record every `.so` and its owner before dependency changes; verify ELF and zip alignment. Exact size is unknowable without the complete dependency tree/artifact.

3. **Upgrade Capacitor and build toolchain — L.** Capacitor 8, JDK 21, AGP 8.13, Gradle 8.14.3, Kotlin/Compose plugin 2.2.20, minSdk 24, API 36, Capacitor AndroidX floors. Keep application libraries otherwise stable until all flavors compile.

4. **Migrate edge-to-edge across every activity and WebView route — L.** Establish one activity/window policy, remove bar-color/restoration assumptions, audit every Compose root and custom meniscus geometry, and add WebView CSS safe-area handling.

5. **Migrate legacy back handling — S/M.** Replace the two `onBackPressed()` overrides; test dispatcher/Compose callback precedence and updater blocking behavior.

6. **Large-screen/adaptive-layout pass — L.** Camera landscape/tablet behavior is riskiest; then meniscus geometry, sign-in/onboarding, WebView, dialogs, resize/configuration handling.

7. **Foreground-service and receiver testing — M/L.** Test user-start, geofence, reboot, package replacement, revoked background location, denied notification permission, and force-stop. The correct replacement for rejected reboot starts cannot be determined solely from this code.

8. **Upgrade optional runtime libraries in small groups — M.** CameraX/Exif, then ML Kit/document scanner, then Play-services location, then Room/WorkManager/Lifecycle/Compose BOM. Dependency-upgrade necessity beyond 16-KB results cannot be established from this snapshot.

9. **Final Play AAB validation — M.** Inspect the merged Play manifest, dependency report, 16-KB alignment, permission declarations, API-35/36 compatibility toggles, and device matrix: API 34/35/36; gesture/3-button navigation; phone/tablet/foldable; portrait/landscape; notification and location denial states.