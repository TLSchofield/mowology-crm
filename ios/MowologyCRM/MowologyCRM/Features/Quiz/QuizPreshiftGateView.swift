//
//  QuizPreshiftGateView.swift
//  MowologyCRM
//
//  Full-screen gate shown before the main tabs when the daily pre-shift
//  quiz is required and not yet completed. Cannot be dismissed — the user
//  must complete the quiz to proceed.
//

import SwiftUI

// MARK: - ViewModel

@MainActor
final class QuizPreshiftViewModel: ObservableObject {

    @Published var status: QuizPreshiftStatus?
    @Published var isLoading = false
    @Published var isMarkingComplete = false
    @Published var errorMessage: String?
    @Published var showQuiz = false

    private let api: APIClient

    init(authSession: AuthSession) {
        self.api = APIClient(authSession: authSession)
    }

    func checkStatus() async {
        isLoading    = true
        errorMessage = nil
        defer { isLoading = false }
        do {
            let s: QuizPreshiftStatus = try await api.request(.quizPreshift)
            status = s
        } catch {
            errorMessage = (error as? APIError)?.localizedDescription ?? error.localizedDescription
        }
    }

    func markComplete(sessionId: Int?) async {
        isMarkingComplete = true
        defer { isMarkingComplete = false }
        var body: [String: Any] = [:]
        if let sid = sessionId { body["session_id"] = sid }
        do {
            let _: QuizPreshiftComplete = try await api.request(.quizPreshift, method: "POST", body: body)
            status = QuizPreshiftStatus(
                success: true, required: true,
                completedToday: true,
                sessionLength: status?.sessionLength ?? 5
            )
        } catch {
            // Even if marking fails, don't block the user — just log
            errorMessage = (error as? APIError)?.localizedDescription ?? error.localizedDescription
        }
    }
}

// MARK: - View

struct QuizPreshiftGateView: View {

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: QuizPreshiftViewModel
    let onComplete: () -> Void

    init(authSession: AuthSession, onComplete: @escaping () -> Void) {
        _viewModel     = StateObject(wrappedValue: QuizPreshiftViewModel(authSession: authSession))
        self.onComplete = onComplete
    }

    var body: some View {
        NavigationStack {
            if viewModel.isLoading {
                loadingView
            } else if let status = viewModel.status, status.completedToday {
                // Should not normally be shown, but guard for safety
                Color.clear.onAppear { onComplete() }
            } else {
                gateContent
            }
        }
        .task { await viewModel.checkStatus() }
    }

    // MARK: - Loading

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
                .scaleEffect(1.3)
            Text("Checking daily quiz…")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Gate content

    private var gateContent: some View {
        ScrollView {
            VStack(spacing: 28) {
                Spacer(minLength: 40)

                // Header illustration
                VStack(spacing: 12) {
                    Text("🌱")
                        .font(.system(size: 64))
                    Text("Daily Knowledge Check")
                        .font(.title2.bold())
                        .multilineTextAlignment(.center)
                    Text("Complete a short quiz before you start today.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                .padding(.horizontal)

                if let error = viewModel.errorMessage {
                    ErrorBannerView(message: error)
                        .padding(.horizontal)
                }

                // Start button
                NavigationLink(destination: preshiftPlayView) {
                    HStack {
                        Image(systemName: "play.fill")
                        Text("Start Quiz (\(viewModel.status?.sessionLength ?? 5) questions)")
                            .font(.headline)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 16)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 14))
                }
                .padding(.horizontal, 32)

                Spacer(minLength: 40)
            }
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle("Good Morning!")
        .navigationBarTitleDisplayMode(.inline)
    }

    // MARK: - Embedded play view

    @ViewBuilder
    private var preshiftPlayView: some View {
        QuizPlayView(
            authSession: authSession,
            categoryId: nil,
            categoryName: "Daily Quiz",
            onFinished: { sessionId in
                Task {
                    await viewModel.markComplete(sessionId: sessionId)
                    onComplete()
                }
            }
        )
    }
}
