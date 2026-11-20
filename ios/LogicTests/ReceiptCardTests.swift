import Foundation

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() { fatalError(message) }
}

let json = #"{"suggestion_id":1,"expense_id":2,"status":"draft","question":{"kind":"purpose","prompt":"What was this for?","options":[{"key":"materials","label":"Materials"}],"memo":"Filed to the matching job."}}"#
let item = try JSONDecoder().decode(BookkeeperItem.self, from: Data(json.utf8))
expect(item.question?.kind == .purpose, "question kind must decode")
expect(item.question?.options.first?.key == "materials", "option key must decode unchanged")
expect(item.question?.memo == "Filed to the matching job.", "memo must decode")

let legacy = try JSONDecoder().decode(BookkeeperItem.self, from: Data(#"{"suggestion_id":3,"expense_id":4,"status":"draft"}"#.utf8))
expect(legacy.question == nil, "an absent question must preserve the legacy confirm path")

for invalidQuestion in [
    #"{"suggestion_id":3,"expense_id":4,"status":"draft","question":{"kind":"typo","prompt":"Check","options":[{"key":"yes","label":"Yes"}]}}"#,
    #"{"suggestion_id":3,"expense_id":4,"status":"draft","question":{"kind":"confirm","prompt":"Check","options":[]}}"#
] {
    do {
        _ = try JSONDecoder().decode(BookkeeperItem.self, from: Data(invalidQuestion.utf8))
        fatalError("an invalid question must fail the response contract")
    } catch DecodingError.dataCorrupted, DecodingError.keyNotFound, DecodingError.typeMismatch, DecodingError.valueNotFound {
        // Expected: never reinterpret a malformed question as legacy approval.
    }
}

let container = ReceiptPhotoPanelLogic.Size(width: 390, height: 700)
let panel = ReceiptPhotoPanelLogic.Size(width: 176, height: 220)
expect(ReceiptPhotoPanelLogic.nearestCorner(to: .init(x: 370, y: 20), panel: panel, container: container, inset: 8) == .topTrailing,
       "photo must snap to the nearest top corner")
expect(ReceiptPhotoPanelLogic.nearestCorner(to: .init(x: 10, y: 650), panel: panel, container: container, inset: 8) == .bottomLeading,
       "photo must snap to the nearest bottom corner")
expect(ReceiptPhotoPanelLogic.clampedZoom(0.2) == 1, "zoom must clamp to fit")
expect(ReceiptPhotoPanelLogic.clampedZoom(9) == 6, "zoom must clamp to its maximum")

var memory = ReceiptPhotoPanelLogic.Memory(corner: .bottomTrailing, size: panel)
memory.corner = .topLeading
expect(memory == .init(corner: .topLeading, size: panel), "panel memory must preserve corner and size between receipts")
let resized = ReceiptPhotoPanelLogic.resized(panel, by: .init(x: 500, y: -500),
                                             minimum: .init(width: 132, height: 176),
                                             maximum: .init(width: 264, height: 440))
expect(resized == .init(width: 264, height: 176), "panel resize must clamp both dimensions")

print("ReceiptCardTests passed")
