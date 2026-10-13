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
//    the queue stops and keeps the punch (a server error is retried; after 5 tries the punch is
//    KEPT, marked refused and shown on the Time Clock screen — never dropped or counted as sent);
//  - each punch carries who made it; only that person's login sends it;
//  - every punch is sent with the time it was tapped; the server decides (OfflineTime).
//

import Foundation

@MainActor
final class ClockQueue: ObservableObject {

    static let shared = ClockQueue()

    /// Punches still to send (refused ones excluded — they no longer block the queue).
    @Published private(set) var hasPending: Bool = false
    /// Punches the server refused 5 times — kept on the phone and shown until the person clears them.
    @Published private(set) var hasRejected: Bool = false

    private let defaults = UserDefaults.standard
    private let storeKey = "mw.clock.queue.v1"
    /// Server errors before a punch is marked refused (a GPS refusal or a 500 that never clears).
    static let maxAttempts = 5

    private var running: Task<DrainResult, Never>?

    private init() { refreshPending() }

    // MARK: - Pending Action

    struct PendingAction: Codable {
        let id: String      // idempotency key sent as Idempotency-Key header
        let action: String  // "clock_in", "clock_out", "break_start" or "break_end" (same endpoint, same order)
        let lat: Double?
        let lng: Double?
        let queuedAt: Date  // when it was tapped
        let userId: Int?    // who tapped it (nil = queued by a build before 1.3.27)
        var attempts: Int?  // server errors so far
        /// Set when the server refused it maxAttempts times. Kept and shown; never dropped silently.
        var rejection: String?
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

    private func refreshPending() {
        let all = items
        hasPending  = all.contains { $0.rejection == nil }
        hasRejected = all.contains { $0.rejection != nil }
    }

    private func mine(_ item: PendingAction, _ userId: Int?) -> Bool { item.userId == nil || item.userId == userId }

    /// Punches not yet on the server for this person, refused ones included (sign-out warns about these).
    func pendingCount(for userId: Int?) -> Int {
        items.filter { mine($0, userId) }.count
    }

    /// Punches still waiting to be sent for this person (not refused).
    func waitingCount(for userId: Int?) -> Int {
        items.filter { mine($0, userId) && $0.rejection == nil }.count
    }

    /// The first refused punch for this person, as a line for the Time Clock screen.
    func rejectedMessage(for userId: Int?) -> String? {
        guard let r = items.first(where: { mine($0, userId) && $0.rejection != nil }) else { return nil }
        let what: String
        switch r.action {
        case "clock_in":    what = "Clock in"
        case "break_start": what = "Start break"
        case "break_end":   what = "End break"
        default:            what = "Clock out"
        }
        let when = r.queuedAt.formatted(date: .abbreviated, time: .shortened)
        return "\(what) at \(when): \(r.rejection ?? "refused by the server")"
    }

    /// The person has dealt with refused punches another way (told the office, corrected by hand).
    func discardRejected(for userId: Int?) {
        items = items.filter { !(mine($0, userId) && $0.rejection != nil) }
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
        let pending = items.filter { mine($0, me) && $0.rejection == nil }
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
                    // Server error: keep it and stop (a later punch must not jump ahead of it).
                    // After maxAttempts it is KEPT, marked refused and shown (it no longer blocks the
                    // queue, so one bad punch can't block the day) — never dropped, never counted
                    // as sent (2026-10-12 review: a refused punch used to vanish as "synced").
                    let tries = (item.attempts ?? 0) + 1
                    if tries >= Self.maxAttempts {
                        print("[ClockQueue] refused \(item.action) tapped \(item.queuedAt) after \(tries) tries: \(err)")
                        let why = err.errorDescription ?? "refused by the server"
                        items = items.map { $0.id == item.id ? { var c = $0; c.attempts = tries; c.rejection = why; return c }($0) : $0 }
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

    /// Discards all pending actions, refused ones included. Never call it on a status read: a queued punch is the only
    /// record of when it was tapped (drain() sends it with that time).
    func clear() {
        items = []
    }
}
