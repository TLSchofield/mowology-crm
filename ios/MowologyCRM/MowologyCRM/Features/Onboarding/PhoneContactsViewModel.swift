import Combine
import Foundation

@MainActor
protocol PhoneContactsAPI {
    func map() async throws -> OnboardingMapResponse
    func step(_ body: [String: Any]) async throws -> OnboardingStepResponse
}

@MainActor
struct LivePhoneContactsAPI: PhoneContactsAPI {
    let client: APIClient
    func map() async throws -> OnboardingMapResponse { try await client.request(.onboardingMap(knows: "contacts")) }
    func step(_ body: [String: Any]) async throws -> OnboardingStepResponse { try await client.request(.onboardingStep, body: body) }
}

enum PhoneContactsPhase: Equatable {
    case hidden, loading, intro, requestingPermission, denied, picking, sending, done, failed
}

@MainActor
final class PhoneContactsViewModel: ObservableObject {
    private enum RetryOperation {
        case load, chooseClients, readContacts, sendRemaining, skip, finish
    }

    @Published private(set) var phase: PhoneContactsPhase = .hidden
    @Published private(set) var contacts: [PhoneContact] = []
    @Published var search = ""
    @Published var selected: Set<String> = []
    @Published private(set) var sentCount = 0
    @Published private(set) var totalToSend = 0
    @Published private(set) var tally = PhoneContactTally()
    @Published private(set) var summaryCount: Int?
    @Published private(set) var error: String?

    private let api: PhoneContactsAPI
    private let reader: PhoneContactsProviding
    private var pendingBatches: [[PhoneContact]] = []
    private var confirmedBatchCount = 0
    private var mapLoaded = false
    private var retryOperation: RetryOperation = .load

    init(api: PhoneContactsAPI, reader: PhoneContactsProviding) {
        self.api = api
        self.reader = reader
    }

    var shownContacts: [PhoneContact] { contacts.filter { PhoneContactsLogic.matches($0, search: search) } }

    func load() async {
        guard !mapLoaded, phase == .hidden || phase == .failed else { return }
        retryOperation = .load
        phase = .loading
        error = nil
        do {
            let response = try await api.map()
            guard response.ok else { return fail(response.message ?? response.error ?? "GET onboarding map returned ok=false without a message.") }
            mapLoaded = true
            // A server without this step (not deployed yet) simply shows no card; that is not the owner's problem.
            guard let step = response.steps.first(where: { $0.key == "contacts" }) else {
                AppDiagnostics.shared.record(category: "onboarding.contacts", message: "GET onboarding map did not include the contacts step; card hidden.")
                phase = .hidden
                return
            }
            switch (step.state, step.data?.answer) {
            case (.todo, _), (.next, _): phase = .intro
            case (.done, .some("imported")):
                summaryCount = step.data?.count ?? 0
                phase = .hidden
            default: phase = .hidden
            }
        } catch {
            // The screen's task was cancelled mid-request (view refreshed or re-created): that is not a server error, so
            // show nothing and let the next load try again (seen 2026-11-12: -999 "cancelled" put an error card up).
            if (error as? URLError)?.code == .cancelled || error is CancellationError {
                phase = .hidden
                return
            }
            fail(ErrorDetails.describe(error))
        }
    }

    func chooseClients() async {
        guard phase == .intro || phase == .denied else { return }
        error = nil
        switch reader.authorizationStatus() {
        case .denied, .restricted: phase = .denied
        case .notDetermined:
            retryOperation = .chooseClients
            phase = .requestingPermission
            do {
                guard try await reader.requestAccess() else { phase = .denied; return }
                await readContacts()
            } catch { fail(ErrorDetails.describe(error)) }
        case .authorized, .other:
            await readContacts()
        }
    }

    func selectAllShown() { selected = PhoneContactsLogic.selectingAllShown(selected: selected, shown: shownContacts) }
    func clearShown() { selected = PhoneContactsLogic.clearingShown(selected: selected, shown: shownContacts) }
    func toggle(_ contact: PhoneContact) {
        if selected.contains(contact.id) { selected.remove(contact.id) } else { selected.insert(contact.id) }
    }

    func sendSelected() async {
        // Two quick taps must not start two sends: the first call moves the phase on before it first suspends.
        guard phase == .picking else { return }
        let chosen = contacts.filter { selected.contains($0.id) }
        guard !chosen.isEmpty else { return }
        pendingBatches = PhoneContactsLogic.batches(chosen)
        confirmedBatchCount = 0
        sentCount = 0
        totalToSend = chosen.count
        tally = PhoneContactTally()
        await sendRemaining()
    }

    func retry() async {
        guard phase == .failed else { return }
        switch retryOperation {
        case .load:
            await load()
        case .chooseClients:
            phase = .intro
            await chooseClients()
        case .readContacts:
            await readContacts()
        case .sendRemaining:
            await sendRemaining()
        case .skip:
            await skip()
        case .finish:
            await finish()
        }
    }

    func skip() async {
        retryOperation = .skip
        do {
            let response = try await api.step(["knows": "contacts", "key": "contacts", "do": "skip"])
            guard response.ok else { return fail(response.message ?? response.error ?? "POST onboarding skip returned ok=false without a message.") }
            phase = .hidden
        } catch { fail(ErrorDetails.describe(error)) }
    }

    func finish() async {
        let count = tally.created + tally.matched
        retryOperation = .finish
        do {
            let response = try await api.step(["knows": "contacts", "key": "contacts", "do": "finish", "count": count])
            guard response.ok else { return fail(response.message ?? response.error ?? "POST onboarding finish returned ok=false without a message.") }
            summaryCount = response.data?.count ?? count
            phase = .hidden
        } catch { fail(ErrorDetails.describe(error)) }
    }

    private func readContacts() async {
        retryOperation = .readContacts
        do {
            let values = try await reader.contacts()
            guard !values.isEmpty else { return fail("No contacts were available to choose.") }
            contacts = values
            selected = []
            phase = .picking
        } catch { fail(ErrorDetails.describe(error)) }
    }

    private func sendRemaining() async {
        retryOperation = .sendRemaining
        phase = .sending
        error = nil
        while let index = PhoneContactsLogic.nextBatch(totalBatches: pendingBatches.count, confirmedBatchCount: confirmedBatchCount) {
            let batch = pendingBatches[index]
            do {
                let response = try await api.step(["knows": "contacts", "key": "contacts", "do": "import",
                                                   "contacts": batch.map(PhoneContactsLogic.wire)])
                guard response.ok else { return fail(response.message ?? response.error ?? "POST onboarding import returned ok=false without a message.") }
                let batchTally = PhoneContactsLogic.tally(response.results)
                tally.created += batchTally.created
                tally.matched += batchTally.matched
                tally.skipped += batchTally.skipped
                confirmedBatchCount += 1
                sentCount += batch.count
            } catch { return fail(ErrorDetails.describe(error)) }
        }
        phase = .done
    }

    private func fail(_ detail: String) {
        error = detail
        phase = .failed
        AppDiagnostics.shared.record(category: "onboarding.contacts", message: "\(detail) (confirmed batches: \(confirmedBatchCount), selected count: \(totalToSend))")
    }
}

#if DEBUG
struct DemoPhoneContactsReader: PhoneContactsProviding {
    func authorizationStatus() -> PhoneContactsAuthorization { .authorized }
    func requestAccess() async throws -> Bool { true }
    func contacts() async throws -> [PhoneContact] {
        (1...25).map { index in
            PhoneContact(id: "demo-\(index)", first: index == 7 ? "" : "Person", last: index == 7 ? "" : "\(index)",
                         company: index == 7 ? "North Shore Gardens" : (index.isMultiple(of: 4) ? "Company \(index)" : ""),
                         emails: [index == 18 ? "person1@example.test" : "person\(index)@example.test"],
                         phones: index == 3 ? [.init(label: "mobile", number: "604-555-0103"), .init(label: "work", number: "604-555-0203")] : [.init(label: "mobile", number: "604-555-\(String(format: "%04d", index))")],
                         address: index == 5 ? .init(street: "5 Cedar Way", city: "Victoria", province: "BC", postal: "V8V 1A1") : nil)
        }
    }
}

@MainActor
final class DemoPhoneContactsAPI: PhoneContactsAPI {
    func map() async throws -> OnboardingMapResponse {
        OnboardingMapResponse(ok: true, steps: [.init(key: "contacts", title: "Phone contacts", state: .todo, head: "kaname", skippable: true, data: .init(answer: nil, count: 0))], next: nil, readyToFinish: false, error: nil, message: nil)
    }
    func step(_ body: [String: Any]) async throws -> OnboardingStepResponse {
        let action = body["do"] as? String
        let contacts = body["contacts"] as? [[String: Any]] ?? []
        let results = contacts.enumerated().compactMap { index, item -> PhoneContactResult? in
            guard let ref = item["ref"] as? String else { return nil }
            return .init(ref: ref, outcome: index.isMultiple(of: 7) ? .matched : .created, contactId: index + 100)
        }
        let count = body["count"] as? Int ?? results.count
        return OnboardingStepResponse(ok: true, next: nil, data: .init(answer: action == "skip" ? "skipped" : "imported", count: count), message: nil, error: nil, results: results)
    }
}
#endif
