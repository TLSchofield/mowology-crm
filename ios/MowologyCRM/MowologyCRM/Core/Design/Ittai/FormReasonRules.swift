import Foundation

enum FormReasonRules {
    static func requiredText(_ value: String, reason: String) -> String? {
        value.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? reason : nil
    }

    static func addTask(title: String, needsClient: Bool, hasClient: Bool) -> String? {
        if let reason = requiredText(title, reason: "Enter a title") { return reason }
        return needsClient && !hasClient ? "Choose a client" : nil
    }

    static func addQuote(hasClient: Bool, title: String, lines: [(description: String, quantity: Double?, price: Double?)]) -> String? {
        if !hasClient { return "Choose a client" }
        if let reason = requiredText(title, reason: "Enter a title") { return reason }
        if lines.isEmpty { return "Add a quote line" }
        for (index, line) in lines.enumerated() {
            let label = "Line \(index + 1)"
            if line.description.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { return "\(label) needs a description" }
            if (line.quantity ?? 0) <= 0 { return "\(label) needs a valid quantity" }
            guard let price = line.price, price >= 0 else { return "\(label) needs a valid price" }
        }
        return nil
    }

    static func vehicle(required: Bool, selected: Bool) -> String? {
        required && !selected ? "Choose a vehicle" : nil
    }

    static func askFirst(hasSelectedService: Bool, serviceCanAsk: Bool, blockedReason: String?) -> String? {
        if !hasSelectedService { return "Choose a service" }
        if !serviceCanAsk { return "Ask first isn't available for this service" }
        return blockedReason
    }

    static func specialRequestMinutes(_ text: String) -> String? {
        guard let minutes = Int(text.trimmingCharacters(in: .whitespacesAndNewlines)), minutes > 0 else {
            return "Enter the minutes it took"
        }
        return nil
    }

    static func positivePrice(_ price: Double?) -> String? {
        (price ?? 0) > 0 ? nil : "Enter a price"
    }

    static func receiptTotal(_ text: String) -> String? {
        guard let value = Double(text.replacingOccurrences(of: ",", with: ".")), value >= 0 else {
            return "Enter a valid total"
        }
        return nil
    }
}
