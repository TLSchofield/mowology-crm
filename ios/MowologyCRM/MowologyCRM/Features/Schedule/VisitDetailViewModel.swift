//
//  VisitDetailViewModel.swift
//  MowologyCRM
//

import Foundation
import Combine
import CoreLocation
import UIKit

// MARK: - Response models (used only inside this file)

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

    /// Which visitId currently has an active running timer (nil if none).
    @Published var activeTimerVisitId: Int?

    /// Elapsed seconds for the currently running timer (ticks every second).
    @Published var elapsedSeconds: Int = 0

    @Published var isLoading: Bool = false
    @Published var errorMessage: String?
    /// Set when the server auto-clocked the user in on job start.
    @Published var autoClockInNotice: String?

    // MARK: - Private

    private let apiClient: APIClient
    private let locationManager: LocationManager
    private let transitionQueue = TransitionQueue()
    private let haptic = UINotificationFeedbackGenerator()
    private var tickTimer: AnyCancellable?
    private var pingTask: Task<Void, Never>?
    private var connectivityObserver: NSObjectProtocol?

    // MARK: - Init

    init(stop: Stop, apiClient: APIClient) {
        self.apiClient       = apiClient
        self.locationManager = LocationManager()
        // Seed statuses from the stop's current visit data.
        for visit in stop.visits {
            visitStatuses[visit.visitId] = visit.visitStatus
        }
        // Request foreground location permission now so the prompt appears
        // while the user is looking at job details, not mid-tap.
        locationManager.requestWhenInUsePermission()

        // Drain any pings buffered while offline as soon as connectivity
        // is restored, while this view (and its apiClient) are still alive.
        connectivityObserver = NotificationCenter.default.addObserver(
            forName: .mwPingQueueOnline,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            guard let self else { return }
            Task { await PingQueue.shared.drain(using: self.apiClient) }
        }
    }

    deinit {
        if let obs = connectivityObserver {
            NotificationCenter.default.removeObserver(obs)
        }
    }

    // MARK: - Public API

    func startJob(visitId: Int) async {
        isLoading    = true
        errorMessage = nil

        // Get current location — non-fatal if unavailable.
        let (lat, lng) = await safeLocation()

        let idempKey = transitionQueue.prepare(visitId: visitId, action: "start", lat: lat, lng: lng)

        var body: [String: Any] = ["action": "start", "visit_id": visitId]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }

        do {
            let response: TimerStartResponse = try await apiClient.request(
                .scheduleTimer,
                body: body,
                extraHeaders: ["Idempotency-Key": idempKey]
            )
            if response.success {
                transitionQueue.confirm(visitId: visitId, action: "start")
                haptic.notificationOccurred(.success)
                visitStatuses[visitId] = "in_progress"
                activeTimerVisitId     = visitId
                elapsedSeconds         = 0
                startTicking()
                if response.autoClockIn == true {
                    autoClockInNotice = "You've been automatically clocked in."
                }
                // Upgrade to "always" permission for background pings, then start.
                locationManager.requestAlwaysPermission()
                locationManager.startBackgroundTracking()
                startPingLoop(visitId: visitId)
            } else {
                setError(response.message ?? "Failed to start job.")
            }
        } catch {
            // Key stays in SwiftData — next retry reuses the same UUID so the
            // server deduplicates even if the first request actually succeeded.
            setError(apiErrorMessage(error))
        }

        isLoading = false
    }

    func completeJob(visitId: Int) async {
        isLoading    = true
        errorMessage = nil

        let (lat, lng) = await safeLocation()

        let idempKey = transitionQueue.prepare(visitId: visitId, action: "stop", lat: lat, lng: lng)

        var body: [String: Any] = [
            "action": "stop",
            "visit_id": visitId,
            "complete_visit": true
        ]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }

        do {
            let response: TimerStopResponse = try await apiClient.request(
                .scheduleTimer,
                body: body,
                extraHeaders: ["Idempotency-Key": idempKey]
            )
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
            } else {
                setError(response.message ?? "Failed to complete job.")
            }
        } catch {
            setError(apiErrorMessage(error))
        }

        isLoading = false
    }

    // MARK: - Live Job Timer

    private func startTicking() {
        stopTicking()
        tickTimer = Timer.publish(every: 1, on: .main, in: .common)
            .autoconnect()
            .sink { [weak self] _ in
                self?.elapsedSeconds += 1
            }
    }

    private func stopTicking() {
        tickTimer?.cancel()
        tickTimer = nil
    }

    // MARK: - Background GPS Ping Loop (30-second intervals)

    private func startPingLoop(visitId: Int) {
        stopPingLoop()
        pingTask = Task { [weak self] in
            while !Task.isCancelled {
                // Wait first — the job-start call already captured coords.
                try? await Task.sleep(for: .seconds(30))
                guard !Task.isCancelled else { break }
                await self?.sendLocationPing(visitId: visitId)
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

        var body: [String: Any] = ["lat": lat, "lng": lng, "accuracy": accuracy, "visit_id": visitId]

        do {
            let response: LocationPingResponse = try await apiClient.request(.scheduleLocation, body: body)

            // Server auto-started a job via proximity — update local state to match.
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
            }
        } catch let error as APIError {
            // Network failures go to the offline queue — not shown to the user.
            // Server errors (4xx/5xx) are silently dropped (no retry benefit).
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
        if h > 0 {
            return String(format: "%d:%02d:%02d", h, m, s)
        }
        return String(format: "%02d:%02d", m, s)
    }

    // Returns (lat, lng) tuple — both nil if location is unavailable.
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
        if let apiError = error as? APIError {
            return apiError.localizedDescription
        }
        return error.localizedDescription
    }
}
