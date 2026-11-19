//
//  CreateOutboxLogic.swift
//  MowologyCRM
//
//  The pure half of CreateOutbox: which entries belong to the signed-in person, which are still to send, and the
//  one line the screen shows about them. Foundation only, checked by ios/LogicTests/CreateOutboxTests.swift.
//

import Foundation

enum CreateOutboxLogic {
    struct Item: Equatable {
        let id: String
        let userId: Int?
        /// The server said no (a 4xx with its reason). Kept on the phone and shown until retried or discarded.
        let refused: String?
    }

    /// Only the person who made an entry sends it (another login on the same phone never replays it).
    static func mine(_ items: [Item], me: Int?) -> [Item] {
        guard let me else { return [] }
        return items.filter { $0.userId == me }
    }

    /// Entries the next drain sends, in the order they were made; refused ones wait for a person.
    static func toSend(_ items: [Item], me: Int?) -> [Item] {
        mine(items, me: me).filter { $0.refused == nil }
    }

    /// "2 waiting to sync", "1 couldn't be saved", or both; nil when there is nothing to say.
    static func statusLine(_ items: [Item], me: Int?) -> String? {
        let mine = mine(items, me: me)
        let waiting = mine.filter { $0.refused == nil }.count
        let refused = mine.count - waiting
        var parts: [String] = []
        if waiting > 0 { parts.append("\(waiting) waiting to sync") }
        if refused > 0 { parts.append("\(refused) couldn't be saved") }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }
}
