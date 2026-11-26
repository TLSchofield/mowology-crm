// Visit checklist, materials, and notes queue offline and send later without duplication, per user.

import Foundation

struct VisitWorkEntry: Codable, Equatable, Identifiable {
    enum Kind: String, Codable {
        case checklist
        case materials
        case note
    }

    let id: String
    let userId: Int
    let visitId: Int
    let kind: Kind
    let payloadJSON: String
    let performedAt: Date
    var tries: Int
    var refusalMessage: String?

    init(
        id: String = UUID().uuidString,
        userId: Int,
        visitId: Int,
        kind: Kind,
        payloadJSON: String,
        performedAt: Date = Date(),
        tries: Int = 0,
        refusalMessage: String? = nil
    ) {
        self.id = id
        self.userId = userId
        self.visitId = visitId
        self.kind = kind
        self.payloadJSON = payloadJSON
        self.performedAt = performedAt
        self.tries = tries
        self.refusalMessage = refusalMessage
    }

    var isRefused: Bool { tries >= VisitWorkQueueLogic.maximumTries }
}

enum VisitWorkQueueLogic {
    static let maximumTries = 5

    static func enqueue(_ entries: [VisitWorkEntry], new entry: VisitWorkEntry) -> [VisitWorkEntry] {
        guard entry.kind != .note,
              let index = entries.firstIndex(where: {
                  $0.userId == entry.userId && $0.visitId == entry.visitId && $0.kind == entry.kind
              }) else {
            return entries + [entry]
        }
        var result = entries
        result[index] = entry
        return result
    }

    static func next(for userId: Int, in entries: [VisitWorkEntry]) -> VisitWorkEntry? {
        ownEntries(userId: userId, in: entries).first(where: { !$0.isRefused })
    }

    static func afterSuccess(_ entry: VisitWorkEntry, in entries: [VisitWorkEntry]) -> [VisitWorkEntry] {
        entries.filter { $0.id != entry.id }
    }

    static func afterFailure(
        _ entry: VisitWorkEntry,
        message: String,
        in entries: [VisitWorkEntry]
    ) -> [VisitWorkEntry] {
        guard let index = entries.firstIndex(where: { $0.id == entry.id }) else { return entries }
        var result = entries
        result[index].tries += 1
        result[index].refusalMessage = message
        return result
    }

    static func afterRefusal(
        _ entry: VisitWorkEntry,
        message: String,
        in entries: [VisitWorkEntry]
    ) -> [VisitWorkEntry] {
        guard let index = entries.firstIndex(where: { $0.id == entry.id }) else { return entries }
        var result = entries
        result[index].tries = maximumTries
        result[index].refusalMessage = message
        return result
    }

    static func hasUnsavedList(
        kind: VisitWorkEntry.Kind,
        localDirty: Bool,
        entries: [VisitWorkEntry]
    ) -> Bool {
        localDirty || entries.contains { $0.kind == kind }
    }

    static func ownEntries(userId: Int, in entries: [VisitWorkEntry]) -> [VisitWorkEntry] {
        entries.filter { $0.userId == userId }
    }
}
