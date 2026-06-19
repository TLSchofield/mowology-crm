//
//  VisitCompletionViewModel.swift
//  MowologyCRM
//
//  Drives the visit-completion sheet: timed-extra-work accrual (live timer +
//  quick-add blocks + note) and the create→send invoice flow.
//
//  The visit is completed by VisitDetailViewModel.completeJob (offline-safe via
//  TransitionQueue). Invoice create/send is online-only — a financial action is
//  never queued; if offline we tell the crew to send it from the office later.
//

import Foundation
import Combine

@MainActor
final class VisitCompletionViewModel: ObservableObject {

    enum Phase: Equatable { case input, working, success, failed }

    // MARK: - Extras input state
    @Published var manualMinutes: Int = 0      // accumulated via +5/+10/+15/+30
    @Published var timerSeconds: Int  = 0      // accrued via the live timer
    @Published var isTimerRunning: Bool = false
    @Published var note: String = ""

    // MARK: - Flow state
    @Published var phase: Phase = .input
    @Published var statusMessage: String = ""
    @Published var invoice: InvoiceCreateResult?
    @Published var sentTo: [String] = []

    /// Dollars per 5-minute block (snapshot of the configured rate at open time).
    let ratePer5Min: Double

    private let apiClient: APIClient
    private var ticker: AnyCancellable?

    init(authSession: AuthSession) {
        self.apiClient   = APIClient(authSession: authSession)
        self.ratePer5Min = AppExtrasConfig.shared.ratePer5Min
    }

    deinit { ticker?.cancel() }

    // MARK: - Derived totals

    /// Total billable minutes = quick-add minutes + whole minutes from the timer.
    var totalMinutes: Int { manualMinutes + (timerSeconds / 60) }

    /// Billable time rounds UP to the next 5-minute block (matches the server).
    var billableBlocks: Int { totalMinutes > 0 ? Int(ceil(Double(totalMinutes) / 5.0)) : 0 }

    /// Live dollar preview. Authoritative amount comes from the server on create.
    var extrasAmount: Double { Double(billableBlocks) * ratePer5Min }

    var hasExtras: Bool { totalMinutes > 0 }

    var timerDisplay: String {
        let m = timerSeconds / 60
        let s = timerSeconds % 60
        return String(format: "%02d:%02d", m, s)
    }

    // MARK: - Timer controls

    func toggleTimer() {
        isTimerRunning ? stopTimer() : startTimer()
    }

    private func startTimer() {
        isTimerRunning = true
        ticker = Timer.publish(every: 1, on: .main, in: .common)
            .autoconnect()
            .sink { [weak self] _ in self?.timerSeconds += 1 }
    }

    private func stopTimer() {
        isTimerRunning = false
        ticker?.cancel()
        ticker = nil
    }

    func addMinutes(_ minutes: Int) {
        manualMinutes = max(0, manualMinutes + minutes)
    }

    func resetExtras() {
        stopTimer()
        timerSeconds  = 0
        manualMinutes = 0
    }

    // MARK: - Invoice flow

    /// Create the draft invoice (base + extras + GST) and email it to the customer.
    /// Returns `true` once sent. Call only after the visit is confirmed completed.
    func createAndSendInvoice(visitId: Int) async -> Bool {
        stopTimer()
        phase = .working

        let trimmedNote = note.trimmingCharacters(in: .whitespacesAndNewlines)

        do {
            let created: InvoiceCreateResult = try await apiClient.request(
                .scheduleInvoice,
                body: [
                    "action":         "create",
                    "visit_id":       visitId,
                    "extras_minutes": totalMinutes,
                    "notes":          trimmedNote,
                ]
            )

            guard created.success, let invoiceId = created.invoiceId else {
                phase = .failed
                statusMessage = created.error ?? "Could not create the invoice."
                return false
            }
            invoice = created

            let sent: InvoiceSendResult = try await apiClient.request(
                .scheduleInvoice,
                body: ["action": "send", "invoice_id": invoiceId]
            )

            if sent.success {
                sentTo = sent.sentTo
                phase = .success
                statusMessage = "Invoice \(created.invoiceNumber ?? "") sent."
                return true
            } else {
                phase = .failed
                statusMessage = sent.error ?? "Invoice created but could not be emailed. Send it from the office."
                return false
            }
        } catch let error as APIError {
            phase = .failed
            if case .networkError = error {
                statusMessage = "Visit completed, but you're offline — create & send this invoice from the office when you're back online."
            } else {
                statusMessage = error.errorDescription ?? "Could not send the invoice."
            }
            return false
        } catch {
            phase = .failed
            statusMessage = error.localizedDescription
            return false
        }
    }
}
