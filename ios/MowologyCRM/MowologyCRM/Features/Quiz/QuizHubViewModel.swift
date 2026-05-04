//
//  QuizHubViewModel.swift
//  MowologyCRM
//

import Foundation

@MainActor
final class QuizHubViewModel: ObservableObject {

    @Published var categories: [QuizCategory] = []
    @Published var stats: QuizStats?
    @Published var season: QuizSeasonInfo?
    @Published var isLoading = false
    @Published var errorMessage: String?

    private let api: APIClient

    init(authSession: AuthSession) {
        self.api = APIClient(authSession: authSession)
    }

    func load() async {
        isLoading    = true
        errorMessage = nil
        defer { isLoading = false }

        async let categoriesTask: QuizCategoriesResponse = api.request(.quizCategories)
        async let statsTask: QuizStats                   = api.request(.quizStats)

        do {
            let (catResp, statsResp) = try await (categoriesTask, statsTask)
            categories = catResp.categories
            season     = catResp.season
            stats      = statsResp
        } catch {
            errorMessage = (error as? APIError)?.localizedDescription ?? error.localizedDescription
        }
    }
}
