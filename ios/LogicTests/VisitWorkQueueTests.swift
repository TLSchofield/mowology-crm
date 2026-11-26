import Foundation

private func entry(
    _ id: String,
    user: Int = 7,
    visit: Int = 42,
    kind: VisitWorkEntry.Kind,
    tries: Int = 0
) -> VisitWorkEntry {
    VisitWorkEntry(
        id: id,
        userId: user,
        visitId: visit,
        kind: kind,
        payloadJSON: "{\"id\":\"\(id)\"}",
        performedAt: Date(timeIntervalSince1970: Double(id.count)),
        tries: tries
    )
}

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        fputs("FAIL: \(message)\n", stderr)
        exit(1)
    }
}

var list = [
    entry("first", kind: .checklist),
    entry("note-1", kind: .note),
    entry("material-old", kind: .materials)
]
list = VisitWorkQueueLogic.enqueue(list, new: entry("replacement", kind: .checklist))
expect(list.map(\.id) == ["replacement", "note-1", "material-old"], "checklist replacement must keep its position")
list = VisitWorkQueueLogic.enqueue(list, new: entry("material-new", kind: .materials))
expect(list.map(\.id) == ["replacement", "note-1", "material-new"], "materials replacement must keep its position")

list = VisitWorkQueueLogic.enqueue(list, new: entry("note-2", kind: .note))
list = VisitWorkQueueLogic.enqueue(list, new: entry("note-3", kind: .note))
expect(list.suffix(2).map(\.id) == ["note-2", "note-3"], "notes must append in order")

let other = entry("other-user", user: 99, kind: .note)
list.append(other)
expect(!VisitWorkQueueLogic.ownEntries(userId: 7, in: list).contains(other), "another user's entry must be invisible")
expect(VisitWorkQueueLogic.next(for: 99, in: list)?.id == other.id, "each user must send only their own oldest entry")

var failures = [entry("failure", kind: .note)]
for attempt in 1...5 {
    failures = VisitWorkQueueLogic.afterFailure(failures[0], message: "HTTP 400 exact message", in: failures)
    expect(failures[0].tries == attempt, "failure must increment tries")
}
expect(failures[0].isRefused, "fifth failure must mark the entry refused")
expect(failures[0].refusalMessage == "HTTP 400 exact message", "refusal must retain the precise server message")
expect(VisitWorkQueueLogic.next(for: 7, in: failures) == nil, "refused entries must never be sent silently again")

let refused = VisitWorkQueueLogic.afterRefusal(
    entry("http-400", kind: .note),
    message: "HTTP 400 exact message",
    in: [entry("http-400", kind: .note)]
)
expect(refused[0].isRefused, "a permanent server refusal must be terminal immediately")
expect(refused[0].refusalMessage == "HTTP 400 exact message", "an immediate refusal must retain the server message")

let queuedChecklist = [entry("queued-checklist", kind: .checklist)]
expect(
    VisitWorkQueueLogic.hasUnsavedList(kind: .checklist, localDirty: false, entries: queuedChecklist),
    "a queued list must remain unsaved"
)
expect(
    VisitWorkQueueLogic.hasUnsavedList(kind: .checklist, localDirty: true, entries: []),
    "a local edit must remain unsaved before it reaches the queue"
)
expect(
    !VisitWorkQueueLogic.hasUnsavedList(kind: .checklist, localDirty: false, entries: []),
    "a clean list with no queue entry must not be unsaved"
)

let removeOnly = [entry("keep-1", kind: .note), entry("remove", kind: .note), entry("keep-2", kind: .note)]
let afterSuccess = VisitWorkQueueLogic.afterSuccess(removeOnly[1], in: removeOnly)
expect(afterSuccess.map(\.id) == ["keep-1", "keep-2"], "success must remove only the matching entry")

print("VisitWorkQueueTests passed")
