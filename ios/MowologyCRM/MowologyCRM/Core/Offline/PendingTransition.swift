//
//  PendingTransition.swift
//  MowologyCRM
//

import Foundation
import SwiftData

extension Date {
    /// The moment an action was tapped, as the server's `client_time` (ISO 8601, UTC). The server
    /// (OfflineTime.php) records an offline action at this time instead of when it arrived —
    /// trusted up to 24 h, never ahead of the server, never before the event it must follow.
    var mwClientTime: String {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f.string(from: self)
    }
}

/// The business's calendar day (America/Vancouver), as the server writes it: "yyyy-MM-dd".
/// ISO8601DateFormatter with .withFullDate works in GMT, which made the quiz gate reopen every
/// evening and showed every receipt one day early (cold review, 2026-10-19).
enum VancouverDay {
    static let zone = TimeZone(identifier: "America/Vancouver") ?? .current

    private static let formatter: DateFormatter = {
        let f = DateFormatter()
        f.calendar   = Calendar(identifier: .gregorian)
        f.locale     = Locale(identifier: "en_US_POSIX")
        f.timeZone   = zone
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()

    /// "2026-10-19" for the Vancouver day that `date` falls on.
    static func string(from date: Date = .now) -> String { formatter.string(from: date) }

    /// Midnight in Vancouver at the start of a "yyyy-MM-dd" day (nil if it isn't one).
    static func date(from ymd: String) -> Date? { formatter.date(from: String(ymd.prefix(10))) }
}

/// A job-timer action (start / stop) whose server response has not yet been
/// confirmed. Persisted so the same idempotency key survives app restarts —
/// ensuring a retry never creates a duplicate timer entry on the server.
@Model
final class PendingTransition {

    /// UUID sent as `Idempotency-Key` header. Stays the same across all retries.
    var idempotencyKey: String

    /// "start" or "stop"
    var action: String

    var visitId: Int
    var lat: Double?
    var lng: Double?
    var queuedAt: Date

    init(action: String, visitId: Int, lat: Double? = nil, lng: Double? = nil) {
        self.idempotencyKey = UUID().uuidString
        self.action         = action
        self.visitId        = visitId
        self.lat            = lat
        self.lng            = lng
        self.queuedAt       = Date()
    }
}
