//
//  OfflineDirectory.swift
//  MowologyCRM
//
//  The people and clients the Add forms pick from, kept on the phone so they work with no signal (2026-11-08).
//  Refreshed whenever a form opens with signal; read from the file when it doesn't. Clients come down only for an
//  account with clients.view: crew phones hold the people list alone. A copy belongs to the user id it was fetched
//  for, and sign-out deletes it (the whole client list must not outlive the login).
//

import Foundation

@MainActor
final class OfflineDirectory: ObservableObject {

    static let shared = OfflineDirectory()

    private struct Saved: Codable {
        let userId: Int
        let people: [TaskPerson]
        let clients: [DirectoryClient]
        let savedAt: Date
    }

    @Published private(set) var people: [TaskPerson] = []
    @Published private(set) var clients: [DirectoryClient] = []
    /// When the copy on the phone was last fetched; nil when there is none for this login.
    @Published private(set) var savedAt: Date?

    private static var fileURL: URL { QueueStorage.directory.appendingPathComponent("offline-directory.json") }

    private init() {}

    /// Shows the phone's copy at once, then replaces it from the server if there is signal.
    func load(using apiClient: APIClient) async {
        guard let me = apiClient.currentUserId else { return }
        if let data = try? Data(contentsOf: Self.fileURL),
           let saved = try? JSONDecoder().decode(Saved.self, from: data), saved.userId == me {
            people = saved.people; clients = saved.clients; savedAt = saved.savedAt
        } else {
            people = []; clients = []; savedAt = nil
        }
        do {
            let r: TaskDirectoryResponse = try await apiClient.request(.tasksMobile)
            guard r.success else { return }
            let fresh = Saved(userId: me, people: r.people ?? [], clients: r.clients ?? [], savedAt: Date())
            people = fresh.people; clients = fresh.clients; savedAt = fresh.savedAt
            if let data = try? JSONEncoder().encode(fresh) {
                try? data.write(to: Self.fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
            }
        } catch {
            // No signal: the phone's copy stands. Recorded so an unexpected failure is still findable.
            AppDiagnostics.shared.record(category: "OfflineDirectory", message: ErrorDetails.describe(error))
        }
    }

    /// Sign-out: nothing of the client list stays on the phone.
    func clear() {
        people = []; clients = []; savedAt = nil
        try? FileManager.default.removeItem(at: Self.fileURL)
    }

    func matches(_ query: String) -> [DirectoryClient] {
        let q = query.trimmingCharacters(in: .whitespaces)
        guard !q.isEmpty else { return Array(clients.prefix(50)) }
        return Array(clients.filter { $0.name.localizedCaseInsensitiveContains(q) || $0.address.localizedCaseInsensitiveContains(q) }.prefix(50))
    }
}
