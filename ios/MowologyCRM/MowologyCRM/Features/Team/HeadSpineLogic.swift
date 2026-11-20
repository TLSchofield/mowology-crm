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

/// Penny's single ordering rule. Views provide the decisions that are still open; this pure layer decides both what
/// opens next and the words Kaname uses for it.
struct PennyDecision: Equatable {
    enum Kind: Int, CaseIterable {
        case materialsReceipt
        case depositMatch
        case cheque
        case duplicate
        case receipt
    }

    let kind: Kind
    let id: String
    let name: String

    var rowText: String {
        switch kind {
        case .materialsReceipt, .receipt: return "\(name.isEmpty ? "Receipt" : name) to review"
        case .depositMatch: return "\(name.isEmpty ? "Deposit" : name) to match"
        case .cheque: return "\(name.isEmpty ? "Cheque" : name) to match"
        case .duplicate: return "Receipts to compare"
        }
    }
}

enum PennyDecisionLogic {
    /// Stable within each kind: today's server order is retained after applying the owner's priority order.
    static func ordered(_ decisions: [PennyDecision]) -> [PennyDecision] {
        decisions.enumerated().sorted {
            if $0.element.kind.rawValue != $1.element.kind.rawValue {
                return $0.element.kind.rawValue < $1.element.kind.rawValue
            }
            return $0.offset < $1.offset
        }.map(\.element)
    }

    static func nextDecision(_ decisions: [PennyDecision]) -> PennyDecision? {
        ordered(decisions).first
    }

    /// The carousel position for the decision selected by the shared ordering rule.
    /// Keeping this lookup pure makes reopening the workspace deterministic without changing swipe behaviour while it is open.
    static func position(of decision: PennyDecision?, in ids: [String]) -> Int? {
        guard let id = decision?.id else { return nil }
        return ids.firstIndex(of: id)
    }
}
