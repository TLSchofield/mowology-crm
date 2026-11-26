import Foundation

func expect(_ actual: String?, _ expected: String?, _ label: String) {
    guard actual == expected else {
        fatalError("\(label): expected \(expected ?? "nil"), got \(actual ?? "nil")")
    }
}

expect(FormReasonRules.addTask(title: "  ", needsClient: false, hasClient: false), "Enter a title", "task title")
expect(FormReasonRules.addTask(title: "Salt", needsClient: true, hasClient: false), "Choose a client", "task client")
expect(FormReasonRules.addTask(title: "Salt", needsClient: false, hasClient: false), nil, "business task")

expect(FormReasonRules.addQuote(hasClient: false, title: "", lines: []), "Choose a client", "quote client first")
expect(FormReasonRules.addQuote(hasClient: true, title: "", lines: []), "Enter a title", "quote title second")
expect(FormReasonRules.addQuote(hasClient: true, title: "Cleanup", lines: []), "Add a quote line", "quote line third")
expect(FormReasonRules.addQuote(hasClient: true, title: "Cleanup", lines: [("", 1, 20)]), "Line 1 needs a description", "line description")
expect(FormReasonRules.addQuote(hasClient: true, title: "Cleanup", lines: [("Work", nil, 20)]), "Line 1 needs a valid quantity", "line quantity")
expect(FormReasonRules.addQuote(hasClient: true, title: "Cleanup", lines: [("Work", 1, nil)]), "Line 1 needs a valid price", "line price")
expect(FormReasonRules.addQuote(hasClient: true, title: "Cleanup", lines: [("Work", 1, 0)]), nil, "valid quote")

expect(FormReasonRules.vehicle(required: true, selected: false), "Choose a vehicle", "vehicle")
expect(FormReasonRules.vehicle(required: false, selected: false), nil, "only vehicle")
expect(FormReasonRules.askFirst(hasSelectedService: false, serviceCanAsk: false, blockedReason: "No recipient"), "Choose a service", "ask service first")
expect(FormReasonRules.askFirst(hasSelectedService: true, serviceCanAsk: false, blockedReason: "No recipient"), "Ask first isn't available for this service", "ask eligibility second")
expect(FormReasonRules.askFirst(hasSelectedService: true, serviceCanAsk: true, blockedReason: "No recipient"), "No recipient", "ask recipient reason")
expect(FormReasonRules.askFirst(hasSelectedService: true, serviceCanAsk: true, blockedReason: nil), nil, "ask available")
expect(FormReasonRules.requiredText(" \n", reason: "Enter a product name"), "Enter a product name", "blank product name")
expect(FormReasonRules.requiredText("Mulch", reason: "Enter a product name"), nil, "valid product name")
expect(FormReasonRules.specialRequestMinutes(""), "Enter the minutes it took", "blank minutes")
expect(FormReasonRules.specialRequestMinutes("abc"), "Enter the minutes it took", "nonnumeric minutes")
expect(FormReasonRules.specialRequestMinutes("0"), "Enter the minutes it took", "zero minutes")
expect(FormReasonRules.specialRequestMinutes("12"), nil, "valid minutes")
expect(FormReasonRules.positivePrice(nil), "Enter a price", "blank price")
expect(FormReasonRules.positivePrice(10), nil, "valid price")
expect(FormReasonRules.receiptTotal(""), "Enter a valid total", "blank total")
expect(FormReasonRules.receiptTotal("12.50"), nil, "valid total")

print("Form reason checks passed")
