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

if failures > 0 { print("CreateOutbox: \(failures) failed"); exit(1) }
print("CreateOutbox: all passed")
