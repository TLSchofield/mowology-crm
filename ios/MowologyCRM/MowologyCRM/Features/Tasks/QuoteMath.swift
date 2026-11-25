//
//  QuoteMath.swift
//  MowologyCRM
//
//  The totals shown on Add → Quote. The server works them out again when the draft is saved (QuoteFromPhoneService);
//  these are what the owner sees with no signal. Foundation only, checked by ios/LogicTests/QuoteMathTests.swift.
//

import Foundation

enum QuoteMath {
    /// Cents, half up: the same rounding as PHP's round(x, 2) for prices.
    static func money(_ x: Double) -> Double { (x * 100).rounded() / 100 }

    static func lineTotal(quantity: Double, price: Double) -> Double { money(quantity * price) }

    static func totals(lines: [(quantity: Double, price: Double)], gstPercent: Double) -> (subtotal: Double, gst: Double, total: Double) {
        let subtotal = money(lines.reduce(0) { $0 + lineTotal(quantity: $1.quantity, price: $1.price) })
        let gst = money(subtotal * gstPercent / 100)
        return (subtotal, gst, money(subtotal + gst))
    }

    /// "12.5" → 12.5; "" or text that isn't a number → nil. Accepts a comma as the decimal mark.
    static func number(_ text: String) -> Double? {
        Double(text.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: "."))
    }
}
