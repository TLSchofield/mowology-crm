// Regression checks for the iPhone's business setup contract. No server mutations.
import Foundation
import Dispatch

// The real networking transport is replaced; endpoint construction and the live adapters remain real.
@MainActor
final class APIClient {
    var payload = Data(#"{"ok":true,"identity":null,"knowledge":{"stage":null,"next":[]},"can_edit":true}"#.utf8)
    var endpoint: APIEndpoint?
    var body: [String: Any]?
    func request<T: Decodable>(_ endpoint: APIEndpoint, body: [String: Any]? = nil) async throws -> T {
        self.endpoint = endpoint
        self.body = body
        let decoded = try JSONDecoder().decode(T.self, from: payload)
        return decoded
    }
}

@MainActor
final class IdentityFixtureAPI: BusinessIdentityAPI {
    var canEdit = true
    var failRead = false
    var failSave = false
    var sends = 0
    var contactReads = 0
    var match: BusinessRegistryMatch {
        try! JSONDecoder().decode(BusinessRegistryMatch.self, from: Data(#"{"record_id":"bc-123","legal_name":"Example Ltd","legal_structure":"Corporation","match":"exact"}"#.utf8))
    }
    func response(stage: String? = nil) throws -> BusinessIdentityCurrentResponse {
        let identity: Any = stage == nil ? NSNull() : ["entered_name":"Example", "legal_name":"Example Ltd", "operating_name":"Example"]
        let object: [String: Any] = ["ok": !failSave, "identity": identity,
                                   "knowledge": ["stage": stage as Any? ?? NSNull(), "next": []], "can_edit": canEdit]
        return try JSONDecoder().decode(BusinessIdentityCurrentResponse.self, from: JSONSerialization.data(withJSONObject: object))
    }
    func current() async throws -> BusinessIdentityCurrentResponse {
        if failRead { throw APIError.serverError("Unavailable") }
        return try response()
    }
    func search(_ name: String) async throws -> BusinessIdentitySearchResponse {
        try JSONDecoder().decode(BusinessIdentitySearchResponse.self, from: Data(#"{"ok":true,"status":"matches","matches":[{"record_id":"bc-123","legal_name":"Example Ltd","match":"exact"}]}"#.utf8))
    }
    func contacts() async throws -> BusinessContactsResponse {
        contactReads += 1
        return try JSONDecoder().decode(BusinessContactsResponse.self, from: Data(#"{"ok":true,"contacts":[{"field":"phone","value":"555-0100","source":"your_settings","status":"suggested"}],"google":"not_configured"}"#.utf8))
    }
    func send(_ body: [String: Any]) async throws -> BusinessIdentityCurrentResponse {
        sends += 1
        return try response(stage: body["mode"] as? String == "start" ? "recognized" : "identified")
    }
}

@main
struct BusinessIdentityChecks {
    // A bounded command-line runner for the MainActor view model.
    static func main() {
        DispatchQueue.global().asyncAfter(deadline: .now() + 30) {
            fputs("Business identity checks timed out.\n", stderr)
            exit(2)
        }
        Task { @MainActor in
            do { try await run() }
            catch { print("Business identity checks failed: \(error)"); exit(1) }
        }
        dispatchMain()
    }

    @MainActor
    static func run() async throws {
        print("Business identity checks: starting"); fflush(stdout)
        var failures = 0
        func check(_ condition: Bool, _ message: String) {
            if !condition { print("FAIL: \(message)"); failures += 1 }
        }
        let client = APIClient()
        let live = LiveBusinessIdentityAPI(client: client)
        for mode in ["start", "confirm", "manual"] {
            _ = try await live.send(["mode": mode, "name": "Example"])
            let endpoint = client.endpoint!
            let query = URLComponents(url: endpoint.url!, resolvingAgainstBaseURL: false)?.queryItems
            check(query?.first(where: { $0.name == "mode" })?.value == mode, "\(mode) is present in the URL, as the PHP endpoint requires")
            check(endpoint.httpMethod == "POST" && endpoint.requiresAuth, "identity writes use authenticated POST")
            check(client.body?["name"] as? String == "Example", "identity write retains its JSON payload")
        }
        do { _ = try await live.send(["mode": "unsupported"]); check(false, "unsupported action rejected") }
        catch APIError.invalidURL {} catch { check(false, "unexpected invalid-action error") }
        let query = URLComponents(url: APIEndpoint.businessIdentitySearch(name: "A & B + Sons").url!, resolvingAgainstBaseURL: false)!.queryItems!
        check(query.first(where: { $0.name == "q" })?.value == "A & B + Sons", "business names preserve query punctuation")

        let api = IdentityFixtureAPI()
        check(api.match.legalStructure == "Corporation", "legal_structure decodes from server spelling")
        let vm = BusinessIdentityViewModel(api: api)
        await vm.load()
        await vm.start("Example")
        if case .oneMatch = vm.state {} else { check(false, "registry search reaches confirmation") }
        api.failSave = true
        await vm.confirm(api.match)
        if case .confirmed = vm.state { check(false, "failed save never claims confirmation") }
        check(vm.error != nil && api.contactReads == 0, "failed save shows an error without fetching contacts")
        api.failSave = false
        await vm.confirm(api.match)
        if case .confirmed = vm.state {} else { check(false, "successful confirmation reaches saved state") }
        if case .available(let contacts) = vm.contactsState { check(contacts.count == 1, "contact suggestions load after confirmation") }
        else { check(false, "contact lookup finishes after confirmation") }
        vm.restart()
        check(vm.isEditing, "Change opens editing even when an identity already exists")
        if case .opening = vm.state {} else { check(false, "Change returns to the name step") }
        await vm.manual(legalName: "Example Ltd", operatingName: nil, legalStructure: nil, jurisdiction: nil)
        check(!vm.isEditing && api.contactReads == 2, "manual save also finishes contact lookup")
        if case .available = vm.contactsState {} else { check(false, "manual entry does not leave an indefinite spinner") }

        let readOnlyAPI = IdentityFixtureAPI(); readOnlyAPI.canEdit = false
        let readOnly = BusinessIdentityViewModel(api: readOnlyAPI)
        await readOnly.load(); await readOnly.start("Example"); await readOnly.confirm(api.match)
        readOnly.restart()
        check(readOnlyAPI.sends == 0 && !readOnly.isEditing, "read-only users cannot start or confirm changes")
        let failedAPI = IdentityFixtureAPI(); failedAPI.failRead = true
        let failed = BusinessIdentityViewModel(api: failedAPI)
        await failed.load(); await failed.start("Example")
        check(failed.current == nil && failed.error != nil && !failed.isBusy && failedAPI.sends == 0,
              "unavailable identity setup fails closed and can be retried")
        failedAPI.failRead = false; await failed.load()
        check(failed.current?.ok == true && failed.error == nil, "retry recovers from a failed initial load")

        client.payload = Data(#"{"ok":false}"#.utf8)
        let knowledge = LiveKnowledgeAPI(client: client)
        do { _ = try await knowledge.knowledge(); check(false, "knowledge error must not appear as empty success") }
        catch APIError.serverError {} catch { check(false, "unexpected knowledge error") }
        do { _ = try await knowledge.items(key: "vendor:1"); check(false, "item error must not appear as empty success") }
        catch APIError.serverError {} catch { check(false, "unexpected item error") }
        print(failures == 0 ? "Business identity checks: all passed" : "Business identity checks: \(failures) FAILED")
        exit(failures == 0 ? 0 : 1)
    }
}
