import Foundation

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        fputs("FAIL: \(message)\n", stderr)
        exit(1)
    }
}

for value in ["Page 1 of 1", "PAGE 12 OF 14", "Receipt", "Receipt!", "Thank you",
              "Unknown vendor", "123456", "$12.34", "---"] {
    expect(ReceiptRowRules.isMerchantDoubtful(value), "expected doubtful merchant: \(value)")
}

for value in ["Page Hardware", "Receipt Coffee Co.", "Thank You Market", "7-Eleven", "Mowology"] {
    expect(!ReceiptRowRules.isMerchantDoubtful(value), "expected merchant: \(value)")
}

expect(ReceiptRowRules.isMerchantDoubtful(nil), "nil should be doubtful")
expect(ReceiptRowRules.isMerchantDoubtful("  \n"), "blank should be doubtful")

print("ReceiptRowRules checks passed")
