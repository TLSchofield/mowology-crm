import Foundation

@MainActor
final class VisitWorkQueue: ObservableObject {
    static let shared = VisitWorkQueue()

    @Published private(set) var entries: [VisitWorkEntry] = []
    private var running: Task<Void, Never>?

    private static var fileURL: URL {
        let directory = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory.appendingPathComponent("mw-visit-work-queue.json")
    }

    private init() {
        guard let data = try? Data(contentsOf: Self.fileURL) else { return }
        do {
            entries = try JSONDecoder().decode([VisitWorkEntry].self, from: data)
        } catch {
            AppDiagnostics.shared.record(category: "VisitWorkQueue", message: "read failed: \(ErrorDetails.describe(error))")
        }
    }

    @discardableResult
    func enqueue(
        userId: Int,
        visitId: Int,
        kind: VisitWorkEntry.Kind,
        body: [String: Any],
        id: String,
        performedAt: Date
    ) -> Bool {
        guard let data = try? JSONSerialization.data(withJSONObject: body),
              let payloadJSON = String(data: data, encoding: .utf8) else { return false }
        let entry = VisitWorkEntry(
            id: id,
            userId: userId,
            visitId: visitId,
            kind: kind,
            payloadJSON: payloadJSON,
            performedAt: performedAt
        )
        return save(VisitWorkQueueLogic.enqueue(entries, new: entry))
    }

    func ownEntries(userId: Int) -> [VisitWorkEntry] {
        VisitWorkQueueLogic.ownEntries(userId: userId, in: entries)
    }

    func entries(userId: Int, visitId: Int) -> [VisitWorkEntry] {
        ownEntries(userId: userId).filter { $0.visitId == visitId }
    }

    func discard(_ id: String, userId: Int) {
        save(entries.filter { !($0.id == id && $0.userId == userId) })
    }

    func removeQueuedList(userId: Int, visitId: Int, kind: VisitWorkEntry.Kind) {
        let list = entries.filter { !($0.userId == userId && $0.visitId == visitId && $0.kind == kind) }
        if list != entries { save(list) }
    }

    func clearAfterTenantQuarantine() {
        running?.cancel()
        running = nil
        entries = []
    }

    func drain(using apiClient: APIClient) async {
        while let running { await running.value }
        let task = Task {
            await self.drainOnce(using: apiClient)
            self.running = nil
        }
        running = task
        await task.value
    }

    private func drainOnce(using apiClient: APIClient) async {
        guard let userId = apiClient.currentUserId else { return }
        while let entry = VisitWorkQueueLogic.next(for: userId, in: entries) {
            guard let data = entry.payloadJSON.data(using: .utf8),
                  var body = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] else {
                recordFailure(entry, message: "The saved visit-work request could not be read back on this phone.")
                return
            }
            body["performed_at"] = Int64(entry.performedAt.timeIntervalSince1970 * 1_000)
            do {
                let state: VisitWorkState = try await apiClient.request(
                    .visitWorkAction,
                    body: body,
                    faultsAreTransient: true
                )
                guard state.success else {
                    recordFailure(entry, message: state.message ?? "Visit work response success=false; server returned no message field.")
                    return
                }
                save(VisitWorkQueueLogic.afterSuccess(entry, in: entries))
                NotificationCenter.default.post(name: .mwVisitWorkQueueChanged, object: entry)
            } catch APIError.serverError(let message) {
                recordRefusal(entry, message: message)
                return
            } catch {
                AppDiagnostics.shared.record(category: "VisitWorkQueue", message: "send waits: \(ErrorDetails.describe(error))")
                return
            }
        }
    }

    private func recordFailure(_ entry: VisitWorkEntry, message: String) {
        save(VisitWorkQueueLogic.afterFailure(entry, message: message, in: entries))
        if entries.first(where: { $0.id == entry.id })?.isRefused == true {
            NotificationCenter.default.post(name: .mwVisitWorkQueueChanged, object: entry)
        }
    }

    private func recordRefusal(_ entry: VisitWorkEntry, message: String) {
        save(VisitWorkQueueLogic.afterRefusal(entry, message: message, in: entries))
        NotificationCenter.default.post(name: .mwVisitWorkQueueChanged, object: entry)
    }

    @discardableResult
    private func save(_ list: [VisitWorkEntry]) -> Bool {
        do {
            let data = try JSONEncoder().encode(list)
            try data.write(to: Self.fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
            entries = list
            return true
        } catch {
            AppDiagnostics.shared.record(category: "VisitWorkQueue", message: "save failed: \(ErrorDetails.describe(error))")
            return false
        }
    }
}

extension Notification.Name {
    static let mwVisitWorkQueueChanged = Notification.Name("mwVisitWorkQueueChanged")
}
