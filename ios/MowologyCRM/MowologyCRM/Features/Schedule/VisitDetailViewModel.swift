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

    /// Visits whose start/stop transition has been applied locally but not yet
    /// confirmed by the server. Drives the "Pending sync" indicator and keeps
    /// the UI showing the user's intended state across navigation + refreshes.
    @Published private(set) var pendingSyncIds: Set<Int> = []

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
    private var reconnectSink:    AnyCancellable?

    /// Called when a visit's status changes locally (optimistic or confirmed).
    /// Lets the owning ScheduleViewModel patch its cached stops so a fresh VM
    /// init after pop/push doesn't show the stale server snapshot.
    private let onStatusChange: ((Int, String) -> Void)?

    private var gps: GPSTrackingService { GPSTrackingService.shared }

    // MARK: - Init

    init(stop: Stop, apiClient: APIClient, onStatusChange: ((Int, String) -> Void)? = nil) {
        self.stop           = stop
        self.apiClient      = apiClient
        self.onStatusChange = onStatusChange

        // Seed local statuses from the stop, but let any pending transition in
        // the queue override — the queue is the source of truth for the user's
        // last expressed intent across app launches.
        for visit in stop.visits {
            let intended = transitionQueue.intendedStatus(forVisitId: visit.visitId)
            visitStatuses[visit.visitId] = intended ?? visit.visitStatus
            if intended != nil {
                pendingSyncIds.insert(visit.visitId)
            }
        }
        gps.locationManager.requestWhenInUsePermission()

        // On reconnect: retry any failed job transitions.
        // GPS ping drain is handled centrally by GPSTrackingService.
        reconnectSink = NotificationCenter.default.publisher(for: .mwPingQueueOnline)
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in
                guard let self else { return }
                Task { await self.drainPendingTransitions() }
            }

        // Best-effort kick on launch — if the queue has anything pending from
        // a previous session, try to flush now.
        Task { [weak self] in await self?.drainPendingTransitions() }
    }

    // MARK: - Job Lifecycle

    func startJob(visitId: Int) async {
        guard !isLoading else { return }

        // Give ArrivalMonitor the job-site coordinate so it can detect arrival.
        // resetSessionMetrics is called by GPSTrackingService.setActiveVisit().
        if let lat = stop.latitude, let lon = stop.longitude {
            ArrivalMonitor.shared.configure(
                site: CLLocationCoordinate2D(latitude: lat, longitude: lon)
            )
        }

        // 1. Optimistic local commit — user's intent is visible immediately.
        let previousStatus = visitStatuses[visitId] ?? "scheduled"
        applyStatus("in_progress", visitId: visitId, markPendingSync: true)
        haptic.notificationOccurred(.success)
        activeTimerVisitId = visitId
        elapsedSeconds     = 0
        startTicking()
        ArrivalMonitor.shared.jobStarted()
        gps.setActiveVisit(visitId)

        // 2. Persist the pending transition with an idempotency key (survives
        //    process death — same key is sent on every retry).
        let (lat, lng) = await safeLocation()
        let idempKey   = transitionQueue.prepare(visitId: visitId, action: "start", lat: lat, lng: lng)

        var body: [String: Any] = ["action": "start", "visit_id": visitId]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }

        // 3. Send with retry-on-transient-failure. The catch path KEEPS the
        //    optimistic state for transient errors and only reverts on a
        //    permanent 4xx (other than 401, which APIClient handles).
        isLoading    = true
        errorMessage = nil

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
                pendingSyncIds.remove(visitId)
                if response.autoClockIn == true {
                    autoClockInNotice = "You've been automatically clocked in."
                }
            } else {
                // 200 OK with success=false — the server accepted the request
                // but declined the action (rare; e.g. visit already completed).
                // Treat as permanent: revert optimistic state.
                revertStatus(to: previousStatus, visitId: visitId, untickIfActive: true)
                setError(response.message ?? "Failed to start job.")
            }
        } catch let apiError as APIError where apiError.isPermanentClientReject {
            // 4xx — request will not succeed on retry. Revert and surface the
            // server's message so the user knows the action was rejected.
            transitionQueue.discard(visitId: visitId, action: "start")
            revertStatus(to: previousStatus, visitId: visitId, untickIfActive: true)
            setError(apiError.errorDescription ?? "Could not start job.")
        } catch {
            // Transient failure (network, timeout, 5xx). Keep optimistic state,
            // keep queue entry — drain will pick it up later. Soft-surface the
            // condition so the crew member knows it hasn't synced yet.
            errorMessage = "Saved locally — will sync when network returns."
        }

        isLoading = false
    }

    func completeJob(visitId: Int) async {
        guard !isLoading else { return }

        // Finalise dwell + accuracy data before the POST.
        ArrivalMonitor.shared.jobCompleted()
        let accountability = ArrivalMonitor.shared.metrics?.serverPayload ?? [:]

        // 1. Optimistic local commit.
        let previousStatus = visitStatuses[visitId] ?? "in_progress"
        applyStatus("completed", visitId: visitId, markPendingSync: true)
        haptic.notificationOccurred(.success)
        if activeTimerVisitId == visitId {
            activeTimerVisitId = nil
            stopTicking()
        }
        gps.setActiveVisit(nil)

        // 2. Persist the stop transition.
        let (lat, lng) = await safeLocation()
        let idempKey   = transitionQueue.prepare(visitId: visitId, action: "stop", lat: lat, lng: lng)

        // 3. ORDERING: if the matching "start" is still queued (never reached
        //    the server), don't fire "stop" yet — the server would reject it
        //    with a state-conflict 4xx and we'd lose the stop. Drain will
        //    process both in queuedAt order once connectivity returns.
        if transitionQueue.hasPending(visitId: visitId, action: "start") {
            errorMessage = "Saved locally — will sync when network returns."
            return
        }

        var body: [String: Any] = [
            "action":         "stop",
            "visit_id":       visitId,
            "complete_visit": true,
            "accuracy_badge": gps.locationManager.accuracyBadge.rawValue
        ]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }
        accountability.forEach { body[$0.key] = $0.value }

        isLoading    = true
        errorMessage = nil

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
                pendingSyncIds.remove(visitId)
            } else {
                revertStatus(to: previousStatus, visitId: visitId, untickIfActive: false)
                setError(response.message ?? "Failed to complete job.")
            }
        } catch let apiError as APIError where apiError.isPermanentClientReject {
            transitionQueue.discard(visitId: visitId, action: "stop")
            revertStatus(to: previousStatus, visitId: visitId, untickIfActive: false)
            setError(apiError.errorDescription ?? "Could not complete job.")
        } catch {
            errorMessage = "Saved locally — will sync when network returns."
        }

        isLoading = false
    }

    // MARK: - State helpers

    /// Commit a new status locally, persist the override upstream so a fresh
    /// VM init sees it, and optionally mark the visit as pending sync.
    private func applyStatus(_ status: String, visitId: Int, markPendingSync: Bool) {
        visitStatuses[visitId] = status
        if markPendingSync {
            pendingSyncIds.insert(visitId)
        } else {
            pendingSyncIds.remove(visitId)
        }
        onStatusChange?(visitId, status)
    }

    /// Revert a previously-applied optimistic status. Called only on
    /// authoritative server rejection (4xx, or 200 OK with success=false).
    private func revertStatus(to previous: String, visitId: Int, untickIfActive: Bool) {
        visitStatuses[visitId] = previous
        pendingSyncIds.remove(visitId)
        onStatusChange?(visitId, previous)

        if untickIfActive, activeTimerVisitId == visitId {
            activeTimerVisitId = nil
            stopTicking()
            gps.setActiveVisit(nil)
        }
    }

    // MARK: - Exponential Backoff

    /// Retry on transient failures with delays 2 s → 4 s → 8 s → 30 s (cap).
    /// Transient = network error or 5xx server error. 4xx errors throw
    /// immediately so the caller can revert the optimistic state.
    private func withExponentialBackoff<T>(
        maxAttempts: Int = 4,
        _ operation: () async throws -> T
    ) async throws -> T {
        var delay: TimeInterval = 2
        var lastError: Error?

        for attempt in 1...maxAttempts {
            do {
                return try await operation()
            } catch let err as APIError where err.isTransient {
                lastError = err
                if attempt < maxAttempts {
                    try? await Task.sleep(for: .seconds(delay))
                    delay = min(delay * 2, 30)
                }
            } catch {
                throw error   // permanent (4xx, decoding, unauthorized, invalidURL)
            }
        }

        throw lastError ?? APIError.networkError(URLError(.timedOut))
    }

    // MARK: - Pending Transition Drain (reconnect path)

    /// Re-submit any job transitions for visits on this stop that were persisted
    /// but never confirmed. Called automatically when PingQueue posts
    /// `.mwPingQueueOnline` and once on init.
    ///
    /// Drains in queuedAt order so "start" lands before "stop".
    func drainPendingTransitions() async {
        guard !isLoading else { return }
        let visitIds = Set(stop.visits.map(\.visitId))
        let queued   = transitionQueue.allPending()
            .filter { visitIds.contains($0.visitId) }

        for entry in queued {
            switch entry.action {
            case "start":
                // Only retry if our local view still considers this visit not-yet-started.
                let status = (visitStatuses[entry.visitId] ?? "").lowercased()
                if status == "in_progress" || status == "scheduled" {
                    await startJob(visitId: entry.visitId)
                }
            case "stop":
                let status = (visitStatuses[entry.visitId] ?? "").lowercased()
                if status == "completed" || status == "in_progress" {
                    await completeJob(visitId: entry.visitId)
                }
            default:
                break
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

    func isPendingSync(_ visit: Visit) -> Bool {
        pendingSyncIds.contains(visit.visitId)
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
}
