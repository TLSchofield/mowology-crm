//
//  CalendarSyncService.swift
//  MowologyCRM
//
//  Opt-in EventKit sync: writes today's stops as read-only calendar events
//  in a dedicated "Mowology Schedule" calendar.
//
//  Rules:
//  - Only runs when the user has enabled the toggle (isSyncEnabled).
//  - Requests full calendar access on-demand, never at launch.
//  - Replaces all existing Mowology events for the synced date rather than
//    diffing — simpler, avoids stale orphan events on schedule changes.
//  - Commits in a single batch; if the commit fails, existing events are
//    unchanged (EventKit transactions aren't atomic, but the replace-all
//    pattern keeps the state coherent on next sync).
//

import EventKit
import UIKit

@MainActor
final class CalendarSyncService {

    static let shared = CalendarSyncService()

    private let store         = EKEventStore()
    private let calendarTitle = "Mowology Schedule"
    private let syncEnabledKey = "mw.calendar.syncEnabled"

    private init() {}

    // MARK: - Public

    var isSyncEnabled: Bool {
        get { UserDefaults.standard.bool(forKey: syncEnabledKey) }
        set { UserDefaults.standard.set(newValue, forKey: syncEnabledKey) }
    }

    func sync(stops: [Stop], for date: Date) async {
        guard isSyncEnabled else { return }
        guard await requestAccessIfNeeded() else { return }
        guard let calendar = getOrCreateCalendar() else { return }

        let cal         = Calendar.current
        let startOfDay  = cal.startOfDay(for: date)
        let endOfDay    = cal.date(byAdding: .day, value: 1, to: startOfDay)!

        // Remove all Mowology events for this day, then re-insert.
        let predicate = store.predicateForEvents(
            withStart: startOfDay, end: endOfDay, calendars: [calendar]
        )
        store.events(matching: predicate).forEach {
            try? store.remove($0, span: .thisEvent, commit: false)
        }

        for stop in stops.sorted(by: { $0.routeOrder < $1.routeOrder }) {
            let event      = EKEvent(eventStore: store)
            event.title    = stop.displayName ?? stop.propertyAddress
            event.location = stop.fullAddress
            event.calendar = calendar
            event.notes    = stop.visits.map { $0.serviceTypeLabel }.joined(separator: "\n")

            let (start, end) = eventWindow(for: stop, on: startOfDay, order: stop.routeOrder)
            event.startDate = start
            event.endDate   = end

            try? store.save(event, span: .thisEvent, commit: false)
        }

        try? store.commit()
    }

    // MARK: - Permissions

    func requestAccessIfNeeded() async -> Bool {
        let status = EKEventStore.authorizationStatus(for: .event)
        switch status {
        case .fullAccess:      return true
        case .notDetermined:   return (try? await store.requestFullAccessToEvents()) ?? false
        default:               return false
        }
    }

    // MARK: - Private helpers

    private func getOrCreateCalendar() -> EKCalendar? {
        if let existing = store.calendars(for: .event).first(where: { $0.title == calendarTitle }) {
            return existing
        }
        guard let source = store.defaultCalendarForNewEvents?.source else { return nil }
        let cal        = EKCalendar(for: .event, eventStore: store)
        cal.title      = calendarTitle
        cal.cgColor    = UIColor(red: 0.176, green: 0.525, blue: 0.349, alpha: 1).cgColor
        cal.source     = source
        try? store.saveCalendar(cal, commit: true)
        return cal
    }

    private func eventWindow(for stop: Stop, on startOfDay: Date, order: Int) -> (Date, Date) {
        if let arrival = stop.estimatedArrival,
           let arrivalDate = parseTimeString(arrival, on: startOfDay) {
            return (arrivalDate, arrivalDate.addingTimeInterval(30 * 60))
        }
        // Fallback: space stops 30 minutes apart from 8am using routeOrder.
        let offset = Double(max(0, order - 1)) * 30 * 60
        let start  = startOfDay.addingTimeInterval(8 * 3600 + offset)
        return (start, start.addingTimeInterval(30 * 60))
    }

    private func parseTimeString(_ string: String, on date: Date) -> Date? {
        let fmt = DateFormatter()
        fmt.locale = Locale(identifier: "en_US_POSIX")
        for format in ["h:mm a", "h:mma", "HH:mm"] {
            fmt.dateFormat = format
            if let time = fmt.date(from: string) {
                var components       = Calendar.current.dateComponents([.hour, .minute], from: time)
                var dateComponents   = Calendar.current.dateComponents([.year, .month, .day], from: date)
                dateComponents.hour  = components.hour
                dateComponents.minute = components.minute
                return Calendar.current.date(from: dateComponents)
            }
        }
        return nil
    }
}
