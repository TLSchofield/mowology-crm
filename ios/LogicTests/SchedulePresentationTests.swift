import Foundation

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() { fatalError(message) }
}

expect(DisplayNameFormatter.string("TIM SCHOFIELD") == "Tim Schofield", "person name should become readable")
expect(DisplayNameFormatter.string("VANCOUVER MANAGEMENT LTD") == "Vancouver Management Ltd", "company suffix should be readable")
expect(DisplayNameFormatter.string("PACIFIC BC ULC") == "Pacific BC ULC", "BC and ULC should remain acronyms")
expect(DisplayNameFormatter.string("McDonald Services") == "McDonald Services", "mixed-case stored names must remain unchanged")

let suiteName = "OpeningAssemblyStoreTests-\(UUID().uuidString)"
let defaults = UserDefaults(suiteName: suiteName)!
defer { defaults.removePersistentDomain(forName: suiteName) }
var calendar = Calendar(identifier: .gregorian)
calendar.timeZone = TimeZone(identifier: "America/Vancouver")!
let formatter = ISO8601DateFormatter()
formatter.formatOptions = [.withInternetDateTime]
let beforeFour = formatter.date(from: "2026-10-18T10:30:00Z")! // 03:30 local
let afterFour = formatter.date(from: "2026-10-18T12:00:00Z")!  // 05:00 local
expect(OpeningAssemblyStore.shouldPlay(date: beforeFour, defaults: defaults, calendar: calendar), "first open should play")
OpeningAssemblyStore.markPlayed(date: beforeFour, defaults: defaults, calendar: calendar)
expect(!OpeningAssemblyStore.shouldPlay(date: beforeFour, defaults: defaults, calendar: calendar), "same app day should not replay")
expect(OpeningAssemblyStore.shouldPlay(date: afterFour, defaults: defaults, calendar: calendar), "04:00 boundary should start a new app day")
OpeningAssemblyStore.markPlayed(date: afterFour, defaults: defaults, calendar: calendar)
expect(!OpeningAssemblyStore.shouldPlay(date: afterFour, defaults: defaults, calendar: calendar), "new app day should play only once")

print("Schedule presentation tests passed")
