import Foundation

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() {
        fputs("FAIL: \(message)\n", stderr)
        exit(1)
    }
}

for method in ReceiptPaymentMethod.known {
    expect(ReceiptPaymentMethod.recognized(method) == method, "known method \(method) must be preserved")
}
expect(ReceiptPaymentMethod.recognized(nil).isEmpty, "nil must remain unselected")
expect(ReceiptPaymentMethod.recognized("").isEmpty, "blank must remain unselected")
expect(ReceiptPaymentMethod.recognized("card").isEmpty, "unknown OCR value must remain unselected")
expect(ReceiptPaymentMethod.suggested(card: "company_card", parsed: "cash") == "company_card",
       "a known matched card method takes precedence")
expect(ReceiptPaymentMethod.suggested(card: "unknown", parsed: "cash") == "cash",
       "an unknown card value must not hide a known parsed method")
expect(ReceiptPaymentMethod.suggested(card: nil, parsed: "unknown").isEmpty,
       "an unknown parsed method must remain unselected")

print("ReceiptPaymentMethodTests passed")
