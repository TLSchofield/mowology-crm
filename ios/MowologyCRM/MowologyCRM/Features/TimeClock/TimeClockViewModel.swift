//
//  TimeClockViewModel.swift
//  MowologyCRM
//

import Foundation
import Combine

@MainActor
final class TimeClockViewModel: ObservableObject {

    // MARK: - Published State

    @Published var clockedIn: Bool      = false
    @Published var entryId: Int?        = nil
    @Published var clockInTime: String? = nil
    @Published var elapsedSeconds: Int  = 0

    @Published var activeJob: ActiveJobTimer? = nil

    @Published var isLoading: Bool   = false
    @Published var errorMessage: String? = nil

    // MARK: - Private

    private let apiClient: APIClient
    private var tickTimer: AnyCancellable?

    // MARK: - Init

    private let authSession: AuthSession

    init(authSession: AuthSession) {
        self.authSession = authSession
        self.apiClient   = APIClient(authSession: authSession)

        // When a job timer start auto-creates a clock-in, resync displayed state
        // so the elapsed counter and clock-in time are accurate.
        NotificationCenter.default.addObserver(
            forName: .mwAutoClockIn,
            object:  nil,
            queue:   .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                await self?.loadStatus()
            }
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
        } else {
            stopTicking()
            GPSTrackingService.shared.stop()
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
