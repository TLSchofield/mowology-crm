// Logic tests for the shared screen header (head line + controls line). Written by the header job.
import Foundation

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        fputs("ScreenHeaderTests failed: \(message)\n", stderr)
        exit(1)
    }
}

let crew = ScreenHeaderRules.resolve(
    isAdmin: false,
    reason: "Waiting for approval",
    dismissedReason: nil,
    isSelecting: false
)
expect(!crew.showsHeadLine, "crew must not see the head line")
expect(crew.stateText == nil, "crew must not receive head state text")
expect(crew.showsTimeChip && crew.showsRefresh, "normal controls remain visible to crew")

let quiet = ScreenHeaderRules.resolve(
    isAdmin: true,
    reason: nil,
    dismissedReason: nil,
    isSelecting: false
)
expect(quiet.showsHeadLine, "admins always see the head line")
expect(quiet.stateText == "No decisions waiting", "quiet heads say No decisions waiting")
expect(!quiet.showsReview, "quiet heads do not offer Review")

let loadingQuiet = ScreenHeaderRules.resolve(isAdmin: true, reason: nil, dismissedReason: nil, isSelecting: false, briefStatus: .loading)
expect(loadingQuiet.stateText == "Checking decisions", "an unloaded brief is not reported as quiet")
let failedQuiet = ScreenHeaderRules.resolve(isAdmin: true, reason: nil, dismissedReason: nil, isSelecting: false, briefStatus: .failed)
expect(failedQuiet.stateText == "Decision status unavailable", "a failed brief is not reported as quiet")

let needsReview = ScreenHeaderRules.resolve(
    isAdmin: true,
    reason: "  Approve the schedule change  ",
    dismissedReason: nil,
    isSelecting: false
)
expect(needsReview.stateText == "Approve the schedule change", "reasons are trimmed")
expect(needsReview.showsReview, "a readable new reason offers Review")

let dismissed = ScreenHeaderRules.resolve(
    isAdmin: true,
    reason: "Approve the schedule change",
    dismissedReason: "Approve the schedule change",
    isSelecting: false
)
expect(dismissed.stateText == "No decisions waiting", "a dismissed reason stays quiet")
expect(!dismissed.showsReview, "a dismissed reason does not offer Review")

let changed = ScreenHeaderRules.resolve(
    isAdmin: true,
    reason: "A different decision is ready",
    dismissedReason: "Approve the schedule change",
    isSelecting: false
)
expect(changed.showsReview, "a changed reason becomes visible again")

let selecting = ScreenHeaderRules.resolve(
    isAdmin: true,
    reason: nil,
    dismissedReason: nil,
    isSelecting: true
)
expect(!selecting.showsTimeChip && !selecting.showsRefresh, "selection mode keeps room for selection controls")

expect(
    ScreenHeaderRules.reason(live: "Otto needs attention", demo: nil, isDemo: true) == nil,
    "demo quiet state must not fall back to Otto's live state"
)
expect(
    ScreenHeaderRules.reason(live: nil, demo: "Three receipts need your review", isDemo: true)
        == "Three receipts need your review",
    "demo review state must use its synthetic reason"
)

print("ScreenHeaderTests passed")
