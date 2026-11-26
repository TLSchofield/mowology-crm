// Checks for ScheduleCacheOwner (2026-11-26): a shared phone never shows the previous person's cached schedule.
import Foundation

var failures = 0
func check(_ ok: Bool, _ what: String) {
    if !ok { failures += 1; print("FAIL: \(what)") }
}

check(!ScheduleCacheOwner.shouldClear(recordedOwner: 7, signingIn: 7), "same person keeps their cache")
check(ScheduleCacheOwner.shouldClear(recordedOwner: 7, signingIn: 8), "a different person empties it")
check(ScheduleCacheOwner.shouldClear(recordedOwner: nil, signingIn: 7), "a cache with no recorded owner is emptied")

if failures == 0 { print("ScheduleCacheOwner: all checks passed") } else { exit(1) }
