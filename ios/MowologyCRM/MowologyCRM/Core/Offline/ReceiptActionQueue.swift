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

import Foundation
import Network

@MainActor
final class ReceiptActionQueue: ObservableObject {

    static let shared = ReceiptActionQueue()

    @Published private(set) var pendingCount: Int = 0

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

    private func updateCount() { pendingCount = items.count }

    // MARK: - Enqueue

    func enqueue(expenseId: Int, action: String, reason: String? = nil) {
        var current = items
        current.append(PendingAction(id: UUID().uuidString, expenseId: expenseId, action: action, reason: reason, queuedAt: Date()))
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

    /// Retries queued actions in order. Stops at the first failure so actions on
    /// the same expense don't get applied out of order (e.g. a queued "reject"
    /// shouldn't be attempted before an earlier queued "approve" on a different
    /// expense that's still pending) — matches ReceiptQueue's drain semantics.
    func drain(actionHandler: @escaping (Int, String, String?) async throws -> ReceiptActionResponse) async {
        guard drainTask == nil else { return }
        drainTask = Task {
            let pending = items
            for item in pending {
                do {
                    let response = try await actionHandler(item.expenseId, item.action, item.reason)
                    guard response.success else { break } // business-rule failure (e.g. no longer valid) — stop, don't lose the rest
                    items = items.filter { $0.id != item.id }
                } catch {
                    break // network/server error — leave queued, retry on next reconnect
                }
            }
            drainTask = nil
        }
        await drainTask?.value
    }
}
