//
//  JobsListViewModel.swift
//  MowologyCRM
//

import Foundation

@MainActor
final class JobsListViewModel: ObservableObject {

    @Published private(set) var visits: [JobItem] = []
    @Published private(set) var isLoading = false
    @Published private(set) var errorMessage: String?
    @Published var statusFilter = "all"

    private let apiClient: APIClient
    private let pageSize = 25

    private(set) var total = 0
    private(set) var offset = 0
    var hasMore: Bool { visits.count < total }

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    func load(reset: Bool = true) async {
        if reset {
            offset  = 0
            visits  = []
            total   = 0
        }
        guard !isLoading else { return }
        isLoading    = true
        errorMessage = nil

        do {
            let response: JobsListResponse = try await apiClient.request(
                .scheduleJobs(status: statusFilter, limit: pageSize, offset: offset)
            )
            total   = response.total
            offset  = offset + response.visits.count
            visits += response.visits
        } catch {
            errorMessage = "Failed to load jobs."
        }

        isLoading = false
    }

    func loadMore() async {
        guard hasMore, !isLoading else { return }
        await load(reset: false)
    }

    func applyFilter(_ status: String) async {
        statusFilter = status
        await load(reset: true)
    }
}
