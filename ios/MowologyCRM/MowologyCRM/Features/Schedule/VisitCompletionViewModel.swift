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

    // MARK: - Also unbilled at this address (UnbilledWorkFinder)
    @Published var unbilledItems: [UnbilledWorkItem] = []
    @Published var unbilledHints: [UnbilledWorkHint] = []
    @Published var canMarkDone: Bool = false
    @Published var tickedUnbilled: Set<Int> = []
    /// Prices typed for $0 lines (and any edited amount), keyed by visit id.
    @Published var unbilledPrices: [Int: Double] = [:]
    private var unbilledLoaded = false

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

    // MARK: - Snow & salt route stop

    /// A daily snow & salt route stop bills only what was done there, so the crew
    /// record it (Salted / Arctic salt / Snow cleared / Nothing needed) before completing.
    @Published var snowIsRoute: Bool = false
    @Published var snowChoices: [SnowRouteChoiceOption] = []
    @Published var snowChoice: String?
    @Published var snowSaving: Bool = false
    @Published var snowError: String?

    /// Whether this stop needs a choice; failures (offline, older server) leave it hidden.
    func loadSnowRoute(visitId: Int) async {
        do {
            let r: SnowRouteChoiceResult = try await apiClient.request(.snowRouteChoice(visitId: visitId))
            guard r.success else { return }
            snowIsRoute = r.isRoute
            snowChoices = r.choices
            snowChoice  = r.choice
        } catch {
            // Not a route stop as far as we can tell — the sheet stays as it was.
        }
    }

    func recordSnowChoice(visitId: Int, choice: String) async -> Bool {
        snowSaving = true
        snowError = nil
        defer { snowSaving = false }
        do {
            let r: SnowRouteChoiceResult = try await apiClient.request(
                .snowRouteChoiceAction,
                body: ["visit_id": visitId, "choice": choice]
            )
            if r.success {
                snowChoice = choice
                return true
            }
            snowError = r.error ?? "Could not save what was done."
        } catch {
            snowError = "No connection — try again."
        }
        return false
    }

    // MARK: - Unbilled work

    /// Load other unbilled work at this visit's address. Read-only; failures just hide the list.
    func loadUnbilled(visitId: Int) async {
        guard !unbilledLoaded else { return }
        unbilledLoaded = true
        do {
            let r: UnbilledWorkResult = try await apiClient.request(
                .scheduleInvoice,
                body: ["action": "unbilled", "visit_id": visitId]
            )
            guard r.success else { return }
            unbilledItems = r.items
            unbilledHints = r.hints
            canMarkDone   = r.canMarkDone
            for item in r.items {
                if item.needsPrice, let s = item.suggestedAmount, s > 0 { unbilledPrices[item.visitId] = s }
                if item.preselect && isTickable(item) { tickedUnbilled.insert(item.visitId) }
            }
        } catch {
            // Offline or older server: no list — invoicing today's visit is unaffected.
        }
    }

    /// Possibly-done lines need the office (admin/manager); $0 lines need a price.
    func isTickable(_ item: UnbilledWorkItem) -> Bool {
        if item.isPossiblyDone && !canMarkDone { return false }
        return unbilledAmount(item) > 0
    }

    func unbilledAmount(_ item: UnbilledWorkItem) -> Double {
        if let p = unbilledPrices[item.visitId] { return p }
        return item.amount
    }

    func setTicked(_ item: UnbilledWorkItem, _ on: Bool) {
        if on && isTickable(item) { tickedUnbilled.insert(item.visitId) } else { tickedUnbilled.remove(item.visitId) }
    }

    func setPrice(_ item: UnbilledWorkItem, _ value: Double?) {
        if let v = value, v > 0 { unbilledPrices[item.visitId] = v } else {
            unbilledPrices.removeValue(forKey: item.visitId)
            tickedUnbilled.remove(item.visitId)
        }
    }

    /// Sum of the ticked extra lines (before GST — GST is always added on top by the server).
    var unbilledSubtotal: Double {
        unbilledItems.filter { tickedUnbilled.contains($0.visitId) }.reduce(0) { $0 + unbilledAmount($1) }
    }

    private var extraVisitsPayload: [[String: Any]] {
        unbilledItems.filter { tickedUnbilled.contains($0.visitId) }.map {
            ["visit_id": $0.visitId, "amount": unbilledAmount($0)]
        }
    }

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
                    "extra_visits":   extraVisitsPayload,
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

// MARK: - Snow & salt route models

struct SnowRouteChoiceOption: Decodable, Hashable {
    let value: String
    let label: String
}

/// GET returns is_route/choice/choices; POST returns success/choice/label (or error).
struct SnowRouteChoiceResult: Decodable {
    let success: Bool
    let error: String?
    let isRoute: Bool
    let choice: String?
    let choices: [SnowRouteChoiceOption]

    enum CodingKeys: String, CodingKey {
        case success, error, choice, choices
        case isRoute = "is_route"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success = (try? c.decode(Bool.self, forKey: .success)) ?? false
        error   = try? c.decode(String.self, forKey: .error)
        isRoute = (try? c.decode(Bool.self, forKey: .isRoute)) ?? false
        choice  = try? c.decode(String.self, forKey: .choice)
        choices = (try? c.decode([SnowRouteChoiceOption].self, forKey: .choices)) ?? []
    }
}
