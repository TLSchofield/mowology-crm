import Foundation

var failures = 0
func check<T: Equatable>(_ got: T, _ want: T, _ label: String) {
    if got != want { failures += 1; print("FAIL \(label): got \(got), want \(want)") }
}

check(HeadSpineLogic.count(0, "to link"), nil, "nothing waiting leaves the right side empty")
check(HeadSpineLogic.count(-2, "to link"), nil, "negative counts read as nothing")
check(HeadSpineLogic.count(3, "to link"), "3 to link", "same word for one and many")
check(HeadSpineLogic.count(1, "message", plural: "messages"), "1 message", "singular")
check(HeadSpineLogic.count(4, "message", plural: "messages"), "4 messages", "plural")

check(HeadSpineLogic.isOpen(stored: 0, defaultOpen: true), true, "untouched uses the default (open)")
check(HeadSpineLogic.isOpen(stored: 0, defaultOpen: false), false, "untouched uses the default (closed)")
check(HeadSpineLogic.isOpen(stored: 2, defaultOpen: true), false, "closed by hand stays closed")
check(HeadSpineLogic.isOpen(stored: 1, defaultOpen: false), true, "opened by hand stays open")
check(HeadSpineLogic.isOpen(stored: HeadSpineLogic.stored(open: true), defaultOpen: false), true, "round trip open")
check(HeadSpineLogic.isOpen(stored: HeadSpineLogic.stored(open: false), defaultOpen: true), false, "round trip closed")

check(HeadSpineLogic.key("penny", "mail"), "spine.penny.mail", "storage key")
check(HeadSpineLogic.role("penny"), "the steward", "Penny's role line")
check(HeadSpineLogic.role("charlie"), "the linchpin", "Kaname is keyed charlie")
check(HeadSpineLogic.role("nobody"), "", "unknown head")

if failures > 0 { print("HeadSpine: \(failures) failed"); exit(1) }
print("HeadSpine: all passed")
