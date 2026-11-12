import Foundation

private var failures = 0

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() {
        failures += 1
        fputs("FAIL: \(message)\n", stderr)
    }
}

let now = Date(timeIntervalSince1970: 1_000_000)

var breadcrumbs = BreadcrumbBuffer(limit: 3)
for crumb in ["one", "two", "three", "four", "five"] { breadcrumbs.append(crumb) }
expect(breadcrumbs.items == ["three", "four", "five"],
       "the breadcrumb buffer retains every recent call, independent of disk throttling")

expect(!HangWatchdogState.isStalled(lastReply: now.addingTimeInterval(-2), now: now, threshold: 2),
       "the threshold itself is not yet a stall")
expect(HangWatchdogState.isStalled(lastReply: now.addingTimeInterval(-2.01), now: now, threshold: 2),
       "a reply older than the threshold is a stall")

var watchdog = HangWatchdogState()
expect(watchdog.update(lastReply: now.addingTimeInterval(-3), now: now, threshold: 2, isActive: true) == nil,
       "starting a stall does not report it")
let ended = watchdog.update(lastReply: now.addingTimeInterval(0.5), now: now.addingTimeInterval(0.5),
                            threshold: 2, isActive: true)
expect(ended == 3.5, "a recovered stall reports its full length once")
expect(watchdog.update(lastReply: now.addingTimeInterval(1), now: now.addingTimeInterval(1),
                       threshold: 2, isActive: true) == nil,
       "a recovered stall is not reported twice")

var backgrounded = HangWatchdogState()
_ = backgrounded.update(lastReply: now.addingTimeInterval(-3), now: now, threshold: 2, isActive: true)
expect(backgrounded.update(lastReply: now, now: now, threshold: 2, isActive: false) == nil,
       "backgrounding cancels stall tracking")
expect(backgrounded.stallStartedAt == nil, "background time leaves no pending stall")

let recentA = CrashLoopStrike(date: now.addingTimeInterval(-60), build: "100")
let recentB = CrashLoopStrike(date: now.addingTimeInterval(-120), build: "100")
let old = CrashLoopStrike(date: now.addingTimeInterval(-(CrashLoopRules.window + 1)), build: "100")
let otherBuild = CrashLoopStrike(date: now.addingTimeInterval(-30), build: "99")

let one = CrashLoopRules.evaluate(strikes: [recentA, old, otherBuild], now: now, build: "100")
expect(one.strikes == [recentA], "evaluation removes old and other-build strikes")
expect(!one.tripped, "one same-build strike does not trip")

let two = CrashLoopRules.evaluate(strikes: [recentA, recentB, otherBuild], now: now, build: "100")
expect(two.tripped, "two same-build strikes inside 24 hours trip")
expect(two.strikes == [recentA, recentB], "only current-build strikes survive")

if failures > 0 { exit(1) }
print("CrashGuardTests passed")
