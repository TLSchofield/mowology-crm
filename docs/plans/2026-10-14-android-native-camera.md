# Plan: native camera + background photo upload in the Android crew app

Status: **Tim: "If yes do it" (2026-10-14). Waiting for his yes to this plan before any code.** Repo: `~/Projects/mowology-android` (app 1.4.0, versionCode 14). Owner: Otto. Ada keeps the shared code.

## Why
The web camera runs inside the app's web page. That gives it:
- lower resolution, weak focus and low light, no flash control, and a slow start;
- photos that wait on the web page until they upload, while Android freezes or kills that page in the background.

The iPhone has a native multi-shot camera with Before / During / After / More tabs, markup, and a background queue.

## What gets built (in the existing app; no new server endpoints)
1. **`MwCameraActivity`** — a full-screen native camera written with Compose and Android's CameraX library:
   - category tabs: Before / After / More;
   - up to 10 shots, with a thumbnail tray, retake (delete a shot), and Done;
   - flash: off / auto / on, plus tap-to-focus;
   - the volume button takes a picture (useful with gloves);
   - each photo is written to app storage the moment it's taken, with its EXIF capture time and the phone's GPS.
2. **`PhotoUploadWorker`** (WorkManager) plus a Room table for the queue:
   - uploads each photo to `POST /api/schedule/job-photo` (the iPhone's endpoint, signed in with the app token: `photo`, `visit_id`, `photo_type`, `gps_*`, `queued_at`);
   - needs network, backs off, and retries forever;
   - keeps running with the app closed or after the phone restarts;
   - a repeat sends the same file, so the server's duplicate check returns the same photo.
3. **`MwCameraPlugin`** (Capacitor, built like `MwTrackingPlugin`): `open({visitId, category})` returns `{count, category, thumbs}`. The web page then takes the same job steps it takes today:
   - a Before photo taken before Start is the start;
   - the After photo asked for at the finish completes the job.
4. **Web side** (`schedule-pill-workflow.js`):
   - when the plugin exists and the switch is on, the Before / After / + tiles open the native camera;
   - otherwise, today's camera.
   - The strip shows the native thumbnails right away, using the app's local file links.
5. **On/off switch:** `ops_settings native_camera_enabled`, default **off**. Tim turns it on for one phone first.

## Unknowns, and how each is settled (cheapest first)
| Unknown | How it's settled |
|---|---|
| Which CameraX version builds on this app (compileSdk 34, Kotlin 1.9.22, JDK 17) | Add the dependency and run `assembleDebug`. A failed build names the version limit. |
| Does `job-photo` take every category and the app token? | **Settled:** it reads `photo_type` and `requireJwt()`; the iPhone uses it today. |
| Does the server's duplicate check make retries safe? | **Settled:** `mediaUploadFile` returns `is_duplicate` for identical bytes. |
| Can the web strip show `file://` thumbnails? | Capacitor's `convertFileSrc()`; checked in the emulator. |
| Does an expired app token stall the queue? | The worker calls `AppTokenFetcher.refreshIfNeeded()` before each pass; checked with a forced 401 in a unit test. |
| Does it work on a real phone? | Only a phone can tell. Tim installs the debug APK on one Android phone with the switch on for him only. |

## Steps and proof
1. CameraX dependency → the build passes.
2. Queue + worker, with JVM unit tests: back-off, never dropping a photo, a 401 refreshes the token, 4xx vs 5xx handling.
3. Camera screen → emulator check (the emulator has a virtual camera) and a screenshot.
4. Plugin + web hook-up behind the switch → local site check with the switch off: nothing changes on today's phones.
5. Version 1.5.0 (versionCode 15) APK → Tim installs it on one phone. **Nothing is released to the crew without Tim.**
6. A week of server numbers for Android vs iPhone (photos per visit, minutes from photo to server). Then Tim decides on turning it on for everyone.
7. Later: markup (pen, shapes, text), as on the iPhone.

About 2–3 days of work.
