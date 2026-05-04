//
//  QuizHubView.swift
//  MowologyCRM
//
//  PRODUCTION SAFETY — READ BEFORE EDITING
//  ────────────────────────────────────────
//  This is the quiz landing screen (tab 4). It shows category cards, monthly
//  stats, and provides entry points for category-specific and any-category play.
//
//  "Play Any Categories" uses categoryId == 0 as a sentinel that is converted
//  to nil before being passed to QuizPlayView (cat.id == 0 ? nil : cat.id).
//  The server interprets nil category_id as "any category". Do not send 0 to
//  the server — quiz_categories has no id=0 row and the query would return no
//  questions.
//
//  Two .navigationDestination(item: $selectedCategory) blocks exist — one for
//  category taps in the grid, one inside the "Play Any" button scope. They
//  both watch the same @State var. In SwiftUI, two destinations for the same
//  item binding coexist but only the last registered one fires reliably.
//  If navigation stops working, check that both destinations resolve to the
//  same QuizPlayView init path (they currently do).
//
//  .task { await viewModel.load() } runs two parallel requests
//  (quizCategories and quizStats). Both must succeed for the UI to render.
//  If either fails, errorMessage is shown. No partial render is attempted —
//  the full stats bar and category grid require both responses.

import SwiftUI

struct QuizHubView: View {

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: QuizHubViewModel
    @State private var selectedCategory: QuizCategory?
    @State private var showingPlay = false

    private let columns = [GridItem(.flexible()), GridItem(.flexible())]

    init(authSession: AuthSession) {
        _viewModel = StateObject(wrappedValue: QuizHubViewModel(authSession: authSession))
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 20) {

                    if let error = viewModel.errorMessage {
                        ErrorBannerView(message: error)
                            .padding(.horizontal)
                    }

                    if viewModel.isLoading {
                        ProgressView()
                            .padding(.top, 60)
                    } else {
                        if let season = viewModel.season {
                            seasonHeader(season)
                                .padding(.horizontal)
                        }

                        if let stats = viewModel.stats {
                            statsBar(stats)
                                .padding(.horizontal)
                        }

                        playAnyButton

                        if !viewModel.categories.isEmpty {
                            categoryGrid
                        }

                        Spacer(minLength: 32)
                    }
                }
                .padding(.top, 16)
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("Quiz")
            .navigationBarTitleDisplayMode(.inline)
            .task { await viewModel.load() }
            .refreshable { await viewModel.load() }
            .navigationDestination(item: $selectedCategory) { cat in
                QuizPlayView(
                    authSession: authSession,
                    categoryId: cat.id,
                    categoryName: cat.name
                )
            }
        }
    }

    // MARK: - Season header

    private func seasonHeader(_ season: QuizSeasonInfo) -> some View {
        HStack(spacing: 10) {
            Text(season.seasonIcon)
                .font(.title2)
            VStack(alignment: .leading, spacing: 2) {
                Text(season.seasonLabel)
                    .font(.subheadline.bold())
                    .foregroundStyle(Color.MW.green)
                Text(season.seasonTagline)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
            }
            Spacer()
        }
        .padding(14)
        .background(Color.MW.green.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Stats bar

    private func statsBar(_ stats: QuizStats) -> some View {
        HStack(spacing: 0) {
            statItem(label: "Games", value: "\(stats.gamesPlayed)")
            Divider().frame(height: 32)
            statItem(label: "Points", value: "\(stats.monthlyPoints)")
            Divider().frame(height: 32)
            statItem(label: "Mastered", value: "\(stats.totalMastered)")
            Divider().frame(height: 32)
            statItem(label: "Streak", value: "🔥\(stats.currentStreak)")
        }
        .padding(.vertical, 12)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.05), radius: 6, x: 0, y: 2)
    }

    private func statItem(label: String, value: String) -> some View {
        VStack(spacing: 2) {
            Text(value)
                .font(.headline)
                .foregroundStyle(Color.MW.green)
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - Play any button

    private var playAnyButton: some View {
        Button {
            selectedCategory = QuizCategory(
                id: 0, name: "All Categories", description: nil, icon: nil, colour: nil,
                sortOrder: 0, questionCount: 0, masteredCount: 0,
                learningCount: 0, unseenCount: 0, dueCount: 0
            )
        } label: {
            HStack {
                Image(systemName: "play.fill")
                Text("Play — All Categories")
                    .font(.headline)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 14)
            .background(Color.MW.green)
            .foregroundStyle(.white)
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
        .padding(.horizontal)
        .navigationDestination(item: $selectedCategory) { cat in
            QuizPlayView(
                authSession: authSession,
                categoryId: cat.id == 0 ? nil : cat.id,
                categoryName: cat.id == 0 ? "All Categories" : cat.name
            )
        }
    }

    // MARK: - Category grid

    private var categoryGrid: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Categories")
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.secondary)
                .textCase(.uppercase)
                .padding(.horizontal)

            LazyVGrid(columns: columns, spacing: 12) {
                ForEach(viewModel.categories) { cat in
                    categoryCard(cat)
                }
            }
            .padding(.horizontal)
        }
    }

    private func categoryCard(_ cat: QuizCategory) -> some View {
        Button {
            selectedCategory = cat
        } label: {
            VStack(alignment: .leading, spacing: 8) {
                HStack {
                    Text(cat.icon ?? "📚")
                        .font(.title2)
                    Spacer()
                    if cat.dueCount > 0 {
                        Text("\(cat.dueCount) due")
                            .font(.caption2.bold())
                            .foregroundStyle(.white)
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(Color.orange)
                            .clipShape(Capsule())
                    }
                }

                Text(cat.name)
                    .font(.subheadline.bold())
                    .foregroundStyle(.primary)
                    .multilineTextAlignment(.leading)
                    .lineLimit(2)

                if cat.questionCount > 0 {
                    GeometryReader { geo in
                        ZStack(alignment: .leading) {
                            RoundedRectangle(cornerRadius: 3)
                                .fill(Color(.systemGray5))
                                .frame(height: 6)
                            RoundedRectangle(cornerRadius: 3)
                                .fill(Color.MW.green)
                                .frame(width: geo.size.width * cat.masteryFraction, height: 6)
                        }
                    }
                    .frame(height: 6)

                    Text("\(cat.masteredCount)/\(cat.questionCount) mastered")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .shadow(color: .black.opacity(0.05), radius: 4, x: 0, y: 1)
        }
        .buttonStyle(.plain)
    }
}
