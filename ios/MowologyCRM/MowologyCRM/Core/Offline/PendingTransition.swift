//
//  PendingTransition.swift
//  MowologyCRM
//

import Foundation
import SwiftData

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
