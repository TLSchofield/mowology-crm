import Foundation

private func item(_ name: String, _ source: VendorItemSource = .usual, count: Int = 0,
                  confirmed: Bool = false, date: String? = nil) -> VendorItemSuggestion {
    VendorItemSuggestion(name: name, source: source, useCount: count, confirmed: confirmed,
                         lastDate: date, suggestedTotal: nil, lastQuantity: nil)
}

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() { fatalError(message) }
}

let now = Date(timeIntervalSince1970: 2_000_000)
expect(!VendorItemRules.isExpired(savedAt: now.addingTimeInterval(-86_399), now: now), "fresh before 24 hours")
expect(VendorItemRules.isExpired(savedAt: now.addingTimeInterval(-86_400), now: now), "expires at 24 hours")
expect(VendorItemRules.isExpired(savedAt: now.addingTimeInterval(1), now: now), "future timestamps refresh")

let merged = VendorItemRules.merge(
    usual: [item("Edging", count: 2), item("Mulch", count: 8), item("Soil", count: 2, confirmed: true)],
    catalogue: [item("mulch", .catalogue), item("Seed", .catalogue)]
)
expect(merged.map(\.name) == ["Mulch", "Soil", "Edging", "Seed"], "usual ranking and catalogue merge")

let capped = VendorItemRules.merge(
    usual: (0..<80).map { item("Item \($0)", count: 80 - $0) },
    catalogue: []
)
expect(capped.count == 60, "per-vendor row cap")

let filtered = VendorItemRules.filter([item("Bark mulch"), item("Mulch, black"), item("Topsoil")], query: "mulch")
expect(filtered.map(\.name) == ["Mulch, black", "Bark mulch"], "local filtering puts prefixes first")
expect(VendorItemRules.filter([item("Élite Seed")], query: "elite").count == 1, "filter ignores accents and case")

expect(VendorItemRules.pickerQuery(name: "LB 21 3N1", hasEditedName: false).isEmpty,
       "an untouched OCR name does not hide supplier items")
expect(VendorItemRules.pickerQuery(name: "mulch", hasEditedName: true) == "mulch",
       "editing the name starts local filtering")

var utcCalendar = Calendar(identifier: .gregorian)
utcCalendar.timeZone = TimeZone(secondsFromGMT: 0)!
let prefetchNow = Date(timeIntervalSince1970: 1_767_225_600) // 2026-01-01 UTC
let recentIDs = VendorItemRules.recentVendorIDs(
    receipts: [(7, "2026-01-01"), (8, "2025-10-03"), (9, "2025-10-02"), (7, "2025-12-01"), (nil, "2025-12-31"), (10, "bad")],
    now: prefetchNow,
    calendar: utcCalendar
)
expect(recentIDs == Set([7, 8]), "prefetch includes the 90-day boundary, rejects older and malformed rows, and deduplicates")

print("VendorItemRulesTests passed")
