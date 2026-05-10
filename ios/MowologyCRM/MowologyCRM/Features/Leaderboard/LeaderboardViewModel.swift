//
//  LeaderboardViewModel.swift
//  MowologyCRM
//

import Foundation

@MainActor
final class LeaderboardViewModel: ObservableObject {

    @Published private(set) var response: LeaderboardResponse? = nil
    @Published private(set) var isLoading                      = false
    @Published var errorMessage: String?                       = nil

    private let apiClient: APIClient

    init(authSession: AuthSession) {
        self.apiClient = APIClient(authSession: authSession)
    }

    func load(week: String? = nil) async {
        isLoading    = true
        errorMessage = nil
        do {
            response = try await apiClient.request(.leaderboard(week: week))
        } catch {
            errorMessage = "Could not load leaderboard. Check your connection."
        }
        isLoading = false
    }
}
