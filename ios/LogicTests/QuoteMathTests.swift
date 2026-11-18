import Foundation

var failures = 0
func check<T: Equatable>(_ got: T, _ want: T, _ label: String) {
    if got != want { failures += 1; print("FAIL \(label): got \(got), want \(want)") }
}

check(QuoteMath.lineTotal(quantity: 2, price: 40.125), 80.25, "line total")
let t = QuoteMath.totals(lines: [(1, 100), (2, 33.33)], gstPercent: 5)
check(t.subtotal, 166.66, "subtotal")
check(t.gst, 8.33, "gst")
check(t.total, 174.99, "total")
let none = QuoteMath.totals(lines: [], gstPercent: 5)
check(none.total, 0, "no lines")
check(QuoteMath.totals(lines: [(1, 50)], gstPercent: 0).gst, 0, "not GST registered")
check(QuoteMath.number(" 12.5 "), 12.5, "decimal")
check(QuoteMath.number("12,5"), 12.5, "comma")
check(QuoteMath.number("abc"), nil, "not a number")
check(QuoteMath.number(""), nil, "empty")

if failures > 0 { print("QuoteMath: \(failures) failed"); exit(1) }
print("QuoteMath: all passed")
