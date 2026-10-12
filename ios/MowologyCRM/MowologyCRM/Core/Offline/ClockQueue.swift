//
//  ClockQueue.swift
//  MowologyCRM
//
//  Offline queue for clock-in / clock-out actions.
//  Actions are stored in UserDefaults and replayed in order on reconnect.
//
//  TimeClockViewModel calls enqueue() on network failure and sets its state
//  optimistically. AppTransitionDrainService sends this queue FIRST on reconnect,
//  then job actions — so a job start can never land before the clock-in it follows.
//
//  Rules (2026-10-12, after an independent review):
//  - one resend at a time: a second caller waits for the run in progress;
//  - nothing is deleted on the phone because of an expired login or a server error —
//    the queue stops and keeps the punch (a server error is retried, dropped after 5 tries);
//  - each punch carries who made it; only that person's login sends it;
//  - every punch is sent with the time it was tapped; the server decides (OfflineTime).
//

import Foundation

@MainActor
final class ClockQueue: ObservableObject {

    static let shared = ClockQueue()

    @Published private(set) var hasPending: Bool = false

    private let defaults = UserDefaults.standard
    private let storeKey = "mw.clock.queue.v1"
    /// Server errors before a punch is given up (a GPS refusal or a 500 that never clears).
    static let maxAttempts = 5

    private var running: Task<DrainResult, Never>?

    private init() { refreshPending() }

    // MARK: - Pending Action

    struct PendingAction: Codable {
        let id: String      // idempotency key sent as Idempotency-Key header
        let action: String  // "clock_in" or "clock_out"
        let lat: Double?
        let lng: Double?
        let queuedAt: Date  // when it was tapped
        let userId: Int?    // who tapped it (nil = queued by a build before 1.3.27)
        var attempts: Int?  // server errors so far
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

    /// Punches waiting to be sent for this person (sign-out warns about these).
    func pendingCount(for userId: Int?) -> Int {
        items.filter { $0.userId == nil || $0.userId == userId }.count
    }

    // MARK: - Enqueue

    /// Records a clock action to be replayed when connectivity returns.
    ///
    /// Only deduplicates a trailing double-tap (last item is the same action).
    /// Earlier items of the same type are preserved because they may form part of
    /// a completed [clock_in, clock_out] pair — stripping them reorders the queue
    /// and replays clock_out before clock_in, corrupting the payroll record.
    func enqueue(action: String, lat: Double?, lng: Double?, at tappedAt: Date = Date(), userId: Int?) {
        var current = items
        if current.last?.action == action, current.last?.userId == userId { current.removeLast() }
        current.append(PendingAction(
            id:       UUID().uuidString,
            action:   action,
            lat:      lat,
            lng:      lng,
            queuedAt: tappedAt,
            userId:   userId,
            attempts: 0
        ))
        items = current
    }

    // MARK: - Drain

    /// `.offline` also means "stopped and kept everything" (expired login, server error).
    enum DrainResult {
        case empty
        case success
        case offline
        case reconcile  // actions sent but caller should reload server state
    }

    /// Replays this person's queued clock actions in order. One run at a time: a caller that
    /// arrives while a run is in progress waits for that run instead of starting a second one
    /// (two runs over the same snapshot doubled shifts).
    func drain(using apiClient: APIClient) async -> DrainResult {
        if let running { return await running.value }
        let task = Task { await self.drainOnce(using: apiClient) }
        running = task
        let result = await task.value
        running = nil
        return result
    }

    private func drainOnce(using apiClient: APIClient) async -> DrainResult {
        let me = apiClient.currentUserId
        let pending = items.filter { $0.userId == nil || $0.userId == me }
        guard !pending.isEmpty, me != nil else { return .empty }

        var sentCount = 0
        for item in pending {
            var body: [String: Any] = [
                "action":      item.action,
                "client_time": item.queuedAt.mwClientTime,
                "queued":      true,
            ]
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
                switch err {
                case .networkError, .unauthorized:
                    // No signal, or the login expired after a day offline: keep everything.
                    return sentCount > 0 ? .reconcile : .offline
                default:
                    // Server error: keep it and stop (a later punch must not jump ahead of it);
                    // give up only after maxAttempts, so one bad punch can't block the day forever.
                    let tries = (item.attempts ?? 0) + 1
                    if tries >= Self.maxAttempts {
                        print("[ClockQueue] giving up on \(item.action) tapped \(item.queuedAt) after \(tries) tries: \(err)")
                        items = items.filter { $0.id != item.id }
                        sentCount += 1
                        continue
                    }
                    items = items.map { $0.id == item.id ? { var c = $0; c.attempts = tries; return c }($0) : $0 }
                    return sentCount > 0 ? .reconcile : .offline
                }
            } catch {
                return sentCount > 0 ? .reconcile : .offline
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
