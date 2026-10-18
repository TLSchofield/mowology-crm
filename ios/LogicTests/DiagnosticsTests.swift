import Foundation

@main
struct DiagnosticsChecks {
    static func main() async throws {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent("diagnostic-tests-" + UUID().uuidString)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        defer {
            do { try FileManager.default.removeItem(at: root) }
            catch { print("Test cleanup failed: \(error)") }
        }
        func check(_ condition: Bool, _ description: String) {
            if !condition { fatalError(description) }
        }
        let directory = root.appendingPathComponent("logs")
        let logger = AppDiagnostics(directory: directory, maxBytes: 256)
        logger.record(category: "network", message: "status=503 error=Unavailable id=first")
        let first = try await logger.snapshot()
        check(first.text.contains("status=503 error=Unavailable"), "Retain actionable error details")
        let reopened = AppDiagnostics(directory: directory, maxBytes: 256)
        check(try await reopened.snapshot().text.contains("id=first"), "Reopening must retain prior events")
        for i in 0..<10 { reopened.record(category: "test", message: "event-\(i) " + String(repeating: "x", count: 140)) }
        let rotated = try await reopened.snapshot()
        for index in [0, 1, 2] {
            let name = index == 0 ? "diagnostics.log" : "diagnostics.\(index).log"
            let size = try FileManager.default.attributesOfItem(atPath: directory.appendingPathComponent(name).path)[.size] as! Int
            check(size <= 256, "Each persisted log must respect the configured bound")
        }
        check(rotated.text.contains("event-7") && rotated.text.contains("event-8") && rotated.text.contains("event-9"), "Exports include both archives and active log")
        check(!rotated.text.contains("event-6"), "Only two archives are retained")
        check(try String(contentsOf: rotated.url) == rotated.text, "Export file matches snapshot")
        reopened.record(category: "test", message: String(repeating: "🌱", count: 500))
        _ = try await reopened.snapshot()
        let capped = try Data(contentsOf: directory.appendingPathComponent("diagnostics.log"))
        check(capped.count <= 256 && String(data: capped, encoding: .utf8) != nil, "Oversized Unicode records remain valid and bounded")
        check(String(decoding: capped, as: UTF8.self).contains("[truncated: original "), "Oversized events explicitly report truncation")

        let secretInput = #"status=401 error=Unauthorized Bearer abc.def-123 {"token":"one", "access_token":"two", "refresh_token":"three", "password":"four", "secret":"five", "api_key":"six", "csrf_token":"seven", "session":"eight", "session_id":"nine", "detail":"Expired"} https://u:pass@example.com/api?mode=status&action=read&token=ten&email=private#fragment csrf=eleven password=twelve"#
        let safe = AppDiagnostics.redacted(secretInput)
        for secret in ["abc.def-123", "\"one\"", "\"two\"", "\"three\"", "\"four\"", "\"five\"", "\"six\"", "\"seven\"", "\"eight\"", "\"nine\"", "=ten", "private", "fragment", "eleven", "twelve", "u:pass"] {
            check(!safe.contains(secret), "Leaked secret \(secret): \(safe)")
        }
        check(safe.contains("status=401") && safe.contains("Unauthorized") && safe.contains("Expired"), "Redaction retains status and error details")
        check(safe.contains("mode=status") && safe.contains("action=read"), "Retain routing selectors")
        let relative = AppDiagnostics.redacted("/api/schedule/clock?mode=status&email=private HTTPS://example.com/x?account=hidden")
        check(!relative.contains("private") && !relative.contains("hidden"), "All URL queries are stripped, including relative routes")
        check(relative.contains("mode=status"), "Relative routes retain mode")
        let extraSecrets = AppDiagnostics.redacted("Authorization: Basic YWxhZGRpbjpvcGVuc2VzYW1l\nCookie: session=private-cookie; other=private-other\nSet-Cookie: PHPSESSID=private-session; HttpOnly\n" + #"{"client_secret":"private-client","auth_token":"private-auth","auth_key":"private-key","authorization":"private-authorization","cookie":"private-json-cookie"} status=403 error=Forbidden eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc123"#)
        check(!extraSecrets.contains("private-") && !extraSecrets.contains("YWxhZGRpbjpvcGVuc2VzYW1l") && !extraSecrets.contains("eyJ"), "Authorization, cookies, JWTs and additional secret keys are redacted")
        check(extraSecrets.contains("status=403") && extraSecrets.contains("Forbidden"), "Additional secret redaction preserves failure details")
        let secure = AppDiagnostics(directory: root.appendingPathComponent("secure"))
        secure.record(category: "auth", message: secretInput)
        check(!(try await secure.snapshot()).text.contains("abc.def-123"), "Disk write redacts secrets")

        let blocked = root.appendingPathComponent("not-a-directory")
        try Data("file".utf8).write(to: blocked)
        let failed = AppDiagnostics(directory: blocked)
        failed.record(category: "test", message: "cannot write")
        do {
            _ = try await failed.snapshot()
            fatalError("A failed append must be exposed by snapshot")
        } catch {
            check(error.localizedDescription.contains("append diagnostic event"), "Expose the failed disk operation precisely")
            let firstFailure = error.localizedDescription
            do { _ = try await failed.snapshot(); fatalError("Failure unexpectedly cleared") }
            catch { check(error.localizedDescription == firstFailure, "Repeated snapshots keep the original failure precise") }
        }
        let unavailable = ErrorDetails.response(Data(#"{"error":"Ledger temporarily unavailable","code":"LEDGER_BUSY"}"#.utf8), status: 503)
        check(unavailable == "HTTP 503 [code: LEDGER_BUSY]: Ledger temporarily unavailable", "Preserve exact HTTP status, server code and message")
        let serverFailure = APIError.serverError(unavailable)
        check(serverFailure.localizedDescription == unavailable, "APIError must preserve server detail without assuming a network failure")
        check(!serverFailure.localizedDescription.contains("Network error"), "A server refusal is not a transport failure")

        // A transient file-protection/disk error must not permanently hide readable logs.
        try FileManager.default.removeItem(at: blocked)
        let recovered = try await failed.snapshot()
        check(recovered.text.contains("Previous log-storage failure:"), "Recovered exports retain the precise earlier storage error")
        failed.record(category: "test", message: "storage recovered")
        check((try await failed.snapshot()).text.contains("storage recovered"), "Logging recovers once storage becomes writable")

        struct IdentityEnvelope: Decodable {
            struct Identity: Decodable { let stage: String }
            let identity: Identity
        }
        do {
            _ = try JSONDecoder().decode(IdentityEnvelope.self, from: Data(#"{"identity":{}}"#.utf8))
            fatalError("Invalid fixture unexpectedly decoded")
        } catch {
            let detail = APIError.decodingError(error).localizedDescription
            check(detail.contains("Missing key 'stage'") && detail.contains("at identity:"), "Decoding failures identify missing key and nested path")
        }
        do {
            _ = try JSONDecoder().decode(IdentityEnvelope.self, from: Data("{broken".utf8))
            fatalError("Malformed JSON unexpectedly decoded")
        } catch {
            let detail = ErrorDetails.describe(error)
            check(detail.contains("Invalid response data at $:"), "Malformed JSON retains decoding kind and root path")
        }
        let timeout = APIError.networkError(URLError(.timedOut)).localizedDescription
        check(timeout.contains("NSURLErrorDomain (-1001)"), "Timeout retains actual URL error domain and numeric code")
        var request = URLRequest(url: URL(string: "https://person:password@example.com/api/test?mode=status&token=hidden-token&email=private@example.com#fragment")!)
        request.httpMethod = "POST"
        request.httpBody = Data("private-request-body".utf8)
        request.setValue("Bearer private-header", forHTTPHeaderField: "Authorization")
        let label = ErrorDetails.request(request)
        check(label == "POST https://example.com/api/test?mode=status", "Request label includes only method, route and permitted selectors")
        check(!label.contains("private") && !label.contains("hidden-token") && !label.contains("password"), "Request labels never contain body, headers, credentials or private query values")
        print("Diagnostics checks passed")
    }
}
