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

In the app, **AI Office → Try the new design** enables the Ittai screens for admins.
The design remains off by default. Field mode selects the outdoor palette.
