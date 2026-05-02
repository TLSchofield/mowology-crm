//
//  VisitDetailViewModel.swift
//  MowologyCRM
//

import Foundation
import Combine
import CoreLocation
import UIKit
import UserNotifications

// MARK: - Response models (file-private)

private struct AutoStartedPayload: Decodable {
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

private struct LocationPingResponse: Decodable {
    let success: Bool
    let skipped: Bool?
    let autoStarted: AutoStartedPayload?

    enum CodingKeys: String, CodingKey {
        case success
        case skipped
        case autoStarted = "auto_started"
    }
}

// MARK: - VisitDetailViewModel

@MainActor
final class VisitDetailViewModel: ObservableObject {

    // MARK: - Published State

    /// Mutable visit statuses keyed by visitId — overrides the original stop.visits status.
    @Published var visitStatuses: [Int: String] = [:]
    @Published var activeTimerVisitId: Int?
    @Published var elapsedSeconds: Int = 0
    @Published var isLoading: Bool = false
    @Published var errorMessage: String?
    @Published var autoClockInNotice: String?

    // MARK: - Private

    private let stop: Stop
    private let apiClient: APIClient
    private let locationManager: LocationManager
    private let transitionQueue  = TransitionQueue()
    private let haptic           = UINotificationFeedbackGenerator()
    private var tickTimer:         AnyCancellable?
    private var pingTask:          Task<Void, Never>?
    private var connectivityObserver: NSObjectProtocol?

    // UserDefaults keys shared with MowologyCRMApp BGTask handler
    private let kJobTimerActive = "mw.jobTimerActive"
    private let kLastPingAt     = "mw.lastPingAt"

    // MARK: - Init

    init(stop: Stop, apiClient: APIClient) {
        self.stop            = stop
        self.apiClient       = apiClient
        self.locationManager = LocationManager()

        for visit in stop.visits {
            visitStatuses[visit.visitId] = visit.visitStatus
        }
        locationManager.requestWhenInUsePermission()

        // On reconnect: drain queued GPS pings AND retry any failed job transitions.
        connectivityObserver = NotificationCenter.default.addObserver(
            forName: .mwPingQueueOnline,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            guard let self else { return }
            Task {
                await PingQueue.shared.drain(using: self.apiClient)
                await self.drainPendingTransitions()
            }
        }
    }

    deinit {
        if let obs = connectivityObserver {
            NotificationCenter.default.removeObserver(obs)
        }
    }

    // MARK: - Job Lifecycle

    func startJob(visitId: Int) async {
        isLoading    = true
        errorMessage = nil

        locationManager.resetSessionMetrics()

        // Give ArrivalMonitor the job-site coordinate so it can detect arrival.
        if let lat = stop.latitude, let lon = stop.longitude {
            ArrivalMonitor.shared.configure(
                site: CLLocationCoordinate2D(latitude: lat, longitude: lon)
            )
        }

        let (lat, lng) = await safeLocation()
        let idempKey   = transitionQueue.prepare(visitId: visitId, action: "start", lat: lat, lng: lng)

        var body: [String: Any] = ["action": "start", "visit_id": visitId]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }

        do {
            let response: TimerStartResponse = try await withExponentialBackoff {
                try await self.apiClient.request(
                    .scheduleTimer,
                    body: body,
                    extraHeaders: ["Idempotency-Key": idempKey]
                )
            }

            if response.success {
                transitionQueue.confirm(visitId: visitId, action: "start")
                haptic.notificationOccurred(.success)
                visitStatuses[visitId] = "in_progress"
                activeTimerVisitId     = visitId
                elapsedSeconds         = 0
                startTicking()
                ArrivalMonitor.shared.jobStarted()

                if response.autoClockIn == true {
                    autoClockInNotice = "You've been automatically clocked in."
                }

                // Signal the BGAppRefreshTask that a job is active.
                UserDefaults.standard.set(true,                          forKey: kJobTimerActive)
                UserDefaults.standard.set(Date().timeIntervalSince1970,  forKey: kLastPingAt)

                locationManager.requestAlwaysPermission()
                locationManager.startBackgroundTracking()
                startPingLoop(visitId: visitId)
            } else {
                setError(response.message ?? "Failed to start job.")
            }
        } catch {
            setError(apiErrorMessage(error))
        }

        isLoading = false
    }

    func completeJob(visitId: Int) async {
        isLoading    = true
        errorMessage = nil

        let (lat, lng) = await safeLocation()
        let idempKey   = transitionQueue.prepare(visitId: visitId, action: "stop", lat: lat, lng: lng)

        // Finalise dwell + accuracy data before the POST.
        ArrivalMonitor.shared.jobCompleted()
        let accountability = ArrivalMonitor.shared.metrics?.serverPayload ?? [:]

        var body: [String: Any] = [
            "action":         "stop",
            "visit_id":       visitId,
            "complete_visit": true,
            "accuracy_badge": locationManager.accuracyBadge.rawValue
        ]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }
        accountability.forEach { body[$0.key] = $0.value }

        do {
            let response: TimerStopResponse = try await withExponentialBackoff {
                try await self.apiClient.request(
                    .scheduleTimer,
                    body: body,
                    extraHeaders: ["Idempotency-Key": idempKey]
                )
            }

            if response.success {
                transitionQueue.confirm(visitId: visitId, action: "stop")
                haptic.notificationOccurred(.success)
                visitStatuses[visitId] = "completed"

                if activeTimerVisitId == visitId {
                    activeTimerVisitId = nil
                    stopTicking()
                }
                stopPingLoop()
                locationManager.stopBackgroundTracking()
                UserDefaults.standard.set(false, forKey: kJobTimerActive)
            } else {
                setError(response.message ?? "Failed to complete job.")
            }
        } catch {
            setError(apiErrorMessage(error))
        }

        isLoading = false
    }

    // MARK: - Exponential Backoff

    /// Retry up to 4 times with delays 2 s → 4 s → 8 s → 30 s (cap).
    /// Only retries on network errors; server errors (4xx/5xx) surface immediately.
    private func withExponentialBackoff<T>(
        maxAttempts: Int = 4,
        _ operation: () async throws -> T
    ) async throws -> T {
        var delay: TimeInterval = 2
        var lastError: Error?

        for attempt in 1...maxAttempts {
            do {
                return try await operation()
            } catch let err as APIError {
                if case .networkError = err {
                    lastError = err
                    if attempt < maxAttempts {
                        try? await Task.sleep(for: .seconds(delay))
                        delay = min(delay * 2, 30)
                    }
                } else {
                    throw err   // don't retry auth/server errors
                }
            } catch {
                throw error
            }
        }

        throw lastError ?? APIError.networkError(URLError(.timedOut))
    }

    // MARK: - Pending Transition Drain (reconnect path)

    /// Re-submit any job transitions that were persisted but never confirmed.
    /// Called automatically when PingQueue posts `.mwPingQueueOnline`.
    private func drainPendingTransitions() async {
        guard !isLoading else { return }
        for visit in stop.visits {
            let vid    = visit.visitId
            let status = visitStatuses[vid] ?? visit.visitStatus
            if transitionQueue.hasPending(visitId: vid, action: "start"),
               status.lowercased() == "scheduled" {
                await startJob(visitId: vid)
            }
            if transitionQueue.hasPending(visitId: vid, action: "stop"),
               status.lowercased() == "in_progress" {
                await completeJob(visitId: vid)
            }
        }
    }

    // MARK: - Live Job Timer

    private func startTicking() {
        stopTicking()
        tickTimer = Timer.publish(every: 1, on: .main, in: .common)
            .autoconnect()
            .sink { [weak self] _ in self?.elapsedSeconds += 1 }
    }

    private func stopTicking() {
        tickTimer?.cancel()
        tickTimer = nil
    }

    // MARK: - Adaptive GPS Ping Loop

    /// Interval adapts every iteration from the current motion-activity state:
    ///   walking/running → 20–30 s | automotive → 45 s | unknown → 60 s | stationary → 120 s
    private func startPingLoop(visitId: Int) {
        stopPingLoop()
        pingTask = Task { [weak self] in
            while !Task.isCancelled {
                guard let self else { break }
                let interval = self.locationManager.currentActivity.pingInterval
                try? await Task.sleep(for: .seconds(interval))
                guard !Task.isCancelled else { break }
                await self.sendLocationPing(visitId: visitId)
            }
        }
    }

    private func stopPingLoop() {
        pingTask?.cancel()
        pingTask = nil
    }

    private func sendLocationPing(visitId: Int) async {
        guard let loc = locationManager.lastLocation else { return }

        let lat      = loc.coordinate.latitude
        let lng      = loc.coordinate.longitude
        let accuracy = loc.horizontalAccuracy >= 0 ? loc.horizontalAccuracy : 50.0

        // Route replay — one point per minute stored locally.
        RouteStore.shared.record(visitId: visitId, location: loc)

        // Arrival / dwell monitoring.
        ArrivalMonitor.shared.observe(fix: loc)

        // Timestamp for BGTask inactivity detection.
        UserDefaults.standard.set(Date().timeIntervalSince1970, forKey: kLastPingAt)

        var body: [String: Any] = [
            "lat":      lat,
            "lng":      lng,
            "accuracy": accuracy,
            "visit_id": visitId,
            "activity": locationManager.currentActivity.rawValue
        ]

        do {
            let response: LocationPingResponse = try await apiClient.request(
                .scheduleLocation, body: body
            )
            if let autoStart = response.autoStarted {
                haptic.notificationOccurred(.success)
                let vid = autoStart.visitId
                visitStatuses[vid] = "in_progress"
                activeTimerVisitId  = vid
                elapsedSeconds      = 0
                startTicking()
                if autoStart.clockInCreated == true {
                    autoClockInNotice = "You've been automatically clocked in."
                }
                UserDefaults.standard.set(true, forKey: kJobTimerActive)
                locationManager.requestAlwaysPermission()
                locationManager.startBackgroundTracking()
                startPingLoop(visitId: vid)
            }
        } catch let error as APIError {
            if case .networkError = error {
                PingQueue.shared.store(lat: lat, lng: lng, accuracy: accuracy, visitId: visitId)
            }
        } catch {
            PingQueue.shared.store(lat: lat, lng: lng, accuracy: accuracy, visitId: visitId)
        }
    }

    // MARK: - Helpers

    func status(for visit: Visit) -> String {
        visitStatuses[visit.visitId] ?? visit.visitStatus
    }

    var elapsedFormatted: String {
        let h = elapsedSeconds / 3600
        let m = (elapsedSeconds % 3600) / 60
        let s = elapsedSeconds % 60
        return h > 0
            ? String(format: "%d:%02d:%02d", h, m, s)
            : String(format: "%02d:%02d", m, s)
    }

    private func safeLocation() async -> (Double?, Double?) {
        guard locationManager.canUseLocation else { return (nil, nil) }
        if let loc = try? await locationManager.currentLocation() {
            return (loc.coordinate.latitude, loc.coordinate.longitude)
        }
        return (nil, nil)
    }

    private func setError(_ message: String) {
        haptic.notificationOccurred(.error)
        errorMessage = message
    }

    private func apiErrorMessage(_ error: Error) -> String {
        (error as? APIError)?.localizedDescription ?? error.localizedDescription
    }
}
