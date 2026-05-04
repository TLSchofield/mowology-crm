//
//  TimeClockViewModel.swift
//  MowologyCRM
//

import Foundation
import Combine
import ActivityKit
import WidgetKit

@MainActor
final class TimeClockViewModel: ObservableObject {

    // MARK: - Published State

    @Published var clockedIn: Bool      = false
    @Published var entryId: Int?        = nil
    @Published var clockInTime: String? = nil
    @Published var elapsedSeconds: Int  = 0

    @Published var activeJob: ActiveJobTimer? = nil {
        didSet { updateLiveActivity(job: activeJob) }
    }

    @Published var isLoading: Bool   = false
    @Published var errorMessage: String? = nil

    // MARK: - Private

    private let apiClient: APIClient
    private var tickTimer: AnyCancellable?
    private var liveActivity: Activity<MowJobActivity>?

    // MARK: - Init

    private let authSession: AuthSession
    private var arrivalObserver: NSObjectProtocol?

    init(authSession: AuthSession) {
        self.authSession = authSession
        self.apiClient   = APIClient(authSession: authSession)
        // Wire geofence auto-clock-in: AppDelegate posts mwArrivalClockIn when the
        // crew member taps "Clock In" on the arrival notification action.
        // Without this observer the notification action silently does nothing.
        arrivalObserver = NotificationCenter.default.addObserver(
            forName: .mwArrivalClockIn,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            guard let self else { return }
            Task { await self.clockIn() }
        }
    }

    deinit {
        if let ob = arrivalObserver {
            NotificationCenter.default.removeObserver(ob)
        }
    }

    // MARK: - Load

    func loadStatus() async {
        isLoading    = true
        errorMessage = nil

        do {
            let response: ClockStatusResponse = try await apiClient.request(
                .scheduleClockStatus
            )
            applyStatus(response)
        } catch {
            errorMessage = friendlyError(error)
        }

        isLoading = false
    }

    // MARK: - Clock In / Out

    func clockIn(lat: Double? = nil, lng: Double? = nil) async {
        isLoading    = true
        errorMessage = nil

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
            if clockedIn {
                startTicking()
                GPSTrackingService.shared.start(authSession: authSession)
                startLiveActivity(elapsed: elapsedSeconds)
                syncWidgetState()
            }
        } catch {
            errorMessage = friendlyError(error)
        }

        isLoading = false
    }

    func clockOut(lat: Double? = nil, lng: Double? = nil) async {
        isLoading    = true
        errorMessage = nil

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
            stopTicking()
            GPSTrackingService.shared.stop()
            endLiveActivity()
            syncWidgetState()
            _ = response // totalMinutes available here if needed for summary display
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

    // MARK: - Private Helpers

    private func applyStatus(_ response: ClockStatusResponse) {
        clockedIn      = response.clockedIn
        entryId        = response.entryId
        clockInTime    = response.clockIn
        elapsedSeconds = response.elapsedSeconds ?? 0
        activeJob      = response.activeJob

        if clockedIn {
            startTicking()
            // Resume always-on tracking if still clocked in after app relaunch.
            GPSTrackingService.shared.start(authSession: authSession)
            startLiveActivity(elapsed: elapsedSeconds)
            syncWidgetState()
        } else {
            stopTicking()
            GPSTrackingService.shared.stop()
            syncWidgetState()
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

    // MARK: - Live Activity

    private func startLiveActivity(elapsed: Int) {
        guard ActivityAuthorizationInfo().areActivitiesEnabled,
              liveActivity == nil else { return }
        let clockInDate = Date().addingTimeInterval(-Double(elapsed))
        let attrs = MowJobActivity(
            clockInDate: clockInDate,
            crewName: authSession.user?.name ?? "Crew"
        )
        let initialState = MowJobActivity.ContentState(
            jobTitle: activeJob?.jobTitle,
            address: activeJob?.propertyAddress,
            isOnJob: activeJob != nil
        )
        liveActivity = try? Activity.request(
            attributes: attrs,
            content: .init(state: initialState, staleDate: nil),
            pushType: nil
        )
    }

    private func updateLiveActivity(job: ActiveJobTimer?) {
        guard let activity = liveActivity else { return }
        let newState = MowJobActivity.ContentState(
            jobTitle: job?.jobTitle,
            address: job?.propertyAddress,
            isOnJob: job != nil
        )
        Task { await activity.update(.init(state: newState, staleDate: nil)) }
    }

    private func endLiveActivity() {
        guard let activity = liveActivity else { return }
        liveActivity = nil
        let finalState = MowJobActivity.ContentState(jobTitle: nil, address: nil, isOnJob: false)
        Task { await activity.end(.init(state: finalState, staleDate: nil), dismissalPolicy: .immediate) }
    }

    // MARK: - Widget shared state

    private func syncWidgetState() {
        let defaults = UserDefaults(suiteName: AppDelegate.appGroupId) ?? .standard
        defaults.set(clockedIn, forKey: "mw.widget.clockedIn")
        if clockedIn {
            let epoch = Date().addingTimeInterval(-Double(elapsedSeconds)).timeIntervalSince1970
            defaults.set(epoch, forKey: "mw.widget.clockInEpoch")
            let name = authSession.user?.name ?? "Crew"
            defaults.set(name, forKey: "mw.widget.crewName")
        } else {
            defaults.removeObject(forKey: "mw.widget.clockInEpoch")
        }
        WidgetCenter.shared.reloadTimelines(ofKind: "ClockStatusWidget")
    }
}
