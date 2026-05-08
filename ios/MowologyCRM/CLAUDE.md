# iOS App — Claude Code Rules

## Architecture
- SwiftUI, minimum iOS 17.0, Swift 5.9
- Xcode project generated from `project.yml` via XcodeGen
- After adding or removing Swift files, run `xcodegen generate` in this directory to regenerate `.xcodeproj`

## APIEndpoint
Every new backend route needs a case in `MowologyCRM/Core/Network/APIEndpoints.swift`.
For each new case, update all three switch blocks:

| Block | What to add |
|-------|-------------|
| `var url` | `URL(string:)` or `URLComponents` for query params |
| `var requiresAuth` | All schedule endpoints return `true` |
| `var httpMethod` | `"GET"` or `"POST"` |

GET endpoints with query params use `URLComponents` + `queryItems`.
POST endpoints with a body pass `body: [String: Any]` to `APIClient.request()`.

## Worktree guard
When Claude Code operates inside a git worktree (path contains `.claude/worktrees/`),
the Edit and Write tools are blocked from editing files outside the worktree path.
For intentional edits to the main repo (e.g. the `feature/language` branch), use
the Bash tool with python3 to write file content directly.

## File organisation
| Directory | Purpose |
|-----------|---------|
| `Core/Auth/` | JWT token storage and session management |
| `Core/Location/` | GPS, motion activity, arrival detection, route storage |
| `Core/Models/` | Decodable model types for API responses |
| `Core/Network/` | APIClient, APIEndpoints, APIError, VersionCheckService |
| `Core/Offline/` | PingQueue, TransitionQueue for offline resilience |
| `Features/Auth/` | Login view + view model |
| `Features/Schedule/` | Day/week schedule views, visit detail, job timer |
| `Features/TimeClock/` | Crew clock-in / clock-out tab |

## Swift conventions
- `@MainActor` on all ViewModels and location-related singletons (LocationManager, ArrivalMonitor, RouteStore)
- `APIClient.request<T: Decodable>(_ endpoint:, body:, extraHeaders:)` returns `T` and throws `APIError`
- All API response model types are `Decodable` structs in `Core/Models/`
- Use `PingQueue.shared` for GPS pings that should survive offline periods
- Use `TransitionQueue` for job start/stop transitions that need idempotency keys
