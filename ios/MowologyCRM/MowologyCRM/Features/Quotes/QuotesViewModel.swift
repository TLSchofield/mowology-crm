//
//  QuotesViewModel.swift
//  MowologyCRM
//

import Foundation

@MainActor
final class QuotesViewModel: ObservableObject {

    @Published private(set) var quotes: [QuoteItem] = []
    @Published private(set) var isLoading = false
    @Published private(set) var errorMessage: String?
    @Published var statusFilter = "all"

    private let apiClient: APIClient
    private let pageSize = 25

    private(set) var total = 0
    private(set) var offset = 0
    var hasMore: Bool { quotes.count < total }

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    func load(reset: Bool = true) async {
        if reset {
            offset = 0
            quotes = []
            total  = 0
        }
        guard !isLoading else { return }
        isLoading    = true
        errorMessage = nil

        do {
            let response: QuotesListResponse = try await apiClient.request(
                .scheduleQuotes(status: statusFilter)
            )
            total   = response.total
            offset  = offset + response.quotes.count
            quotes += response.quotes
        } catch {
            errorMessage = "Failed to load quotes."
        }

        isLoading = false
    }

    func applyFilter(_ status: String) async {
        statusFilter = status
        await load(reset: true)
    }
}
