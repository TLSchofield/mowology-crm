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
    private(set) var activeVisitId: Int? = nil
    private var connectivityObserver: NSObjectProtocol?

    private init() {
        connectivityObserver = NotificationCenter.default.addObserver(
            forName: .mwPingQueueOnline,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            guard let self, let client = self.apiClient else { return }
            Task { await PingQueue.shared.drain(using: client) }
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
        startPingLoop()
    }

    func stop() {
        isTracking    = false
        activeVisitId = nil
        pingTask?.cancel()
        pingTask = nil
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

    private func startPingLoop() {
        pingTask?.cancel()
        pingTask = Task { [weak self] in
            while !Task.isCancelled {
                guard let self else { break }
                let interval = locationManager.currentActivity.pingInterval
                try? await Task.sleep(for: .seconds(interval))
                guard !Task.isCancelled else { break }
                await self.sendPing()
            }
        }
    }
}
