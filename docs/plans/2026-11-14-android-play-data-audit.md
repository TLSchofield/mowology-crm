# Android Play data and permissions audit (Codex, read-only, 2026-11-14)

Source for the Play Data safety form and the Play-only build. Code only; server-side behaviour not proven.

## Audit scope and build caveat

This repository snapshot contains the common manifest and Gradle file, but not the proposed Play-specific manifest/source-set overlays. I therefore treated the user-described removals as the intended Play-build specification, while auditing everything else that remains.

The current common manifest still declares the updater, background location, tracking service, receivers, and battery exemption. A merged Play manifest or built AAB is needed to prove they are actually absent. Evidence: [AndroidManifest.xml:8](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/src/main/AndroidManifest.xml:8>), [AndroidManifest.xml:120](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/src/main/AndroidManifest.xml:120>), [AndroidManifest.xml:148](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/src/main/AndroidManifest.xml:148>).

The Ittai server host is build-configurable. The source default is `rehearsal2.ittai.ca`, but the shipped value may be overridden with `-PittaiHost`; do not put the default into the declaration without checking the release build. [build.gradle:45](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:45>), [build.gradle:90](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:90>).

All first-party native networking is constructed as `https://<IttaiHost>`; API-relative paths use `https://<IttaiHost>/api/...`. [ServerConfig.kt:14](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/config/ServerConfig.kt:14>), [ServerConfig.kt:17](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/config/ServerConfig.kt:17>).

## 1. Data safety inventory

“Optional” below means the person can avoid the feature and still use other parts of the app. Login/account identifiers are required for authenticated use.

| Google category | What remains and where it is sent | Optional | Sharing assessment |
|---|---|---:|---|
| Personal info — name, email, phone | Account creation sends first/last/full name, mobile, email and password to `/api/auth/signup.php`, then email/code/profile fields to `/api/auth/signup-confirm.php`. Login sends email/username and password to `/api/auth/token.php`. [SignUpLogic.kt:43](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/auth/SignUpLogic.kt:43>), [SignUpViewModel.kt:26](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/auth/SignUpViewModel.kt:26>), [NativeAuthSession.kt:27](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/auth/NativeAuthSession.kt:27>) | Account data: no. Client records: optional | First-party Ittai tenant/business server. No client-side sale or unrelated third-party sharing shown. Password is authentication data, even though Google’s displayed category may be “other personal info.” |
| Personal info — address | New-client workflows send property address, city and postal code to `/api/schedule/...` endpoints. [FieldToolsLogic.kt:293](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/day/FieldToolsLogic.kt:293>) | Yes | First-party/business service processing |
| User IDs | Authenticated requests carry a bearer token representing the user; many records include user, visit, client, property, invoice, expense or request IDs. The auth response explicitly contains `user{id,email,name,role}`. [NativeAuthLogic.kt:9](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/auth/NativeAuthLogic.kt:9>), [TrackingApi.kt:223](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/tracking/TrackingApi.kt:223>) | No for authenticated use | First-party/business service processing |
| Financial info | Receipt images and parsed expense data—including vendor, date, subtotal, GST/PST, total, category and payment method—go to `/api/expenses/receipt-upload`, `/expense-save`, `/expense-update`, `/receipt-actions`, `/expense-line-items`, etc. [ReceiptIntakeClient.kt:132](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/receipts/ReceiptIntakeClient.kt:132>), [ReceiptIntakeModels.kt:140](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/receipts/ReceiptIntakeModels.kt:140>), [ReviewLogic.kt:21](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/receipts/ReviewLogic.kt:21>). Invoice and quote actions go to `/api/invoices/invoices-mobile` and `/api/quotes/quote-mobile`. [OfficeDocumentsViewModel.kt:99](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/office/OfficeDocumentsViewModel.kt:99>) | Yes, feature-dependent | First-party/business service processing |
| Precise and approximate location | After background tracking is removed, location still remains in foreground features: current/nearby property search, creating a client at the current location, geocoding an address, GPS-stamping job/receipt/plant/label photos, and plant identification payloads. [SchedulePlusViewModel.kt:537](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/day/SchedulePlusViewModel.kt:537>), [FieldToolsViewModel.kt:742](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/day/FieldToolsViewModel.kt:742>), [MwCameraActivity.kt:858](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/camera/MwCameraActivity.kt:858>), [PlantWorker.kt:110](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/plants/PlantWorker.kt:110>). Destinations include relevant `/api/schedule/...` operations and multipart photo endpoints such as `/api/schedule/job-photo`; location can also be embedded in uploaded photo EXIF. | Yes; code checks permission and omits location if unavailable | First-party/business service. Google Play Services location is an SDK/service provider involved in obtaining fixes. |
| Contacts | The app reads the address book locally and uploads only contacts explicitly selected by the person. Uploaded fields include name, company, emails, phone numbers and postal address, sent to `/api/onboarding/step.php`. [PhoneContactsCard.kt:43](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/onboarding/PhoneContactsCard.kt:43>), [PhoneContactsLogic.kt:47](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/onboarding/PhoneContactsLogic.kt:47>), [PhoneContactsViewModel.kt:161](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/onboarding/PhoneContactsViewModel.kt:161>) | Yes: “Not now,” individual selection | First-party/business service processing |
| Photos and videos | Receipt photos, job-site before/after/additional photos, plant photos, labels and equipment/plate photos are uploaded. Examples: `/api/expenses/receipt-upload`, `/api/expenses/receipt-capture`, `/api/schedule/job-photo`, `/api/expenses/label-upload`, `/api/team/equipment-mobile`. [ReceiptUploader.kt:19](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/receipts/ReceiptUploader.kt:19>), [PhotoUploader.kt:24](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/camera/PhotoUploader.kt:24>), [ReviewLogic.kt:39](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/receipts/ReviewLogic.kt:39>), [EquipmentViewModel.kt:156](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/office/EquipmentViewModel.kt:156>) | Yes | First-party/business service. ML Kit text recognition is described as bundled/on-device; the Play Services document scanner supplies local scanning UI. [build.gradle:213](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:213>) |
| Files and documents | The native code creates/sends invoices and quotes and handles office documents, but I found no general file-picker upload in the supplied sources. Receipt and equipment images should be declared as photos, not additionally as files merely because they are files internally. PDFs/documents may be returned or emailed by the server. | Feature-dependent | Declare “Files and docs” only if the WebView/site or omitted Capacitor plugin code lets users upload documents. The supplied snapshot cannot settle that. |
| App activity | Work schedule queries, clock-in/out and breaks, timers, visit completion/skips, checklist answers, notes, special-request outcomes, quiz answers/results, trip reports, office decisions and search terms are sent to the tenant API. Representative evidence: `/api/schedule/clock`, `/timer`, `/visit-work` [DayViewModel.kt:286](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/day/DayViewModel.kt:286>), [DayViewModel.kt:334](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/day/DayViewModel.kt:334>), [DayViewModel.kt:579](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/day/DayViewModel.kt:579>); quiz endpoints [QuizLogic.kt:42](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/quiz/QuizLogic.kt:42>). | Core activity is not optional once the associated feature is used | First-party/business service processing |
| Web browsing | The app contains a WebView shell that loads the configured business website. This exposes normal page navigation and server-side web logs to the same configured host. [AndroidManifest.xml:73](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/src/main/AndroidManifest.xml:73>), [AccountScreen.kt:230](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/auth/AccountScreen.kt:230>) | The shell is a fallback, but available | Same first-party host. The web application’s JavaScript and server behavior were not included, so its cookies, telemetry and external embeds cannot be audited here. |
| Crash logs and diagnostics | Crashes, stack traces, device manufacturer/model, app build, thread and breadcrumbs are captured locally in `filesDir` and displayed after restart. The code says it writes a local file and marks it seen; I found no native upload endpoint or crash SDK. [CrashReport.kt:12](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/launch/CrashReport.kt:12>) | N/A | On the supplied code, not collected off-device and therefore not a Data safety collection. Server HTTP logs may still contain ordinary diagnostics; server code is out of scope. |
| Device or other IDs | FCM may generate and transmit an installation/push token if Firebase is correctly configured. Firebase Messaging is a dependency, while Ittai’s Google Services processing is deliberately disabled in the shown Gradle configuration because the package has no Firebase client. [build.gradle:187](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:187>), [build.gradle:146](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:146>) | Notifications are optional | If FCM is enabled for the actual Play package, Google/Firebase is a service provider and “Device or other IDs” should be declared. No direct `ANDROID_ID`, IMEI or advertising-ID access appears in the supplied code. |

All first-party entries above are encrypted in transit by construction. The repository cannot prove server-side retention, deletion behavior, onward disclosures, or whether the business/server engages outside processors.

## 2. Remaining permissions

### `READ_CONTACTS`

Used only for the optional onboarding/client import. It is requested when the contacts card is at the permission stage, after an in-app disclosure and before the native permission dialog. [PhoneContactsCard.kt:31](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/onboarding/PhoneContactsCard.kt:31>).

The disclosure is unusually clear: only selected contacts leave the phone, and no marketing consent is inferred. [PhoneContactsCard.kt:43](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/onboarding/PhoneContactsCard.kt:43>). There is a “Not now” path and a per-contact picker. This is a good prominent-disclosure pattern.

### Fine/coarse location

Location remains useful even after background tracking is removed:

- “My location”/nearby property search.
- New-client-at-current-location and address geocoding.
- GPS metadata in job/receipt/plant/label photos.
- Plant-identification location fields.

The remaining native code generally checks permission and silently omits location if unavailable. [MwCameraActivity.kt:858](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/camera/MwCameraActivity.kt:858>).

However, the only concrete native location request and notification request I found are in `TrackingSetupScreen`, which the Play build is supposed to remove. The day screen explicitly says the location notice and permission prompts live in the web app. [DayViewModel.kt:269](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/day/DayViewModel.kt:269>). The website’s disclosure was not supplied, so I cannot verify it.

For Play, add/verify a native just-in-time disclosure and request when the person taps “My location,” “New client at this location,” or enables photo geotagging. Explain that the precise position may be sent with the search/client/photo and that the photo may contain GPS metadata. Do not request background location.

### `CAMERA`

Used for receipts, before/after job photos, plant identification, labels and equipment/plate capture. It is requested inside the camera activity immediately when that user-invoked activity opens. [MwCameraActivity.kt:182](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/camera/MwCameraActivity.kt:182>), [MwCameraActivity.kt:254](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/camera/MwCameraActivity.kt:254>). This is appropriately tied to use.

### `POST_NOTIFICATIONS`

Potential remaining uses are FCM push and local arrival/action notifications. If the geofence/tracking path is fully removed, arrival notifications may no longer be reachable, but FCM remains in the dependency set.

The only native request found is in the tracking setup screen. [TrackingSetupScreen.kt:139](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/tracking/TrackingSetupScreen.kt:139>). Once that screen is removed, the Play build has no demonstrated just-in-time request. Add one when enabling notifications, or remove the permission and FCM integration if Ittai push remains unconfigured.

### `ACTIVITY_RECOGNITION`

Its only identified implementation is the tracking subsystem: Play Services location/activity dependency and `ActivityTransitionReceiver`. [build.gradle:183](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:183>), [AndroidManifest.xml:180](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/src/main/AndroidManifest.xml:180>).

It can and should be dropped from the Play build, including both:

- `android.permission.ACTIVITY_RECOGNITION`
- `com.google.android.gms.permission.ACTIVITY_RECOGNITION`

If Play Services Location remains for foreground location, remove only the permission/receiver/activity-recognition code—not necessarily the entire location dependency.

## 3. Advertising ID, ads and tracking SDKs

I found:

- No `com.google.android.gms.permission.AD_ID` declaration.
- No advertising-ID API use.
- No ad SDK.
- No analytics SDK.
- No Crashlytics, Sentry, Bugsnag, Amplitude, Mixpanel or similar crash/analytics dependency.
- No client-side marketing/tracking SDK.

The notable Google dependencies are Play Services Location, Firebase Messaging, Credential Manager’s Play Services bridge, bundled ML Kit text recognition and the Play Services ML Kit document scanner. [build.gradle:183](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:183>), [build.gradle:187](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:187>), [build.gradle:213](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:213>), [build.gradle:234](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:234>).

A dependency declaration alone cannot prove the final merged manifest lacks `AD_ID`. Before submission, inspect the release merged manifest or AAB. Based on the supplied dependency list, there is no obvious dependency that needs advertising ID.

## 4. Account creation and deletion

### Present

- Native “Create business” flow.
- Signup endpoint: `/api/auth/signup.php`.
- Confirmation endpoint: `/api/auth/signup-confirm.php`.
- Login endpoint: `/api/auth/token.php`.
- Terms and privacy links: `https://ittai.ca/terms/` and `https://ittai.ca/privacy/`. [SignUpLogic.kt:11](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/auth/SignUpLogic.kt:11>).
- Account screen with profile, appearance, app/update information and sign-out. [AccountScreen.kt:64](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/auth/AccountScreen.kt:64>), [AccountScreen.kt:119](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/auth/AccountScreen.kt:119>).

### Missing

I found no:

- In-app “Delete account” or “Request account deletion” action.
- Account deletion confirmation screen.
- Account deletion endpoint.
- Link to a dedicated external account-deletion request page.
- Code explaining what business/account data is deleted versus retained.

The many `delete` methods found are local queue/database cleanup or deletion of business objects such as expenses, not deletion of the person’s account.

This does not meet the Play requirement for an app that supports account creation. Both are needed:

1. An in-app deletion initiation path, normally under Account.
2. A publicly reachable web deletion page entered in Play Console.

Because business accounts may contain legally retained bookkeeping records, the flow should distinguish deleting login/personal data from records retained for tax/legal reasons and state the retention period. The repository cannot establish that policy.

## 5. Other Play-review risks

### Self-update: must be removed completely

The common manifest requests `REQUEST_INSTALL_PACKAGES` and `UPDATE_PACKAGES_WITHOUT_USER_ACTION`, registers update components, and the Account screen exposes “Update now” and “Allow updates.” [AndroidManifest.xml:21](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/src/main/AndroidManifest.xml:21>), [AccountScreen.kt:208](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/auth/AccountScreen.kt:208>).

For the Play build, remove:

- Both install/update permissions.
- `UpdateActivity` and `SelfUpdateReceiver`.
- APK download/install code.
- Account-screen update button and unknown-sources link.
- Any APK download interception in the WebView.

Merely making the updater unreachable is weaker than excluding it from the Play artifact.

### WebView shell

A Capacitor WebView shell remains, configured at build time to load `https://<IttaiHost>`. [build.gradle:129](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/build.gradle:129>), [AndroidManifest.xml:73](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/files/Users/timschofield/Projects/mowology-android-worktrees/receipts-explainer/android/app/src/main/AndroidManifest.xml:73>).

This is not automatically prohibited, but reviewers may scrutinize whether the app is primarily a thin website wrapper. Here there is substantial native functionality, which helps. The shell also has a JavaScript interface and calls `loadUrl`. [WebShellActivity.java:114](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/WebShellActivity.java:114>), [WebShellActivity.java:177](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/WebShellActivity.java:177>). Host restrictions in `ServerConfig` reject HTTP, foreign hosts, user-info, ports and dangerous schemes for externally supplied URLs. [ServerConfig.kt:50](</private/var/folders/3d/9zlg_84d1fxdgsrrs6pwlz_40000gn/T/codex-review/play-data/java/ca/mowology/crew/config/ServerConfig.kt:50>).

The actual remote website and JavaScript bundle were not supplied, so I cannot determine whether it loads third-party trackers, remote executable JavaScript outside ordinary WebView content, or exposes additional upload/data behavior.

### Remote code

I found no `DexClassLoader`, downloaded DEX/JAR execution, dynamic feature loader, or native-library download. Loading ordinary HTML/JavaScript in the WebView is present; downloading and executing Android application code was not found apart from the self-updating APK path, which must be excluded.

### Native process execution

No `Runtime.exec` or `ProcessBuilder` use found.

### Sensitive platform capabilities

No evidence found of:

- Accessibility service or `BIND_ACCESSIBILITY_SERVICE`.
- SMS reading/sending.
- Call logs or direct phone calling.
- `QUERY_ALL_PACKAGES`.
- `READ_PHONE_STATE`.
- Broad storage/media-library permissions.

## Submission blockers and recommended declarations

Before submitting, I would treat these as blockers:

1. Prove the Play merged manifest/AAB actually excludes background location, foreground-location service permissions, tracking/boot/geofence/activity receivers, battery exemption and both package-install permissions.
2. Remove `ACTIVITY_RECOGNITION` from the Play artifact.
3. Add or verify a foreground-location prominent disclosure and just-in-time request; the only documented disclosure is currently in the unavailable website.
4. Add an in-app account-deletion request and public deletion page.
5. Decide whether FCM is genuinely enabled for the Play application ID. If yes, declare device IDs/push token and provide a just-in-time notification request; if no, remove FCM and `POST_NOTIFICATIONS`.
6. Inspect the final merged manifest for transitive `AD_ID`.
7. Audit the remote WebView site separately for cookies, analytics, uploads, external embeds and data processors.

On the native code alone, the likely Data safety selections are: personal info, financial info, precise and approximate location, contacts, photos, app activity, web browsing, and—only if FCM works—device or other IDs. “Files and docs” and off-device crash/diagnostic collection are not established by the supplied code.