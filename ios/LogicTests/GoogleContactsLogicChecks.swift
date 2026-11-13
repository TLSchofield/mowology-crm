import Foundation

func googleContactsLogicChecks() -> [String] {
    googleContactsCallbackChecks() + googleContactsWireChecks()
}

func googleContactsCallbackChecks() -> [String] {
    var failures: [String] = []
    let cases: [(String, GoogleSessionResult)] = [
        ("ittai-google://done?ok=1&nonce=n-1&grant=g-1", .ok(nonce: "n-1", grant: "g-1")),
        ("ittai-google://done?ok=0&reason=denied", .denied),
        ("ittai-google://done?ok=0&reason=expired", .expired),
        ("ittai-google://done?ok=0&reason=failed", .failed),
        ("ittai-google://done?ok=1&nonce=n-1", .unrecognised),
        ("https://example.test/done?ok=1&nonce=n-1&grant=g-1", .unrecognised)
    ]
    for (value, expected) in cases {
        guard let url = URL(string: value) else {
            failures.append("Check URL could not be constructed.")
            continue
        }
        let actual = PhoneContactsLogic.googleResult(from: url)
        if actual != expected { failures.append("Expected \(expected) for callback, got \(actual).") }
    }
    return failures
}

func googleContactsWireChecks() -> [String] {
    var failures: [String] = []
    let contact = PhoneContact(id: "ref", first: "A", last: "Person", company: "", emails: [], phones: [], address: nil)
    if PhoneContactsLogic.wire(contact, source: "google")["source"] as? String != "google" {
        failures.append("Google contact wire payload did not retain its source.")
    }

    let json = Data(#"{"ok":true,"contacts":[{"ref":"people/1","first":"A","phones":[{"number":"604-555-0100"}]}]}"#.utf8)
    do {
        let response = try JSONDecoder().decode(OnboardingStepResponse.self, from: json)
        guard let decoded = response.contacts.first?.phoneContact else {
            failures.append("Google wire contact was not decoded.")
            return failures
        }
        if decoded.id != "people/1" || decoded.first != "A" || decoded.last != "" || decoded.phones.first?.label != "" {
            failures.append("Google wire contact did not decode missing fields leniently.")
        }
    } catch {
        failures.append("Google wire response did not decode: \(error)")
    }
    return failures
}
