# Mowology iOS App

Native SwiftUI app for field staff and admin. v1 covers schedule/calendar only.

## Requirements

- Xcode 15+
- iOS 17+ simulator or device
- Apple Developer account (for device testing / App Store)

## Setup

### Option A — XcodeGen (recommended, keeps `.xcodeproj` out of git)

```bash
brew install xcodegen
cd ios/MowologyCRM
xcodegen generate
open MowologyCRM.xcodeproj
```

### Option B — Manual Xcode project

1. Open Xcode → File → New → Project → iOS → App
2. Product Name: `MowologyCRM`, Bundle ID: `ca.mowology.crm-field`
3. Interface: SwiftUI, Language: Swift, minimum iOS 17
4. Replace the generated source files with the files in `MowologyCRM/`
5. Add all Swift files from the directory structure below

## Project Structure

```
MowologyCRM/
├── App/
│   ├── MowologyCRMApp.swift      @main entry point
│   └── RootView.swift             Login ↔ Schedule gate
├── Core/
│   ├── Models/
│   │   ├── User.swift
│   │   ├── ScheduleDay.swift      Week strip model
│   │   ├── Stop.swift             Calendar stop + visits
│   │   └── Visit.swift            Job visit
│   ├── Auth/
│   │   ├── KeychainStore.swift    JWT token storage
│   │   └── AuthSession.swift      Login state (ObservableObject)
│   └── Network/
│       ├── APIClient.swift        URLSession + Bearer auth
│       ├── APIEndpoints.swift     Endpoint definitions
│       └── APIError.swift         Error types
├── Features/
│   ├── Auth/
│   │   ├── LoginView.swift
│   │   └── LoginViewModel.swift
│   └── Schedule/
│       ├── ScheduleView.swift     Root (week strip + day list)
│       ├── WeekStripView.swift    7-day date chip strip
│       ├── DayListView.swift      Scrollable stop list
│       ├── StopCardView.swift     Individual stop card
│       ├── VisitDetailView.swift  Full stop + map detail
│       └── ScheduleViewModel.swift
└── Resources/
    ├── Info.plist
    └── Assets.xcassets/           (create in Xcode — add AppIcon)
```

## API Endpoints (production)

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `POST /api/auth/token.php` | None | Get JWT token |
| `GET /api/schedule/day?date=YYYY-MM-DD` | Bearer | Day's stops |
| `GET /api/schedule/week?start=YYYY-MM-DD` | Bearer | Week summary |

## Next Steps (v2)

- Job check-in / check-out (time clock)
- Photo capture + upload on visit completion
- Push notifications (APNs) for new job assignments
- Offline persistence with SwiftData
- Refresh token support
