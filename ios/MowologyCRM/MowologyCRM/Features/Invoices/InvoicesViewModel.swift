//
//  InvoicesViewModel.swift
//  MowologyCRM
//

import Foundation

@MainActor
final class InvoicesViewModel: ObservableObject {

    @Published private(set) var invoices: [InvoiceItem] = []
    @Published private(set) var isLoading = false
    @Published private(set) var errorMessage: String?
    @Published var statusFilter = "all"

    private let apiClient: APIClient
    private let pageSize = 25

    private(set) var total = 0
    private(set) var offset = 0
    var hasMore: Bool { invoices.count < total }

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    func load(reset: Bool = true) async {
        if reset {
            offset   = 0
            invoices = []
            total    = 0
        }
        guard !isLoading else { return }
        isLoading    = true
        errorMessage = nil

        do {
            let response: InvoicesListResponse = try await apiClient.request(
                .scheduleInvoices(status: statusFilter)
            )
            total    = response.total
            offset   = offset + response.invoices.count
            invoices += response.invoices
        } catch {
            errorMessage = "Failed to load invoices."
        }

        isLoading = false
    }

    func applyFilter(_ status: String) async {
        statusFilter = status
        await load(reset: true)
    }

    var totalOutstanding: Double {
        invoices.filter { $0.status != "paid" && $0.status != "cancelled" }
                .reduce(0) { $0 + $1.balanceOwing }
    }
}
