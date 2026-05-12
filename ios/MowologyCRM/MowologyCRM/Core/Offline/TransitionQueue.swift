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

    private let container: ModelContainer

    init() {
        do {
            container = try ModelContainer(for: PendingTransition.self)
        } catch {
            let config = ModelConfiguration(isStoredInMemoryOnly: true)
            container = try! ModelContainer(for: PendingTransition.self, configurations: config)
            print("[TransitionQueue] SwiftData init failed, using in-memory fallback: \(error)")
        }
    }

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

    /// Discard a pending record without confirming it. Used when the server
    /// returned a permanent 4xx (the request will never succeed on retry).
    func discard(visitId: Int, action: String) {
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

    /// All currently-pending transitions, oldest first. Used by drain logic to
    /// retry queued actions in the order they were enqueued — start before stop.
    func allPending() -> [PendingTransition] {
        let ctx = ModelContext(container)
        let desc = FetchDescriptor<PendingTransition>(
            sortBy: [SortDescriptor(\.queuedAt)]
        )
        return (try? ctx.fetch(desc)) ?? []
    }

    /// The optimistic intended status for a visit, derived from any pending
    /// transitions. Returns nil if nothing is queued.
    ///
    /// - "stop" pending → user intends "completed".
    /// - "start" pending (and no stop) → user intends "in_progress".
    func intendedStatus(forVisitId visitId: Int) -> String? {
        let pending = allPending().filter { $0.visitId == visitId }
        guard !pending.isEmpty else { return nil }
        // Stop wins — if both queued, the latest user intent is to complete.
        if pending.contains(where: { $0.action == "stop" }) { return "completed" }
        if pending.contains(where: { $0.action == "start" }) { return "in_progress" }
        return nil
    }

    // MARK: - Private

    private func pendingTransition(visitId: Int, action: String, ctx: ModelContext) -> PendingTransition? {
        let all = (try? ctx.fetch(FetchDescriptor<PendingTransition>())) ?? []
        return all.first { $0.visitId == visitId && $0.action == action }
    }
}
