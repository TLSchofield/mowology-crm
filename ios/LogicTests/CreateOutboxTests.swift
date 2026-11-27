import Foundation

var failures = 0
func check<T: Equatable>(_ got: T, _ want: T, _ label: String) {
    if got != want { failures += 1; print("FAIL \(label): got \(got), want \(want)") }
}
typealias I = CreateOutboxLogic.Item

let items = [I(id: "a", userId: 7, refused: nil), I(id: "b", userId: 9, refused: nil),
             I(id: "c", userId: 7, refused: "Property not found"), I(id: "d", userId: 7, refused: nil)]

check(CreateOutboxLogic.mine(items, me: 7).map(\.id), ["a", "c", "d"], "only the maker's entries")
check(CreateOutboxLogic.mine(items, me: nil).map(\.id), [], "signed out sends nothing")
check(CreateOutboxLogic.toSend(items, me: 7).map(\.id), ["a", "d"], "refused ones wait for a person, order kept")
check(CreateOutboxLogic.statusLine(items, me: 7), "2 waiting to sync · 1 couldn't be saved", "both counts")
check(CreateOutboxLogic.statusLine(items, me: 9), "1 waiting to sync", "another login sees only theirs")
check(CreateOutboxLogic.statusLine(items, me: 3), nil, "nothing to say")
check(CreateOutboxLogic.statusLine([I(id: "x", userId: 3, refused: "no")], me: 3), "1 couldn't be saved", "refused only")

let t0 = Date(timeIntervalSince1970: 1_000_000)
var fault = CreateOutboxLogic.afterServerFault(failures: nil, firstFailureAt: nil, now: t0)
check(fault.failures, 1, "first server fault counts one")
check(fault.firstFailureAt, t0, "first fault starts the clock")
check(fault.giveUp, false, "one fault never gives up")
for i in 1..<5 {   // five quick faults inside the first minutes
    fault = CreateOutboxLogic.afterServerFault(failures: fault.failures, firstFailureAt: fault.firstFailureAt, now: t0.addingTimeInterval(Double(i) * 60))
}
check(fault.failures, 5, "five faults counted")
check(fault.giveUp, false, "five quick faults are not enough")
fault = CreateOutboxLogic.afterServerFault(failures: fault.failures, firstFailureAt: fault.firstFailureAt, now: t0.addingTimeInterval(3600))
check(fault.giveUp, true, "after an hour it needs attention")
let day = CreateOutboxLogic.afterServerFault(failures: 2, firstFailureAt: t0, now: t0.addingTimeInterval(86_400))
check(day.giveUp, false, "fewer than five never gives up, even after a day")
let old = CreateOutboxLogic.afterServerFault(failures: 0, firstFailureAt: t0, now: t0.addingTimeInterval(86_400))
check(old.firstFailureAt, t0.addingTimeInterval(86_400), "a cleared count restarts the clock")

if failures > 0 { print("CreateOutbox: \(failures) failed"); exit(1) }
print("CreateOutbox: all passed")
