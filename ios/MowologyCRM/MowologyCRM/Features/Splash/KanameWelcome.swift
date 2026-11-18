//
//  KanameWelcome.swift
//  MowologyCRM
//
//  The one-time welcome card (2026-11-13): shown instead of the morning brief the first time a person opens the app
//  for a business, never again once they tap its button or Later. Pure logic, no SwiftUI: checked by
//  `bash ios/LogicTests/run.sh`. The card itself is drawn in CharlieMorningBrief.swift.
//

import Foundation

enum KanameWelcome {
    enum Entry: String, Equatable {
        case books   // only receipts are on
        case work    // the business can add jobs
    }

    /// WORK when the business can add jobs, BOOKS when only receipts are on, nil when neither (then no welcome shows).
    static func entry(canAddJobs: Bool, receiptsOn: Bool) -> Entry? {
        if canAddJobs { return .work }
        if receiptsOn { return .books }
        return nil
    }

    static func shouldShowWelcome(seen: Bool, entry: Entry?) -> Bool {
        !seen && entry != nil
    }

    static func headline(firstName: String) -> String {
        let n = firstName.trimmingCharacters(in: .whitespacesAndNewlines)
        return n.isEmpty ? "Hello. Let's dot the i's and cross the t's." : "Hello \(n). Let's dot the i's and cross the t's."
    }

    /// First word of the signed-in user's name ("Sam Lee" gives "Sam").
    static func firstName(fromFullName name: String) -> String {
        name.split(whereSeparator: { $0.isWhitespace }).first.map(String.init) ?? ""
    }

    static let body = "I'm here to clear your desk and give you your time back. You make the decisions; I handle the loose ends."

    static func entryLine(_ e: Entry) -> String {
        switch e {
        case .books: return "Start with receipts: take a photo and Penny, the bookkeeper, files it."
        case .work:  return "Start with the work: add a client and a job."
        }
    }

    static func primaryButton(_ e: Entry) -> String {
        switch e {
        case .books: return "Take my first receipt"
        case .work:  return "Add my first job"
        }
    }

    static let laterButton = "Later"

    /// Per business (its host) and per signed-in user, like the driver question's per-person key.
    static func seenKey(host: String, userId: Int) -> String { "mw.kanameWelcome.seen.\(host).\(userId)" }

    static func isSeen(host: String, userId: Int, defaults: UserDefaults = .standard) -> Bool {
        defaults.bool(forKey: seenKey(host: host, userId: userId))
    }

    static func markSeen(host: String, userId: Int, defaults: UserDefaults = .standard) {
        defaults.set(true, forKey: seenKey(host: host, userId: userId))
    }
}

/// Everything the opening overlay needs to draw the card and remember it was seen.
struct KanameWelcomeCard: Equatable {
    let entry: KanameWelcome.Entry
    let firstName: String
    let host: String
    let userId: Int
}
