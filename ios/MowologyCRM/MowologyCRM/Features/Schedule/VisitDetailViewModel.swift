//
//  VisitDetailViewModel.swift
//  MowologyCRM
//

import Foundation
import Combine
import CoreLocation
import UIKit
import UserNotifications

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

    /// Local override for flag state: [visitId: isFlagged]. Wins over Visit.isFlagged once set.
    @Published private(set) var flagOverrides:  [Int: Bool] = [:]
    /// Visit IDs with an in-flight flag toggle request — drives the heart loading indicator.
    @Published private(set) var flagLoadingIds: Set<Int>    = []

    // MARK: - Private

    private let stop: Stop
    private let apiClient: APIClient
    private let transitionQueue  = TransitionQueue()
    private let haptic           = UINotificationFeedbackGenerator()
    private var tickTimer:        AnyCancellable?
    private var autoStartSink:    AnyCancellable?

    private var gps: GPSTrackingService { GPSTrackingService.shared }

    // MARK: - Init

    nonisolated init(stop: Stop, apiClient: APIClient) {
        self.stop      = stop
        self.apiClient = apiClient
        // visitStatuses intentionally starts empty.
        // status(for:) returns visitStatuses[id] ?? visit.visitStatus, so the
        // initial state is always correct without a pre-population loop.
        //
        // Do NOT write @Published properties here — StateObject(wrappedValue:)
        // may be called during SwiftUI view diffing on a background thread.
        // Any @Published mutation off the main actor corrupts objectWillChange's
        // NSString-backed retain counts → EXC_BAD_ACCESS.

        // On reconnect: retry any failed job transitions.
        // GPS ping drain is handled centrally by GPSTrackingService.
        autoStartSink = NotificationCenter.default.publisher(for: .mwPingQueueOnline)
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in
                guard let self else { return }
                Task { await self.drainPendingTransitions() }
            }
    }

    // MARK: - Job Lifecycle

    func startJob(visitId: Int) async {
        isLoading    = true
        errorMessage = nil

        // Prompt for location permission now that the user has explicitly
        // chosen to start a job (previously done in init, which runs before
        // the main actor takes over and caused EXC_BAD_ACCESS).
        gps.locationManager.requestWhenInUsePermission()

        // Give ArrivalMonitor the job-site coordinate so it can detect arrival.
        // resetSessionMetrics is called by GPSTrackingService.setActiveVisit().
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
                gps.setActiveVisit(visitId)

                if response.autoClockIn == true {
                    autoClockInNotice = "You've been automatically clocked in."
                }
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
            "accuracy_badge": gps.locationManager.accuracyBadge.rawValue
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
                gps.setActiveVisit(nil)
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

    // MARK: - Flag Toggle

    /// Resolves the current flag state for a visit, preferring local override over server value.
    func isFlagged(for visit: Visit) -> Bool {
        flagOverrides[visit.visitId] ?? visit.isFlagged
    }

    func toggleFlag(_ visit: Visit) async {
        let visitId = visit.visitId
        guard !flagLoadingIds.contains(visitId) else { return }

        flagLoadingIds.insert(visitId)

        do {
            let response: VisitFlagResponse = try await apiClient.request(
                .visitFlag,
                body: ["visit_id": visitId]
            )
            if response.success {
                flagOverrides[visitId] = response.isFlagged
            }
        } catch {
            // Non-fatal — the heart reverts to its previous state silently.
        }

        flagLoadingIds.remove(visitId)
    }

    // MARK: - Private

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
        guard gps.locationManager.canUseLocation else { return (nil, nil) }
        if let loc = try? await gps.locationManager.currentLocation() {
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
