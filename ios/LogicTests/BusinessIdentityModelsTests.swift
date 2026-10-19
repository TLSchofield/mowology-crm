import Foundation

var failures = 0
func check(_ condition: Bool, _ message: String) {
    if !condition { failures += 1; print("FAIL: \(message)") }
}

func decode(_ json: String) throws -> BusinessContactsResponse {
    try JSONDecoder().decode(BusinessContactsResponse.self, from: Data(json.utf8))
}

do {
    let response = try decode(#"{"ok":true,"contacts":[{"field":"website","value":"example.ca","source":"your_settings","status":"suggested"},{"field":"phone","value":"1","source":"future_source","status":"suggested"},{"field":"address","value":"x","source":"your_settings","status":"future_status"}],"google":"ok"}"#)
    check(response.contacts.count == 1, "unknown contact source/status rows are skipped individually")
    check(response.contacts.first?.field == .website, "valid contact rows survive lossy decoding")
} catch { check(false, "mixed valid/unknown contacts should decode: \(error)") }

do {
    let response = try decode(#"{"ok":true,"contacts":[],"google":"not_configured"}"#)
    check(response.contacts.isEmpty, "a genuine empty contacts list decodes")
} catch { check(false, "empty contacts list should decode: \(error)") }

for malformed in [#"{"ok":true,"contacts":{}}"#, #"{"contacts":[]}"#] {
    do { _ = try decode(malformed); check(false, "malformed envelope must fail") }
    catch { }
}

print(failures == 0 ? "Business identity model checks: all passed" : "Business identity model checks: \(failures) FAILED")
exit(failures == 0 ? 0 : 1)
