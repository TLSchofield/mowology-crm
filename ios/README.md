# Mowology iOS app

Native SwiftUI iPhone app for iOS 17+, with schedule and job work, photos, time clock,
receipts, search, and an admin-only AI Office. The API uses Bearer authentication
against `https://mowology.ca/api`.

## Source and project

The app is in `ios/MowologyCRM/MowologyCRM/`. `Core/` contains networking, models,
authentication, location, offline queues, and shared design components; `Features/`
contains screens and their view models.

The committed Xcode project is what CI builds. `project.yml` describes its settings,
but CI does not regenerate the project. Register a new Swift file with:

```sh
python3 ios/add_swift_file.py ios/MowologyCRM/MowologyCRM/path/NewFile.swift
```

Keep the marketing version in both `project.yml` and the Xcode project consistent.
Do not edit generated `Core/Design/Ittai/ItTokens.swift` directly; regenerate it with
`node scripts/ittai-swift-tokens.mjs` after editing the source design tokens.

## Checks

`bash ios/typecheck.sh` checks every app source against the installed iPhone SDK
without requiring a simulator or connected device. It does not perform signing or
replace the Xcode Cloud archive check.

Run from the repository/worktree root:

```sh
bash ios/LogicTests/run.sh
bash ios/DesignRender/run.sh /tmp/ittai-ios-render
vendor/bin/phpunit
```

The Swift checks cover camera logic, Ittai contrast and geometry, and business setup
using a fake transport. The renderer compiles the actual shared views on the Mac
and produces PNGs, including large-text samples. These checks do not replace an
iOS build or testing the app on a phone.

## TestFlight release

As verified on 2026-10-17, the active pipeline is **Xcode Cloud**. Builds 268–270
successfully built the Ittai commits pushed to `feature/language`. The older manual
GitHub Actions workflow remains in the repository but is not the active route.

1. Review and check the release in its worktree. Confirm backend dependencies are
   deployed before enabling new API features. Business setup requires the deployed
   business-identity endpoint and migration 1373; if unavailable, the app shows an
   unavailable state and does not offer writes. Contact suggestions are read-only.
2. Commit the reviewed files and fetch `origin/feature/language`. Reconcile any new
   commits without overwriting them. Push explicitly with
   `git push origin HEAD:feature/language` (the local branch name may differ).
3. Check Xcode Cloud. Use the existing `~/.claude/scripts/appstore-cloud.py` with the
   project's gitignored `.appstore-cloud.json` configuration; never copy credentials
   into a tracked file. Its `builds`, `show`, and `testflight` commands are read-only.
4. If no build starts, use `trigger --branch feature/language`. Do not also launch
   the GitHub Actions workflow for the same release.
5. Confirm the run's commit matches the release, archive/distribution succeed, and
   the uploaded build finishes TestFlight processing. A successful archive alone is
   not proof of TestFlight availability. Xcode Cloud supplies its own build number.

In the app, **AI Office → Try the new design** enables Ittai for admins; crew use
**Account → Appearance → Try the new design**. Schedule, Time Clock, Search,
Receipts and their detail/forms inherit the same Inter typography and semantic
light/dark palette. The design remains off by default. Field mode selects the
outdoor palette and takes precedence over system dark mode.

The root `.itAppTheme` adapter preserves the view subtree while preferences change.
Use the shared `ItAppStyle` environment instead of adding screen-specific colours.
`bash ios/DesignRender/run.sh /tmp/ittai-schedule schedule-only` renders the actual
week strip and stop rows with synthetic data at three phone widths and accessibility
text sizes. The Mac ImageRenderer omits horizontal ScrollView content, so the accessibility
week strip cannot be visually verified by these PNGs. Stop rows and regular-size
chips render directly from their production views. Xcode Cloud verifies the device
archive; native scrolling and touch interaction still require an iPhone check.

## Precise errors and admin diagnostics

Show the actual failure, never replace a caught exception with an assumed cause
such as “no connection”. `ErrorDetails.describe` retains Foundation domain/code,
JSON decoding kind/key path, and server messages. A failed server response without
an error/message field must identify the failed response flag and absent field.
Validation and saved-offline states remain separate from failures.

`APIClient` records failed HTTP responses, transport errors and decoding failures.
It keeps HTTP method, endpoint (only mode/action query selectors), status, response
size and the precise reason. It never records request headers, request bodies,
photos or GPS coordinates. Secret-bearing fields are redacted; bounded response
snippets explicitly identify truncation.

Admins open **AI Office → Diagnostic log** below the appearance settings, then
Refresh or Export log. Role access is checked both in navigation and the viewer.
Logs remain in Application Support/Diagnostics on this phone: one 1 MiB active log,
two rotated archives and a current export. They are excluded from backups and use
iOS file protection. Logging runs on a serial utility queue; disk failures reach
the system console and are displayed by the viewer.

`AppCrashReports` subscribes to Apple's MetricKit diagnostic reports and imports
available past reports, deduplicated by payload hash. Crashes/hangs are included
when iOS delivers them, not guaranteed synchronously at the instant of a crash.
The app deliberately does not install unsafe signal handlers or mislabel a
forced termination as a crash. No third-party telemetry or automatic log upload.

Registry confirmation requires `search/topic?q=<registration>&latest=true&inactive=any`.
`topic_source_id` is not the query filter on that endpoint; `inactive=true` selects
inactive companies rather than including both statuses. Always retain the exact
returned source-ID check. The provider lookup is covered by PHPUnit and verified
against the public registry before deployment.

Driver declaration drawers keep both answers in a bottom safe-area inset, outside
the scrolling explanation. Do not put required choices after a large illustration
inside the scroll content: the default medium drawer can hide the second answer.
