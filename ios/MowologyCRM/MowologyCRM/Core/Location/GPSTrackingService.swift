//
//  GPSTrackingService.swift
//  MowologyCRM
//
//  Always-on GPS service. Starts on clock-in, runs until clock-out.
//  Stamps visit_id on pings when a job timer is active; nil between jobs.
//

import Foundation
import Combine

// MARK: - Response models

struct LocationPingResponse: Decodable {
    let success: Bool
    let skipped: Bool?
    let autoStarted: AutoStartedPayload?

    enum CodingKeys: String, CodingKey {
        case success
        case skipped
        case autoStarted = "auto_started"
    }
}

struct AutoStartedPayload: Decodable {
    let visitId: Int
    let jobTitle: String?
    let propertyAddress: String?
    let distanceMeters: Int?
    let clockInCreated: Bool?

    enum CodingKeys: String, CodingKey {
        case visitId         = "visit_id"
        case jobTitle        = "job_title"
        case propertyAddress = "property_address"
        case distanceMeters  = "distance_meters"
        case clockInCreated  = "clock_in_created"
    }
}

// MARK: - GPSTrackingService

/// Singleton that drives always-on location tracking for the duration of a crew shift.
///
/// Call sequence:
///   start(authSession:)   ← clock-in (or loadStatus finds a clocked-in state)
///   setActiveVisit(_:)    ← job starts/stops — stamps visit_id on pings
///   stop()                ← clock-out
@MainActor
final class GPSTrackingService: ObservableObject {

    static let shared = GPSTrackingService()

    // MARK: - Published

    @Published private(set) var isTracking = false

    /// Set when a proximity auto-start fires. VisitDetailViewModel observes this
    /// to update its visitStatuses without owning the ping loop itself.
    @Published private(set) var autoStartedPayload: AutoStartedPayload? = nil

    // MARK: - Internal (used by VisitDetailViewModel for location reads)

    let locationManager = LocationManager()

    // MARK: - UserDefaults keys (shared with MowologyCRMApp BGTask handler)

    static let kShiftActive = "mw.shiftActive"
    static let kLastPingAt  = "mw.lastPingAt"

    // MARK: - Private

    private var apiClient: APIClient?
    private var pingTask:  Task<Void, Never>?
    private var heartbeatTimer: DispatchSourceTimer?
    private(set) var activeVisitId: Int? = nil
    private var connectivityObserver: NSObjectProtocol?

    /// Heartbeat tick. Fires roughly every 60 s while clocked in; on each
    /// tick we check whether a ping is overdue and, if so, force a fresh
    /// location fix via `requestImmediateFix()`. The fix delivery in turn
    /// triggers `onAcceptedFix` which calls `sendPingIfDue`.
    private static let heartbeatInterval: TimeInterval = 60

    private init() {
        connectivityObserver = NotificationCenter.default.addObserver(
            forName: .mwPingQueueOnline,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                guard let self, let client = self.apiClient else { return }
                await PingQueue.shared.drain(using: client)
            }
        }
    }

    // MARK: - Lifecycle

    func start(authSession: AuthSession) {
        guard !isTracking else { return }
        apiClient  = APIClient(authSession: authSession)
        isTracking = true
        UserDefaults.standard.set(true, forKey: Self.kShiftActive)
        locationManager.requestAlwaysPermission()
        // Delegate-driven pings: whenever CoreLocation gives us a usable
        // fix, see if we're due to post. This is the primary cadence source
        // when the app is backgrounded — Task.sleep below is a fallback.
        locationManager.onAcceptedFix = { [weak self] _ in
            guard let self else { return }
            Task { @MainActor in
                await self.sendPingIfDue(minInterval: self.currentInterval())
            }
        }
        locationManager.startBackgroundTracking()
        startPingLoop()
        startHeartbeat()
    }

    func stop() {
        isTracking    = false
        activeVisitId = nil
        pingTask?.cancel()
        pingTask = nil
        heartbeatTimer?.cancel()
        heartbeatTimer = nil
        locationManager.onAcceptedFix = nil
        locationManager.stopBackgroundTracking()
        locationManager.resetSessionMetrics()
        UserDefaults.standard.set(false, forKey: Self.kShiftActive)
    }

    /// Call when a job timer starts (visitId) or stops (nil).
    func setActiveVisit(_ visitId: Int?) {
        activeVisitId = visitId
        if visitId != nil {
            locationManager.resetSessionMetrics()
        }
    }

    // MARK: - Single ping (used by BGTask handler)

    func sendPing() async {
        guard let loc = locationManager.lastLocation,
              let client = apiClient else { return }

        let lat      = loc.coordinate.latitude
        let lng      = loc.coordinate.longitude
        let accuracy = loc.horizontalAccuracy >= 0 ? loc.horizontalAccuracy : 50.0
        // Capture time of the fix itself — sent to the server as
        // `client_timestamp` so chronological order is preserved even
        // when this ping ends up in the offline drain queue.
        let captureTs = loc.timestamp.timeIntervalSince1970

        if let vid = activeVisitId {
            RouteStore.shared.record(visitId: vid, location: loc)
            ArrivalMonitor.shared.observe(fix: loc)
        }

        UserDefaults.standard.set(Date().timeIntervalSince1970, forKey: Self.kLastPingAt)

        var body: [String: Any] = [
            "lat":              lat,
            "lng":              lng,
            "accuracy":         accuracy,
            "client_timestamp": captureTs,
            "activity":         locationManager.currentActivity.rawValue
        ]
        if let vid    = activeVisitId            { body["visit_id"] = vid }
        // CLLocation reports speed/course as negative when invalid.
        if loc.speed  >= 0                       { body["speed"]    = loc.speed }
        if loc.course >= 0                       { body["course"]   = loc.course }
        // altitude is always provided; may be coarse without a barometer.
        body["altitude"] = loc.altitude

        do {
            let response: LocationPingResponse = try await client.request(
                .scheduleLocation, body: body
            )
            if let payload = response.autoStarted {
                autoStartedPayload = payload
                // Reset after a tick so observers see the change even if same visitId fires twice.
                Task {
                    try? await Task.sleep(for: .milliseconds(100))
                    autoStartedPayload = nil
                }
            }
        } catch let error as APIError {
            if case .networkError = error {
                PingQueue.shared.store(
                    lat: lat, lng: lng, accuracy: accuracy,
                    visitId:      activeVisitId,
                    fixTimestamp: loc.timestamp,
                    speed:    loc.speed  >= 0 ? loc.speed  : nil,
                    course:   loc.course >= 0 ? loc.course : nil,
                    altitude: loc.altitude
                )
            }
        } catch { }
    }

    // MARK: - Cadence

    /// The minimum gap between pings right now. Pure activity-based — the
    /// table in `LocationManager.ActivityState.pingInterval` is the single
    /// source of truth (20–120 s depending on motion). Visit-state no longer
    /// gates cadence; the server stamps `visit_id` when a timer is active.
    private func currentInterval() -> TimeInterval {
        locationManager.currentActivity.pingInterval
    }

    /// Single rate-limited entry point for ping triggers (Task.sleep loop,
    /// delegate callback, and heartbeat all funnel through this). Prevents
    /// duplicate pings when multiple wake sources fire near-simultaneously.
    ///
    /// Also gates on fix freshness: if `lastLocation` is stale (older than
    /// `2 × minInterval`), we force a refresh instead of posting old data.
    /// The fresh delegate callback will re-enter this method with current
    /// coordinates.
    func sendPingIfDue(minInterval: TimeInterval) async {
        let lastPing = UserDefaults.standard.double(forKey: Self.kLastPingAt)
        let elapsed  = lastPing > 0
            ? Date().timeIntervalSince1970 - lastPing
            : .greatestFiniteMagnitude
        guard elapsed >= minInterval else { return }

        // Stale-fix guard: don't post a fix that's much older than the
        // cadence we're trying to enforce. Request a fresh one instead.
        if let fix = locationManager.lastLocation {
            let fixAge = Date().timeIntervalSince(fix.timestamp)
            if fixAge > minInterval * 2 {
                locationManager.requestImmediateFix()
                return
            }
        }

        await sendPing()
    }

    // MARK: - Private

    /// Fallback ping loop. Survives foreground reliably; gets unreliable in
    /// the background when the process loses CPU. Kept as belt-and-suspenders
    /// alongside the delegate-driven path. All triggers funnel through
    /// `sendPingIfDue` so duplicates are deduped via `lastPingAt`.
    private func startPingLoop() {
        pingTask?.cancel()
        pingTask = Task { [weak self] in
            while !Task.isCancelled {
                guard let self else { break }
                let interval = self.currentInterval()
                try? await Task.sleep(for: .seconds(interval))
                guard !Task.isCancelled else { break }
                await self.sendPingIfDue(minInterval: interval)
            }
        }
    }

    /// Stationary heartbeat. Every 60 s, if a ping is overdue, we ask
    /// CoreLocation for a fresh fix — even when the user hasn't moved.
    /// This is what catches "crew mowing for 20 min with phone in pocket"
    /// where no `didUpdateLocations` would otherwise fire.
    ///
    /// GCD timers on `.main` get CPU whenever the process does, which is
    /// often enough in background-resident state (location entitlement
    /// keeps the process alive). Not bulletproof under deep iOS sleep,
    /// but covers the dominant production failure mode.
    private func startHeartbeat() {
        heartbeatTimer?.cancel()
        let timer = DispatchSource.makeTimerSource(queue: .main)
        timer.schedule(
            deadline: .now() + Self.heartbeatInterval,
            repeating: Self.heartbeatInterval,
            leeway: .seconds(5)
        )
        timer.setEventHandler { [weak self] in
            self?.heartbeatTick()
        }
        timer.resume()
        heartbeatTimer = timer
    }

    private func heartbeatTick() {
        guard isTracking else { return }
        let lastPing = UserDefaults.standard.double(forKey: Self.kLastPingAt)
        let elapsed  = lastPing > 0
            ? Date().timeIntervalSince1970 - lastPing
            : .greatestFiniteMagnitude
        guard elapsed >= currentInterval() else { return }
        // Force a fresh fix; the delegate's onAcceptedFix will fire and
        // route through sendPingIfDue. If the fix is rejected by the
        // quality filter we'll try again on the next tick (60 s).
        locationManager.requestImmediateFix()
    }
}
