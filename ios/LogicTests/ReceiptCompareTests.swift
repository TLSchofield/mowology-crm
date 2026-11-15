import Foundation

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        fputs("ReceiptCompareTests failed: \(message)\n", stderr)
        exit(1)
    }
}

let changed = ReceiptCompareRow(id: "total", label: "Total", current: "$10.00", proposed: "$12.00")
expect(changed.state == .changed, "different values are changed")

let unchanged = ReceiptCompareRow(id: "date", label: "Date", current: "2026-11-14", proposed: " 2026-11-14 ")
expect(unchanged.state == .unchanged, "trimmed equal values are unchanged")

let missing = ReceiptCompareRow(id: "gst", label: "GST", current: nil, proposed: "$1.00")
expect(missing.state == .missingCurrent, "a value absent from the receipt is missing")

let notProposed = ReceiptCompareRow(id: "pst", label: "PST", current: "$2.00", proposed: nil)
expect(notProposed.state == .notProposed, "an absent suggestion is not proposed")

let empty = ReceiptCompareRow(id: "note", label: "Note", current: "  ", proposed: nil)
expect(empty.state == .unchanged, "two absent values are quiet")

private func decodedItems(receipt: [(String, Double)], penny: [(String, Double)]) -> PennyReadItems {
    let object: [String: Any] = [
        "receipt": receipt.map { ["name": $0.0, "amount": $0.1] },
        "penny": penny.map { ["name": $0.0, "amount": $0.1] },
        "receipt_sum": receipt.reduce(0) { $0 + $1.1 },
        "penny_sum": penny.reduce(0) { $0 + $1.1 },
    ]
    let data = try! JSONSerialization.data(withJSONObject: object)
    return try! JSONDecoder().decode(PennyReadItems.self, from: data)
}

let reordered = ReceiptCompareRules.items(decodedItems(
    receipt: [("Rake", 10), ("Soil", 20)],
    penny: [("Soil", 20), ("Rake", 10)]
))
expect(reordered.count == 2 && reordered.allSatisfy { $0.state == .unchanged },
       "reordered items match by identity instead of array position")

let deletion = ReceiptCompareRules.items(decodedItems(
    receipt: [("Rake", 10), ("Soil", 20)],
    penny: [("Soil", 20)]
))
expect(deletion[0].currentName == "Rake" && deletion[0].proposedName == nil && deletion[0].state == .notProposed,
       "a deleted item is not paired with the following proposal")
expect(deletion[1].currentName == "Soil" && deletion[1].proposedName == "Soil" && deletion[1].state == .unchanged,
       "an item following a deletion remains aligned")

let insertion = ReceiptCompareRules.items(decodedItems(
    receipt: [("Soil", 20)],
    penny: [("Rake", 10), ("Soil", 20)]
))
expect(insertion[0].currentName == "Soil" && insertion[0].proposedName == "Soil",
       "an item following an insertion remains aligned")
expect(insertion[1].currentName == nil && insertion[1].proposedName == "Rake" && insertion[1].state == .missingCurrent,
       "an inserted proposal is shown as missing from the current receipt")

let amountChange = ReceiptCompareRules.items(decodedItems(receipt: [("Soil", 20)], penny: [(" soil ", 22)]))
expect(amountChange.count == 1 && amountChange[0].state == .changed,
       "normalized names align an item whose amount changed")

let currentItem = ReceiptCompareItemRow(index: 0, currentName: "Rake", currentAmount: 10, proposedName: "Rake", proposedAmount: 10, state: .unchanged)
expect(ReceiptCompareRules.selection(differs: false, fieldRows: [], itemRows: [currentItem]) == .penny,
       "matching saved proposal selects Penny")
expect(ReceiptCompareRules.selection(differs: true, fieldRows: [changed], itemRows: []) == .current,
       "a later mismatch selects the current receipt")
expect(ReceiptCompareRules.selection(differs: false, fieldRows: [], itemRows: []) == .current,
       "an empty suggestion never claims to be applied")

print("ReceiptCompareTests passed")
