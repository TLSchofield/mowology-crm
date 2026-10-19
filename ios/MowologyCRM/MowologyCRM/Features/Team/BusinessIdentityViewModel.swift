import Foundation
import Combine

@MainActor
protocol BusinessIdentityAPI {
    func current() async throws -> BusinessIdentityCurrentResponse
    func search(_ name: String) async throws -> BusinessIdentitySearchResponse
    func contacts() async throws -> BusinessContactsResponse
    func send(_ body: [String: Any]) async throws -> BusinessIdentityCurrentResponse
}

@MainActor
struct LiveBusinessIdentityAPI: BusinessIdentityAPI {
    let client: APIClient
    func current() async throws -> BusinessIdentityCurrentResponse { try await client.request(.businessIdentityCurrent) }
    func search(_ name: String) async throws -> BusinessIdentitySearchResponse { try await client.request(.businessIdentitySearch(name: name)) }
    func contacts() async throws -> BusinessContactsResponse { try await client.request(.businessIdentityContacts) }
    func send(_ body: [String: Any]) async throws -> BusinessIdentityCurrentResponse {
        guard let mode = body["mode"] as? String, ["start", "confirm", "manual"].contains(mode) else {
            throw APIError.invalidURL
        }
        return try await client.request(.businessIdentityAction(mode: mode), body: body)
    }
}

@MainActor
final class BusinessIdentityViewModel: ObservableObject {
    @Published private(set) var current: BusinessIdentityCurrentResponse?
    @Published private(set) var state: ItPennyOnboardingState = .opening
    @Published private(set) var isBusy = false
    @Published private(set) var isEditing = false
    @Published private(set) var error: String? {
        didSet {
            if let error { AppDiagnostics.shared.record(category: "business.identity", message: error) }
        }
    }
    @Published private(set) var attribution: String?
    @Published private(set) var contactsState: BusinessContactsState = .idle
    @Published private(set) var canEdit = false
    private let api: BusinessIdentityAPI
    private(set) var enteredName = ""
    private var lastMatches: [BusinessRegistryMatch] = []

    init(api: BusinessIdentityAPI) { self.api = api }

    func load() async {
        guard current == nil, !isBusy else { return }
        isBusy = true; error = nil
        do {
            let response = try await api.current()
            guard response.ok else { error = response.error ?? "I couldn't reach the server. Please try again."; isBusy = false; return }
            apply(response)
            canEdit = response.canEdit
            isBusy = false
            if response.knowledge.stage == .recognized, let name = response.identity?.enteredName {
                enteredName = name
                await search(name)
            }
        } catch { self.error = "I couldn't reach the server. Please try again."; isBusy = false }
    }

    func retry() async {
        error = nil
        if current == nil { await load() } else { await searchAgain() }
    }

    func start(_ name: String) async {
        guard !isBusy, current?.canEdit == true else { return }
        enteredName = name; isBusy = true; error = nil
        do {
            let response = try await api.send(["mode": "start", "name": name])
            guard response.ok else { error = response.error ?? "POST business identity start: response ok=false; server supplied no error message."; isBusy = false; return }
            apply(response); isBusy = false
            await search(name)
        } catch { self.error = failure(error); isBusy = false }
    }

    func searchAgain() async { await search(enteredName) }

    func search(_ name: String) async {
        guard !isBusy else { return }
        state = .searching; isBusy = true; error = nil
        do {
            let response = try await api.search(name)
            attribution = response.attribution ?? attribution
            lastMatches = response.matches
            switch response.status {
            case .matches:
                if let first = response.matches.first, first.match == "exact" { state = .oneMatch(first) }
                else if response.matches.count == 1 { state = .oneMatch(response.matches[0]) }
                else if response.matches.isEmpty { state = .none(manual: false) }
                else { state = .several(response.matches, selected: nil) }
            case .none: state = .none(manual: false)
            case .unavailable, .rateLimited: state = .unavailable
            }
            if !response.ok, response.status == .matches { error = response.error ?? "Business registry search: response ok=false with status=matches; server supplied no error message."; state = .unavailable }
        } catch { self.error = failure(error); state = .unavailable }
        isBusy = false
    }

    func select(_ match: BusinessRegistryMatch) {
        if match.id == BusinessRegistryMatch.manualID { state = .none(manual: true); return }
        if case .several(let matches, _) = state { state = .several(matches, selected: match) }
    }

    func showOtherMatches() {
        state = lastMatches.count > 1 ? .several(lastMatches, selected: nil) : .none(manual: false)
    }

    var hasOtherMatches: Bool { lastMatches.count > 1 }

    func confirm(_ match: BusinessRegistryMatch) async {
        let saved = await save(["mode": "confirm", "record_id": match.recordId])
        if saved { await loadContacts() }
    }

    func manual(legalName: String, operatingName: String?, legalStructure: String?, jurisdiction: String?) async {
        var body: [String: Any] = ["mode": "manual", "legal_name": legalName]
        if let operatingName { body["operating_name"] = operatingName }
        if let legalStructure { body["legal_structure"] = legalStructure }
        if let jurisdiction { body["jurisdiction"] = jurisdiction }
        if await save(body) { await loadContacts() }
    }

    func restart() {
        guard !isBusy, current?.canEdit == true else { return }
        isEditing = true
        state = .opening
        enteredName = current?.identity?.operatingName ?? current?.identity?.enteredName ?? ""
        contactsState = .idle
        error = nil
    }

    private func save(_ body: [String: Any]) async -> Bool {
        guard !isBusy, current?.canEdit == true else { return false }
        isBusy = true; error = nil; defer { isBusy = false }
        do {
            let response = try await api.send(body)
            guard response.ok else { error = response.error ?? "POST business identity: response ok=false; server supplied no error message."; return false }
            apply(response)
            guard response.knowledge.stage.map({ $0 >= .identified }) == true else {
                error = "POST business identity returned ok=true but knowledge.stage=\(response.knowledge.stage?.rawValue ?? "null"); expected identified or higher."
                return false
            }
            isEditing = false
            state = .confirmed(response.identity?.legalName ?? enteredName)
            return true
        } catch { self.error = failure(error); return false }
    }

    func loadContacts() async {
        contactsState = .loading
        do {
            let response = try await api.contacts()
            contactsState = response.ok ? .available(response.contacts) : .failed
        } catch { contactsState = .failed }
    }

    private func apply(_ response: BusinessIdentityCurrentResponse) {
        current = response; attribution = response.attribution ?? attribution
        if let name = response.identity?.enteredName { enteredName = name }
    }

    private func failure(_ error: Error) -> String {
        let detail = ErrorDetails.describe(error)
        return detail
    }
}
