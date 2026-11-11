//
//  HeadSpineLogic.swift
//  MowologyCRM
//
//  The pure half of the head's spine (HeadSpine.swift): the words on the right of a section ("3 to link") and
//  whether a section is open. Foundation only, checked by ios/LogicTests/HeadSpineTests.swift.
//

import Foundation

enum HeadSpineLogic {
    /// Stored open state per section: 0 = never touched (use the section's default), 1 = open, 2 = closed.
    static func isOpen(stored: Int, defaultOpen: Bool) -> Bool {
        switch stored {
        case 1: return true
        case 2: return false
        default: return defaultOpen
        }
    }

    static func stored(open: Bool) -> Int { open ? 1 : 2 }

    static func key(_ slug: String, _ id: String) -> String { "spine.\(slug).\(id)" }

    /// "3 to link", "1 message" / "2 messages"; nil when nothing waits, so the right side stays empty.
    static func count(_ n: Int, _ one: String, plural: String? = nil) -> String? {
        guard n > 0 else { return nil }
        return "\(n) \(n == 1 ? one : (plural ?? one))"
    }

    /// The English role line under the head's name (council, 2026-11-07: names stay, Kanjō and the other
    /// Japanese word-names are out until the Claudia test).
    static func role(_ slug: String) -> String {
        ["charlie": "the linchpin", "yui": "the connector", "sam": "the pathfinder", "penny": "the steward",
         "otto": "the orchestrator", "mia": "the voice", "ada": "the foundation"][slug] ?? ""
    }
}
