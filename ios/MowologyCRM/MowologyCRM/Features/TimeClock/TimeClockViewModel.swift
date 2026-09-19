//
//  TimeClockViewModel.swift
//  MowologyCRM
//

import Foundation
import UIKit
import Combine

@MainActor
final class TimeClockViewModel: ObservableObject {

    // MARK: - Published State

    @Published var clockedIn: Bool      = false
    @Published var entryId: Int?        = nil
    @Published var clockInTime: String? = nil
    @Published var elapsedSeconds: Int  = 0

    @Published var activeJob: ActiveJobTimer? = nil

    @Published var isLoading: Bool        = false
    @Published var errorMessage: String?  = nil

    /// True when a clock action was accepted locally but not yet confirmed by the server.
    @Published private(set) var isPendingSync: Bool = false

    // MARK: - Private

    private let apiClient: APIClient
    private var tickTimer: AnyCancellable?
    private var connectivityObserver: NSObjectProtocol?
    private var serverStopObserver: NSObjectProtocol?

    // MARK: - Tracking consent

    /// Non-nil while the location disclosure should be on screen (first clock-in, or
    /// whenever the disclosure version changes). Clock-in continues once it is agreed.
    @Published var pendingDisclosure: TrackingDisclosure?
    /// True when the office has made consent a condition of tracking.
    @Published private(set) var consentRequired = false
    /// Why the server ended tracking, when it wasn't the crew member clocking out.
    @Published var serverStopNotice: String?

    // MARK: - Init

    private let authSession: AuthSession

    init(authSession: AuthSession) {
        self.authSession = authSession
        self.apiClient   = APIClient(authSession: authSession)

        // Drain queued clock actions automatically when connectivity is restored.
        connectivityObserver = NotificationCenter.default.addObserver(
            forName: .mwPingQueueOnline,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                await self?.drainAndReconcile()
            }
        }

        // The server ended tracking (auto clock-out, office edit, consent withdrawn…).
        // Re-read the truth so this screen can't keep saying "Clocked In".
        serverStopObserver = NotificationCenter.default.addObserver(
            forName: .mwTrackingStoppedByServer,
            object: nil,
            queue: .main
        ) { [weak self] note in
            let reason = note.userInfo?["reason"] as? String
            Task { @MainActor [weak self] in
                self?.serverStopNotice = Self.notice(forServerStop: reason)
                await self?.loadStatus()
            }
        }
    }

    deinit {
        if let obs = connectivityObserver { NotificationCenter.default.removeObserver(obs) }
        if let obs = serverStopObserver   { NotificationCenter.default.removeObserver(obs) }
    }

    private static func notice(forServerStop reason: String?) -> String {
        switch reason {
        case "not_clocked_in":    return "You were clocked out by the office or the end-of-day auto clock-out. Location tracking has stopped."
        case "consent_required":  return "Location tracking is paused until you review and agree to the location disclosure."
        case "tracking_disabled": return "Location tracking has been turned off for your account."
        default:                  return "Location tracking has stopped."
        }
    }

    // MARK: - Consent

    /// Clock-in entry point for the UI: shows the location disclosure first when the
    /// crew member hasn't agreed to the current version, then clocks in.
    func clockInWithConsentCheck() async {
        do {
            let response: TrackingConsentResponse = try await apiClient.request(.trackingConsent)
            consentRequired = response.consent?.required ?? false
            if response.consent?.current == false, let disclosure = response.disclosure {
                pendingDisclosure = disclosure
                return
            }
        } catch {
            // Offline or an older server — never block a clock-in on the disclosure fetch.
        }
        await clockIn()
    }

    func agreeToDisclosure() async {
        guard let disclosure = pendingDisclosure else { return }
        do {
            let _: TrackingStatusResponse = try await apiClient.request(.trackingAction, body: [
                "action":  "consent",
                "version": disclosure.version,
                "device":  [
                    "id":          UIDevice.current.identifierForVendor?.uuidString ?? "unknown",
                    "platform":    "ios",
                    "app_version": Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? ""
                ]
            ])
            pendingDisclosure = nil
            await clockIn()
        } catch {
            errorMessage = (error as? APIError)?.localizedDescription ?? error.localizedDescription
        }
    }

    /// "Not now" — allowed only while consent is not yet a condition of tracking.
    func declineDisclosure() async {
        pendingDisclosure = nil
        if !consentRequired { await clockIn() }
    }

    // MARK: - Load

    func loadStatus() async {
        isLoading    = true
        errorMessage = nil

        do {
            let response: ClockStatusResponse = try await apiClient.request(
                .scheduleClockStatus
            )
            // Server confirmed current state — discard any stale queued action.
            if ClockQueue.shared.hasPending {
                ClockQueue.shared.clear()
                isPendingSync = false
            }
            applyStatus(response)
        } catch let err as APIError {
            if case .networkError = err {
                // Offline — restore last-known clock state from disk so the UI
                // shows the correct clocked-in/out status without an error message.
                restorePersistedClockState()
                if ClockQueue.shared.hasPending { isPendingSync = true }
            } else {
                errorMessage = friendlyError(err)
            }
        } catch {
            errorMessage = friendlyError(error)
        }

        isLoading = false
    }

    // MARK: - Clock In / Out

    /// Where the punch happened. Callers rarely have a coordinate to hand, so the
    /// view model resolves one itself — otherwise every punch reaches the server
    /// with no location. Reuses the tracking service's fix when it is ≤30 s old,
    /// so a clock-out during a tracked shift costs nothing.
    private func punchLocation() async -> (Double?, Double?) {
        let lm = GPSTrackingService.shared.locationManager
        lm.requestWhenInUsePermission()
        guard lm.canUseLocation else { return (nil, nil) }
        let loc = (try? await lm.currentLocation()) ?? lm.lastLocation
        return (loc?.coordinate.latitude, loc?.coordinate.longitude)
    }

    func clockIn(lat: Double? = nil, lng: Double? = nil) async {
        isLoading    = true
        errorMessage = nil

        var (lat, lng) = (lat, lng)
        if lat == nil || lng == nil { (lat, lng) = await punchLocation() }

        var body: [String: Any] = ["action": "clock_in"]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }

        do {
            let response: ClockActionResponse = try await apiClient.request(
                .scheduleClock,
                body: body
            )
            clockedIn      = response.clockedIn
            entryId        = response.entryId
            clockInTime    = response.clockIn
            elapsedSeconds = response.elapsedSeconds ?? 0
            isPendingSync  = false
            persistClockState()
            if clockedIn {
                startTicking()
                GPSTrackingService.shared.start(authSession: authSession)
            }
        } catch let err as APIError {
            if case .networkError = err {
                // Offline — accept locally and queue for sync.
                clockedIn     = true
                isPendingSync = true
                ClockQueue.shared.enqueue(action: "clock_in", lat: lat, lng: lng)
                persistClockState()
                startTicking()
                GPSTrackingService.shared.start(authSession: authSession)
            } else {
                errorMessage = err.localizedDescription
            }
        } catch {
            errorMessage = friendlyError(error)
        }

        isLoading = false
    }

    func clockOut(lat: Double? = nil, lng: Double? = nil) async {
        isLoading    = true
        errorMessage = nil

        var (lat, lng) = (lat, lng)
        if lat == nil || lng == nil { (lat, lng) = await punchLocation() }

        var body: [String: Any] = ["action": "clock_out"]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }

        do {
            let response: ClockActionResponse = try await apiClient.request(
                .scheduleClock,
                body: body
            )
            clockedIn      = false
            entryId        = nil
            clockInTime    = nil
            elapsedSeconds = 0
            activeJob      = nil
            isPendingSync  = false
            persistClockState()
            stopTicking()
            GPSTrackingService.shared.stop()
            _ = response  // totalMinutes available if needed for summary display
        } catch let err as APIError {
            if case .networkError = err {
                // Offline — accept locally and queue for sync.
                clockedIn      = false
                entryId        = nil
                clockInTime    = nil
                elapsedSeconds = 0
                activeJob      = nil
                isPendingSync  = true
                ClockQueue.shared.enqueue(action: "clock_out", lat: lat, lng: lng)
                persistClockState()
                stopTicking()
                GPSTrackingService.shared.stop()
            } else {
                errorMessage = err.localizedDescription
            }
        } catch {
            errorMessage = friendlyError(error)
        }

        isLoading = false
    }

    // MARK: - Formatted Elapsed

    var elapsedFormatted: String {
        let h = elapsedSeconds / 3600
        let m = (elapsedSeconds % 3600) / 60
        let s = elapsedSeconds % 60
        if h > 0 {
            return String(format: "%d:%02d:%02d", h, m, s)
        }
        return String(format: "%02d:%02d", m, s)
    }

    // MARK: - Private

    private func drainAndReconcile() async {
        let result = await ClockQueue.shared.drain(using: apiClient)
        switch result {
        case .empty:
            isPendingSync = false
        case .success, .reconcile:
            isPendingSync = false
            // Reload from server to confirm our optimistic state.
            await loadStatus()
        case .offline:
            break  // still no signal — keep pending state
        }
    }

    private func applyStatus(_ response: ClockStatusResponse) {
        clockedIn      = response.clockedIn
        entryId        = response.entryId
        clockInTime    = response.clockIn
        elapsedSeconds = response.elapsedSeconds ?? 0
        activeJob      = response.activeJob
        persistClockState()

        if clockedIn {
            startTicking()
            // Resume always-on tracking if still clocked in after app relaunch.
            GPSTrackingService.shared.start(authSession: authSession)
        } else {
            stopTicking()
            GPSTrackingService.shared.stop()
        }
    }

    // MARK: - Clock State Persistence

    private enum PersistKey {
        static let clockedIn  = "mw.clock.state.clockedIn"
        static let entryId    = "mw.clock.state.entryId"
        static let clockInTime = "mw.clock.state.clockInTime"
    }

    private func persistClockState() {
        let d = UserDefaults.standard
        d.set(clockedIn, forKey: PersistKey.clockedIn)
        d.set(entryId ?? 0, forKey: PersistKey.entryId)
        d.set(clockInTime, forKey: PersistKey.clockInTime)
    }

    private func restorePersistedClockState() {
        let d         = UserDefaults.standard
        clockedIn     = d.bool(forKey: PersistKey.clockedIn)
        let savedId   = d.integer(forKey: PersistKey.entryId)
        entryId       = savedId > 0 ? savedId : nil
        clockInTime   = d.string(forKey: PersistKey.clockInTime)
        // Elapsed seconds will restart from 0 — server reconciles on reconnect.
        if clockedIn {
            startTicking()
            GPSTrackingService.shared.start(authSession: authSession)
        }
    }

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

    private func friendlyError(_ error: Error) -> String {
        if let apiError = error as? APIError {
            return apiError.localizedDescription
        }
        return error.localizedDescription
    }
}
