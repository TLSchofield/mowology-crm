//
//  ScheduleViewModel.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation
import CoreLocation

// MARK: - API Response Types

private struct DayResponse: Decodable {
    let success: Bool
    let date: String
    let role: String
    let stopCount: Int
    let stops: [Stop]
    let extrasRatePer5Min: Double?

    enum CodingKeys: String, CodingKey {
        case success
        case date
        case role
        case stopCount = "stop_count"
        case stops
        case extrasRatePer5Min = "extras_rate_per_5min"
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
    /// Stops for the selected date, ordered for the list: nearest to the
    /// device first (see `sortedForDisplay`). Falls back to the server's route
    /// order until a location fix arrives.
    @Published private(set) var stops: [Stop] = []

    /// Last known device position used for nearest-first ordering.
    @Published private(set) var userLocation: CLLocation?
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

    /// Stops exactly as the server returned them (route order). `stops` is
    /// re-derived from this whenever the data or the device location changes.
    private var rawStops: [Stop] = [] {
        didSet { stops = Self.sortedForDisplay(rawStops, from: userLocation) }
    }

    /// One-shot location source. Shares the tracking service's manager so a
    /// fresh fix from an active job is reused instead of spinning up GPS again.
    private let locationManager: LocationManager
    private var locationTask: Task<Void, Never>?
    private static let maxCachedDays = 14

    /// Handles for in-flight tasks — cancelled when a new load supersedes them.
    private var scheduleTask: Task<Void, Never>?
    private var trailsTask:   Task<Void, Never>?

    /// Background poll loop — re-fetches the selected day silently so completions
    /// made on another device/platform show up without a manual refresh.
    private var pollTask: Task<Void, Never>?
    private static let pollIntervalNanoseconds: UInt64 = 20_000_000_000 // 20s

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

    init(apiClient: APIClient,
         locationManager: LocationManager = GPSTrackingService.shared.locationManager) {
        self.apiClient       = apiClient
        self.locationManager = locationManager
    }

    // MARK: - Public API

    /// Loads both the week strip summary and the day stops for the given date.
    /// Also gates on the daily pre-shift quiz.
    ///
    /// Always busts the in-memory day cache first. `refresh()` is the target of
    /// both the initial `.task` load and pull-to-refresh — it must reflect
    /// completions made by teammates on other devices, not silently re-serve
    /// whatever was cached earlier in the session. (`selectDate`'s swipe-between-
    /// dates cache is left alone — that one is an intentional same-session
    /// optimization, not a "give me current truth" action.)
    func refresh() async {
        ScheduleCache.shared.evictOlderThan()
        quizRequired = !QuizViewModel.hasAttemptedToday()
        guard !quizRequired else { return }
        stopCache.removeValue(forKey: isoDateString(from: selectedDate))
        updateUserLocation()
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

    /// Starts a background poll loop that silently re-checks the selected day
    /// every ~20s so a completion made elsewhere (another crew member, another
    /// platform) shows up without the user having to do anything. No-op if a
    /// loop is already running. Caller is responsible for calling
    /// `stopPolling()` when the schedule view leaves the screen or the app
    /// backgrounds — this deliberately does not watch scenePhase itself so it
    /// stays a plain, testable loop.
    func startPolling() {
        guard pollTask == nil else { return }
        pollTask = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: Self.pollIntervalNanoseconds)
                guard !Task.isCancelled else { break }
                await self?.silentRefresh()
            }
        }
    }

    func stopPolling() {
        pollTask?.cancel()
        pollTask = nil
    }

    /// Re-fetches the selected day in the background with no loading spinner,
    /// no cancellation of other in-flight work, and no week-strip refetch —
    /// just "is there anything new," applied only if it actually changed so a
    /// no-op tick never triggers a view diff.
    private func silentRefresh() async {
        let dateString = isoDateString(from: selectedDate)
        do {
            let response: DayResponse = try await apiClient.request(.scheduleDay(date: dateString))
            guard !Task.isCancelled else { return }
            if response.stops != rawStops {
                rawStops = response.stops
            }
            cacheStops(response.stops, forDate: dateString)
            ScheduleCache.shared.save(response.stops, forDate: dateString)
        } catch {
            // Silent by design — a poll tick failing (offline, session hiccup)
            // isn't worth surfacing; the next tick or a manual refresh recovers.
        }
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
            rawStops    = cached
            lastFetched = .now
            return
        }

        do {
            let response: DayResponse = try await apiClient.request(
                .scheduleDay(date: dateString)
            )
            guard !Task.isCancelled else { return }
            AppExtrasConfig.shared.update(ratePer5Min: response.extrasRatePer5Min)
            let fetched = response.stops
            cacheStops(fetched, forDate: dateString)
            ScheduleCache.shared.save(fetched, forDate: dateString)
            isOffline   = false
            rawStops    = fetched
            lastFetched = .now
        } catch let err as APIError {
            guard !Task.isCancelled else { return }
            if case .networkError = err {
                // Offline — try disk cache before showing an error.
                if let diskCached = ScheduleCache.shared.load(forDate: dateString) {
                    cacheStops(diskCached, forDate: dateString)
                    rawStops  = diskCached
                    isOffline = true
                } else {
                    isOffline    = true
                    errorMessage = "No signal and no cached schedule for this date."
                    rawStops     = []
                }
            } else {
                errorMessage = err.errorDescription ?? "Failed to load stops."
                rawStops     = []
            }
        } catch {
            guard !Task.isCancelled else { return }
            errorMessage = error.localizedDescription
            rawStops     = []
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

    // MARK: - Nearest-First Ordering

    /// Requests a one-shot device fix and re-sorts the list when it arrives.
    /// Silent on failure — the list simply stays in server route order.
    /// If permission hasn't been decided yet, asks once and waits for the answer.
    func updateUserLocation() {
        locationTask?.cancel()
        locationTask = Task { [weak self] in
            guard let self else { return }
            let lm = self.locationManager
            if lm.authorizationStatus == .notDetermined {
                lm.requestWhenInUsePermission()
                for await status in lm.$authorizationStatus.values {
                    if Task.isCancelled { return }
                    if status != .notDetermined { break }
                }
            }
            guard lm.canUseLocation else { return }
            guard let fix = try? await lm.currentLocation(), !Task.isCancelled else { return }
            self.userLocation = fix
            self.stops = Self.sortedForDisplay(self.rawStops, from: fix)
        }
    }

    /// Orders stops for the list:
    ///   1. in-progress stops (the crew is already there)
    ///   2. remaining stops, nearest to the device first
    ///   3. stops with no coordinates, in route order
    ///   4. completed stops, in route order (sunk to the bottom)
    /// Without a device location the server's route order is kept unchanged.
    static func sortedForDisplay(_ stops: [Stop], from location: CLLocation?) -> [Stop] {
        guard let location else { return stops }

        func distance(_ stop: Stop) -> CLLocationDistance? {
            guard let lat = stop.latitude, let lng = stop.longitude else { return nil }
            return location.distance(from: CLLocation(latitude: lat, longitude: lng))
        }

        // Precompute so the comparator doesn't re-run haversine per comparison.
        let keyed = stops.map { stop -> (stop: Stop, tier: Int, dist: CLLocationDistance) in
            let d = distance(stop)
            let tier: Int
            if stop.isComplete        { tier = 3 }
            else if stop.isInProgress { tier = 0 }
            else if d == nil          { tier = 2 }
            else                      { tier = 1 }
            return (stop, tier, d ?? .greatestFiniteMagnitude)
        }

        return keyed.sorted { a, b in
            if a.tier != b.tier { return a.tier < b.tier }
            if a.tier == 1, a.dist != b.dist { return a.dist < b.dist }
            return a.stop.routeOrder < b.stop.routeOrder
        }.map(\.stop)
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
}
