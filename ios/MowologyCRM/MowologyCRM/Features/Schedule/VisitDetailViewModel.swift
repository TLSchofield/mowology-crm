//
//  VisitDetailViewModel.swift
//  MowologyCRM
//
//  Manages the job timer lifecycle for a single stop's visits.
//
//  Responsibilities:
//  - POST /api/schedule/timer to start or stop a visit timer
//  - Stamp GPSTrackingService with the active visit ID so pings carry visit_id
//  - Drive a local tick timer for elapsed display (mirrors TimeClockViewModel)
//  - Handle server-side auto-clock-in (server may create a timesheet entry
//    automatically when starting the first visit of a shift)
//
//  Design notes:
//  - One instance per VisitDetailView. Not a singleton — different stops
//    may be viewed simultaneously by navigating the stack.
//  - apiClient is created per-instance here. When the app migrates to a
//    shared @EnvironmentObject APIClient, swap the init and remove the
//    per-instance URLSession cost.
//  - Timer state (isTimerRunning, activeVisitId) is local. The authoritative
//    state lives on the server; a full reload of clock status reflects reality.
//

import Foundation
import Combine

@MainActor
final class VisitDetailViewModel: ObservableObject {

    // MARK: - Published

    @Published private(set) var isTimerRunning:      Bool           = false
    @Published private(set) var activeVisitId:       Int?           = nil
    @Published private(set) var timerElapsedSeconds: Int            = 0
    @Published private(set) var isLoading:           Bool           = false
    @Published var             errorMessage:          String?        = nil

    /// Live visit statuses fetched from server — overrides the stale `stop.visits` statuses.
    /// Keyed by visit_id. Populated by syncState() and updated after start/stop actions.
    @Published private(set) var liveStatuses: [Int: String] = [:]

    // Invoice state — non-nil triggers the compose sheet in VisitDetailView.
    @Published var invoicePrefill:    InvoicePrefill? = nil
    @Published var isLoadingInvoice:  Bool            = false

    // MARK: - Private

    // `internal` (not `private`) so VisitDetailView can pass it to InvoiceComposeView.
    let apiClient: APIClient
    private var tickTimer: AnyCancellable?

    // MARK: - Init

    init(authSession: AuthSession) {
        self.apiClient = APIClient(authSession: authSession)
    }

    // MARK: - Formatted Elapsed

    var elapsedFormatted: String {
        let h = timerElapsedSeconds / 3600
        let m = (timerElapsedSeconds % 3600) / 60
        let s = timerElapsedSeconds % 60
        return h > 0
            ? String(format: "%d:%02d:%02d", h, m, s)
            : String(format: "%02d:%02d", m, s)
    }

    // MARK: - Sync State on Appear

    /// Fetch the server's current timer state for this stop's visits.
    /// Must be called on view appear so the Start/Stop button reflects reality
    /// rather than always defaulting to "Start" regardless of server state.
    func syncState(visitIds: [Int]) async {
        guard !isLoading else { return }
        isLoading = true

        do {
            let response: ClockStatusResponse = try await apiClient.request(.scheduleClockStatus)
            if let active = response.activeJob, visitIds.contains(active.visitId) {
                activeVisitId       = active.visitId
                timerElapsedSeconds = active.elapsedSeconds
                isTimerRunning      = true
                startTicking()
                GPSTrackingService.shared.setActiveVisit(active.visitId)
            }
        } catch {
            // Non-fatal — worst case the button label is wrong until they tap it
        }

        isLoading = false
    }

    // MARK: - Start Timer

    func startTimer(visitId: Int) async {
        guard !isTimerRunning else { return }
        isLoading    = true
        errorMessage = nil

        do {
            let response: TimerStartResponse = try await apiClient.request(
                .scheduleTimer,
                body: ["action": "start", "visit_id": visitId]
            )

            if response.success {
                activeVisitId       = visitId
                timerElapsedSeconds = 0
                isTimerRunning      = true
                liveStatuses[visitId] = "in_progress"
                startTicking()
                // Stamp GPS pings with this visit ID for the proof-of-work chain.
                GPSTrackingService.shared.setActiveVisit(visitId)

                if response.autoClockIn == true {
                    // Server auto-clocked the crew in — tell TimeClockView to resync.
                    NotificationCenter.default.post(name: .mwAutoClockIn, object: nil)
                }
            } else {
                errorMessage = response.message ?? "Could not start timer."
            }
        } catch {
            errorMessage = "Could not start the job timer. Please check your connection."
        }

        isLoading = false
    }

    // MARK: - Stop Timer

    func stopTimer(visitId: Int) async {
        guard isTimerRunning, activeVisitId == visitId else { return }
        isLoading    = true
        errorMessage = nil

        do {
            let response: TimerStopResponse = try await apiClient.request(
                .scheduleTimer,
                body: ["action": "stop", "visit_id": visitId]
            )

            if response.success {
                stopTicking()
                liveStatuses[visitId] = "completed"
                isTimerRunning      = false
                activeVisitId       = nil
                timerElapsedSeconds = 0
                // Stop stamping pings with a visit ID — crew is between jobs.
                GPSTrackingService.shared.setActiveVisit(nil)
            } else {
                errorMessage = response.message ?? "Could not stop timer."
            }
        } catch {
            errorMessage = "Could not stop the job timer. Please check your connection."
        }

        isLoading = false
    }

    // MARK: - Invoice Prefill

    /// Fetch invoice prefill data for an already-completed visit.
    /// On success, sets `invoicePrefill` which triggers the `.sheet(item:)` in VisitDetailView.
    /// On 409 / already-invoiced: surfaces an error message instead.
    func fetchInvoicePrefill(visitId: Int) async {
        guard !isLoadingInvoice else { return }
        isLoadingInvoice = true
        errorMessage     = nil

        do {
            let resp: InvoicePrefillResponse = try await apiClient.request(
                .scheduleInvoicePrefill(visitId: visitId)
            )
            if let data = resp.data {
                invoicePrefill = data          // triggers .sheet(item:) in VisitDetailView
            } else if let existingId = resp.existingInvoiceId {
                errorMessage = "Invoice #\(existingId) already exists for this visit."
            } else {
                errorMessage = resp.message ?? "Could not load invoice data."
            }
        } catch {
            errorMessage = "Could not load invoice. Please check your connection."
        }

        isLoadingInvoice = false
    }

    // MARK: - Private

    private func startTicking() {
        stopTicking()
        tickTimer = Timer.publish(every: 1, on: .main, in: .common)
            .autoconnect()
            .sink { [weak self] _ in self?.timerElapsedSeconds += 1 }
    }

    private func stopTicking() {
        tickTimer?.cancel()
        tickTimer = nil
    }
}

// MARK: - Notification Names

extension Notification.Name {
    /// Posted when the timer API auto-creates a clock-in record for this crew member.
    /// TimeClockViewModel observes this and calls loadStatus() to resync elapsed time.
    static let mwAutoClockIn = Notification.Name("ca.mowology.autoClockIn")
}
