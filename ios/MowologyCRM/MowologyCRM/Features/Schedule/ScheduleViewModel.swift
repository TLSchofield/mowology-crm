//
//  ScheduleViewModel.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation
import Combine
import CoreLocation

// MARK: - API Response Types

private struct DayResponse: Decodable {
    let success: Bool
    let date: String
    let role: String
    let stopCount: Int
    let stops: [Stop]
    let extrasRatePer5Min: Double?
    let scope: String?
    let canSwitchScope: Bool?
    let serviceTypes: [ScheduleJobType]?
    /// Quote visits for the day (2026-10-15); an older server sends none.
    let appointments: [Appointment]?

    enum CodingKeys: String, CodingKey {
        case success
        case date
        case role
        case stopCount = "stop_count"
        case stops
        case extrasRatePer5Min = "extras_rate_per_5min"
        case scope
        case canSwitchScope = "can_switch_scope"
        case serviceTypes = "service_types"
        case appointments
    }
}

/// A job type the schedule can be narrowed to (the company's service types).
struct ScheduleJobType: Decodable, Hashable, Identifiable {
    let value: String
    let label: String
    var id: String { value }
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
    /// Quote visits on the selected day — their own cards above the stops, never stops.
    @Published private(set) var appointments: [Appointment] = []
    private var apptCache: [String: [Appointment]] = [:]

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

    // Whose jobs + job type (CrewViewScope, 2026-10-14). Remembered on the phone; the server decides
    // what is allowed (crew see only their own unless the company allows all).
    @Published private(set) var scope: String = UserDefaults.standard.string(forKey: "mw_sched_scope") ?? "all"
    @Published private(set) var jobType: String = UserDefaults.standard.string(forKey: "mw_sched_service") ?? ""
    @Published private(set) var canSwitchScope: Bool = false
    @Published private(set) var jobTypes: [ScheduleJobType] = []

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

        // A proximity auto-start changes a stop's status server-side — pull it
        // now rather than waiting for the next poll tick.
        autoStartSink = GPSTrackingService.shared.$autoStartedPayload
            .compactMap { $0 }
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in
                Task { await self?.silentRefresh() }
            }
        // The pull-forward sheet moved a visit to today / added one and started it.
        scheduleChangedSink = NotificationCenter.default.publisher(for: .mwScheduleChanged)
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in
                Task { await self?.silentRefresh() }
            }
    }

    private var autoStartSink: AnyCancellable?
    private var scheduleChangedSink: AnyCancellable?

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
        updateUserLocation()
        await loadSchedule(for: selectedDate, reloadWeek: true)
    }

    /// Changes the selected date and loads its stops. If the week changes,
    /// also refreshes the week strip.
    func selectDate(_ date: Date) async {
        let weekChanged = !isSameDay(mondayOf(week: selectedDate), mondayOf(week: date))
        selectedDate = date
        selectedOnDay = Calendar.current.startOfDay(for: .now)
        await loadSchedule(for: date, reloadWeek: weekChanged)
    }

    /// The calendar day the current selection was made on.
    private var selectedOnDay = Calendar.current.startOfDay(for: .now)

    /// Foreground: a phone left on yesterday's schedule overnight (the default selection is
    /// "now" at launch) opens on today. A past day picked today is left alone.
    func selectTodayIfDayRolledOver() async {
        let today = Calendar.current.startOfDay(for: .now)
        guard today > selectedOnDay else { return }
        if selectedDate < today {
            await selectDate(.now)
        } else {
            selectedOnDay = today
        }
    }

    /// A stop just moved between two dates — drop both cached days (memory and
    /// disk-backed load path) and reload the day on screen plus the week strip.
    func stopMoved(from: String, to: String) async {
        stopCache.removeValue(forKey: from)
        stopCache.removeValue(forKey: to)
        stopCache.removeValue(forKey: isoDateString(from: selectedDate))
        await loadSchedule(for: selectedDate, reloadWeek: true)
    }

    /// Builds a client for sheets launched from the schedule (e.g. Move Stop).
    var client: APIClient { apiClient }

    /// Invalidates the cache for the current day and reloads.
    func invalidateAndRefresh() async {
        stopCache.removeValue(forKey: isoDateString(from: selectedDate))
        await refresh()
    }

    /// Resolves a tapped notification to the stop it is about: jumps to the stop's
    /// date (today when the sender didn't say), reloads fresh, and returns the
    /// matching stop — or nil when it isn't on that day's schedule any more.
    func resolve(_ route: NotificationRoute) async -> Stop? {
        let target = route.date.flatMap { isoFormatter.date(from: $0) } ?? .now
        stopCache.removeValue(forKey: isoDateString(from: target))
        await selectDate(target)

        return stops.first { stop in
            if let stopId = route.stopId, stop.stopId == stopId { return true }
            if let visitId = route.visitId {
                return stop.visits.contains { $0.visitId == visitId }
            }
            return false
        }
    }

    /// Public wrapper so a foreground push can trigger the same no-spinner refresh the poll uses.
    func refreshSilently() async { await silentRefresh() }

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
            let response: DayResponse = try await apiClient.request(.scheduleDay(date: dateString, scope: scope, service: jobType))
            guard !Task.isCancelled else { return }
            // The day may have changed while this poll was out: never show Monday's stops under Tuesday.
            guard dateString == isoDateString(from: selectedDate) else { return }
            if response.stops != rawStops {
                rawStops = response.stops
            }
            applyAppointments(response.appointments, forDate: dateString)
            cacheStops(response.stops, forDate: dateString)
            ScheduleCache.shared.save(response.stops, forDate: diskKey(dateString))
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

    /// What the server says this person may do (an older server sends none of it). The scope it
    /// actually applied is kept, so a refused "All crews" isn't asked for again after a restart.
    private func applyScope(_ r: DayResponse) {
        canSwitchScope = r.canSwitchScope ?? false
        jobTypes = r.serviceTypes ?? []
        if let s = r.scope, s != scope {
            scope = s
            UserDefaults.standard.set(s, forKey: "mw_sched_scope")
        }
    }

    /// The offline copy is kept per filter (Codex review 2026-10-14): an "All crews" copy must never
    /// be shown as "Mine", or after the company limits crew to their own jobs.
    private func diskKey(_ dateString: String) -> String {
        "\(dateString)_\(scope)_\(jobType.isEmpty ? "any" : jobType)"
    }

    /// Mine / All crews.
    func setScope(_ value: String) async {
        guard value != scope else { return }
        scope = value
        UserDefaults.standard.set(value, forKey: "mw_sched_scope")
        await reloadForFilter()
    }

    /// One job type, or "" for all.
    func setJobType(_ value: String) async {
        guard value != jobType else { return }
        jobType = value
        UserDefaults.standard.set(value, forKey: "mw_sched_service")
        await reloadForFilter()
    }

    /// A filter change: what was cached was for the old filter.
    private func reloadForFilter() async {
        stopCache.removeAll()
        await refresh()
    }

    /// Fetches the week summary strip (7 ScheduleDay objects).
    private func loadWeek(for date: Date) async {
        let startString = isoDateString(from: mondayOf(week: date))
        do {
            let response: WeekResponse = try await apiClient.request(
                .scheduleWeek(start: startString, scope: scope, service: jobType)
            )
            guard !Task.isCancelled else { return }
            isOffline = false
            weekDays  = response.days
        } catch let err as APIError {
            guard !Task.isCancelled else { return }
            if case .networkError = err {
                isOffline = true   // week strip is decorative — fail silently
            } else {
                errorMessage = ErrorDetails.describe(err)
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
            appointments = apptCache[dateString] ?? []
            lastFetched = .now
            return
        }

        do {
            let response: DayResponse = try await apiClient.request(
                .scheduleDay(date: dateString, scope: scope, service: jobType)
            )
            guard !Task.isCancelled else { return }
            AppExtrasConfig.shared.update(ratePer5Min: response.extrasRatePer5Min)
            applyScope(response)
            let fetched = response.stops
            applyAppointments(response.appointments, forDate: dateString)
            cacheStops(fetched, forDate: dateString)
            ScheduleCache.shared.save(fetched, forDate: diskKey(dateString))
            isOffline   = false
            rawStops    = fetched
            lastFetched = .now
        } catch let err as APIError {
            guard !Task.isCancelled else { return }
            if case .networkError = err {
                // Offline — try disk cache before showing an error.
                appointments = apptCache[dateString] ?? ScheduleCache.shared.loadAppointments(forDate: diskKey(dateString)) ?? []
                if let diskCached = ScheduleCache.shared.load(forDate: diskKey(dateString)) {
                    cacheStops(diskCached, forDate: dateString)
                    rawStops  = diskCached
                    isOffline = true
                } else {
                    isOffline    = true
                    errorMessage = "No signal and no cached schedule for this date."
                    rawStops     = []
                }
            } else {
                errorMessage = ErrorDetails.describe(err)
                rawStops     = []
                appointments = []
            }
        } catch {
            guard !Task.isCancelled else { return }
            errorMessage = error.localizedDescription
            rawStops     = []
            appointments = []
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
            guard let fix = await Self.acquireFix(from: lm), !Task.isCancelled else { return }
            self.userLocation = fix
            self.stops = Self.sortedForDisplay(self.rawStops, from: fix)
        }
    }

    /// A cold `requestLocation()` can take 10s+ indoors and may need more than
    /// one attempt, so race each attempt against a timeout and retry a few
    /// times before giving up. Returns nil if no fix could be obtained.
    private static func acquireFix(from lm: LocationManager,
                                   attempts: Int = 3,
                                   timeoutSeconds: UInt64 = 15) async -> CLLocation? {
        for attempt in 0..<attempts {
            if Task.isCancelled { return nil }
            let fix: CLLocation? = await withTaskGroup(of: CLLocation?.self) { group in
                group.addTask { @MainActor in try? await lm.currentLocation() }
                group.addTask {
                    try? await Task.sleep(nanoseconds: timeoutSeconds * 1_000_000_000)
                    return nil
                }
                let first = await group.next() ?? nil
                group.cancelAll()
                return first
            }
            if let fix { return fix }
            if attempt < attempts - 1 {
                try? await Task.sleep(nanoseconds: 2_000_000_000)
            }
        }
        return nil
    }

    /// An in-progress stop is pinned to the top only while the device is actually near it.
    /// A visit auto-started at clock-in stays "in progress" all day once the crew drives
    /// off, and pinning it hid the stop they were parked beside.
    static let inProgressPinRadius: CLLocationDistance = 400

    /// Orders stops for the list:
    ///   1. in-progress stops the device is near (the crew is actually there)
    ///   2. remaining stops, nearest to the device first — a far-away in-progress stop
    ///      competes here on distance like any other
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
            if stop.isResolved        { tier = 3 }
            else if stop.isInProgress, (d ?? 0) <= Self.inProgressPinRadius { tier = 0 }
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

    private func applyAppointments(_ fetched: [Appointment]?, forDate dateString: String) {
        let list = fetched ?? []
        apptCache[dateString] = list
        ScheduleCache.shared.saveAppointments(list, forDate: diskKey(dateString))   // kept for offline (Codex)
        if dateString == isoDateString(from: selectedDate), list != appointments { appointments = list }
    }

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
