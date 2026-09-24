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

    /// nil = status not yet fetched; false = definitely not clocked in; true = clocked in.
    /// Drives the "not clocked in" warning on the Start Job button.
    @Published private(set) var isClockedIn: Bool? = nil

    /// Local override for flag state: [visitId: isFlagged]. Wins over Visit.isFlagged once set.
    @Published private(set) var flagOverrides:  [Int: Bool] = [:]
    /// Visit IDs with an in-flight flag toggle request — drives the heart loading indicator.
    @Published private(set) var flagLoadingIds: Set<Int>    = []

    /// Local override for the stop's assigned crew — wins over stop.crewIds/crewNames once set.
    @Published private(set) var crewIdsOverride:   [Int]?
    @Published private(set) var crewNamesOverride: [String]?
    @Published var teamMembers:      [TeamMember] = []
    @Published var isCrewActionBusy: Bool = false

    // MARK: - Private

    private let stop: Stop
    private let apiClient: APIClient
    private let transitionQueue  = TransitionQueue()
    private let haptic           = UINotificationFeedbackGenerator()
    private var tickTimer:        AnyCancellable?
    private var autoStartSink:    AnyCancellable?
    private var proximitySink:    AnyCancellable?
    private var departureSink:    AnyCancellable?

    private var gps: GPSTrackingService { GPSTrackingService.shared }

    // MARK: - Init

    init(stop: Stop, apiClient: APIClient) {
        self.stop      = stop
        self.apiClient = apiClient
        // visitStatuses intentionally starts empty — no @Published writes here.
        // status(for:) returns visitStatuses[id] ?? visit.visitStatus, so the
        // initial state is always correct without a pre-population loop.
        // The pre-population loop was removed because StateObject(wrappedValue:)
        // can be called during SwiftUI view diffing on a background thread;
        // any @Published mutation off the main actor corrupts objectWillChange's
        // retain counts → EXC_BAD_ACCESS.

        // On reconnect: retry any failed job transitions.
        // GPS ping drain is handled centrally by GPSTrackingService.
        autoStartSink = NotificationCenter.default.publisher(for: .mwPingQueueOnline)
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in
                guard let self else { return }
                Task { await self.drainPendingTransitions() }
            }

        // Proximity auto-start: the server already started the timer, so mirror it
        // here instead of leaving a stale "Start Job" button on screen.
        // Subscribing only — no @Published write happens during init (see above).
        proximitySink = GPSTrackingService.shared.$autoStartedPayload
            .compactMap { $0 }
            .receive(on: DispatchQueue.main)
            .sink { [weak self] payload in self?.applyAutoStart(payload) }

        departureSink = GPSTrackingService.shared.$autoStoppedPayload
            .compactMap { $0 }
            .receive(on: DispatchQueue.main)
            .sink { [weak self] payload in self?.applyAutoStop(payload) }
    }

    /// Timer stopped because they left — the visit is still in progress until THEY complete it.
    private func applyAutoStop(_ payload: AutoStoppedPayload) {
        guard stop.visits.contains(where: { $0.visitId == payload.visitId }) else { return }
        if activeTimerVisitId == payload.visitId {
            activeTimerVisitId = nil
            stopTicking()
        }
        let minutes = payload.durationMinutes.map { " (\($0) min)" } ?? ""
        autoClockInNotice = "Timer stopped when you left the site\(minutes). Mark the visit complete when you're done — or come back and it resumes."
    }

    private func applyAutoStart(_ payload: AutoStartedPayload) {
        guard stop.visits.contains(where: { $0.visitId == payload.visitId }),
              activeTimerVisitId != payload.visitId else { return }
        haptic.notificationOccurred(.success)
        visitStatuses[payload.visitId] = "in_progress"
        activeTimerVisitId = payload.visitId
        elapsedSeconds     = 0
        startTicking()
        isClockedIn        = true
        autoClockInNotice  = payload.clockInCreated == true
            ? "You arrived — clocked in and job started automatically."
            : "You arrived — job started automatically."
    }

    // MARK: - Clock Status

    /// Fetches whether the crew member is currently clocked in.
    /// Called once when the detail view appears. Lightweight GET — does not block the UI.
    func checkClockStatus() async {
        do {
            let response: ClockStatusResponse = try await apiClient.request(.scheduleClockStatus)
            isClockedIn = response.clockedIn
        } catch {
            // Non-critical — if the check fails, stay silent (nil = unknown).
            isClockedIn = nil
        }
    }

    /// Picks a running timer back up when the visit is reopened. Without this the screen only
    /// knew about timers it had started itself: leave and come back mid-job and the clock vanished
    /// (and with it everything keyed to "job active").
    func restoreActiveTimer() async {
        guard activeTimerVisitId == nil,
              let response: ActiveTimerResponse = try? await apiClient.request(.scheduleTimerActive),
              let timer = response.activeTimer,
              stop.visits.contains(where: { $0.visitId == timer.visitId }),
              activeTimerVisitId == nil else { return }

        visitStatuses[timer.visitId] = "in_progress"
        activeTimerVisitId = timer.visitId
        elapsedSeconds     = timer.elapsedSeconds
        startTicking()
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

                isClockedIn = true   // job started → clock is definitely running now
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

    /// Completes the visit (timer stop). Optional timed-extras minutes + a note
    /// to the client are persisted on the visit server-side, so they survive even
    /// when the crew complete without invoicing.
    /// Returns `true` when the server confirmed completion.
    @discardableResult
    func completeJob(visitId: Int, extrasMinutes: Int = 0, extrasNote: String = "") async -> Bool {
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
        if extrasMinutes > 0 { body["extras_minutes"] = extrasMinutes }
        let trimmedNote = extrasNote.trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmedNote.isEmpty { body["extras_note"] = trimmedNote }
        accountability.forEach { body[$0.key] = $0.value }

        var completed = false
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
                completed = true

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
        return completed
    }

    // MARK: - Skip Visit

    /// UserDefaults key carrying the skip reason across an offline replay
    /// (PendingTransition has no free-text field; AppTransitionDrainService reads this).
    static func skipReasonKey(visitId: Int) -> String { "mw.skipReason.\(visitId)" }

    /// Marks a not-yet-started visit as skipped. Offline-safe: on a network
    /// failure the transition stays queued and replays on reconnect.
    func skipVisit(visitId: Int, reason: String) async {
        isLoading    = true
        errorMessage = nil

        let idempKey = transitionQueue.prepare(visitId: visitId, action: "skip")
        UserDefaults.standard.set(reason, forKey: Self.skipReasonKey(visitId: visitId))

        let body: [String: Any] = ["action": "skip", "visit_id": visitId, "reason": reason]

        do {
            let response: TimerBaseResponse = try await apiClient.request(
                .scheduleTimer,
                body: body,
                extraHeaders: ["Idempotency-Key": idempKey]
            )
            transitionQueue.confirm(visitId: visitId, action: "skip")
            UserDefaults.standard.removeObject(forKey: Self.skipReasonKey(visitId: visitId))

            if response.success {
                haptic.notificationOccurred(.success)
                visitStatuses[visitId] = "skipped"
            } else {
                setError(response.message ?? "Failed to skip visit.")
            }
        } catch let err as APIError {
            if case .networkError = err {
                // Leave queued — AppTransitionDrainService replays it on reconnect.
                haptic.notificationOccurred(.warning)
                visitStatuses[visitId] = "skipped"
                autoClockInNotice = "Skip saved — will sync when signal returns."
            } else {
                transitionQueue.confirm(visitId: visitId, action: "skip")
                UserDefaults.standard.removeObject(forKey: Self.skipReasonKey(visitId: visitId))
                setError(apiErrorMessage(err))
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

    // MARK: - Crew Assignment

    var currentCrewIds:   [Int]    { crewIdsOverride   ?? stop.crewIds }
    var currentCrewNames: [String] { crewNamesOverride ?? stop.crewNames }

    /// Loads the active team member list for the assignment picker. Cached for the
    /// life of this view model — reopening the sheet doesn't refetch.
    func loadTeamMembers() async {
        guard teamMembers.isEmpty else { return }
        do {
            let response: TeamMembersResponse = try await apiClient.request(.teamMembers)
            teamMembers = response.members
        } catch {
            // Non-fatal — the sheet shows an empty list; dismissing and reopening retries.
        }
    }

    func assignCrew(_ crewIds: [Int]) async {
        guard !isCrewActionBusy else { return }
        isCrewActionBusy = true

        do {
            let response: CrewAssignResponse = try await apiClient.request(
                .assignCrew,
                body: ["stop_id": stop.stopId, "crew_ids": crewIds]
            )
            if response.success {
                crewIdsOverride   = response.crewIds
                crewNamesOverride = response.crewNames
            }
        } catch {
            setError(apiErrorMessage(error))
        }

        isCrewActionBusy = false
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
