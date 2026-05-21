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
    /// Last wall-clock time a ping was fired. Used as rate-limiter by the
    /// location callback and the stationary watchdog.
    private var lastPingDate: Date = .distantPast
    /// RunLoop-backed timer that covers truly stationary periods where the
    /// 10m distanceFilter suppresses CLLocation updates entirely.
    /// Timer fires every 60s but only sends a ping when the rate-limiter
    /// is due — avoids double-pinging during normal movement.
    private var stationaryWatchdog: Timer?
    private(set) var activeVisitId: Int? = nil
    private var connectivityObserver: NSObjectProtocol?

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

    // MARK: - Permission

    private static let kPermissionAsked = "mw.locationPermissionAsked"

    /// Request Always location permission if we haven't asked before on this device.
    /// Called from MainTabView.onAppear — gives the user context before clock-in.
    func requestPermissionIfNeeded() {
        guard !UserDefaults.standard.bool(forKey: Self.kPermissionAsked) else { return }
        UserDefaults.standard.set(true, forKey: Self.kPermissionAsked)
        locationManager.requestAlwaysPermission()
    }

    // MARK: - Lifecycle

    func start(authSession: AuthSession) {
        guard !isTracking else { return }
        apiClient  = APIClient(authSession: authSession)
        isTracking = true
        UserDefaults.standard.set(true, forKey: Self.kShiftActive)
        locationManager.requestAlwaysPermission()
        locationManager.startBackgroundTracking()
        ActivityMonitor.shared.start(updating: locationManager)
        attachLocationCallback()
        startStationaryWatchdog()
    }

    func stop() {
        isTracking    = false
        activeVisitId = nil
        locationManager.onLocationFix = nil
        stationaryWatchdog?.invalidate()
        stationaryWatchdog = nil
        ActivityMonitor.shared.stop()
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

    // MARK: - Background resume (BGTask cold-launch path)

    /// Called by the BGAppRefreshTask handler when the app is cold-launched
    /// in the background (app was previously terminated by the user).
    /// Re-creates the apiClient from Keychain so sendPing() has something to
    /// send with. Seeds lastLocation from the OS cache so the ping has a position.
    /// No-op if the apiClient is already set (normal foreground/background path).
    func backgroundResume() {
        guard apiClient == nil else { return }
        let bgSession = AuthSession()          // restores JWT + user from Keychain
        guard bgSession.isAuthenticated else { return }
        apiClient = APIClient(authSession: bgSession)
        locationManager.seedFromSystemCache()
    }

    // MARK: - Single ping (used by BGTask handler)

    func sendPing() async {
        guard let loc = locationManager.lastLocation,
              let client = apiClient else { return }

        let lat      = loc.coordinate.latitude
        let lng      = loc.coordinate.longitude
        let accuracy = loc.horizontalAccuracy >= 0 ? loc.horizontalAccuracy : 50.0

        if let vid = activeVisitId {
            RouteStore.shared.record(visitId: vid, location: loc)
            ArrivalMonitor.shared.observe(fix: loc)
        }

        UserDefaults.standard.set(Date().timeIntervalSince1970, forKey: Self.kLastPingAt)

        var body: [String: Any] = [
            "lat":      lat,
            "lng":      lng,
            "accuracy": accuracy,
            "activity": locationManager.currentActivity.rawValue
        ]
        if let vid = activeVisitId { body["visit_id"] = vid }

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
                PingQueue.shared.store(lat: lat, lng: lng, accuracy: accuracy, visitId: activeVisitId)
            }
        } catch { }
    }

    // MARK: - Private

    /// Wire the location-delegate callback so every valid GPS fix can trigger
    /// a ping. The rate-limiter (lastPingDate + pingInterval) prevents flooding.
    /// This replaces the Task.sleep loop: the OS always delivers CLLocation
    /// events via the delegate even when the app is background-suspended,
    /// whereas a Task.sleep can be cancelled if iOS freezes the process.
    private func attachLocationCallback() {
        locationManager.onLocationFix = { [weak self] _ in
            guard let self else { return }
            let interval = self.locationManager.currentActivity.pingInterval
            guard Date().timeIntervalSince(self.lastPingDate) >= interval else { return }
            Task { @MainActor [weak self] in
                await self?.firePing()
            }
        }
    }

    /// RunLoop timer that covers true stationary periods: when the crew is
    /// standing still, distanceFilter=10m can suppress CLLocation callbacks
    /// for minutes at a time. The watchdog fires every 60s and sends a ping
    /// only if the rate-limiter is overdue (no double-pinging during movement).
    private func startStationaryWatchdog() {
        stationaryWatchdog?.invalidate()
        stationaryWatchdog = Timer.scheduledTimer(withTimeInterval: 60, repeats: true) { [weak self] _ in
            Task { @MainActor [weak self] in
                guard let self, self.isTracking else { return }
                guard Date().timeIntervalSince(self.lastPingDate) >= 60 else { return }
                await self.firePing()
            }
        }
    }

    private func firePing() async {
        lastPingDate = Date()
        await sendPing()
    }
}
