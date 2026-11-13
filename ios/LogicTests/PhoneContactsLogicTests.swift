import Foundation

enum PhoneContactsLogicTests {
    static func run() -> [String] {
        var failures: [String] = []
        let named = PhoneContact(id: "1", first: "Ada", last: "Lovelace", company: "Engine Co", emails: ["ada@example.com"], phones: [.init(label: "mobile", number: "+1 604 555 0101")], address: nil)
        let company = PhoneContact(id: "2", first: "", last: "", company: "Green Works", emails: [], phones: [], address: .init(street: "1 Main, Unit 2", city: "Victoria", province: "BC", postal: "V1V 1V1"))
        if PhoneContactsLogic.displayName(named) != "Ada Lovelace" { failures.append("display name should join first and last") }
        if PhoneContactsLogic.displayName(company) != "Green Works" { failures.append("display name should fall back to company") }
        if !PhoneContactsLogic.matches(named, search: "engine") || !PhoneContactsLogic.matches(named, search: "5550101") { failures.append("search should cover company and phone digits") }
        if PhoneContactsLogic.matches(named, search: "grace") { failures.append("unrelated search should not match") }
        let all = PhoneContactsLogic.selectingAllShown(selected: ["outside"], shown: [named, company])
        if all != ["outside", "1", "2"] { failures.append("select all shown should preserve hidden selections") }
        if PhoneContactsLogic.clearingShown(selected: all, shown: [named]) != ["outside", "2"] { failures.append("clear should remove only shown contacts") }
        if PhoneContactsLogic.batches(Array(repeating: named, count: 201)).map(\.count) != [100, 100, 1] { failures.append("batches should be capped at 100") }
        if PhoneContactsLogic.nextBatch(totalBatches: 3, confirmedBatchCount: 1) != 1 || PhoneContactsLogic.nextBatch(totalBatches: 3, confirmedBatchCount: 3) != nil { failures.append("next batch should be the first unconfirmed batch") }
        let tally = PhoneContactsLogic.tally([.init(ref: "1", outcome: .created, contactId: 4), .init(ref: "2", outcome: .matched, contactId: 5), .init(ref: "3", outcome: .skipped, contactId: nil)])
        if tally != PhoneContactTally(created: 1, matched: 1, skipped: 1) { failures.append("results should tally every outcome") }
        let wire = PhoneContactsLogic.wire(company)
        if wire["ref"] as? String != "2" || !(wire["phones"] as? [[String: String]] ?? []).isEmpty || wire["address"] is NSNull { failures.append("wire payload should keep exact contact fields") }
        failures += decodingFailures()
        return failures
    }

    /// The map the server really sends (Codex review 2026-11-11): other steps' data has no `count`, a step with no data
    /// sends `[]`, the first unfinished step is "next", a skipped one is "skipped". None of that may break this card.
    static func decodingFailures() -> [String] {
        var failures: [String] = []
        let json = """
        {"ok":true,"next":"contacts","ready_to_finish":false,"steps":[
         {"key":"name","title":"About you","state":"done","head":"Penny","skippable":false,"data":{"first":"Ada","parts":{"name":true}}},
         {"key":"terms","title":"Terms","state":"done","head":"Penny","skippable":false,"data":[]},
         {"key":"quickbooks","title":"QuickBooks","state":"skipped","head":"Penny","skippable":true,"data":{"answer":"no","connected":false}},
         {"key":"contacts","title":"Your clients from your phone","state":"next","head":"Sam","skippable":true,"data":{"answer":null,"count":0}},
         {"key":"gst","title":"GST","state":"somethingnew","head":"Penny","skippable":true,"data":{"answer":true}}]}
        """
        guard let response = try? JSONDecoder().decode(OnboardingMapResponse.self, from: Data(json.utf8)) else {
            return ["a realistic onboarding map should decode"]
        }
        let states = response.steps.map(\.state)
        if states != [.done, .done, .skipped, .next, .other] { failures.append("map states next/skipped/unknown should decode, got \(states)") }
        if response.steps.first(where: { $0.key == "contacts" })?.data?.count != 0 { failures.append("contacts data should decode its count") }
        if response.steps.first(where: { $0.key == "name" })?.data?.count != nil { failures.append("a step without a count should decode with none") }
        let step = #"{"ok":true,"next":null,"data":{"answer":"imported","count":3},"results":[{"ref":"a","outcome":"created","contact_id":9}]}"#
        guard let done = try? JSONDecoder().decode(OnboardingStepResponse.self, from: Data(step.utf8)) else { return failures + ["a step response should decode"] }
        if done.data?.count != 3 || done.results.count != 1 { failures.append("step response should keep count and results") }
        return failures
    }
}

let phoneContactFailures = PhoneContactsLogicTests.run()
for failure in phoneContactFailures { print("FAIL: \(failure)") }
if !phoneContactFailures.isEmpty { print("PhoneContactsLogic: \(phoneContactFailures.count) failed"); exit(1) }
print("PhoneContactsLogic: all passed")
