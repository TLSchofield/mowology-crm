//
//  ReceiptActionQueue.swift
//  MowologyCRM
//
//  Disk-free offline queue for receipt approve/reject/send actions — mirrors
//  ReceiptQueue's pattern (UserDefaults-backed, NWPathMonitor-driven auto-drain)
//  but without a temp-file payload, since these actions are small JSON bodies,
//  not images.
//
//  No formal idempotency key is needed here (unlike TransitionQueue's timer
//  actions): approve/reject are safe to re-apply (same end state, harmless
//  re-stamp of approved_by/approved_at), and send is already explicitly
//  guarded server-side against double-forwarding (forwarded_to_accounting
//  check in ExpenseApprovalService/receipt-actions.php).
//
//  Review round 2 (2026-10-12): a refusal (success:false, or a 4xx the server decided) used to
//  `break` the drain forever, so one stale action blocked every later one. Now it is set aside
//  with its reason (lastProblem) and the drain goes on; only no signal / 5xx / signed-out stops it.
//  Each action carries the user who tapped it (nil = a build before 1.3.28): only the signed-in
//  person's actions drain.
//

import Foundation
import Network

@MainActor
final class ReceiptActionQueue: ObservableObject {

    static let shared = ReceiptActionQueue()

    @Published private(set) var pendingCount: Int = 0
    /// The last queued action the server refused, in plain words.
    @Published private(set) var lastProblem: String?

    /// Who is signed in. Only their actions (and untagged legacy ones) are counted and drained.
    var activeUserId: Int? { didSet { updateCount() } }
    /// Reads who is signed in at drain time, so a reconnect after a sign-out / sign-in never
    /// sends the previous person's items under the new login.
    var userProvider: (() -> Int?)?

    private let defaults       = UserDefaults.standard
    private let storeKey       = "mw.receipt.action.queue.v1"
    private let monitor        = NWPathMonitor()
    private var monitorStarted = false
    private var drainTask: Task<Void, Never>?

    private init() { updateCount() }

    // MARK: - Pending Item

    struct PendingAction: Codable {
        let id: String
        let expenseId: Int
        let action: String   // "approve" | "reject" | "send"
        let reason: String?  // only set for "reject"
        let queuedAt: Date
        var userId: Int?     // nil = queued before 1.3.28
    }

    private var items: [PendingAction] {
        get {
            guard let data = defaults.data(forKey: storeKey),
                  let decoded = try? JSONDecoder().decode([PendingAction].self, from: data)
            else { return [] }
            return decoded
        }
        set {
            defaults.set(try? JSONEncoder().encode(newValue), forKey: storeKey)
            updateCount()
        }
    }

    private func mine(_ item: PendingAction) -> Bool {
        item.userId == nil || item.userId == activeUserId
    }

    private func updateCount() { pendingCount = items.filter { self.mine($0) }.count }

    func clearProblem() { lastProblem = nil }

    // MARK: - Enqueue

    func enqueue(expenseId: Int, action: String, reason: String? = nil, userId: Int?) {
        var current = items
        current.append(PendingAction(id: UUID().uuidString, expenseId: expenseId, action: action, reason: reason,
                                     queuedAt: Date(), userId: userId))
        items = current
    }

    // MARK: - Monitor & Drain

    func startMonitoring(actionHandler: @escaping (Int, String, String?) async throws -> ReceiptActionResponse) {
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor [weak self] in
                await self?.drain(actionHandler: actionHandler)
            }
        }
        guard !monitorStarted else { return }
        monitorStarted = true
        let queue = DispatchQueue(label: "mw.receipt-action.monitor", qos: .utility)
        monitor.start(queue: queue)
    }

    /// Retries this person's queued actions in order. No signal / 5xx / signed out stops the run
    /// (the rest wait, in order). A refusal — success:false or a 4xx — is permanent: that action
    /// is set aside with the server's reason and the next one is tried.
    func drain(actionHandler: @escaping (Int, String, String?) async throws -> ReceiptActionResponse) async {
        guard drainTask == nil else { return }
        if let userProvider { activeUserId = userProvider() }
        guard activeUserId != nil else { return }
        drainTask = Task {
            let pending = items.filter { self.mine($0) }
            for item in pending {
                do {
                    let response = try await actionHandler(item.expenseId, item.action, item.reason)
                    if !response.success {
                        lastProblem = "Couldn't \(item.action) receipt #\(item.expenseId): \(response.error ?? response.message ?? "the server said no")"
                    }
                    items = items.filter { $0.id != item.id }
                } catch {
                    if let reason = ReceiptQueue.permanentReason(error) {
                        lastProblem = "Couldn't \(item.action) receipt #\(item.expenseId): \(reason)"
                        items = items.filter { $0.id != item.id }
                        continue
                    }
                    break // no signal / server busy / signed out — leave queued, retry on next reconnect
                }
            }
            drainTask = nil
        }
        await drainTask?.value
    }
}
