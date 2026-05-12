//
//  ScheduleViewModel.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

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

    /// GPS trail polylines for the selected date — caller's own trail for crew,
    /// every tracked crew member for admins.
    @Published var crewRoutes: [CrewRoute] = []

    /// Latest known position per crew member (last 24 h). Same visibility rules.
    @Published var crewLive: [CrewLiveLocation] = []

    // MARK: - Private

    private let apiClient: APIClient
    private var stopCache: [String: [Stop]] = [:]

    /// Optimistic status overrides applied locally before the server confirms.
    /// Survives day-cache hits so a fresh VisitDetailView sees the latest intent.
    /// Key = visit_id, value = "in_progress" / "completed" / "scheduled".
    private var optimisticVisitStatus: [Int: String] = [:]

    private let transitionQueue = TransitionQueue()

    // MARK: - Init

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    // MARK: - Public API

    /// Loads both the week strip summary and the day stops for the given date.
    func refresh() async {
        await loadWeek(for: selectedDate)
        await loadDay(selectedDate, forceReload: true)
        await loadCrewTrails(for: selectedDate)
    }

    /// Fetches GPS trail polylines + live crew positions for the given date.
    /// Failures are silent — trails are non-essential, the rest of the schedule
    /// view should keep working if the endpoint errors or is offline.
    func loadCrewTrails(for date: Date) async {
        let dateString = isoDateString(from: date)
        do {
            let response: CrewTrailsResponse = try await apiClient.request(
                .scheduleCrewTrails(date: dateString)
            )
            crewRoutes = response.routes.filter { !$0.points.isEmpty }
            crewLive   = response.live
        } catch {
            crewRoutes = []
            crewLive   = []
        }
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
    /// Pass `forceReload: true` to bypass the cache (used by refresh()).
    func loadDay(_ date: Date, forceReload: Bool = false) async {
        let dateString = isoDateString(from: date)

        // Serve from cache if available (and the caller didn't force a reload).
        if !forceReload, let cached = stopCache[dateString] {
            stops       = applyOverrides(to: cached)
            lastFetched = .now
            return
        }

        isLoading    = true
        errorMessage = nil

        do {
            let response: DayResponse = try await apiClient.request(
                .scheduleDay(date: dateString)
            )
            let fetched = applyOverrides(to: response.stops)
            stopCache[dateString] = fetched
            stops       = fetched
            lastFetched = .now
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
        await loadCrewTrails(for: date)
    }

    /// Invalidates the cache for the current day and reloads.
    func invalidateAndRefresh() async {
        let dateString = isoDateString(from: selectedDate)
        stopCache.removeValue(forKey: dateString)
        await refresh()
    }

    /// Moves the selected date forward or backward by the given number of weeks.
    func advanceWeek(by weeks: Int) async {
        let newDate = calendar.date(byAdding: .weekOfYear, value: weeks, to: selectedDate) ?? selectedDate
        await selectDate(newDate)
    }

    // MARK: - Optimistic Status Propagation

    /// Apply a locally-known visit status to the in-memory stops and cache.
    /// Called from `VisitDetailViewModel` so optimistic state survives popping
    /// back to the schedule and re-pushing the detail view.
    func patchVisitStatus(visitId: Int, newStatus: String) {
        optimisticVisitStatus[visitId] = newStatus
        stops = applyOverrides(to: stops)
        for (key, cached) in stopCache {
            stopCache[key] = applyOverrides(to: cached)
        }
    }

    /// Overlay optimistic statuses (from explicit patches AND any pending
    /// transitions in the queue) onto a list of stops.
    private func applyOverrides(to source: [Stop]) -> [Stop] {
        source.map { stop in
            let patchedVisits = stop.visits.map { visit -> Visit in
                // Explicit patch wins; otherwise fall back to the transition
                // queue's intended status. If neither, leave the visit alone.
                let override = optimisticVisitStatus[visit.visitId]
                    ?? transitionQueue.intendedStatus(forVisitId: visit.visitId)
                guard let override, override != visit.visitStatus else { return visit }
                return visit.withStatus(override)
            }
            return stop.withVisits(patchedVisits)
        }
    }

    /// Week range label shown in the nav bar principal slot.
    /// Same month:   "Jun 8–14, 2026"
    /// Cross-month:  "May 28 – Jun 3, 2026"
    var weekRangeLabel: String {
        let monday = mondayOf(week: selectedDate)
        let sunday = calendar.date(byAdding: .day, value: 6, to: monday) ?? monday
        let f      = DateFormatter()
        f.dateFormat = "MMM d"
        f.locale     = Locale(identifier: "en_CA")
        let year   = calendar.component(.year,  from: sunday)
        let sMonth = calendar.component(.month, from: monday)
        let eMonth = calendar.component(.month, from: sunday)
        if sMonth == eMonth {
            let endDay = calendar.component(.day, from: sunday)
            return "\(f.string(from: monday))–\(endDay), \(year)"
        } else {
            return "\(f.string(from: monday)) – \(f.string(from: sunday)), \(year)"
        }
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
}
