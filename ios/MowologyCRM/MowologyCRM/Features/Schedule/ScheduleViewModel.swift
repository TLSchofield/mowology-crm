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

struct FreshnessDay: Decodable {
    let date: String
    let stopCount: Int
    let checksum: String

    enum CodingKeys: String, CodingKey {
        case date
        case stopCount = "stop_count"
        case checksum
    }
}

struct FreshnessResponse: Decodable {
    let success: Bool
    let days: [FreshnessDay]
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

    /// True when the pre-shift quiz gate should be displayed.
    @Published var quizRequired: Bool = false

    /// True when the displayed schedule is from the disk cache (server unreachable).
    @Published var isOffline: Bool = false

    // MARK: - Private

    private let apiClient: APIClient
    private var stopCache: [String: [Stop]] = [:]
    private static let maxCachedDays = 14

    /// Handles for in-flight tasks — cancelled when a new load supersedes them.
    private var scheduleTask: Task<Void, Never>?
    private var trailsTask:   Task<Void, Never>?

    // Stored once — DateFormatter and Calendar construction is expensive.
    private let calendar: Calendar = {
        var cal = Calendar(identifier: .gregorian)
        cal.firstWeekday = 2
        cal.locale = Locale(identifier: "en_CA")
        return cal
    }()

    private lazy var isoFormatter: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_CA")
        f.calendar   = calendar
        return f
    }()

    private lazy var displayFormatter: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "MMM d"
        f.locale     = Locale(identifier: "en_CA")
        return f
    }()

    // MARK: - Init

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    // MARK: - Public API

    /// Loads both the week strip summary and the day stops for the given date.
    /// Also gates on the daily pre-shift quiz.
    func refresh() async {
        ScheduleCache.shared.evictOlderThan()
        quizRequired = !QuizViewModel.hasPassedToday()
        guard !quizRequired else { return }
        await loadSchedule(for: selectedDate, reloadWeek: true)
    }

    /// Called by QuizView's onPass callback — dismisses the gate and loads the schedule.
    func quizPassed() async {
        quizRequired = false
        await loadSchedule(for: selectedDate, reloadWeek: true)
    }

    /// Changes the selected date and loads its stops. If the week changes,
    /// also refreshes the week strip.
    func selectDate(_ date: Date) async {
        let weekChanged = !isSameDay(mondayOf(week: selectedDate), mondayOf(week: date))
        selectedDate = date
        await loadSchedule(for: date, reloadWeek: weekChanged)
    }

    /// Invalidates the cache for the current day and reloads.
    func invalidateAndRefresh() async {
        stopCache.removeValue(forKey: isoDateString(from: selectedDate))
        await refresh()
    }

    /// Moves the selected date forward or backward by the given number of weeks.
    func advanceWeek(by weeks: Int) async {
        let newDate = calendar.date(byAdding: .weekOfYear, value: weeks, to: selectedDate) ?? selectedDate
        await selectDate(newDate)
    }

    /// Week range label shown in the nav bar principal slot.
    /// Same month:   "Jun 8–14, 2026"
    /// Cross-month:  "May 28 – Jun 3, 2026"
    var weekRangeLabel: String {
        let monday = mondayOf(week: selectedDate)
        let sunday = calendar.date(byAdding: .day, value: 6, to: monday) ?? monday
        let year   = calendar.component(.year,  from: sunday)
        let sMonth = calendar.component(.month, from: monday)
        let eMonth = calendar.component(.month, from: sunday)
        if sMonth == eMonth {
            let endDay = calendar.component(.day, from: sunday)
            return "\(displayFormatter.string(from: monday))–\(endDay), \(year)"
        } else {
            return "\(displayFormatter.string(from: monday)) – \(displayFormatter.string(from: sunday)), \(year)"
        }
    }

    // MARK: - Date Utilities

    /// Returns the Monday of the week containing `date`.
    func mondayOf(week date: Date) -> Date {
        let comps = calendar.dateComponents([.yearForWeekOfYear, .weekOfYear], from: date)
        return calendar.date(from: comps) ?? date
    }

    func isoDateString(from date: Date) -> String {
        isoFormatter.string(from: date)
    }

    // MARK: - Private Load Orchestration

    /// Single entry point for all schedule loads. Cancels any in-flight request before
    /// starting, then fires week + day concurrently via a task group. Trails are
    /// non-essential and run in a separate background task that does not block the spinner.
    private func loadSchedule(for date: Date, reloadWeek: Bool) async {
        scheduleTask?.cancel()
        trailsTask?.cancel()

        isLoading    = true
        errorMessage = nil

        let handle = Task {
            await withTaskGroup(of: Void.self) { group in
                if reloadWeek {
                    group.addTask { await self.loadWeek(for: date) }
                }
                group.addTask { await self.loadDay(date) }
            }
        }
        scheduleTask = handle
        await handle.value

        // Only clear the spinner and start trails if this load wasn't superseded.
        guard !handle.isCancelled else { return }
        isLoading = false

        let trailHandle = Task { await self.loadCrewTrails(for: date) }
        trailsTask = trailHandle
    }

    /// Fetches the week summary strip (7 ScheduleDay objects).
    private func loadWeek(for date: Date) async {
        let startString = isoDateString(from: mondayOf(week: date))
        do {
            let response: WeekResponse = try await apiClient.request(
                .scheduleWeek(start: startString)
            )
            guard !Task.isCancelled else { return }
            isOffline = false
            weekDays  = response.days
        } catch let err as APIError {
            guard !Task.isCancelled else { return }
            if case .networkError = err {
                isOffline = true   // week strip is decorative — fail silently
            } else {
                errorMessage = err.errorDescription ?? "Failed to load week schedule."
            }
        } catch {
            guard !Task.isCancelled else { return }
            errorMessage = error.localizedDescription
        }
    }

    /// Fetches stops for a specific date. Falls back to the disk cache when offline.
    private func loadDay(_ date: Date) async {
        let dateString = isoDateString(from: date)

        // Serve from in-memory cache if available (avoids redundant network hits
        // when the user swipes back to a date they already loaded this session).
        if let cached = stopCache[dateString] {
            stops       = cached
            lastFetched = .now
            return
        }

        do {
            let response: DayResponse = try await apiClient.request(
                .scheduleDay(date: dateString)
            )
            guard !Task.isCancelled else { return }
            let fetched = response.stops
            cacheStops(fetched, forDate: dateString)
            ScheduleCache.shared.save(fetched, forDate: dateString)
            isOffline   = false
            stops       = fetched
            lastFetched = .now
            prefetchUpcoming()
        } catch let err as APIError {
            guard !Task.isCancelled else { return }
            if case .networkError = err {
                // Offline — try disk cache before showing an error.
                if let diskCached = ScheduleCache.shared.load(forDate: dateString) {
                    cacheStops(diskCached, forDate: dateString)
                    stops     = diskCached
                    isOffline = true
                } else {
                    isOffline    = true
                    errorMessage = "No signal and no cached schedule for this date."
                    stops        = []
                }
            } else {
                errorMessage = err.errorDescription ?? "Failed to load stops."
                stops        = []
            }
        } catch {
            guard !Task.isCancelled else { return }
            errorMessage = error.localizedDescription
            stops        = []
        }
    }

    /// Fetches GPS trail polylines + live crew positions for the given date.
    /// Failures are silent — trails are non-essential; the rest of the schedule
    /// view keeps working if the endpoint errors or is offline.
    private func loadCrewTrails(for date: Date) async {
        let dateString = isoDateString(from: date)
        do {
            let response: CrewTrailsResponse = try await apiClient.request(
                .scheduleCrewTrails(date: dateString)
            )
            guard !Task.isCancelled else { return }
            crewRoutes = response.routes.filter { !$0.points.isEmpty }
            crewLive   = response.live
        } catch {
            crewRoutes = []
            crewLive   = []
        }
    }

    // MARK: - Cache

    private func cacheStops(_ fetched: [Stop], forDate dateString: String) {
        stopCache[dateString] = fetched
        // Evict oldest entry when cap is exceeded. ISO date strings sort lexically.
        if stopCache.count > Self.maxCachedDays,
           let oldest = stopCache.keys.sorted().first {
            stopCache.removeValue(forKey: oldest)
        }
    }

    // MARK: - Helpers

    private func isSameDay(_ a: Date, _ b: Date) -> Bool {
        calendar.isDate(a, inSameDayAs: b)
    }

    // MARK: - Background Prefetch

    private static let prefetchDays = 7

    /// Fires after a successful refresh. Checks freshness for the next 7 days,
    /// then silently fetches only stale or uncached days.
    func prefetchUpcoming() {
        Task.detached(priority: .background) { [weak self] in
            await self?.runPrefetch()
        }
    }

    @MainActor
    private func runPrefetch() async {
        // Rate-limit: skip if we already prefetched for today within the last 30 min.
        let prefetchKey = "mw_prefetch_\(isoDateString(from: Date()))"
        let lastRun = UserDefaults.standard.double(forKey: prefetchKey)
        let elapsed = Date().timeIntervalSince1970 - lastRun
        guard elapsed > 1800 else { return }  // 30 minutes

        guard !isOffline else { return }

        let startDate   = calendar.startOfDay(for: Date())
        let startString = isoDateString(from: startDate)

        // Step 1: freshness check
        guard let freshnessResponse: FreshnessResponse = try? await apiClient.request(
            .scheduleFreshness(start: startString, days: Self.prefetchDays)
        ) else { return }

        // Step 2: fetch only stale/uncached days
        for day in freshnessResponse.days {
            let cached = stopCache[day.date]
            let cachedChecksum = ScheduleCache.shared.checksum(forDate: day.date)

            // Skip if in-memory cache exists AND checksums match.
            if cached != nil, cachedChecksum == day.checksum { continue }

            // Skip if disk cache has matching checksum — hydrate in-memory cache.
            if cached == nil, cachedChecksum == day.checksum,
               let disk = ScheduleCache.shared.load(forDate: day.date) {
                cacheStops(disk, forDate: day.date)
                continue
            }

            // Fetch fresh data.
            if let dayResponse: DayResponse = try? await apiClient.request(
                .scheduleDay(date: day.date)
            ) {
                cacheStops(dayResponse.stops, forDate: day.date)
                ScheduleCache.shared.save(dayResponse.stops, forDate: day.date, checksum: day.checksum)
            }

            // Small delay between fetches — be a polite background task.
            try? await Task.sleep(for: .milliseconds(300))
        }

        UserDefaults.standard.set(Date().timeIntervalSince1970, forKey: prefetchKey)
    }
}
