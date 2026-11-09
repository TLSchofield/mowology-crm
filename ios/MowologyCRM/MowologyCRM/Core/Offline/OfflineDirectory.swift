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
        // Office accounts only (Add → Quote, Add → Invoice). Optional so a file from an earlier version still reads.
        var products: [QuoteProduct]?
        var gstPercent: Double?
        var unbilled: [UnbilledVisit]?
        var drafts: [DraftInvoice]?
    }

    @Published private(set) var people: [TaskPerson] = []
    @Published private(set) var clients: [DirectoryClient] = []
    @Published private(set) var products: [QuoteProduct] = []
    @Published private(set) var gstPercent: Double = 5
    @Published private(set) var unbilled: [UnbilledVisit] = []
    @Published private(set) var drafts: [DraftInvoice] = []
    /// When the copy on the phone was last fetched; nil when there is none for this login.
    @Published private(set) var savedAt: Date?

    private static var fileURL: URL { QueueStorage.directory.appendingPathComponent("offline-directory.json") }

    private init() {}

    /// Shows the phone's copy at once, then replaces each part from the server if there is signal. A part that fails
    /// keeps what the phone had. Each form asks only for what it uses (the invoice list is slow to build).
    func load(using apiClient: APIClient, prices: Bool = false, invoices: Bool = false) async {
        guard let me = apiClient.currentUserId else { return }
        var saved: Saved
        if let data = try? Data(contentsOf: Self.fileURL),
           let read = try? JSONDecoder().decode(Saved.self, from: data), read.userId == me {
            saved = read
        } else {
            saved = Saved(userId: me, people: [], clients: [], savedAt: .distantPast)
        }
        show(saved)
        var fetched = false

        if let r: TaskDirectoryResponse = await fetch(.tasksMobile, using: apiClient), r.success {
            saved = Saved(userId: me, people: r.people ?? [], clients: r.clients ?? [], savedAt: Date(),
                          products: saved.products, gstPercent: saved.gstPercent, unbilled: saved.unbilled, drafts: saved.drafts)
            fetched = true
        }
        if prices, let r: QuotePriceListResponse = await fetch(.quoteMobile, using: apiClient), r.success {
            saved.products = r.products ?? []; saved.gstPercent = r.gstPercent
            fetched = true
        }
        if invoices, let r: InvoicesMobileResponse = await fetch(.invoicesMobile, using: apiClient), r.success {
            saved.unbilled = r.unbilled ?? []; saved.drafts = r.drafts ?? []
            fetched = true
        }
        guard fetched else { return }
        show(saved)
        if let data = try? JSONEncoder().encode(saved) {
            try? data.write(to: Self.fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
        }
    }

    /// nil with no signal or a refusal: the phone's copy stands. Recorded so an unexpected failure is still findable.
    private func fetch<T: Decodable>(_ endpoint: APIEndpoint, using apiClient: APIClient) async -> T? {
        do {
            return try await apiClient.request(endpoint)
        } catch {
            AppDiagnostics.shared.record(category: "OfflineDirectory", message: ErrorDetails.describe(error))
            return nil
        }
    }

    private func show(_ s: Saved) {
        people = s.people; clients = s.clients
        savedAt = s.savedAt == .distantPast ? nil : s.savedAt
        products = s.products ?? []; gstPercent = s.gstPercent ?? 5
        unbilled = s.unbilled ?? []; drafts = s.drafts ?? []
    }

    /// Sign-out: nothing of the client list stays on the phone.
    func clear() {
        people = []; clients = []; savedAt = nil
        products = []; unbilled = []; drafts = []
        try? FileManager.default.removeItem(at: Self.fileURL)
    }

    func matches(_ query: String) -> [DirectoryClient] {
        let q = query.trimmingCharacters(in: .whitespaces)
        guard !q.isEmpty else { return Array(clients.prefix(50)) }
        return Array(clients.filter { $0.name.localizedCaseInsensitiveContains(q) || $0.address.localizedCaseInsensitiveContains(q) }.prefix(50))
    }
}
