//
//  QuizPreshiftGateView.swift
//  MowologyCRM
//
//  Full-screen gate shown before the main tabs when the daily pre-shift
//  quiz is required and not yet completed. Cannot be dismissed — the user
//  must complete the quiz to proceed.
//
//  PRODUCTION SAFETY — READ BEFORE EDITING
//  ────────────────────────────────────────
//  This view is the crew-facing enforcement of the pre-shift requirement.
//  Getting it wrong in either direction has field consequences:
//    - Always shows  → crew can't start their day even if quiz is disabled
//    - Never shows   → pre-shift training is bypassed
//
//  QuizPreshiftViewModel.checkStatus() short-circuits if status != nil.
//  This is intentional — RootView fetches status once and passes it via
//  initialStatus to avoid a redundant API call. Do NOT remove the guard or
//  the gate will always do a second fetch on appear, doubling the latency.
//
//  markComplete() is fail-open: if the POST to /api/quiz/preshift fails,
//  the error is recorded but onComplete() is still called. This ensures a
//  network timeout after a completed quiz does not re-show the gate.
//  The pre-shift log is written server-side; if the POST failed, the server
//  didn't write the log — but blocking the crew for a network error is worse
//  than allowing the bypass. The server will simply require the quiz again
//  tomorrow (or if the crew logs out and back in).
//
//  The completedToday guard in the body (status.completedToday → onComplete())
//  is a defensive safety net for if the gate is shown when the quiz is already
//  done (e.g., race condition on quick re-login). It auto-dismisses silently.
//
//  preshiftPlayView passes sessionLength from viewModel.status?.sessionLength.
//  This ensures the quiz length matches what the admin configured in
//  quiz_preshift_settings. Do NOT hardcode 5 or 10 here.
//

import SwiftUI

// MARK: - ViewModel

@MainActor
final class QuizPreshiftViewModel: ObservableObject {

    @Published var status: QuizPreshiftStatus?
    @Published var isLoading = false
    @Published var isMarkingComplete = false
    @Published var errorMessage: String?

    private let api: APIClient

    init(authSession: AuthSession, initialStatus: QuizPreshiftStatus? = nil) {
        self.api    = APIClient(authSession: authSession)
        self.status = initialStatus
    }

    func checkStatus() async {
        // Skip network call if we already have status from the login gate check
        guard status == nil else { return }
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

    init(authSession: AuthSession, preshiftStatus: QuizPreshiftStatus? = nil, onComplete: @escaping () -> Void) {
        _viewModel      = StateObject(wrappedValue: QuizPreshiftViewModel(authSession: authSession, initialStatus: preshiftStatus))
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
            sessionLength: viewModel.status?.sessionLength ?? 5,
            onFinished: { sessionId in
                Task {
                    await viewModel.markComplete(sessionId: sessionId)
                    onComplete()
                }
            }
        )
    }
}
