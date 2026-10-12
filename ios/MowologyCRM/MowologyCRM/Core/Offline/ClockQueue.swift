//
//  ClockQueue.swift
//  MowologyCRM
//
//  Offline queue for clock-in / clock-out actions.
//  Actions are stored in UserDefaults and replayed in order on reconnect.
//
//  TimeClockViewModel calls enqueue() on network failure and sets its state
//  optimistically. It observes mwPingQueueOnline and calls drain() to sync,
//  then calls loadStatus() to reconcile the UI with actual server state.
//

import Foundation

@MainActor
final class ClockQueue: ObservableObject {

    static let shared = ClockQueue()

    @Published private(set) var hasPending: Bool = false

    private let defaults = UserDefaults.standard
    private let storeKey = "mw.clock.queue.v1"
    /// Matches the server's OfflineTime::MAX_AGE_HOURS.
    static let maxAge: TimeInterval = 24 * 3600

    private init() { refreshPending() }

    // MARK: - Pending Action

    struct PendingAction: Codable {
        let id: String      // idempotency key sent as Idempotency-Key header
        let action: String  // "clock_in" or "clock_out"
        let lat: Double?
        let lng: Double?
        let queuedAt: Date
    }

    // MARK: - Persistence

    private var items: [PendingAction] {
        get {
            guard let data    = defaults.data(forKey: storeKey),
                  let decoded = try? JSONDecoder().decode([PendingAction].self, from: data)
            else { return [] }
            return decoded
        }
        set {
            defaults.set(try? JSONEncoder().encode(newValue), forKey: storeKey)
            refreshPending()
        }
    }

    private func refreshPending() { hasPending = !items.isEmpty }

    // MARK: - Enqueue

    /// Records a clock action to be replayed when connectivity returns.
    ///
    /// Only deduplicates a trailing double-tap (last item is the same action).
    /// Earlier items of the same type are preserved because they may form part of
    /// a completed [clock_in, clock_out] pair — stripping them reorders the queue
    /// and replays clock_out before clock_in, corrupting the payroll record.
    func enqueue(action: String, lat: Double?, lng: Double?, at tappedAt: Date = Date()) {
        var current = items
        if current.last?.action == action { current.removeLast() }
        current.append(PendingAction(
            id:       UUID().uuidString,
            action:   action,
            lat:      lat,
            lng:      lng,
            queuedAt: tappedAt
        ))
        items = current
    }

    // MARK: - Drain

    /// Replays all queued clock actions in order.
    ///
    /// Returns `.success` if all actions were sent (or the queue was empty),
    /// `.offline` if a network error was encountered, or `.reconcile` if
    /// the caller should call `loadStatus()` because the server state may differ
    /// from the locally optimistic state.
    enum DrainResult {
        case empty
        case success
        case offline
        case reconcile  // actions sent but caller should reload server state
    }

    func drain(using apiClient: APIClient) async -> DrainResult {
        let pending = items
        guard !pending.isEmpty else { return .empty }

        var sentCount = 0
        for item in pending {
            // Older than the server will trust (OfflineTime::MAX_AGE_HOURS): sending it would record
            // a punch at the replay time — a phantom shift. Drop it; the office fixes the timesheet.
            if item.queuedAt < Date().addingTimeInterval(-Self.maxAge) {
                items = items.filter { $0.id != item.id }
                sentCount += 1
                continue
            }
            var body: [String: Any] = ["action": item.action, "client_time": item.queuedAt.mwClientTime]
            if let lat = item.lat { body["lat"] = lat }
            if let lng = item.lng { body["lng"] = lng }

            do {
                let _: ClockActionResponse = try await apiClient.request(
                    .scheduleClock,
                    body: body,
                    extraHeaders: ["Idempotency-Key": item.id]
                )
                items = items.filter { $0.id != item.id }
                sentCount += 1
            } catch let err as APIError {
                if case .networkError = err {
                    // Still offline — stop draining, keep remaining items.
                    return .offline
                }
                // Server error (e.g. already clocked in, shift conflict) —
                // discard this action and continue; loadStatus() will reconcile.
                items = items.filter { $0.id != item.id }
                sentCount += 1
            } catch {
                return .offline
            }
        }
        return sentCount > 0 ? .reconcile : .empty
    }

    /// Discards all pending actions. Never call it on a status read: a queued punch is the only
    /// record of when it was tapped (drain() sends it with that time).
    func clear() {
        items = []
    }
}
