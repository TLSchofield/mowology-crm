//
//  TransitionQueue.swift
//  MowologyCRM
//

import Foundation
import SwiftData

// MARK: - TransitionQueue

/// Manages idempotency keys for job-timer actions (start / stop).
///
/// Before every API call the VM calls `prepare(visitId:action:lat:lng:)` which:
///   1. Returns an existing persisted key if this action is already pending
///      (i.e. a previous attempt timed out without a confirmed response).
///   2. Creates and persists a fresh UUID key for new actions.
///
/// On confirmed success the VM calls `confirm(visitId:action:)` which
/// removes the SwiftData record. On failure the record remains — so the next
/// retry (user tap or reconnect) receives the **same** key and the server
/// deduplicates via its `idempotency_keys` table.
@MainActor
final class TransitionQueue {

    /// ONE container for the whole app. Every visit screen used to build its own (and the drain
    /// service built one per call): several SwiftData stacks on the same SQLite file, which is
    /// how a save ends up spinning on a lock on the main thread (crash 2026-09-21, 0x8BADF00D).
    static let sharedContainer: ModelContainer = {
        do {
            return try ModelContainer(for: PendingTransition.self)
        } catch {
            print("[TransitionQueue] SwiftData init failed, using in-memory fallback: \(error)")
            let config = ModelConfiguration(isStoredInMemoryOnly: true)
            return try! ModelContainer(for: PendingTransition.self, configurations: config)
        }
    }()

    private var container: ModelContainer { Self.sharedContainer }

    init() {}

    // MARK: - Public API

    /// Returns the idempotency key to use for this action.
    /// Reuses an existing key if a previous attempt is still pending.
    func prepare(visitId: Int, action: String, lat: Double? = nil, lng: Double? = nil) -> String {
        let ctx = ModelContext(container)
        if let existing = pendingTransition(visitId: visitId, action: action, ctx: ctx) {
            return existing.idempotencyKey
        }
        let t = PendingTransition(action: action, visitId: visitId, lat: lat, lng: lng)
        ctx.insert(t)
        try? ctx.save()
        return t.idempotencyKey
    }

    /// Call after a successful server response to clear the pending record.
    func confirm(visitId: Int, action: String) {
        let ctx = ModelContext(container)
        if let t = pendingTransition(visitId: visitId, action: action, ctx: ctx) {
            ctx.delete(t)
            try? ctx.save()
        }
    }

    /// Returns true if there is a pending (unconfirmed) transition for this visit + action.
    /// Used on reconnect to auto-retry transitions that failed while offline.
    func hasPending(visitId: Int, action: String) -> Bool {
        let ctx = ModelContext(container)
        return pendingTransition(visitId: visitId, action: action, ctx: ctx) != nil
    }

    // MARK: - Private

    private func pendingTransition(visitId: Int, action: String, ctx: ModelContext) -> PendingTransition? {
        let all = (try? ctx.fetch(FetchDescriptor<PendingTransition>())) ?? []
        return all.first { $0.visitId == visitId && $0.action == action }
    }
}
