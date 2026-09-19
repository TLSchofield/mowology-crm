//
//  GPSTrackingService.swift
//  MowologyCRM
//
//  Always-on GPS service. Starts on clock-in, runs until clock-out.
//  Stamps visit_id on pings when a job timer is active; nil between jobs.
//

import Foundation
import Combine
import CoreLocation
import UserNotifications

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

    // MARK: - Lifecycle

    func start(authSession: AuthSession) {
        guard !isTracking else { return }
        apiClient  = APIClient(authSession: authSession)
        isTracking = true
        UserDefaults.standard.set(true, forKey: Self.kShiftActive)
        locationManager.requestAlwaysPermission()
        locationManager.startBackgroundTracking()
        // Post a ping on every accepted location update (throttled). This fires
        // in the background too, so tracking no longer requires the app to be
        // foreground; the timer loop below is a stationary fallback.
        locationManager.onAcceptedFix = { [weak self] _ in
            Task { @MainActor in await self?.sendPingIfDue() }
        }
        startPingLoop()
    }

    func stop() {
        isTracking    = false
        activeVisitId = nil
        pingTask?.cancel()
        pingTask = nil
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

    // MARK: - Proximity auto-start

    /// The server started this visit's timer because a ping landed on the job
    /// site. Bring local state in line: stamp pings with the visit, begin the
    /// accountability metrics, and tell the crew — their phone is usually in a
    /// pocket, so without an alert nobody knows a timer is running.
    private func handleAutoStart(_ payload: AutoStartedPayload) {
        let today = Self.isoDay.string(from: Date())
        if let stop = ScheduleCache.shared.load(forDate: today)?
            .first(where: { $0.visits.contains { $0.visitId == payload.visitId } }),
           let lat = stop.latitude, let lng = stop.longitude {
            ArrivalMonitor.shared.configure(site: CLLocationCoordinate2D(latitude: lat, longitude: lng))
        }
        setActiveVisit(payload.visitId)
        if let fix = locationManager.lastLocation { ArrivalMonitor.shared.observe(fix: fix) }
        ArrivalMonitor.shared.jobStarted()

        let content = UNMutableNotificationContent()
        content.title = "Job started automatically"
        let place = payload.propertyAddress ?? payload.jobTitle ?? "the job site"
        content.body  = payload.clockInCreated == true
            ? "You arrived at \(place). You've been clocked in and the job timer is running."
            : "You arrived at \(place). The job timer is running."
        content.sound    = .default
        content.userInfo = ["type": "visit_auto_started", "visit_id": payload.visitId]

        let request = UNNotificationRequest(
            identifier: "mw.auto-start.\(payload.visitId)",
            content:    content,
            trigger:    nil
        )
        UNUserNotificationCenter.current().add(request)
    }

    private static let isoDay: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_US_POSIX")
        return f
    }()

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
                handleAutoStart(payload)
                autoStartedPayload = payload
                // Reset after a tick so observers see the change even if same visitId fires twice.
                Task {
                    try? await Task.sleep(for: .milliseconds(100))
                    autoStartedPayload = nil
                }
            }
            // Server is reachable — flush any pings queued during the outage.
            if PingQueue.shared.pendingCount > 0 {
                await PingQueue.shared.drain(using: client)
            }
        } catch let error as APIError {
            if case .networkError = error {
                PingQueue.shared.store(lat: lat, lng: lng, accuracy: accuracy, visitId: activeVisitId)
            }
        } catch { }
    }

    // MARK: - Private

    private func startPingLoop() {
        pingTask?.cancel()
        pingTask = Task { [weak self] in
            while !Task.isCancelled {
                guard let self else { break }
                let interval = locationManager.currentActivity.pingInterval
                try? await Task.sleep(for: .seconds(interval))
                guard !Task.isCancelled else { break }
                // Stationary fallback: when the device isn't moving, the location
                // delegate may not fire, so the timer keeps pings alive (foreground).
                await self.sendPingIfDue()
            }
        }
    }

    /// Sends a ping only if at least the current activity's interval has elapsed
    /// since the last one. Both the timer loop and the location-update delegate
    /// call this, so the two paths can't double-send.
    private func sendPingIfDue() async {
        guard isTracking else { return }
        let interval = Double(locationManager.currentActivity.pingInterval)
        let last     = UserDefaults.standard.double(forKey: Self.kLastPingAt)
        // last == 0 → never pinged this install; send immediately.
        if last == 0 || (Date().timeIntervalSince1970 - last) >= interval - 2 {
            await sendPing()
        }
    }
}
