//
//  QuizHubViewModel.swift
//  MowologyCRM
//
//  PRODUCTION SAFETY — READ BEFORE EDITING
//  ────────────────────────────────────────
//  load() fires two requests concurrently using async let. Both must complete
//  before any UI state is set. If either throws, errorMessage is set and
//  neither categories nor stats are updated (consistent empty state).
//
//  The categories and stats endpoints are separate API calls by design —
//  stats covers lifetime data, categories covers the current user's mastery
//  per-category. Merging them into one endpoint would require a schema change.
//
//  isLoading wraps the full load — it is set false in defer{}, which runs
//  even if the async let throws. Do not set isLoading = false in the catch
//  block as well or it would be set twice (harmless but redundant).

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
