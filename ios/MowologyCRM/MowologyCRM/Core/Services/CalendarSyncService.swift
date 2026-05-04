//
//  CalendarSyncService.swift
//  MowologyCRM
//
//  Opt-in EventKit sync: writes today's stops as read-only calendar events
//  in a dedicated "Mowology Schedule" calendar.
//
//  ┌─ PRODUCTION SAFETY ──────────────────────────────────────────────────────┐
//  │  Invariants this file depends on — break any one and calendar sync       │
//  │  will silently corrupt the user's calendar or lose events:               │
//  │                                                                           │
//  │  1. SINGLE STORE. EKEventStore is shared across the whole process.       │
//  │     Never create a second instance — iOS throttles stores per app and    │
//  │     two stores can see stale snapshots of each other's writes.           │
//  │                                                                           │
//  │  2. REPLACE-ALL, NOT DIFF. The delete-then-insert approach is            │
//  │     intentional. A diff would require stable event IDs across schedule   │
//  │     changes — the API doesn't provide those. Any "optimisation" that     │
//  │     tries to update existing events in place will create orphan events   │
//  │     when stops are added/removed/reordered mid-day.                      │
//  │                                                                           │
//  │  3. SINGLE BATCH COMMIT. All removes and saves use commit: false.        │
//  │     The single store.commit() at the end is the only write to disk.      │
//  │     Do NOT add commit: true anywhere inside the loops — EventKit         │
//  │     notifies other apps (Calendar.app) on every commit, causing          │
//  │     visible flicker as events appear and disappear mid-loop.             │
//  │                                                                           │
//  │  4. @MainActor. EKEventStore is not thread-safe. The @MainActor          │
//  │     annotation on the class ensures all EventKit calls happen on the     │
//  │     main thread. Do not dispatch sync() to a background queue.           │
//  │                                                                           │
//  │  5. OPT-IN GATE. The isSyncEnabled guard MUST be the first check in      │
//  │     sync(). Requesting calendar access without the user opting in would  │
//  │     trigger the OS permission dialog unexpectedly, violating the         │
//  │     "never at launch" rule in the plan.                                  │
//  └───────────────────────────────────────────────────────────────────────────┘
//

import EventKit
import UIKit

@MainActor
final class CalendarSyncService {

    // MARK: - Singleton
    //
    // Shared instance is required here — not just convenience. A second
    // CalendarSyncService would create a second EKEventStore, violating
    // invariant #1 above. CalledFrom: ScheduleViewModel.loadDay().

    static let shared = CalendarSyncService()

    // MARK: - Store and constants
    //
    // calendarTitle is the user-visible name of the dedicated calendar created
    // in the device's Calendar app. Changing this string abandons the old
    // calendar (leaving its events orphaned) and creates a new empty one.
    // If you need to rename it, write a migration that renames the existing
    // EKCalendar rather than just changing this constant.
    //
    // syncEnabledKey is stored in UserDefaults.standard (not the App Group
    // container) because it's a per-device preference, not widget-shared state.

    private let store          = EKEventStore()
    private let calendarTitle  = "Mowology Schedule"
    private let syncEnabledKey = "mw.calendar.syncEnabled"

    private init() {}

    // MARK: - Public API

    // isSyncEnabled — user-facing toggle (set from Account tab settings).
    // Reads/writes UserDefaults.standard synchronously. Safe to call from
    // any context because it's just a bool flag, not EventKit.

    var isSyncEnabled: Bool {
        get { UserDefaults.standard.bool(forKey: syncEnabledKey) }
        set { UserDefaults.standard.set(newValue, forKey: syncEnabledKey) }
    }

    // sync(stops:for:) — main entry point. Called after every successful
    // loadDay() response. It is idempotent: calling it twice for the same
    // date and stop list produces the same calendar state.
    //
    // ⚠️  Do not call this with a date range spanning multiple days. The
    //     predicate deletes ALL Mowology events between startOfDay and endOfDay,
    //     so passing a range would wipe multiple days' worth of events.
    //
    // ⚠️  Do not call this from a BGProcessingTask or BGAppRefreshTask without
    //     first verifying that EKEventStore access still works in background
    //     mode — iOS may suspend EventKit access when the app is fully
    //     background-refreshed (as opposed to foregrounded).

    func sync(stops: [Stop], for date: Date) async {
        // Gate 1: user must have opted in (invariant #5).
        guard isSyncEnabled else { return }
        // Gate 2: must have .fullAccess authorisation (invariant #4 flows through here).
        guard await requestAccessIfNeeded() else { return }
        // Gate 3: calendar must exist or be creatable. Returns nil if the device
        // has no writable calendar source (e.g., read-only Exchange account only).
        guard let calendar = getOrCreateCalendar() else { return }

        let cal        = Calendar.current
        let startOfDay = cal.startOfDay(for: date)
        // Force-unwrap is safe: adding 1 day to a valid Date never fails.
        let endOfDay   = cal.date(byAdding: .day, value: 1, to: startOfDay)!

        // ── Phase 1: delete ─────────────────────────────────────────────────
        // Scope the predicate to ONLY our dedicated calendar so we never
        // touch events in other calendars (Work, Personal, etc.).
        // commit: false — accumulate into the batch (invariant #3).

        let predicate = store.predicateForEvents(
            withStart: startOfDay, end: endOfDay, calendars: [calendar]
        )
        store.events(matching: predicate).forEach {
            try? store.remove($0, span: .thisEvent, commit: false)
        }

        // ── Phase 2: insert ─────────────────────────────────────────────────
        // Sort by routeOrder so the calendar events appear in job sequence.
        // commit: false — still accumulating (invariant #3).

        for stop in stops.sorted(by: { $0.routeOrder < $1.routeOrder }) {
            let event      = EKEvent(eventStore: store)
            event.title    = stop.displayName ?? stop.propertyAddress
            event.location = stop.fullAddress
            event.calendar = calendar
            // notes is best-effort; nil visits produce an empty string, which is fine.
            event.notes    = stop.visits.map { $0.serviceTypeLabel }.joined(separator: "\n")

            let (start, end) = eventWindow(for: stop, on: startOfDay, order: stop.routeOrder)
            event.startDate  = start
            event.endDate    = end

            try? store.save(event, span: .thisEvent, commit: false)
        }

        // ── Phase 3: single atomic commit ───────────────────────────────────
        // One disk write, one Calendar.app notification (invariant #3).
        // If this fails (e.g., iCloud sync conflict), the existing events from
        // the previous sync remain unchanged — next loadDay() will retry.

        try? store.commit()
    }

    // MARK: - Permissions
    //
    // iOS 17 requires .fullAccess to create or modify events. The older
    // .writeOnly permission (iOS 16) is not sufficient for our delete-then-
    // insert pattern because delete requires read to fetch event references.
    //
    // .restricted and .denied both fall through to the default: false branch.
    // Callers should surface a settings deep-link to the user in those cases,
    // but this service treats them as "sync silently disabled" — it does not
    // surface errors, because sync is a non-critical background enhancement.
    //
    // ⚠️  Do not call requestAccessIfNeeded() at app launch. The OS presents
    //     the permission dialog immediately on the first call when status is
    //     .notDetermined. It must only be called after the user has toggled
    //     isSyncEnabled = true.

    func requestAccessIfNeeded() async -> Bool {
        let status = EKEventStore.authorizationStatus(for: .event)
        switch status {
        case .fullAccess:    return true
        case .notDetermined: return (try? await store.requestFullAccessToEvents()) ?? false
        default:             return false  // .denied, .restricted, .writeOnly
        }
    }

    // MARK: - Calendar management
    //
    // getOrCreateCalendar() finds our dedicated calendar by title or creates it.
    //
    // ⚠️  Title matching is case-sensitive. If calendarTitle ever drifts (typo,
    //     localisation attempt), the lookup fails and a duplicate calendar is
    //     created on every sync until the old one is deleted manually.
    //
    // ⚠️  store.defaultCalendarForNewEvents can be nil on a device with no
    //     writable calendar source (e.g., all calendars are read-only Exchange).
    //     The guard lets sync() exit cleanly rather than crashing.
    //
    // Colour: MW green (#2D8659 ≈ RGB 0.176, 0.525, 0.349). The cgColor bridge
    // is required because EKCalendar exposes cgColor, not UIColor. Changing the
    // colour only affects newly created calendars — existing calendars retain
    // their colour until explicitly updated via store.saveCalendar().

    private func getOrCreateCalendar() -> EKCalendar? {
        if let existing = store.calendars(for: .event).first(where: { $0.title == calendarTitle }) {
            return existing
        }
        guard let source = store.defaultCalendarForNewEvents?.source else { return nil }
        let cal       = EKCalendar(for: .event, eventStore: store)
        cal.title     = calendarTitle
        cal.cgColor   = UIColor(red: 0.176, green: 0.525, blue: 0.349, alpha: 1).cgColor
        cal.source    = source
        // Calendar creation IS committed immediately (separate from event batch)
        // because other methods need to look it up by title right away.
        try? store.saveCalendar(cal, commit: true)
        return cal
    }

    // MARK: - Event timing
    //
    // eventWindow(for:on:order:) determines start/end times for each calendar event.
    //
    // Priority:
    //   1. estimatedArrival from the API (set by the route optimiser server-side)
    //   2. Fallback: 8:00 AM + (routeOrder - 1) × 30 minutes
    //
    // All events are 30 minutes long regardless of visit duration. This is
    // intentional — the duration in Calendar is for at-a-glance scheduling,
    // not billing. The actual job timer is managed by TimeClockViewModel.
    //
    // ⚠️  routeOrder is 1-based. The max(0, order - 1) ensures stop #1 starts
    //     at exactly 8:00 AM with zero offset. Removing the max() guard causes
    //     stop #0 (if the API ever sends it) to schedule at 7:30 AM.

    private func eventWindow(for stop: Stop, on startOfDay: Date, order: Int) -> (Date, Date) {
        if let arrival     = stop.estimatedArrival,
           let arrivalDate = parseTimeString(arrival, on: startOfDay) {
            return (arrivalDate, arrivalDate.addingTimeInterval(30 * 60))
        }
        let offset = Double(max(0, order - 1)) * 30 * 60
        let start  = startOfDay.addingTimeInterval(8 * 3600 + offset)
        return (start, start.addingTimeInterval(30 * 60))
    }

    // MARK: - Time string parsing
    //
    // parseTimeString(_:on:) converts the API's time strings into Date objects
    // anchored to a specific calendar day.
    //
    // Formats tried in order:
    //   "h:mm a"  — "9:00 AM"   (most common — PHP date('g:i A'))
    //   "h:mma"   — "9:00AM"    (no space variant, seen in some API versions)
    //   "HH:mm"   — "09:00"     (24-hour, used by some schedule endpoints)
    //
    // ⚠️  locale must be en_US_POSIX, not the device locale. Without it,
    //     DateFormatter interprets AM/PM using the device's locale, which can
    //     silently produce wrong times on devices set to 24-hour mode.
    //
    // ⚠️  The two-step date-component reassembly (parse time, extract year/month/
    //     day from `date`, merge) is required because DateFormatter's date(from:)
    //     for a time-only format returns 2000-01-01 as the date component. We
    //     must replace that with the actual day being synced.

    private func parseTimeString(_ string: String, on date: Date) -> Date? {
        let fmt = DateFormatter()
        fmt.locale = Locale(identifier: "en_US_POSIX")  // must not be .current — see above
        for format in ["h:mm a", "h:mma", "HH:mm"] {
            fmt.dateFormat = format
            if let time = fmt.date(from: string) {
                let timeComponents = Calendar.current.dateComponents([.hour, .minute], from: time)
                var dateComponents = Calendar.current.dateComponents([.year, .month, .day], from: date)
                dateComponents.hour   = timeComponents.hour
                dateComponents.minute = timeComponents.minute
                return Calendar.current.date(from: dateComponents)
            }
        }
        return nil
    }
}
