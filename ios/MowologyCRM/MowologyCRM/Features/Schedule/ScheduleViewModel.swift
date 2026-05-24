//
//  ScheduleViewModel.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation
import WidgetKit
import CoreLocation

// MARK: - API Response Types

private struct DayResponse: Decodable {
    let success: Bool
    let date: String
    let role: String
    let stopCount: Int
    let stops: [Stop]

    enum CodingKeys: String, CodingKey {
        case success
        case date
        case role
        case stopCount = "stop_count"
        case stops
    }
}

private struct WeekResponse: Decodable {
    let success: Bool
    let start: String
    let end: String
    let role: String
    let days: [ScheduleDay]
}

// MARK: - ScheduleViewModel

@MainActor
final class ScheduleViewModel: ObservableObject {

    // MARK: - Published State

    @Published var selectedDate: Date = .now
    @Published var weekDays: [ScheduleDay] = []
    @Published var stops: [Stop] = []
    @Published var isLoading: Bool = false
    @Published var errorMessage: String?
    @Published var lastFetched: Date?
    @Published var quizRequired: Bool = false

    // MARK: - Nearest-stop scroll
    @Published private(set) var scrollTargetStopId: Int? = nil
    private var scrolledDates: Set<String> = []

    // MARK: - Private

    private let apiClient: APIClient
    private var stopCache:       [String: [Stop]] = [:]
    private var stopCacheFetched:[String: Date]   = [:]
    private let cacheMaxAge: TimeInterval = 5 * 60  // 5 minutes

    // MARK: - Init

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    // MARK: - Public API

    /// Loads both the week strip summary and the day stops for the given date.
    /// Also gates on the daily pre-shift quiz.
    func refresh() async {
        quizRequired = !QuizViewModel.hasPassedToday()
        guard !quizRequired else { return }
        await loadWeek(for: selectedDate)
        await loadDay(selectedDate)
        await triggerNearestScrollIfNeeded()
    }

    /// Finds the stop closest to `location` and publishes its id as the scroll target.
    func computeNearestStop(to location: CLLocation) {
        let nearest = stops
            .compactMap { stop -> (Int, CLLocationDistance)? in
                guard let lat = stop.latitude, let lng = stop.longitude else { return nil }
                let dist = CLLocation(latitude: lat, longitude: lng).distance(from: location)
                return (stop.stopId, dist)
            }
            .min(by: { $0.1 < $1.1 })
        scrollTargetStopId = nearest?.0
    }

    /// One-shot: resolves current location then scrolls. No-ops if this date was already scrolled.
    func triggerNearestScrollIfNeeded() async {
        let dateKey = String(selectedDate.ISO8601Format().prefix(10))
        guard !scrolledDates.contains(dateKey), !stops.isEmpty else { return }
        scrolledDates.insert(dateKey)

        let lm = GPSTrackingService.shared.locationManager
        if let loc = lm.lastLocation {
            computeNearestStop(to: loc)
        } else if let loc = try? await lm.currentLocation() {
            computeNearestStop(to: loc)
        }
    }

    /// Called by QuizView's onPass callback — dismisses the gate and loads schedule.
    func quizPassed() async {
        quizRequired = false
        await loadWeek(for: selectedDate)
        await loadDay(selectedDate)
    }

    /// Fetches the week summary strip (7 ScheduleDay objects).
    func loadWeek(for date: Date) async {
        let monday = mondayOf(week: date)
        let startString = isoDateString(from: monday)

        isLoading    = true
        errorMessage = nil

        do {
            let response: WeekResponse = try await apiClient.request(
                .scheduleWeek(start: startString)
            )
            weekDays = response.days
        } catch let apiError as APIError {
            errorMessage = apiError.errorDescription
        } catch {
            errorMessage = "Failed to load week schedule."
        }

        isLoading = false
    }

    /// Fetches stops for a specific date. Results are cached by ISO date string.
    func loadDay(_ date: Date) async {
        let dateString = isoDateString(from: date)

        // Serve from cache if fresh (within cacheMaxAge).
        if let cached = stopCache[dateString],
           let fetchedAt = stopCacheFetched[dateString],
           Date().timeIntervalSince(fetchedAt) < cacheMaxAge {
            stops       = cached
            lastFetched = fetchedAt
            return
        }

        isLoading    = true
        errorMessage = nil

        do {
            let response: DayResponse = try await apiClient.request(
                .scheduleDay(date: dateString)
            )
            let fetched = response.stops
            stopCache[dateString]        = fetched
            stopCacheFetched[dateString] = .now
            stops       = fetched
            lastFetched = .now
            let sites: [MonitoredSite] = fetched.flatMap { stop -> [MonitoredSite] in
                guard let lat = stop.latitude, let lon = stop.longitude else { return [] }
                let coord = CLLocationCoordinate2D(latitude: lat, longitude: lon)
                return stop.visits.map { MonitoredSite(visitId: $0.visitId, coordinate: coord) }
            }
            ArrivalMonitor.shared.configure(sites: sites)
            Task { await CalendarSyncService.shared.sync(stops: fetched, for: date) }
            syncWidgetSchedule(fetched)
        } catch let apiError as APIError {
            errorMessage = apiError.errorDescription
            stops = []
        } catch {
            errorMessage = "Failed to load stops for \(dateString)."
            stops = []
        }

        isLoading = false
    }

    /// Changes the selected date and loads its stops. If the week changes,
    /// also refreshes the week strip.
    func selectDate(_ date: Date) async {
        let previousWeekMonday = mondayOf(week: selectedDate)
        let newWeekMonday      = mondayOf(week: date)

        selectedDate = date

        if !isSameDay(previousWeekMonday, newWeekMonday) {
            await loadWeek(for: date)
        }

        await loadDay(date)
    }

    /// Invalidates the cache for the current day and reloads.
    func invalidateAndRefresh() async {
        let dateString = isoDateString(from: selectedDate)
        stopCache.removeValue(forKey: dateString)
        stopCacheFetched.removeValue(forKey: dateString)
        await refresh()
    }

    // MARK: - Date Utilities

    private var calendar: Calendar {
        var cal = Calendar(identifier: .gregorian)
        cal.firstWeekday = 2  // Monday = first day of week.
        cal.locale = Locale(identifier: "en_CA")
        return cal
    }

    /// Returns the Monday of the week containing `date`.
    func mondayOf(week date: Date) -> Date {
        let comps = calendar.dateComponents([.yearForWeekOfYear, .weekOfYear], from: date)
        return calendar.date(from: comps) ?? date
    }

    func isoDateString(from date: Date) -> String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd"
        formatter.locale     = Locale(identifier: "en_CA")
        formatter.calendar   = calendar
        return formatter.string(from: date)
    }

    private func isSameDay(_ a: Date, _ b: Date) -> Bool {
        calendar.isDate(a, inSameDayAs: b)
    }

    // MARK: - Widget sync

    private func syncWidgetSchedule(_ stops: [Stop]) {
        let defaults = UserDefaults(suiteName: AppDelegate.appGroupId)
        defaults?.set(stops.count, forKey: "mw.widget.stopCount")
        let next = stops.sorted { $0.routeOrder < $1.routeOrder }.first
        defaults?.set(next?.propertyAddress, forKey: "mw.widget.nextAddress")
        defaults?.set(next?.estimatedArrival, forKey: "mw.widget.nextTime")
        WidgetCenter.shared.reloadTimelines(ofKind: "TodayScheduleWidget")
    }
}
