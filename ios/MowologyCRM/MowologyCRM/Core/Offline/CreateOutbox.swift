//
//  CreateOutbox.swift
//  MowologyCRM
//
//  Everything the phone creates (jobs and clients now; tasks, quotes and invoices as they are built) goes out through
//  here, so it works with no signal (Tim, 2026-11-08: "maintaining fully offline capabilities throughout").
//
//  An entry is the request body plus the `client_request_id` the server dedupes on (IdempotencyHelper), so a send whose
//  answer was lost and is sent again never makes a second job. Entries live in a file in Application Support (not
//  purgeable, encrypted at rest; see QueueStorage) and drain in the order they were made: right after they are queued,
//  and on every reconnect (AppPhotoQueueDrainService, mwPingQueueOnline).
//
//  No signal or an expired login: the entry waits. The server saying no (a 4xx with a reason): the entry is kept with
//  that reason and shown, never dropped silently; the person retries or discards it. Only the person who made an entry
//  sends it.
//

import Foundation

@MainActor
final class CreateOutbox: ObservableObject {

    static let shared = CreateOutbox()

    enum Kind: String, Codable {
        case fieldJob   // POST field-job: add_visit / create_job / create_client_job
        case task       // POST tasks-mobile
        case quote      // POST quote-mobile: a draft; sent from Sam's "Quotes to send"
        case invoice    // POST invoices-mobile action=create: a draft; sent from Add → Invoice
    }

    struct Entry: Codable, Identifiable, Equatable {
        /// The `client_request_id` in the body: the server's dedupe key.
        let id: String
        let kind: Kind
        let userId: Int?
        /// What the person made, in their words ("Job for 12 Oak St").
        let title: String
        let bodyJSON: Data
        let queuedAt: Date
        var refused: String?
    }

    @Published private(set) var entries: [Entry] = []
    private var running: Task<Void, Never>?

    private static var fileURL: URL { QueueStorage.directory.appendingPathComponent("create-outbox.json") }

    private init() {
        if let data = try? Data(contentsOf: Self.fileURL),
           let saved = try? JSONDecoder().decode([Entry].self, from: data) {
            entries = saved
        }
    }

    // MARK: - Queue

    /// Keeps the body (with its client_request_id) on the phone. Returns false if it could not be written, so the
    /// caller never says "saved" about something that isn't.
    @discardableResult
    func enqueue(kind: Kind, userId: Int?, title: String, body: [String: Any]) -> Bool {
        guard userId != nil,   // an entry with no owner would never be sent, so never say it was saved
              let id = body["client_request_id"] as? String,
              let json = try? JSONSerialization.data(withJSONObject: body) else { return false }
        if entries.contains(where: { $0.id == id }) { return true }   // the same tap queued twice
        var list = entries
        list.append(Entry(id: id, kind: kind, userId: userId, title: title, bodyJSON: json, queuedAt: Date(), refused: nil))
        return save(list)
    }

    func retry(_ id: String, using apiClient: APIClient) async {
        guard let i = entries.firstIndex(where: { $0.id == id }) else { return }
        var list = entries
        list[i].refused = nil
        save(list)
        await drain(using: apiClient)
    }

    func discard(_ id: String) {
        save(entries.filter { $0.id != id })
    }

    var hasPending: Bool { !entries.isEmpty }

    func statusLine(me: Int?) -> String? { CreateOutboxLogic.statusLine(items, me: me) }

    /// What sign-out warns about.
    func pendingCount(me: Int?) -> Int { CreateOutboxLogic.mine(items, me: me).count }

    func mine(me: Int?) -> [Entry] { entries.filter { me != nil && $0.userId == me } }

    enum Outcome: Equatable {
        case sent, waiting, refused(String), notKept
    }

    /// From a form that is still open: queue it, try to send it, and say what happened. A refusal comes back to the form
    /// (and leaves the outbox), so the person fixes it there rather than in the sync list.
    func submit(kind: Kind, title: String, body: [String: Any], using apiClient: APIClient) async -> Outcome {
        guard enqueue(kind: kind, userId: apiClient.currentUserId, title: title, body: body),
              let id = body["client_request_id"] as? String else { return .notKept }
        await drain(using: apiClient)
        guard let entry = entries.first(where: { $0.id == id }) else { return .sent }
        if let reason = entry.refused {
            discard(id)
            return .refused(reason)
        }
        return .waiting
    }

    /// Visits with an invoice still on the phone, so Add → Invoice doesn't offer them twice.
    func queuedInvoiceVisitIds(me: Int?) -> Set<Int> {
        Set(mine(me: me).filter { $0.kind == .invoice }.compactMap {
            ((try? JSONSerialization.jsonObject(with: $0.bodyJSON)) as? [String: Any])?["visit_id"] as? Int
        })
    }

    // MARK: - Drain

    /// One run at a time. A caller arriving mid-run waits for it and then runs its own pass, because that run chose
    /// its entries before this caller's was queued.
    func drain(using apiClient: APIClient) async {
        while let running { await running.value }
        // `running` clears inside the task, so a waiter in the loop above never sees a finished run still set.
        let task = Task {
            await self.drainOnce(using: apiClient)
            self.running = nil
        }
        running = task
        await task.value
    }

    private func drainOnce(using apiClient: APIClient) async {
        let me = apiClient.currentUserId
        for item in CreateOutboxLogic.toSend(items, me: me) {
            guard let entry = entries.first(where: { $0.id == item.id }) else { continue }
            guard let body = (try? JSONSerialization.jsonObject(with: entry.bodyJSON)) as? [String: Any] else {
                mark(entry.id, refused: "The saved request could not be read back on this phone.")
                continue
            }
            do {
                if let refusal = try await send(entry.kind, body: body, using: apiClient) {
                    mark(entry.id, refused: refusal)
                } else {
                    save(entries.filter { $0.id != entry.id })
                    NotificationCenter.default.post(name: .mwOutboxSent, object: entry.kind.rawValue)
                }
            } catch APIError.serverError(let message) {
                mark(entry.id, refused: message)
            } catch {
                // No signal, a busy server, or the login expired: keep it, and keep the order.
                AppDiagnostics.shared.record(category: "CreateOutbox", message: "\(entry.kind.rawValue) waits: \(ErrorDetails.describe(error))")
                return
            }
        }
    }

    /// nil = saved on the server; a string = the server's reason for saying no.
    private func send(_ kind: Kind, body: [String: Any], using apiClient: APIClient) async throws -> String? {
        switch kind {
        case .fieldJob:
            let r: FieldJobActionResponse = try await apiClient.request(.fieldJobAction, body: body, faultsAreTransient: true)
            return r.success ? nil : (r.error ?? "Field job response success=false; server returned no error field.")
        case .task:
            let r: TaskCreateResponse = try await apiClient.request(.tasksMobileCreate, body: body, faultsAreTransient: true)
            return r.success ? nil : (r.error ?? "Task response success=false; server returned no error field.")
        case .quote:
            let r: QuoteCreateResponse = try await apiClient.request(.quoteMobileCreate, body: body, faultsAreTransient: true)
            return r.success ? nil : (r.error ?? "Quote response success=false; server returned no error field.")
        case .invoice:
            let r: InvoiceActionResponse = try await apiClient.request(.invoicesMobileAction, body: body, faultsAreTransient: true)
            return r.success ? nil : (r.error ?? "Invoice response success=false; server returned no error field.")
        }
    }

    // MARK: - Storage

    private var items: [CreateOutboxLogic.Item] {
        entries.map { CreateOutboxLogic.Item(id: $0.id, userId: $0.userId, refused: $0.refused) }
    }

    private func mark(_ id: String, refused: String) {
        guard let i = entries.firstIndex(where: { $0.id == id }) else { return }
        var list = entries
        list[i].refused = refused
        save(list)
    }

    @discardableResult
    private func save(_ list: [Entry]) -> Bool {
        do {
            let data = try JSONEncoder().encode(list)
            try data.write(to: Self.fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
            entries = list
            return true
        } catch {
            AppDiagnostics.shared.record(category: "CreateOutbox", message: "save failed: \(ErrorDetails.describe(error))")
            return false
        }
    }
}

extension Notification.Name {
    /// Something made offline reached the server (object: the Kind's raw value), so lists can refresh.
    static let mwOutboxSent = Notification.Name("mwOutboxSent")
}
